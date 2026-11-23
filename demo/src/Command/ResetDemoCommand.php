<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\DemoReset;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:demo:reset', description: 'Rebuild the demo database and load the starting product')]
final class ResetDemoCommand
{
    public function __construct(private readonly DemoReset $reset)
    {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        ($this->reset)();
        $io->success('Demo database reset.');

        return Command::SUCCESS;
    }
}
