<?php

declare(strict_types=1);

// SPDX-License-Identifier: GPL-2.0-or-later

namespace Oneco\AnalyticsPro\Typo3\Service;

use Oneco\AnalyticsPro\Typo3\Security\SecretBox;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Locking\LockFactory;

final class ConnectionStore
{
    private const TABLE = 'tx_onecoanalyticspro_state';

    public function __construct(
        private readonly ConnectionPool $pool,
        private readonly SecretBox $crypto,
        private readonly LockFactory $locks,
    ) {
    }

    public function read(string $site): array
    {
        $payload = $this->pool->getConnectionForTable(self::TABLE)->select(['payload'], self::TABLE,
            ['site_hash' => hash('sha256', $site)])->fetchOne();
        if ($payload === false) {
            return [];
        }
        return json_decode($this->crypto->open((string) $payload, $site), true, 512, JSON_THROW_ON_ERROR);
    }

    /** Must be called inside locked(); the DB never contains plaintext credentials or reports. */
    public function write(string $site, array $state): void
    {
        $connection = $this->pool->getConnectionForTable(self::TABLE);
        $key = ['site_hash' => hash('sha256', $site)];
        $data = ['payload' => $this->crypto->seal(json_encode($state, JSON_THROW_ON_ERROR), $site)];
        if ($connection->count('*', self::TABLE, $key) > 0) {
            $connection->update(self::TABLE, $data, $key);
        } else {
            $connection->insert(self::TABLE, $data + $key);
        }
    }

    public function locked(string $site, callable $operation): mixed
    {
        $lock = $this->locks->createLocker('prelumen-' . hash('sha256', $site));
        if (!$lock->acquire()) {
            throw new \RuntimeException('RETRY_CONNECTION');
        }
        try {
            return $operation();
        } finally {
            $lock->release();
        }
    }
}
