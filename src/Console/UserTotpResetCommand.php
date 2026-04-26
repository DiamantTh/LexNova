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
        $io       = new SymfonyStyle($input, $output);
        $username = trim((string) $input->getArgument('username'));

        $user = $this->users->findByUsername($username);

        if ($user === null) {
            $io->error("User '{$username}' not found.");
            return Command::FAILURE;
        }

        $userId   = (int) $user['id'];
        $keyCount = $this->users->countActiveKeys($userId);

        if ($keyCount === 0) {
            $io->info("No active TOTP keys found for '{$username}'. Nothing to reset.");
            return Command::SUCCESS;
        }

        $io->table(
            ['ID', 'Username', 'Role', 'Active TOTP keys'],
            [[$userId, $username, $user['role'] ?? '?', $keyCount]]
        );

        if (!$input->getOption('yes')) {
            $q = new ConfirmationQuestion(
                "Delete all {$keyCount} TOTP key(s) for '{$username}'? [y/N] ",
                false
            );
            if (!$this->getHelper('question')->ask($input, $output, $q)) {
                $io->note('Aborted.');
                return Command::SUCCESS;
            }
        }

        $removed = $this->users->deleteAllTotpKeys($userId);
        $io->success("Removed {$removed} TOTP key(s) for '{$username}'. The user can re-enroll via /admin/totp/enroll.");

        return Command::SUCCESS;
    }
}
