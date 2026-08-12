<?php

declare(strict_types=1);

namespace LexNova\Console;

use LexNova\Service\InstallService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'install:prepare', description: 'Generate the one-time password for the web installer')]
final class InstallPrepareCommand extends Command
{
    public function __construct(private readonly InstallService $install)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->install->isInstalled()) {
            $output->writeln('<error>LexNova is already installed.</error>');

            return Command::FAILURE;
        }
        if ($this->install->readPasswordHash() !== null) {
            $output->writeln('<error>An installer password already exists. Complete the installation or remove data/install.pw deliberately.</error>');

            return Command::FAILURE;
        }

        $password = $this->install->initializePassword([]);
        if ($password === null) {
            $output->writeln('<error>Could not create data/install.pw. Check directory permissions.</error>');

            return Command::FAILURE;
        }

        $output->writeln('<comment>One-time installer password (shown only now):</comment>');
        $output->writeln($password);

        return Command::SUCCESS;
    }
}
