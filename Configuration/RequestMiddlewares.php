<?php

declare(strict_types=1);

// SPDX-License-Identifier: GPL-2.0-or-later

return ['frontend' => ['prelumen/analytics' => [
    'target' => \Oneco\AnalyticsPro\Typo3\Middleware\AnalyticsMiddleware::class,
    'after' => ['typo3/cms-frontend/site', 'typo3/cms-frontend/authentication'],
    'before' => ['typo3/cms-frontend/page-resolver'],
]]];
