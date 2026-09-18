<?php

declare(strict_types=1);

// SPDX-License-Identifier: GPL-2.0-or-later

namespace Oneco\AnalyticsPro\Typo3\Tests\Functional;

use Oneco\AnalyticsPro\Typo3\Middleware\AnalyticsMiddleware;
use Oneco\AnalyticsPro\Typo3\Service\ConnectionStore;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class ConnectionTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['backend', 'extbase', 'fluid', 'frontend'];
    protected array $testExtensionsToLoad = [__DIR__ . '/../..'];
    protected array $configurationToUseInTestInstance = ['SYS' => ['encryptionKey' => 'functional-only-test-key-32-characters-long',
        'caching' => ['cacheConfigurations' => ['pages' => ['backend' => \TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend::class]]]]];

    public function testRealFrontendCacheAndSiteRouting(): void
    {
        $pool = GeneralUtility::makeInstance(ConnectionPool::class);
        $pool->getConnectionForTable('pages')->insert('pages', ['uid' => 1, 'pid' => 0, 'title' => 'Home', 'slug' => '/', 'is_siteroot' => 1]);
        $this->setUpFrontendRootPage(1, [], ['config' => 'page = PAGE
page.10 = TEXT
page.10.value = Cached site
config.no_cache = 0']);
        $writer = $this->get(class_exists(\TYPO3\CMS\Core\Configuration\SiteWriter::class)
            ? \TYPO3\CMS\Core\Configuration\SiteWriter::class : \TYPO3\CMS\Core\Configuration\SiteConfiguration::class);
        $writer->write('alpha', ['rootPageId' => 1, 'base' => 'https://alpha.example/sub/',
            'languages' => [['title' => 'English', 'enabled' => true, 'languageId' => 0, 'base' => '/', 'locale' => 'en_US.UTF-8', 'navigationTitle' => 'English', 'flag' => 'us']],
            'prelumenAnalytics' => ['siteId' => '00000000-0000-4000-8000-000000000001', 'apiBaseUrl' => 'https://analytics.example']]);
        $request = new \TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest('https://alpha.example/sub/');
        $first = $this->executeFrontendSubRequest($request);
        self::assertSame(200, $first->getStatusCode());
        self::assertStringContainsString('prelumenConsent', (string) $first->getBody());
        self::assertGreaterThan(0, $pool->getConnectionForTable('cache_pages')->count('*', 'cache_pages', []));
        $store = $this->get(ConnectionStore::class);
        $store->locked('alpha', fn () => $store->write('alpha', ['mode' => 'disabled']));
        $cached = $this->executeFrontendSubRequest($request);
        self::assertSame(200, $cached->getStatusCode());
        self::assertStringNotContainsString('prelumenConsent', (string) $cached->getBody());
        self::assertStringContainsString('Cached site', (string) $cached->getBody());
    }

    public function testSitePermissionsAndPerSiteCsrf(): void
    {
        $pool = GeneralUtility::makeInstance(ConnectionPool::class);
        $pool->getConnectionForTable('pages')->insert('pages', ['uid' => 1, 'pid' => 0, 'title' => 'Allowed', 'perms_userid' => 1, 'perms_user' => 3]);
        $pool->getConnectionForTable('pages')->insert('pages', ['uid' => 2, 'pid' => 0, 'title' => 'Outside mount', 'perms_userid' => 1, 'perms_user' => 3]);
        $pool->getConnectionForTable('be_groups')->insert('be_groups', ['uid' => 1, 'title' => 'Editors']);
        $pool->getConnectionForTable('be_users')->insert('be_users', ['uid' => 1, 'username' => 'editor', 'usergroup' => '1', 'db_mountpoints' => '1']);
        $user = $this->setUpBackendUser(1);
        self::assertSame(1, (int) ($user->user['uid'] ?? 0), 'Backend test session must be authenticated');
        self::assertContains(1, array_map('intval', $user->returnWebmounts()), json_encode([$user->user['db_mountpoints'], $user->groupData['webmounts'] ?? null]));
        $row = $pool->getConnectionForTable('pages')->select(['*'], 'pages', ['uid' => 1])->fetchAssociative();
        self::assertTrue((bool) $user->isInWebMount($row), 'Webmount membership');
        self::assertTrue($user->doesUserHaveAccess($row, 2), 'Page edit permission');
        $access = $this->get(\Oneco\AnalyticsPro\Typo3\Security\SiteAccess::class);
        self::assertTrue($access->allowed(new Site('allowed', 1, ['base' => 'https://allowed.example', 'languages' => []])));
        self::assertFalse($access->allowed(new Site('outside', 2, ['base' => 'https://outside.example', 'languages' => []])));
        $forms = $this->get(\TYPO3\CMS\Core\FormProtection\FormProtectionFactory::class)->createForType('backend');
        $token = $forms->generateToken('prelumen', 'allowed');
        self::assertTrue($forms->validateToken($token, 'prelumen', 'allowed'));
        self::assertFalse($forms->validateToken($token, 'prelumen', 'outside'));
        self::assertFalse($forms->validateToken('', 'prelumen', 'allowed'));
        $backendRequest = (new ServerRequest('https://allowed.example/typo3/'))->withAttribute('applicationType', 2);
        $this->get(\TYPO3\CMS\Extbase\Configuration\ConfigurationManager::class)->setRequest($backendRequest);
        // Compiles/instantiates the real Extbase module, not only the frontend services.
        self::assertInstanceOf(\Oneco\AnalyticsPro\Typo3\Controller\AnalyticsController::class,
            $this->get(\Oneco\AnalyticsPro\Typo3\Controller\AnalyticsController::class));
    }

    public function testCredentialsAreEncryptedAndSiteBound(): void
    {
        $store = $this->get(ConnectionStore::class);
        $store->locked('alpha', fn () => $store->write('alpha', ['secret' => 'never-plaintext']));
        self::assertSame(['secret' => 'never-plaintext'], $store->read('alpha'));
        self::assertSame([], $store->read('beta'));
        $db = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_onecoanalyticspro_state');
        $cipher = $db->select(['payload'], 'tx_onecoanalyticspro_state', [])->fetchOne();
        self::assertStringNotContainsString('never-plaintext', $cipher);
        $db->insert('tx_onecoanalyticspro_state', ['site_hash' => hash('sha256', 'beta'), 'payload' => $cipher]);
        $this->expectExceptionMessage('RECOVERY_UNAVAILABLE');
        $store->read('beta');
    }

    public function testCachedHtmlIsReevaluatedAfterDisconnectAndForGroups(): void
    {
        $site = new Site('alpha', 1, ['base' => 'https://alpha.example/sub/', 'languages' => [],
            'prelumenAnalytics' => ['siteId' => '00000000-0000-4000-8000-000000000001',
                'apiBaseUrl' => 'https://analytics.example', 'footerBadgeEnabled' => true,
                'excludedFrontendGroups' => [7]]]);
        $request = (new ServerRequest('https://alpha.example/sub/'))->withAttribute('site', $site);
        // Reuse identical cached content; mutation happens only on the returned response.
        $cached = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new HtmlResponse('<html><head></head><body>Cached page</body></html>');
            }
        };
        $middleware = $this->get(AnalyticsMiddleware::class);
        $response = $middleware->process($request, $cached);
        self::assertStringContainsString('prelumenConsent', (string) $response->getBody());
        self::assertStringContainsString('badge.svg', (string) $response->getBody());
        self::assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
        $groupRequest = $request->withAttribute('frontend.user', (object) ['groupData' => ['uid' => [7]]]);
        self::assertStringNotContainsString('prelumenConsent', (string) $middleware->process($groupRequest, $cached)->getBody());
        $store = $this->get(ConnectionStore::class);
        $store->locked('alpha', fn () => $store->write('alpha', ['mode' => 'disabled']));
        self::assertStringNotContainsString('prelumenConsent', (string) $middleware->process($request, $cached)->getBody());
        self::assertStringNotContainsString('badge.svg', (string) $middleware->process($request, $cached)->getBody());
    }

    public function testChallengeExpiresAndNeverExposesSecrets(): void
    {
        $site = new Site('alpha', 1, ['base' => 'https://alpha.example/sub/', 'languages' => []]);
        $store = $this->get(ConnectionStore::class);
        $store->locked('alpha', fn () => $store->write('alpha', ['url' => 'https://alpha.example/sub',
            'pending_until' => time() + 60, 'challenge' => 'proof', 'secret' => 'private']));
        $request = (new ServerRequest('https://alpha.example/sub/?prelumen=challenge'))
            ->withQueryParams(['prelumen' => 'challenge'])->withAttribute('site', $site);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface { throw new \LogicException('Must short circuit'); }
        };
        $middleware = $this->get(AnalyticsMiddleware::class);
        $response = $middleware->process($request, $handler);
        self::assertSame(['challenge' => 'proof'], json_decode((string) $response->getBody(), true));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $store->locked('alpha', fn () => $store->write('alpha', ['pending_until' => time() - 1, 'challenge' => 'expired']));
        self::assertSame(['challenge' => ''], json_decode((string) $middleware->process($request, $handler)->getBody(), true));
    }
}
