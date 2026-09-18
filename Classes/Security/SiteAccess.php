<?php

declare(strict_types=1);

// SPDX-License-Identifier: GPL-2.0-or-later

namespace Oneco\AnalyticsPro\Typo3\Security;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\Entity\Site;

final class SiteAccess
{
    public function __construct(private readonly ConnectionPool $pool)
    {
    }

    public function allowed(Site $site): bool
    {
        $user = $GLOBALS['BE_USER'] ?? null;
        if (!$user || empty($user->user['uid'])) {
            return false;
        }
        if ($user->isAdmin()) {
            return true;
        }
        $page = $this->pool->getConnectionForTable('pages')->select(['*'], 'pages',
            ['uid' => $site->getRootPageId(), 'deleted' => 0])->fetchAssociative();
        // Both webmount membership and edit permission on this site's root are required.
        return $page !== false && (bool) $user->isInWebMount($page)
            && $user->doesUserHaveAccess($page, 2);
    }
}
