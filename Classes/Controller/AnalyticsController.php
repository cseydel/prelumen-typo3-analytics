<?php

declare(strict_types=1);

// SPDX-License-Identifier: GPL-2.0-or-later

namespace Oneco\AnalyticsPro\Typo3\Controller;

use Oneco\AnalyticsPro\Core\Config\SiteConfig;
use Oneco\AnalyticsPro\Typo3\Configuration\SiteSettings;
use Oneco\AnalyticsPro\Typo3\Service\WebsiteRegistrationService;
use Psr\Http\Message\ResponseInterface;
use Oneco\AnalyticsPro\Typo3\Security\SiteAccess;
use Oneco\AnalyticsPro\Typo3\Service\InstallationService;
use TYPO3\CMS\Core\FormProtection\FormProtectionFactory;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

final class AnalyticsController extends ActionController
{
    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly SiteFinder $siteFinder,
        private readonly WebsiteRegistrationService $registrationService,
        private readonly SiteAccess $access,
        private readonly InstallationService $connections,
        private readonly FormProtectionFactory $forms,
    ) {
    }

    public function indexAction(): ResponseInterface
    {
        $sites = [];
        foreach ($this->siteFinder->getAllSites() as $site) {
            if ($this->access->allowed($site)) {
                $sites[] = $this->describeSite($site);
            }
        }

        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->setTitle('Prelumen Analytics');
        $moduleTemplate->assign('sites', $sites);

        return $moduleTemplate->renderResponse('Analytics/Index');
    }

    public function registerAction(string $site = '', string $apiKey = '', string $formToken = ''): ResponseInterface
    {
        $selected = $this->authorize($site, $formToken);
        if (!empty($this->connections->state($selected)['id'])) {
            throw new \RuntimeException('Disconnect the managed installation first.');
        }
        $site = trim($site);
        $apiKey = trim($apiKey);

        if ($site === '' || $apiKey === '') {
            $this->addFlashMessage(
                'Site and API key are required.',
                'Prelumen Analytics',
                ContextualFeedbackSeverity::ERROR
            );

            return $this->redirect('index');
        }

        try {
            $result = $this->registrationService->register($site, $apiKey);
        } catch (\Throwable $exception) {
            $this->addFlashMessage(
                'Registration failed. Check the site configuration and API key.',
                'Registration failed',
                ContextualFeedbackSeverity::ERROR
            );

            return $this->redirect('index');
        }

        if ($result->successful && $result->websiteUuid !== null) {
            $this->connections->run($selected, 'manual');
            $message = sprintf('Site registered. Site ID %s stored in the site configuration.', $result->websiteUuid);
            $token = $result->verificationToken();
            if ($token !== null) {
                $message .= ' Ownership verification token: ' . $token;
            }

            $this->addFlashMessage($message, 'Prelumen Analytics', ContextualFeedbackSeverity::OK);
        } else {
            $this->addFlashMessage(
                sprintf(
                    '%s (%s)',
                    $result->errorMessage ?? 'Registration failed.',
                    $result->errorCode ?? 'error'
                ),
                'Registration failed',
                ContextualFeedbackSeverity::ERROR
            );
        }

        return $this->redirect('index');
    }

    public function connectionAction(string $site = '', string $operation = '', string $formToken = ''): ResponseInterface
    {
        $selected = $this->authorize($site, $formToken);
        try {
            $state = $this->connections->run($selected, $operation);
            if ($operation === 'billing' && isset($state['redirect'])) {
                $module = $this->moduleTemplateFactory->create($this->request);
                $module->setTitle('Prelumen billing');
                $module->assign('billingUrl', $state['redirect']);
                return $module->renderResponse('Analytics/Billing');
            }
        } catch (\Throwable $error) {
            $this->addFlashMessage($this->errorMessage($error->getMessage()), 'Prelumen Analytics', ContextualFeedbackSeverity::WARNING);
        }
        return $this->redirect('index');
    }

    private function authorize(string $identifier, string $token): Site
    {
        try {
            $site = $this->siteFinder->getSiteByIdentifier($identifier);
        } catch (\TYPO3\CMS\Core\Exception\SiteNotFoundException) {
            throw new \TYPO3\CMS\Core\Http\ImmediateResponseException(new \TYPO3\CMS\Core\Http\HtmlResponse('Access denied', 403));
        }
        if ($this->request->getMethod() !== 'POST' || !$this->access->allowed($site)
            || !$this->forms->createFromRequest($this->request)->validateToken($token, 'prelumen', $identifier)) {
            throw new \TYPO3\CMS\Core\Http\ImmediateResponseException(new \TYPO3\CMS\Core\Http\HtmlResponse('Access denied', 403));
        }
        return $site;
    }

    private function describeSite(Site $site): array
    {
        $configuration = $site->getConfiguration();
        $settings = SiteSettings::fromSiteConfiguration($configuration);
        $state = [];
        $error = '';
        try {
            $state = $this->connections->state($site);
            if (!empty($state['website_uuid'])) {
                $state = $this->connections->run($site, ($state['fetched_at'] ?? 0) < time() - 86400 ? 'refresh' : 'status');
            }
        } catch (\Throwable) {
            $state = ['mode' => 'disabled'];
            $error = 'Saved credentials could not be read. Restore the database and TYPO3 encryption key; do not reset the connection blindly.';
        }
        try {
            $canonical = \Oneco\AnalyticsPro\Core\Client\InstallationUrl::canonical((string) $site->getBase());
        } catch (\RuntimeException) {
            $canonical = '';
        }
        $active = !empty($state['status']['active']) && empty($state['suspended'])
            && ($state['url'] ?? '') === $canonical
            && ($state['expires_at'] ?? 0) > time();
        $pending = !empty($state['id']) && empty($state['website_uuid']);
        $approval = $pending && ($state['account_mode'] ?? '') !== 'recovery'
            ? $state['api_base_url'] . '/plugin-connect/' . rawurlencode($state['id'])
                . '?mode=' . ($state['account_mode'] === 'register' ? 'register' : 'login') : '';
        try {
            $dashboard = (new SiteConfig($settings->siteId, $settings->apiBaseUrl))->dashboardUrl();
        } catch (\InvalidArgumentException) {
            $dashboard = null;
        }
        $report = $active ? ($state['report'] ?? []) : [];
        foreach ($report['checks'] ?? [] as $index => $check) {
            $report['checks'][$index]['displayScore'] = isset($check['score']) ? (string) $check['score'] : 'No score available';
        }
        // Explicit projection: never assign encrypted/decrypted credentials or challenges to a view.
        return [
            'title' => trim((string) ($configuration['websiteTitle'] ?? '')) ?: $site->getIdentifier(),
            'identifier' => $site->getIdentifier(),
            'token' => $this->forms->createFromRequest($this->request)->generateToken('prelumen', $site->getIdentifier()),
            'manual' => ($state['mode'] ?? 'manual') === 'manual',
            'configured' => $settings->isConfigured(), 'siteId' => $state['website_uuid'] ?? $settings->siteId,
            'dashboardUrl' => $dashboard, 'active' => $active, 'saved' => !empty($state['id']),
            'pending' => $pending, 'expired' => $pending && ($state['pending_until'] ?? 0) <= time(),
            'approvalUrl' => $approval, 'recovery' => !empty($state['recovery']),
            'plan' => $active ? ($state['status']['plan']['plan_key'] ?? '') : '',
            'trialEnds' => $active ? ($state['status']['plan']['trial_ends_at'] ?? '') : '',
            'fetchedAt' => !empty($state['fetched_at']) ? gmdate('Y-m-d H:i:s', $state['fetched_at']) . ' UTC' : '',
            'organisation' => $active ? ($state['status']['organisation_uuid'] ?? '') : '',
            'report' => $report,
            'free' => ($state['status']['plan']['plan_key'] ?? '') === 'free',
            'findings' => $active && in_array('issuesList', $state['status']['plan']['features'] ?? [], true),
            'stale' => ($state['fetched_at'] ?? 0) < time() - 86400
                || strtotime($state['report']['generated_at'] ?? '1970-01-01') < time() - 86400,
            'error' => $error ?: (!empty($state['error']) ? $this->errorMessage($state['error']) : ''),
        ];
    }

    private function errorMessage(string $code): string
    {
        return match ($code) {
            'ACCOUNT_AUTHORIZATION_REQUIRED' => 'Sign in at Prelumen, select your organisation and project, approve access, then complete the connection.',
            'PLATFORM_NOT_SUPPORTED' => 'The configured Prelumen backend needs the TYPO3 installation-contract update before connection is possible.',
            'SETUP_EXPIRED' => 'The connection request expired. Start account login or registration again.',
            'SITE_CHANGED' => 'The site URL changed. Disconnect before explicitly authorising the new URL.',
            'RECOVERY_UNAVAILABLE', 'CREDENTIAL_STORAGE_FAILED' => 'Recovery credentials are unavailable. Restore the database and original TYPO3 encryption key or contact support.',
            'UNAUTHORIZED' => 'Access expired or was revoked. Disconnect the saved installation and restore access.',
            'WEBSITE_BLOCKED' => 'This website is not eligible for connection. Contact Prelumen support.',
            'RATE_LIMITED' => 'Too many requests. Try again later.',
            'PUBLIC_HTTPS_REQUIRED', 'VERIFICATION_FAILED' => 'Website verification requires a publicly accessible canonical HTTPS site base and challenge route.',
            'ALREADY_CONNECTED', 'DISCONNECT_FIRST' => 'Disconnect the saved installation before changing connection mode.',
            default => 'Prelumen access could not be confirmed. Reports are hidden while the service is unavailable. Retry later.',
        };
    }
}
