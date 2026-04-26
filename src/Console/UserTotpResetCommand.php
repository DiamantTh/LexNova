<?php

declare(strict_types=1);

namespace LexNova\Console;

use LexNova\Service\UserService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'user:totp-reset',
    description: 'Disable and wipe TOTP for a user (recovery / admin reset)'
)]
final class UserTotpResetCommand extends Command
{
    public function __construct(private readonly UserService $users)
    {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('username', InputArgument::REQUIRED, 'Username to reset TOTP for')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Skip confirmation prompt');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $username = trim((string) $input->getArgument('username'));

        // findByUsername returns only limited columns — fetch full record via findById
        $byName = $this->users->findByUsername($username);

        if ($byName === null) {
            $io->error("User '{$username}' not found.");
            return Command::FAILURE;
        }

        $user = $this->users->findById((int) $byName['id']);

        if ($user === null) {
            $io->error("User '{$username}' not found.");
            return Command::FAILURE;
        }

        $totpEnabled = (bool) ($user['totp_enabled'] ?? false);

        if (!$totpEnabled) {
            $io->info("TOTP is not enabled for '{$username}'. Nothing to reset.");
            return Command::SUCCESS;
        }

        $io->table(
            ['ID', 'Username', 'Role', 'TOTP'],
            [[$user['id'], $user['username'], $user['role'], $totpEnabled ? 'enabled' : 'disabled']]
        );

        if (!$input->getOption('yes')) {
            $q = new ConfirmationQuestion(
                "Disable and wipe TOTP for '{$username}'? [y/N] ",
                false
            );
            if (!$this->getHelper('question')->ask($input, $output, $q)) {
                $io->note('Aborted.');
                return Command::SUCCESS;
            }
        }

        $this->users->setTotpSecret((int) $user['id'], null, false);
        $io->success("TOTP disabled and secret wiped for '{$username}'. The user can re-enroll via /admin/totp/enroll.");

        return Command::SUCCESS;
    }
}
