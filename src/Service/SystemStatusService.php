<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\BotConfigRepository;
use Psr\Log\LoggerInterface;

/**
 * Manages system status (running/stopped)
 */
class SystemStatusService
{
    private const KEY = 'system_status';
    private const STATUS_RUNNING = 'running';
    private const STATUS_STOPPED = 'stopped';

    public function __construct(
        private readonly BotConfigRepository $configRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Check if the system is running
     */
    public function isRunning(): bool
    {
        $status = $this->getStatus();
        return $status === self::STATUS_RUNNING;
    }

    /**
     * Check if the system is stopped
     */
    public function isStopped(): bool
    {
        return !$this->isRunning();
    }

    /**
     * Get current system status
     */
    public function getStatus(): string
    {
        $config = $this->configRepository->findByKey(self::KEY);

        if ($config === null) {
            // Default to running if not set
            $this->logger->warning('System status not found in config, defaulting to running');
            return self::STATUS_RUNNING;
        }

        $value = $config->getValue();

        // Check if status is set in the array
        if (isset($value['status'])) {
            return (string)$value['status'];
        }

        // Default to running
        return self::STATUS_RUNNING;
    }

    /**
     * Start the system (allow order placement)
     */
    public function start(): void
    {
        $this->setStatus(self::STATUS_RUNNING);
        $this->logger->info('System started');
    }

    /**
     * Stop the system (prevent order placement)
     */
    public function stop(): void
    {
        $this->setStatus(self::STATUS_STOPPED);
        $this->logger->warning('System stopped - no orders will be placed');
    }

    /**
     * Set system status
     */
    private function setStatus(string $status): void
    {
        $this->configRepository->setConfig(self::KEY, ['status' => $status]);
    }
}
