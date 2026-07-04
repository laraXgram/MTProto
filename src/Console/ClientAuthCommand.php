<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Console;

use LaraGram\Console\Command;
use LaraGram\MTProto\Auth\Authorization;
use LaraGram\MTProto\Foundation\ClientManager;

use function LaraGram\Console\Prompts\password;
use function LaraGram\Console\Prompts\select;
use function LaraGram\Console\Prompts\text;

/**
 * Interactive login command for MTProto client.
 *
 * Usage:
 *   php laragram client:auth
 *   php laragram client:auth --session=user2
 *   php laragram client:auth --bot=BOT_TOKEN
 */
class ClientAuthCommand extends Command
{
    protected $signature = 'client:auth
        {--session= : Session name}
        {--bot= : Bot token for bot login}';

    protected $description = 'Authenticate an MTProto client session (interactive phone/bot login)';

    protected string $session = 'default';

    /** @var ClientManager */
    protected ClientManager $manager;

    public function handle(): int
    {
        $botToken = $this->option('bot');

        $session = (string) $this->option('session');
        if ($session === '') {
            $random = bin2hex(random_bytes(4)); // 8 hex chars
            $session = trim((string) text(
                label: 'Session name',
                placeholder: $random,
                default: $random,
            ));
            if ($session === '') {
                $session = $random;
            }
        }
        $this->session = $session;

        /** @var ClientManager $manager */
        $manager = $this->manager = $this->laragram['mtproto.manager'];

        $this->components->info("Authenticating session: {$session}");

        try {
            $manager->connect($session);
        } catch (\Throwable $e) {
            $this->components->error("Connection failed: {$e->getMessage()}");
            return self::FAILURE;
        }

        $prevHookFlags = null;
        if (class_exists(\Swoole\Runtime::class)) {
            $prevHookFlags = method_exists(\Swoole\Runtime::class, 'getHookFlags')
                ? \Swoole\Runtime::getHookFlags()
                : \SWOOLE_HOOK_ALL;
            \Swoole\Runtime::enableCoroutine(0);
        }

        try {
            $auth = $manager->authorization($session);

            if ($botToken !== null && $botToken !== '') {
                return $this->loginAsBot($auth, $botToken);
            }

            $method = select('How do you want to log in?', ['account', 'bot'], 'account');

            if ($method === 'bot') {
                $token = password('Enter your bot token (from @BotFather)');

                if (empty(trim((string) $token))) {
                    $this->components->error('Bot token is required.');
                    return self::FAILURE;
                }

                return $this->loginAsBot($auth, $token);
            }

            return $this->loginWithPhone($auth);
        } finally {
            if ($prevHookFlags !== null) {
                \Swoole\Runtime::enableCoroutine($prevHookFlags);
            }
        }
    }

    /**
     * Login as a bot using a token.
     */
    protected function loginAsBot(Authorization $auth, string $token): int
    {
        try {
            $this->components->task('Logging in as bot', function () use ($auth, $token) {
                $auth->botLogin($token);
            });

            $this->components->info('✓ Bot login successful!');
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->components->error("Bot login failed: {$e->getMessage()}");
            return self::FAILURE;
        }
    }

    protected function resetSession(): ?Authorization
    {
        try {
            $this->components->task("Resetting session '{$this->session}'", function () {
                $this->manager->disconnect($this->session);
                $this->manager->forget($this->session);
                $this->manager->deleteSession($this->session);
                $this->manager->connect($this->session); // fresh handshake -> new auth key
            });

            return $this->manager->authorization($this->session);
        } catch (\Throwable $e) {
            $this->components->error("Failed to reset session: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Interactive phone login flow.
     */
    protected function loginWithPhone(Authorization $auth, bool $allowReset = true, ?string $phone = null): int
    {
        $phone ??= text('Enter your phone number (international format, e.g. +1234567890)');

        if (empty($phone)) {
            $this->components->error('Phone number is required.');
            return self::FAILURE;
        }

        try {
            $this->components->task('Sending verification code', function () use ($auth, $phone) {
                $auth->sendCode($phone);
            });
        } catch (\Throwable $e) {
            if ($allowReset && str_contains($e->getMessage(), 'BOT_METHOD_INVALID')) {
                $this->components->warn(
                    "Session '{$this->session}' already holds a BOT authorization; "
                    .'account (phone) login cannot run on a bot key.'
                );

                $choice = select(
                    "Reset session '{$this->session}' and log in as an account instead?",
                    ['no', 'yes'],
                    'no'
                );

                if ($choice === 'yes') {
                    $fresh = $this->resetSession();

                    return $fresh === null
                        ? self::FAILURE
                        : $this->loginWithPhone($fresh, allowReset: false, phone: $phone);
                }

                $this->components->error('Aborted. Use a different --session name for the account.');
                return self::FAILURE;
            }

            $this->components->error("Failed to send code: {$e->getMessage()}");
            return self::FAILURE;
        }

        $code = password('Enter the verification code you received');

        if (empty($code)) {
            $this->components->error('Verification code is required.');
            return self::FAILURE;
        }

        try {
            $result = $auth->signIn($code);

            if (isset($result['_']) && $result['_'] === 'auth.authorizationSignUpRequired') {
                return $this->handleSignUp($auth);
            }

            $this->components->info('✓ Login successful!');
            return self::SUCCESS;

        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), 'SESSION_PASSWORD_NEEDED')) {
                return $this->handle2FA($auth);
            }

            $this->components->error("Sign in failed: {$e->getMessage()}");
            return self::FAILURE;
        }
    }

    /**
     * Handle 2FA password entry.
     */
    protected function handle2FA(Authorization $auth): int
    {
        $this->components->warn('Two-factor authentication is enabled.');

        try {
            $pwInfo = $auth->getPasswordInfo();
            if (!empty($pwInfo['hint'])) {
                $this->components->info("Password hint: {$pwInfo['hint']}");
            }
        } catch (\Throwable) {
            //
        }

        $password = password('Enter your 2FA password');

        if (empty($password)) {
            $this->components->error('Password is required.');
            return self::FAILURE;
        }

        try {
            $auth->checkPassword($password);
            $this->components->info('✓ Login successful!');
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->components->error("2FA failed: {$e->getMessage()}");
            return self::FAILURE;
        }
    }

    /**
     * Handle sign up for new accounts.
     */
    protected function handleSignUp(Authorization $auth): int
    {
        $this->components->warn('Account does not exist. Sign up required.');

        $firstName = text('Enter your first name');
        $lastName  = text('Enter your last name (optional)', '');

        if (empty($firstName)) {
            $this->components->error('First name is required.');
            return self::FAILURE;
        }

        try {
            $auth->signUp($firstName, $lastName);
            $this->components->info('✓ Sign up and login successful!');
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->components->error("Sign up failed: {$e->getMessage()}");
            return self::FAILURE;
        }
    }
}
