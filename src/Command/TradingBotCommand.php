<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Orchestrator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:trading-bot',
    description: 'Start the grid trading bot'
)]
class TradingBotCommand extends Command
{
    private ?Orchestrator $orchestrator = null;

    public function __construct(
        private readonly Orchestrator $orchestratorService
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln([
            '====================================',
            '   Binance Grid Trading Bot',
            '====================================',
            '',
            'Starting orchestrator...',
            'Press Ctrl+C to stop gracefully',
            '',
        ]);

        $this->orchestrator = $this->orchestratorService;

        // Register signal handlers for graceful shutdown
        if (extension_loaded('pcntl')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, [$this, 'handleSignal']);
            pcntl_signal(SIGINT, [$this, 'handleSignal']);
        }

        try {
            $this->orchestrator->run();
        } catch (\Throwable $e) {
            $output->writeln([
                '',
                '<error>Fatal error:</error>',
                '<error>' . $e->getMessage() . '</error>',
                '',
            ]);
            return Command::FAILURE;
        }

        $output->writeln([
            '',
            'Bot stopped.',
            '',
        ]);

        return Command::SUCCESS;
    }

    public function handleSignal(int $signal): void
    {
        if ($this->orchestrator !== null) {
            $this->orchestrator->stop();
        }
    }
}
