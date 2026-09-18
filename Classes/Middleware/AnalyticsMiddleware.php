<?php

declare(strict_types=1);

// SPDX-License-Identifier: GPL-2.0-or-later

namespace Oneco\AnalyticsPro\Typo3\Middleware;

use Oneco\AnalyticsPro\Core\Config\SiteConfig;
use Oneco\AnalyticsPro\Typo3\Configuration\SiteSettings;
use Oneco\AnalyticsPro\Typo3\EventListener\TrackerSnippetListener;
use Oneco\AnalyticsPro\Typo3\Service\InstallationService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Site\Entity\Site;

/** Runs outside TYPO3's page cache: cached pages never retain an installation decision. */
final class AnalyticsMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly InstallationService $connections, private readonly TrackerSnippetListener $renderer)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $site = $request->getAttribute('site');
        if (!$site instanceof Site) {
            return $handler->handle($request);
        }
        try {
            $state = $this->connections->state($site);
        } catch (\Throwable) {
            $state = ['mode' => 'disabled']; // Unreadable credentials must never enable fallback tracking.
        }
        try {
            $canonical = \Oneco\AnalyticsPro\Core\Client\InstallationUrl::canonical((string) $site->getBase());
        } catch (\RuntimeException) {
            $canonical = '';
        }
        if (($request->getQueryParams()['prelumen'] ?? '') === 'challenge') {
            $challenge = $request->getMethod() === 'GET' && ($state['pending_until'] ?? 0) > time()
                && ($state['url'] ?? '') === $canonical
                ? ($state['challenge'] ?? '') : '';
            return new JsonResponse(['challenge' => $challenge], 200, ['Cache-Control' => 'no-store']);
        }
        $response = $handler->handle($request);
        if ($request->getMethod() !== 'GET' || $response->getStatusCode() !== 200
            || !str_contains(strtolower($response->getHeaderLine('Content-Type')), 'text/html')) {
            return $response;
        }
        $configuration = $site->getConfiguration();
        $mode = $state['mode'] ?? 'manual';
        if ($mode === 'managed') {
            if (empty($state['website_uuid']) || !empty($state['suspended'])
                || ($state['expires_at'] ?? 0) <= time() || empty($state['status']['active'])
                || ($state['status_at'] ?? 0) < time() - 600
                || ($state['url'] ?? '') !== $canonical) {
                return $response;
            }
            $configuration['prelumenAnalytics']['siteId'] = $state['website_uuid'];
            $configuration['prelumenAnalytics']['apiBaseUrl'] = $state['api_base_url'];
        } elseif ($mode !== 'manual') {
            return $response;
        }
        $settings = SiteSettings::fromSiteConfiguration($configuration);
        $user = $request->getAttribute('frontend.user');
        if (array_intersect($settings->excludedFrontendGroups, (array) ($user->groupData['uid'] ?? [])) !== []) {
            return $response;
        }
        $snippet = $this->renderer->render($settings);
        $html = (string) $response->getBody();
        $head = strripos($html, '</head>');
        if ($snippet === '' || $head === false) {
            return $response;
        }
        $html = substr_replace($html, $snippet, $head, 0);
        if ($settings->footerBadgeEnabled && ($body = strripos($html, '</body>')) !== false) {
            $config = new SiteConfig($settings->siteId, $settings->apiBaseUrl);
            $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $badge = '<div class="prelumen-footer-badge"><a href="' . $escape($config->trackingUrl('badge'))
                . '" rel="noreferrer"><img src="' . $escape($config->trackingUrl('badge.svg'))
                . '" alt="Prelumen Analytics" width="200" height="92" loading="lazy" referrerpolicy="no-referrer"></a></div>';
            $html = substr_replace($html, $badge, $body, 0);
        }
        $stream = new Stream('php://temp', 'rw');
        $stream->write($html);
        // Downstream proxies must not cache installation decisions; TYPO3's page cache still works.
        return $response->withBody($stream)->withoutHeader('Content-Length')->withoutHeader('ETag')
            ->withHeader('Cache-Control', 'private, no-store');
    }
}
