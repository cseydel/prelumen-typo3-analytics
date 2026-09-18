<?php

declare(strict_types=1);

// SPDX-License-Identifier: GPL-2.0-or-later

$EM_CONF[$_EXTKEY] = [
    'title' => 'Prelumen Analytics',
    'description' => 'TYPO3 connector for Prelumen Analytics.',
    'category' => 'plugin',
    'author' => 'Prelumen',
    'author_email' => '',
    'state' => 'stable',
    'version' => '1.0.0',
    'constraints' => [
        'depends' => [
            'typo3' => '12.4.0-13.4.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
