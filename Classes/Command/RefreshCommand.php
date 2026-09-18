<?php

declare(strict_types=1);

// SPDX-License-Identifier: GPL-2.0-or-later

namespace Oneco\AnalyticsPro\Typo3\Command;

use Oneco\AnalyticsPro\Typo3\Service\InstallationService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Site\SiteFinder;

final class RefreshCommand extends Command
{
    public function __construct(private readonly SiteFinder $sites, private readonly InstallationService $connections)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $failed = false;
        foreach ($this->sites->getAllSites() as $site) {
            try {
                $state = $this->connections->state($site);
                if (!empty($state['website_uuid'])) {
                    $state = $this->connections->run($site, 'refresh');
                    if (!empty($state['error'])) {
                        $failed = true;
                    }
                }
            } catch (\Throwable) {
                $failed = true;
            }
        }
        $output->writeln($failed ? 'Some sites could not be refreshed; inspect their dashboard.' : 'Site refresh complete.');
        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
