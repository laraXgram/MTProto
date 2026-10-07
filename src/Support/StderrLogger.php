<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Support;

use LaraGram\Log\LoggerInterface;

/**
 * Minimal logger for a standalone {@see \LaraGram\MTProto\Core\Client} (no
 * framework container). Enabled with the `MTPROTO_LOG` environment variable:
 * `MTPROTO_LOG=debug|info|notice|warning|error` writes that level and above
 * to STDERR, so diagnostics never mix with a script's STDOUT.
 */
final class StderrLogger implements LoggerInterface
{
    private const LEVELS = [
        'debug' => 0,
        'info' => 1,
        'notice' => 2,
        'warning' => 3,
        'error' => 4,
        'critical' => 5,
        'alert' => 6,
        'emergency' => 7,
    ];

    private int $threshold;

    /**
     * @param resource|null $stream
     */
    public function __construct(string $level = 'info', private mixed $stream = null)
    {
        $this->threshold = self::LEVELS[strtolower($level)] ?? self::LEVELS['info'];
        $this->stream ??= defined('STDERR') ? STDERR : fopen('php://stderr', 'wb');
    }

    /**
     * A logger for the `MTPROTO_LOG` level, or null when the variable is unset.
     */
    public static function fromEnvironment(): ?self
    {
        $level = getenv('MTPROTO_LOG');

        return is_string($level) && $level !== '' && $level !== '0' ? new self($level === '1' ? 'info' : $level) : null;
    }

    public function emergency(string|\Stringable $message, array $context = []): void
    {
        $this->log('emergency', $message, $context);
    }

    public function alert(string|\Stringable $message, array $context = []): void
    {
        $this->log('alert', $message, $context);
    }

    public function critical(string|\Stringable $message, array $context = []): void
    {
        $this->log('critical', $message, $context);
    }

    public function error(string|\Stringable $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    public function warning(string|\Stringable $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    public function notice(string|\Stringable $message, array $context = []): void
    {
        $this->log('notice', $message, $context);
    }

    public function info(string|\Stringable $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    public function debug(string|\Stringable $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $level = strtolower((string) $level);
        if ((self::LEVELS[$level] ?? 0) < $this->threshold) {
            return;
        }

        unset($context['exception']);
        $suffix = $context === [] ? '' : ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        fwrite($this->stream, sprintf("[%s] mtproto.%s: %s%s\n", date('H:i:s'), $level, $message, $suffix));
    }
}
