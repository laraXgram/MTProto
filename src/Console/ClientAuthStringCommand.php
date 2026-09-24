<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Console;

use LaraGram\Console\Command;
use LaraGram\MTProto\Foundation\ClientManager;
use LaraGram\MTProto\Session\AuthString;

class ClientAuthStringCommand extends Command
{
    protected $signature = 'client:auth-string
        {--session=default : Session to export (or import into)}
        {--format=laragram : Output format: laragram, telethon or pyrogram}
        {--encrypt : Encrypt the string with the session key (CLIENT_SESSION_KEY, else APP_KEY; laragram format)}
        {--offline : Read the stored session without connecting (no user id in the string)}
        {--inspect= : Decode a string and show what it holds (never prints the key)}
        {--import= : Create the session from a LaraGram, Telethon/GramJS or Pyrogram string}
        {--force : Overwrite an existing session when importing}';

    protected $description = 'Export a session as a portable auth string, or inspect/import one';

    public function handle(): int
    {
        /** @var ClientManager $manager */
        $manager = $this->laragram['mtproto.manager'];
        $session = (string) $this->option('session');

        try {
            return match (true) {
                $this->option('inspect') !== null => $this->inspect($manager, (string) $this->option('inspect')),
                $this->option('import') !== null => $this->import($manager, $session, (string) $this->option('import')),
                default => $this->export($manager, $session),
            };
        } catch (\Throwable $e) {
            $this->components->error($e->getMessage());
            return self::FAILURE;
        }
    }

    private function export(ClientManager $manager, string $session): int
    {
        $format = (string) $this->option('format');
        if (!in_array($format, AuthString::FORMATS, true)) {
            $this->components->error("Unknown format '{$format}' (use: " . implode(', ', AuthString::FORMATS) . ').');
            return self::FAILURE;
        }

        $client = $manager->client($session);
        $encrypter = $this->option('encrypt') ? $this->encrypter($client) : null;

        if ($this->option('offline')) {
            $stored = $client->openSession($session);
            if ($stored->getAuthKey() === null) {
                $this->components->error("Session '{$session}' has no auth key. Run: php laragram client:auth --session={$session}");
                return self::FAILURE;
            }
            $string = (new AuthString(
                dcId: $stored->getDcId(),
                authKey: $stored->getAuthKey(),
                apiId: $client->getApiId(),
            ))->encode($format, $encrypter);
        } else {
            $previous = $this->disableSwooleHooks();
            try {
                $manager->connect($session);
                if ($client->getSession()->getAuthKey() === null) {
                    $this->components->error("Session '{$session}' is not authorized. Run: php laragram client:auth --session={$session}");
                    return self::FAILURE;
                }
                $string = $client->authString($format, encrypter: $encrypter);
            } finally {
                $manager->disconnect($session);
                $this->restoreSwooleHooks($previous);
            }
        }

        $this->components->warn('This string is the account itself - keep it secret (e.g. CLIENT_AUTH_STRING in your secrets), never in the repository.');
        $this->line($string);

        return self::SUCCESS;
    }

    private function inspect(ClientManager $manager, string $string): int
    {
        $decoded = AuthString::decode($string, $this->optionalEncrypter($manager));

        foreach ($decoded->describe() as $label => $value) {
            $this->components->twoColumnDetail(str_replace('_', ' ', ucfirst($label)), match (true) {
                $value === null => '<fg=gray>—</>',
                is_bool($value) => $value ? 'yes' : 'no',
                default => (string) $value,
            });
        }

        return self::SUCCESS;
    }

    private function import(ClientManager $manager, string $session, string $string): int
    {
        $client = $manager->client($session);
        $decoded = AuthString::decode($string, $client->getSessionEncrypter() ?? $this->optionalEncrypter($manager));

        $stored = $client->openSession($session);
        if ($stored->getAuthKey() !== null && !$this->option('force')) {
            $this->components->error("Session '{$session}' already exists - use --force to overwrite it.");
            return self::FAILURE;
        }

        if ($decoded->apiId !== null && $decoded->apiId !== $client->getApiId()) {
            $this->components->warn("The string was created with api_id {$decoded->apiId}, this session uses {$client->getApiId()}. It works, but Telegram sees a different app.");
        }

        $stored->setAuthKey($decoded->authKey);
        $stored->setDcId($decoded->dcId);
        if ($decoded->serverSalt !== null) {
            $stored->setServerSalt($decoded->serverSalt);
        }
        $stored->setTimeDelta($decoded->timeDelta);
        $stored->save();

        $this->components->info("Imported a {$decoded->format} session into '{$session}' (DC{$decoded->dcId}" . ($decoded->userId ? ", user {$decoded->userId}" : '') . ').');

        return self::SUCCESS;
    }

    private function encrypter(\LaraGram\MTProto\Core\Client $client): \LaraGram\Encryption\Encrypter
    {
        return $client->getSessionEncrypter() ?? \LaraGram\MTProto\Store\EncryptedStore::encrypter(
            (string) (config('mtproto.session.encryption.key') ?: config('app.key')
                ?: throw new \RuntimeException('--encrypt needs CLIENT_SESSION_KEY or APP_KEY.'))
        );
    }

    private function optionalEncrypter(ClientManager $manager): ?\LaraGram\Encryption\Encrypter
    {
        $key = config('mtproto.session.encryption.key') ?: config('app.key');

        return is_string($key) && $key !== '' ? \LaraGram\MTProto\Store\EncryptedStore::encrypter($key) : null;
    }

    /**
     * The export talks to Telegram synchronously, outside any coroutine.
     */
    private function disableSwooleHooks(): ?int
    {
        if (!class_exists(\Swoole\Runtime::class)) {
            return null;
        }

        $previous = method_exists(\Swoole\Runtime::class, 'getHookFlags') ? \Swoole\Runtime::getHookFlags() : \SWOOLE_HOOK_ALL;
        \Swoole\Runtime::enableCoroutine(0);

        return $previous;
    }

    private function restoreSwooleHooks(?int $previous): void
    {
        if ($previous !== null) {
            \Swoole\Runtime::enableCoroutine($previous);
        }
    }
}
