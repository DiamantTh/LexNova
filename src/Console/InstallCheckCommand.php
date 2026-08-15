<?php

declare(strict_types=1);

namespace LexNova\Console;

use LexNova\Handler\Install\Step\PrerequisiteCheck;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'install:check', description: 'Check PHP, extensions, database drivers, and runtime permissions')]
final class InstallCheckCommand extends Command
{
    public function __construct(
        private readonly PrerequisiteCheck $prerequisites,
        private readonly PrerequisiteReporter $reporter,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->prerequisites->run();
        $this->reporter->render($output, $result);

        return $result['blocked'] ? Command::FAILURE : Command::SUCCESS;
    }
}
