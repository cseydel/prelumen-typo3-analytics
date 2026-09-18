<?php

declare(strict_types=1);

// SPDX-License-Identifier: GPL-2.0-or-later

namespace Oneco\AnalyticsPro\Typo3\Service;

use Oneco\AnalyticsPro\Core\Client\InstallationClient;
use Oneco\AnalyticsPro\Core\Client\InstallationLifecycle;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Site\Entity\Site;

/** Thin TYPO3 adapter: transport, site context, encryption and locking. */
final class InstallationService
{
    public function __construct(private readonly ConnectionStore $store, private readonly RequestFactory $http)
    {
    }

    public function run(Site $site, string $action): array
    {
        return $this->store->locked($site->getIdentifier(), function () use ($site, $action): array {
            $identifier = $site->getIdentifier();
            $state = $this->store->read($identifier);
            // Trusted deployment configuration, never read from browser input or API replies.
            $extensionConfiguration = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['prelumen_analytics']
                ?? $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['oneco_analytics_pro']
                ?? [];
            $base = rtrim((string) ($extensionConfiguration['installationApiUrl'] ?? 'https://analytics.prelumen.com'), '/');
            if ($action === 'disconnect' && !empty($state['api_base_url'])) {
                $base = $state['api_base_url']; // Revoke only at the server that issued this credential.
            }
            if (parse_url($base, PHP_URL_SCHEME) !== 'https' || parse_url($base, PHP_URL_USER) !== null
                || parse_url($base, PHP_URL_QUERY) !== null || parse_url($base, PHP_URL_FRAGMENT) !== null) {
                throw new \RuntimeException('PUBLIC_HTTPS_REQUIRED');
            }
            $url = in_array($action, ['disconnect', 'manual'], true)
                ? rtrim((string) $site->getBase(), '/')
                : \Oneco\AnalyticsPro\Core\Client\InstallationUrl::canonical((string) $site->getBase());
            $client = new InstallationClient(function (string $method, string $path, array $body, string $secret) use ($base): array {
                try {
                    $options = ['timeout' => str_ends_with($path, '/status') ? 8 : 20,
                        'allow_redirects' => false, 'http_errors' => false, 'stream' => true,
                        'headers' => ['Accept' => 'application/json', 'X-Installation-Key' => $secret]];
                    if ($body !== []) {
                        $options['json'] = $body;
                    }
                    $response = $this->http->request($base . $path, $method, $options);
                    $stream = $response->getBody();
                    $body = '';
                    try {
                        while (!$stream->eof() && strlen($body) <= 262144) {
                            $chunk = $stream->read(min(8192, 262145 - strlen($body)));
                            if ($chunk === '' && !$stream->eof()) {
                                throw new \RuntimeException('INVALID_RESPONSE');
                            }
                            $body .= $chunk;
                        }
                    } finally {
                        $stream->close();
                    }
                    if (strlen($body) > 262144) {
                        throw new \RuntimeException('INVALID_RESPONSE');
                    }
                    return ['status' => $response->getStatusCode(), 'body' => $body];
                } catch (\Throwable) {
                    throw new \RuntimeException('TEMPORARILY_UNAVAILABLE');
                }
            });
            $lifecycle = new InstallationLifecycle($client, $state, $url, $base,
                fn (array $value) => $this->store->write($identifier, $value), 'typo3',
                (string) ($extensionConfiguration['backendReturnPath'] ?? '/typo3/'));
            if ($action === 'billing') {
                return ['redirect' => $lifecycle->billingUrl()];
            }
            match ($action) {
                'login', 'register', 'recovery' => $lifecycle->begin($action),
                'complete' => $lifecycle->complete(),
                'disconnect' => $lifecycle->disconnect(),
                'manual' => $lifecycle->useManual(),
                'refresh' => $lifecycle->refresh(),
                'status' => $lifecycle->status(),
                default => throw new \RuntimeException('INVALID_ACTION'),
            };
            return $lifecycle->state();
        });
    }

    public function state(Site $site): array
    {
        return $this->store->read($site->getIdentifier());
    }
}
