<?php

declare(strict_types=1);

namespace LexNova\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'user:list',
    description: 'List all users'
)]
final class UserListCommand extends Command
{
    public function __construct(private readonly \LexNova\Service\UserService $users)
    {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $users = $this->users->list();

        if (empty($users)) {
            $io->info('No users found.');
            return Command::SUCCESS;
        }

        $io->table(
            ['ID', 'Username', 'Role', 'Created at'],
            array_map(static fn(array $u) => [
                $u['id'],
                $u['username'],
                $u['role'],
                $u['created_at'],
            ], $users)
        );

        return Command::SUCCESS;
    }
}
