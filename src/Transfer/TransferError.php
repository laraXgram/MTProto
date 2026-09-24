<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Transfer;

/**
 * Sorts a failed file-part call into what the transfer engine should do next.
 */
enum TransferError
{
    /** The server asked us to slow down (FLOOD_WAIT_X, FLOOD_PREMIUM_WAIT_X). */
    case Flood;

    /** A glitch worth retrying at once, preferably on another socket. */
    case Transient;

    /** Retrying cannot help; the transfer fails. */
    case Fatal;

    private const TRANSIENT = [
        'Timeout',
        'RPC_CALL_FAIL',
        'RPC_MCGET_FAIL',
        'WORKER_BUSY',
        'INTERNAL_SERVER_ERROR',
        'TRANSPORT_FLOOD',
        'Pump stopped',
        'Not connected',
        'Failed to send',
        'Bad message notification',
        'rejected after',
        'Connection',
        'closed',
        'AUTH_RESTART',
    ];

    /**
     * @return array{0: self, 1: int} the category and, for floods, the seconds to wait
     */
    public static function classify(\Throwable $e): array
    {
        $message = $e->getMessage();

        if (preg_match('/FLOOD_(?:PREMIUM_)?WAIT_(\d+)/', $message, $m)) {
            return [self::Flood, max(1, (int) $m[1])];
        }

        $code = $e->getCode();
        if ($code === -500 || $code === -503 || $code === 500) {
            return [self::Transient, 0];
        }

        foreach (self::TRANSIENT as $needle) {
            if (str_contains($message, $needle)) {
                return [self::Transient, 0];
            }
        }

        return [self::Fatal, 0];
    }
}
