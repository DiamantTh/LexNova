<?php

declare(strict_types=1);

namespace LexNova\Console;

use LexNova\Service\Password\DicewareGenerator;
use LexNova\Service\Password\RandomPasswordGenerator;
use LexNova\Service\PasswordService;
use LexNova\Service\UserService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'user:create',
    description: 'Create a new admin user',
)]
final class UserCreateCommand extends Command
{
    public function __construct(
        private readonly UserService $users,
        private readonly PasswordService $passwords,
        private readonly DicewareGenerator $diceware,
        private readonly RandomPasswordGenerator $random,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('username', InputArgument::REQUIRED, 'Username for the new user')
            ->addOption('generate', 'g', InputOption::VALUE_NONE, 'Auto-generate a password instead of prompting')
            ->addOption('mode', 'm', InputOption::VALUE_OPTIONAL, 'Generator mode: diceware (default) or random', 'diceware');
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

        if ($input->getOption('generate')) {
            $password = $this->generatePassword($input, $io);
            if ($password === null) {
                return Command::FAILURE;
            }
        } else {
            /** @var QuestionHelper $helper */
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

    private function generatePassword(InputInterface $input, SymfonyStyle $io): ?string
    {
        $mode = strtolower((string) $input->getOption('mode'));

        if ($mode === 'random') {
            $gen = $this->random;
            $label = 'random';
        } else {
            $gen = $this->diceware;
            $label = 'diceware';
        }

        $password = $gen->generate();
        $bits = round($gen->entropyBits(), 1);

        $io->section("Generated password ({$label}, ~{$bits} bits entropy)");
        $io->writeln("  <info>{$password}</info>");
        $io->newLine();

        if ($input->isInteractive()) {
            /** @var QuestionHelper $helper */
            $helper = $this->getHelper('question');
            $q = new ConfirmationQuestion('Use this password? [y/N] ', false);

            if (!$helper->ask($input, $io, $q)) {
                $io->note('Aborted. Re-run without --generate to enter a password manually.');

                return null;
            }
        }

        return $password;
    }
}
