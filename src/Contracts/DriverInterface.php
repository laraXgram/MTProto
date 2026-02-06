<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Contracts;

/**
 * Base driver interface for all MTProto drivers.
 * 
 * This interface defines the contract that all transport drivers must implement.
 * Drivers handle the low-level communication with Telegram servers.
 */
interface DriverInterface
{
    /**
     * Get the driver name.
     */
    public function getName(): string;

    /**
     * Check if the driver supports async operations.
     */
    public function isAsync(): bool;
}
