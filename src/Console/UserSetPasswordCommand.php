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
    name: 'user:set-password',
    description: 'Reset the password of a user (server-side admin access)',
)]
final class UserSetPasswordCommand extends Command
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
            ->addArgument('username', InputArgument::REQUIRED, 'Username of the user to update')
            ->addOption('generate', 'g', InputOption::VALUE_NONE, 'Auto-generate a new password instead of prompting')
            ->addOption('mode', 'm', InputOption::VALUE_OPTIONAL, 'Generator mode: diceware (default) or random', 'diceware');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $username = trim((string) $input->getArgument('username'));
        $user = $this->users->findByUsername($username);

        if ($user === null) {
            $io->error("User '{$username}' not found.");

            return Command::FAILURE;
        }

        $io->note(sprintf(
            "Resetting password for '%s' (ID: %d, role: %s).",
            $user['username'],
            (int) $user['id'],
            $user['role'],
        ));

        if ($input->getOption('generate')) {
            $password = $this->generatePassword($input, $io);
            if ($password === null) {
                return Command::FAILURE;
            }
        } else {
            /** @var QuestionHelper $helper */
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
