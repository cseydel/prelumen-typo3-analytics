<?php

declare(strict_types=1);

// SPDX-License-Identifier: GPL-2.0-or-later

namespace Oneco\AnalyticsPro\Typo3\Service;

use Oneco\AnalyticsPro\Core\Client\PublicApiClient;
use Oneco\AnalyticsPro\Core\Dto\WebsiteRegistrationRequest;
use Oneco\AnalyticsPro\Core\Dto\WebsiteRegistrationResult;
use Oneco\AnalyticsPro\Typo3\Configuration\SiteSettings;
use Psr\Http\Client\ClientInterface;
use TYPO3\CMS\Core\Configuration\SiteConfiguration;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Http\StreamFactory;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Registers a TYPO3 site against the Prelumen Analytics public API and, on success,
 * persists the returned website_uuid into the site's prelumenAnalytics configuration.
 */
final class WebsiteRegistrationService
{
    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactory $requestFactory,
        private readonly StreamFactory $streamFactory,
        private readonly SiteFinder $siteFinder,
        private readonly SiteConfiguration $siteConfiguration,
    ) {
    }

    public function register(string $siteIdentifier, string $apiKey): WebsiteRegistrationResult
    {
        $site = $this->siteFinder->getSiteByIdentifier($siteIdentifier);
        $settings = SiteSettings::fromSiteConfiguration($site->getConfiguration());
        if ($settings->apiBaseUrl === '') {
            throw new \RuntimeException(
                'Set prelumenAnalytics.apiBaseUrl in the site configuration before registering.'
            );
        }

        $client = new PublicApiClient(
            $this->httpClient,
            $this->requestFactory,
            $this->streamFactory,
            $settings->apiBaseUrl,
            $apiKey
        );

        $result = $client->registerWebsite(new WebsiteRegistrationRequest((string) $site->getBase()));

        if ($result->successful && $result->websiteUuid !== null) {
            $configuration = $this->siteConfiguration->load($siteIdentifier);
            $configuration['prelumenAnalytics'] = is_array($configuration['prelumenAnalytics'] ?? null)
                ? $configuration['prelumenAnalytics']
                : [];
            $configuration['prelumenAnalytics']['siteId'] = $result->websiteUuid;
            if (method_exists($this->siteConfiguration, 'write')) {
                $this->siteConfiguration->write($siteIdentifier, $configuration);
            } else {
                \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(SiteWriter::class)
                    ->write($siteIdentifier, $configuration);
            }
        }

        return $result;
    }
}
