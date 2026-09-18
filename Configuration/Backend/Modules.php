<?php

declare(strict_types=1);

// SPDX-License-Identifier: GPL-2.0-or-later

return [
    'site_prelumen_analytics' => [
        'parent' => 'site',
        'position' => ['after' => 'site_configuration'],
        'access' => 'user',
        'path' => '/module/site/prelumen-analytics',
        'iconIdentifier' => 'prelumen-analytics-module',
        'labels' => 'LLL:EXT:prelumen_analytics/Resources/Private/Language/locallang_mod.xlf',
        'extensionName' => 'OnecoAnalyticsPro',
        'controllerActions' => [
            \Oneco\AnalyticsPro\Typo3\Controller\AnalyticsController::class => [
                'index',
                'register',
                'connection',
            ],
        ],
    ],
];
