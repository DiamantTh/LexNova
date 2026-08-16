<?php

declare(strict_types=1);

namespace LexNova\Console;

use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\OutputInterface;

final class PrerequisiteReporter
{
    /**
     * @param array{checks: list<array{label:string, ok:bool, value:?string, required:bool, fallback:bool}>, blocked: bool} $result
     */
    public function render(OutputInterface $output, array $result): void
    {
        $rows = [];
        foreach ($result['checks'] as $check) {
            $status = match (true) {
                $check['fallback'] => '<comment>POLYFILL</comment>',
                $check['ok'] => '<info>OK</info>',
                $check['required'] => '<error>MISSING</error>',
                default => '<comment>RECOMMENDED</comment>',
            };
            $rows[] = [
                $check['label'],
                $status,
                $check['value'] ?? '—',
                $check['required'] ? 'yes' : 'no',
            ];
        }

        (new Table($output))
            ->setHeaders(['Requirement', 'Status', 'Detected value', 'Required'])
            ->setRows($rows)
            ->render();

        if ($result['blocked']) {
            $output->writeln('<error>At least one required prerequisite is not satisfied.</error>');
        } else {
            $output->writeln('<info>All required prerequisites are satisfied.</info>');
        }
    }
}
