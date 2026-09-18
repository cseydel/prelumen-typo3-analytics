<?php

declare(strict_types=1);

// SPDX-License-Identifier: GPL-2.0-or-later

namespace Oneco\AnalyticsPro\Typo3\EventListener;

use Oneco\AnalyticsPro\Core\Config\ConsentState;
use Oneco\AnalyticsPro\Core\Config\SiteConfig;
use Oneco\AnalyticsPro\Core\Snippet\ConsentLoader;
use Oneco\AnalyticsPro\Typo3\Configuration\SiteSettings;

/** Renders the runtime-consent loader; response middleware owns cache-safe injection. */
final class TrackerSnippetListener
{
    /**
     * The consent loader plus its consent bridge, or '' when the site is not configured.
     */
    public function render(SiteSettings $settings): string
    {
        try {
            $siteConfig = new SiteConfig(
                $settings->siteId,
                $settings->apiBaseUrl,
                null,
                ConsentState::Unknown,
                [],
                $settings->heartbeatEnabled,
                $settings->metadataPushEnabled,
                $settings->cookiePersistEnabled
            );
        } catch (\InvalidArgumentException) {
            return '';
        }

        return (new ConsentLoader())->render($siteConfig, null, $settings->consentManaged || $settings->consentCookie !== '', true) . $this->consentBridge($settings);
    }

    private function consentBridge(SiteSettings $settings): string
    {
        if ($settings->consentCookie !== '') {
            return $this->cookieBridge($settings->consentCookie);
        }

        if ($settings->consentManaged) {
            // The site's consent manager calls window.prelumenConsent(cap) itself.
            return '';
        }

        // No consent gating configured: load the full website-configured script.
        return '<script>window.prelumenConsent("full");</script>';
    }

    private function cookieBridge(string $cookieName): string
    {
        $name = json_encode($cookieName, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);
        if ($name === false) {
            return '';
        }

        $script = '(function(){'
            . 'function r(n){var p=document.cookie.split("; ");'
            . 'for(var i=0;i<p.length;i++){var x=p[i].split("=");'
            . 'if(x[0]===n){try{return decodeURIComponent(x.slice(1).join("="));}catch(e){return "none";}}}return "";}'
            . 'function a(){if(!window.prelumenConsent){return;}'
            . 'var v=r(' . $name . ');'
            . 'window.prelumenConsent(v==="full"||v==="basic"||v==="none"?v:"none");}'
            . 'document.addEventListener("prelumen:consent-change",a);window.addEventListener("focus",a);'
            . 'window.setInterval(a,1000);'
            . 'if(document.readyState!=="loading"){a();}else{document.addEventListener("DOMContentLoaded",a);}'
            . '})();';

        return '<script>' . $script . '</script>';
    }

}
