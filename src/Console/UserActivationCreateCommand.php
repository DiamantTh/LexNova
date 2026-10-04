<?php

declare(strict_types=1);

namespace LexNova\Console;

use LexNova\Service\ActivationService;
use LexNova\Service\UserService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'user:activation-create', description: 'Issue a one-time passkey enrollment or recovery ticket')]
final class UserActivationCreateCommand extends Command
{
    public function __construct(private readonly UserService $users, private readonly ActivationService $activation)
    {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('username', InputArgument::REQUIRED, 'Account that will enroll a FIDO2 credential')
            ->addOption('recovery', null, InputOption::VALUE_NONE, 'Use audited recovery for an existing account');
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

        $recovery = (bool) $input->getOption('recovery');
        if ($recovery && $input->isInteractive()) {
            $io->warning('This will invalidate the account’s current sessions and issue a passkey recovery ticket. Existing credentials are retained.');
            /** @var QuestionHelper $helper */
            $helper = $this->getHelper('question');
            if (!$helper->ask($input, $output, new ConfirmationQuestion('Continue with recovery? [y/N] ', false))) {
                $io->note('Aborted.');

                return Command::SUCCESS;
            }
        }

        try {
            $ticket = $this->activation->issue((int) $user['id'], null, $recovery);
        } catch (\Throwable $error) {
            $io->error($error->getMessage());

            return Command::FAILURE;
        }

        $io->success('One-time ticket created. It expires in 24 hours and is shown only once.');
        $io->writeln('Open /activate and enter this ticket:');
        $io->writeln($ticket);

        return Command::SUCCESS;
    }
}
