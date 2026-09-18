<?php

declare(strict_types=1);

// SPDX-License-Identifier: GPL-2.0-or-later

namespace Oneco\AnalyticsPro\Typo3\Configuration;

final class SiteSettings
{
    /**
     * @param list<int> $excludedFrontendGroups
     */
    public function __construct(
        public readonly string $siteId,
        public readonly string $apiBaseUrl,
        public readonly bool $heartbeatEnabled = true,
        public readonly bool $metadataPushEnabled = false,
        public readonly bool $cookiePersistEnabled = false,
        public readonly array $excludedFrontendGroups = [],
        public readonly bool $consentManaged = false,
        public readonly string $consentCookie = '',
        public readonly bool $footerBadgeEnabled = false
    ) {
    }

    /**
     * @param array<string, mixed> $configuration
     */
    public static function fromSiteConfiguration(array $configuration): self
    {
        $settings = is_array($configuration['prelumenAnalytics'] ?? null)
            ? $configuration['prelumenAnalytics']
            : (is_array($configuration['onecoAnalyticsPro'] ?? null)
                ? $configuration['onecoAnalyticsPro']
                : []);

        $groups = [];
        foreach ((array) ($settings['excludedFrontendGroups'] ?? []) as $group) {
            $group = (int) $group;
            if ($group > 0) {
                $groups[] = $group;
            }
        }

        return new self(
            trim((string) ($settings['siteId'] ?? '')),
            rtrim(trim((string) ($settings['apiBaseUrl'] ?? '')), '/'),
            (bool) ($settings['heartbeatEnabled'] ?? true),
            (bool) ($settings['metadataPushEnabled'] ?? false),
            (bool) ($settings['cookiePersistEnabled'] ?? false),
            array_values(array_unique($groups)),
            (bool) ($settings['consentManaged'] ?? false),
            trim((string) ($settings['consentCookie'] ?? '')),
            (bool) ($settings['footerBadgeEnabled'] ?? false)
        );
    }

    public function isConfigured(): bool
    {
        return $this->siteId !== '' && $this->apiBaseUrl !== '';
    }
}
