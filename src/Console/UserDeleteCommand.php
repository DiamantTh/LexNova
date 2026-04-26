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
    name: 'user:delete',
    description: 'Permanently delete a user account'
)]
final class UserDeleteCommand extends Command
{
    public function __construct(private readonly UserService $users)
    {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('username', InputArgument::REQUIRED, 'Username of the account to delete')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Skip confirmation prompt');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io       = new SymfonyStyle($input, $output);
        $username = trim((string) $input->getArgument('username'));
        $user     = $this->users->findByUsername($username);

        if ($user === null) {
            $io->error("User '{$username}' not found.");
            return Command::FAILURE;
        }

        $io->table(
            ['ID', 'Username', 'Role'],
            [[$user['id'], $user['username'], $user['role']]]
        );

        if (!$input->getOption('yes')) {
            $q = new ConfirmationQuestion(
                "<fg=red>Permanently delete '{$username}'? This cannot be undone.</> [y/N] ",
                false
            );
            if (!$this->getHelper('question')->ask($input, $output, $q)) {
                $io->note('Aborted.');
                return Command::SUCCESS;
            }
        }

        $this->users->delete((int) $user['id']);
        $io->success("User '{$username}' (ID {$user['id']}) permanently deleted.");

        return Command::SUCCESS;
    }
}
