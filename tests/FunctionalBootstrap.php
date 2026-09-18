<?php
// SPDX-License-Identifier: GPL-2.0-or-later
require dirname(__DIR__) . '/vendor/autoload.php';
putenv('TYPO3_PATH_ROOT=' . dirname(__DIR__) . '/public');
$testbase = new \TYPO3\TestingFramework\Core\Testbase();
$testbase->defineOriginalRootPath();
$testbase->createDirectory(ORIGINAL_ROOT . 'typo3temp/var/tests');
$testbase->createDirectory(ORIGINAL_ROOT . 'typo3temp/var/transient');
