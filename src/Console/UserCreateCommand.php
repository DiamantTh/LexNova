<?php

declare(strict_types=1);

namespace LexNova\Console;

use LexNova\Service\PasswordService;
use LexNova\Service\UserService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'user:create',
    description: 'Create a new admin user'
)]
final class UserCreateCommand extends Command
{
    public function __construct(
        private readonly UserService $users,
        private readonly PasswordService $passwords,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addArgument('username', InputArgument::REQUIRED, 'Username for the new user');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $username = trim((string) $input->getArgument('username'));

        if ($username === '') {
            $io->error('Username must not be empty.');
            return Command::FAILURE;
        }

        if ($this->users->findByUsername($username) !== null) {
            $io->error("User '{$username}' already exists.");
            return Command::FAILURE;
        }

        $helper = $this->getHelper('question');

        $q = new Question('Password: ');
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

        $id = $this->users->create($username, $password, 'admin');
        $io->success("User '{$username}' created (ID: {$id}).");

        return Command::SUCCESS;
    }
}
