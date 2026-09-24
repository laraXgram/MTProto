<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Session;

use LaraGram\MTProto\Exceptions\MTProtoException;

/**
 * Exclusive, process-level lock on a session.
 *
 * Two processes driving the same session send conflicting msg_ids/seqnos and
 * Telegram may revoke the auth key (AUTH_KEY_DUPLICATED). The lock is an
 * `flock` on `<directory>/<session>.lock`, released when the process ends -
 * even on a crash - so a stale lock never outlives its owner.
 */
final class SessionLock
{
    /** @var resource|null */
    private $handle;

    /**
     * @param resource $handle
     */
    private function __construct($handle, private readonly string $path)
    {
        $this->handle = $handle;
    }

    /**
     * Take the lock, waiting up to $wait seconds for a previous owner (e.g. a
     * pump process being replaced on reload) to let go.
     */
    public static function acquire(string $directory, string $session, float $wait = 5.0): self
    {
        if (!is_dir($directory)) {
            @mkdir($directory, 0700, true);
        }

        $path = rtrim($directory, '/') . '/' . preg_replace('/[^a-zA-Z0-9_.-]/', '_', $session) . '.lock';
        $handle = @fopen($path, 'c+');
        if ($handle === false) {
            throw new MTProtoException("Cannot open the session lock file {$path}.");
        }
        @chmod($path, 0600);

        $deadline = microtime(true) + $wait;
        while (!flock($handle, LOCK_EX | LOCK_NB)) {
            if (microtime(true) >= $deadline) {
                $owner = trim((string) stream_get_contents($handle, 32, 0));
                fclose($handle);

                throw new MTProtoException(
                    "Session '{$session}' is already in use by another process" . ($owner !== '' ? " (pid {$owner})" : '')
                    . '. Running one session in two processes gets its auth key revoked (AUTH_KEY_DUPLICATED) - '
                    . 'stop the other process, or reach it through the pump RPC (mtproto.rpc).'
                );
            }
            usleep(100_000);
        }

        ftruncate($handle, 0);
        fwrite($handle, (string) getmypid());
        fflush($handle);

        return new self($handle, $path);
    }

    public function path(): string
    {
        return $this->path;
    }

    public function release(): void
    {
        if ($this->handle !== null) {
            @flock($this->handle, LOCK_UN);
            @fclose($this->handle);
            $this->handle = null;
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}
