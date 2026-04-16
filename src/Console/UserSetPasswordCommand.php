<?php

declare(strict_types=1);

namespace LexNova\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'user:set-password',
    description: 'Reset the password of a user (server-side admin access)'
)]
final class UserSetPasswordCommand extends Command
{
    public function __construct(
        private readonly \LexNova\Service\UserService $users,
        private readonly \LexNova\Service\PasswordService $passwords,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addArgument('username', InputArgument::REQUIRED, 'Username of the user to update');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $username = trim((string) $input->getArgument('username'));
        $user     = $this->users->findByUsername($username);

        if ($user === null) {
            $io->error("User '{$username}' not found.");
            return Command::FAILURE;
        }

        $io->note(sprintf(
            "Resetting password for '%s' (ID: %d, role: %s).",
            $user['username'],
            (int) $user['id'],
            $user['role']
        ));

        $helper = $this->getHelper('question');

        $q = new Question('New password: ');
        $q->setHidden(true)->setHiddenFallback(false);
        $password = (string) $helper->ask($input, $output, $q);

        $q2 = new Question('Confirm password: ');
        $q2->setHidden(true)->setHiddenFallback(false);
        $confirm = (string) $helper->ask($input, $output, $q2);

        if ($password !== $confirm) {
            $io->error('Passwords do not match.');
            return Command::FAILURE;
        }

        $error = $this->passwords->validate($password);
        if ($error !== null) {
            $io->error($error);
            return Command::FAILURE;
        }

        $this->users->updatePassword((int) $user['id'], $password);
        $io->success("Password for '{$username}' has been updated.");

        return Command::SUCCESS;
    }
}
