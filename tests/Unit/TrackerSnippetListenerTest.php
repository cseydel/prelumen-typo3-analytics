<?php

declare(strict_types=1);

// SPDX-License-Identifier: GPL-2.0-or-later

namespace Oneco\AnalyticsPro\Typo3\Tests\Unit;

use Oneco\AnalyticsPro\Typo3\Configuration\SiteSettings;
use Oneco\AnalyticsPro\Typo3\EventListener\TrackerSnippetListener;
use PHPUnit\Framework\TestCase;

final class TrackerSnippetListenerTest extends TestCase
{
    private const SITE_ID = '00000000-0000-4000-8000-000000000001';

    public function testSiteSettingsParseSiteConfiguration(): void
    {
        $settings = SiteSettings::fromSiteConfiguration([
            'prelumenAnalytics' => [
                'siteId' => self::SITE_ID,
                'apiBaseUrl' => 'https://analytics.prelumen.com/',
                'heartbeatEnabled' => false,
                'metadataPushEnabled' => true,
                'cookiePersistEnabled' => true,
                'excludedFrontendGroups' => [1, '2', '0', 'bad'],
                'consentManaged' => true,
                'consentCookie' => 'cookie_consent',
            ],
        ]);

        self::assertSame(self::SITE_ID, $settings->siteId);
        self::assertSame('https://analytics.prelumen.com', $settings->apiBaseUrl);
        self::assertFalse($settings->heartbeatEnabled);
        self::assertTrue($settings->metadataPushEnabled);
        self::assertTrue($settings->cookiePersistEnabled);
        self::assertSame([1, 2], $settings->excludedFrontendGroups);
        self::assertTrue($settings->consentManaged);
        self::assertSame('cookie_consent', $settings->consentCookie);
        self::assertTrue($settings->isConfigured());
    }

    public function testRenderEmitsLoaderAndDefaultsToFull(): void
    {
        $html = (new TrackerSnippetListener())->render($this->settings());

        self::assertStringContainsString('window.prelumenConsent', $html);
        self::assertStringContainsString('/track/' . self::SITE_ID . '/script.js?features=pageviews', $html);
        self::assertStringContainsString('/track/' . self::SITE_ID . '/script.js"', $html);
        self::assertStringContainsString('"persist":false', $html);
        // No gating configured -> full.
        self::assertStringContainsString('window.prelumenConsent("full")', $html);
    }

    public function testPersistenceRequiresExplicitConsentIntegration(): void
    {
        $html = (new TrackerSnippetListener())->render($this->settings(cookiePersistEnabled: true));

        self::assertStringContainsString('"persist":false', $html);
        self::assertStringContainsString('"persist":true', (new TrackerSnippetListener())->render(
            $this->settings(cookiePersistEnabled: true, consentManaged: true)
        ));
    }

    public function testManagedConsentEmitsLoaderWithoutAutoTrigger(): void
    {
        $html = (new TrackerSnippetListener())->render($this->settings(consentManaged: true));

        self::assertStringContainsString('window.prelumenConsent', $html);
        self::assertStringNotContainsString('window.prelumenConsent("full")', $html);
    }

    public function testConsentCookieEmitsCookieBridge(): void
    {
        $html = (new TrackerSnippetListener())->render($this->settings(consentCookie: 'cookie_consent'));

        self::assertStringContainsString('document.cookie', $html);
        self::assertStringContainsString('"cookie_consent"', $html);
        self::assertStringNotContainsString('window.prelumenConsent("full")', $html);
    }

    public function testRenderReturnsEmptyWhenNotConfigured(): void
    {
        self::assertSame('', (new TrackerSnippetListener())->render($this->settings(siteId: '')));
    }

    private function settings(
        string $siteId = self::SITE_ID,
        bool $cookiePersistEnabled = false,
        bool $consentManaged = false,
        string $consentCookie = ''
    ): SiteSettings {
        return new SiteSettings(
            $siteId,
            'https://analytics.prelumen.com',
            true,
            false,
            $cookiePersistEnabled,
            [],
            $consentManaged,
            $consentCookie
        );
    }
}
