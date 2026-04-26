<?php

declare(strict_types=1);

namespace LexNova\Console;

use LexNova\Service\EntityService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'entity:list',
    description: 'List all legal entities with their public hash'
)]
final class EntityListCommand extends Command
{
    public function __construct(private readonly EntityService $entities)
    {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io       = new SymfonyStyle($input, $output);
        $entities = $this->entities->list();

        if (empty($entities)) {
            $io->info('No legal entities found.');
            return Command::SUCCESS;
        }

        $io->table(
            ['ID', 'Hash', 'Name'],
            array_map(static fn(array $e) => [
                $e['id'],
                $e['hash'],
                $e['name'],
            ], $entities)
        );

        return Command::SUCCESS;
    }
}
