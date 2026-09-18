<?php

declare(strict_types=1);

// SPDX-License-Identifier: GPL-2.0-or-later

namespace Oneco\AnalyticsPro\Typo3\Metadata;

use Oneco\AnalyticsPro\Core\Dto\PlatformMetadata;
use Oneco\AnalyticsPro\Core\Metadata\PlatformMetadataCollectorInterface;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Package\PackageManager;

final class Typo3PlatformMetadataCollector implements PlatformMetadataCollectorInterface
{
    public function __construct(
        private readonly PackageManager $packageManager,
        private readonly Typo3Version $typo3Version
    ) {
    }

    public function collect(): PlatformMetadata
    {
        return new PlatformMetadata(
            'typo3',
            $this->typo3Version->getVersion(),
            PHP_VERSION,
            null,
            [],
            [],
            $this->extensions(),
            [
                'composer_mode' => Environment::isComposerMode(),
                'context' => (string) Environment::getContext(),
            ]
        );
    }

    /**
     * @return list<array{name: string, version?: string|null}>
     */
    private function extensions(): array
    {
        $extensions = [];
        foreach ($this->packageManager->getActivePackages() as $package) {
            $extensions[] = [
                'name' => $package->getPackageKey(),
                'version' => $package->getPackageMetaData()->getVersion(),
            ];
        }

        return $extensions;
    }
}

