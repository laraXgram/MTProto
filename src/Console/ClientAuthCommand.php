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
        {--bot= : Bot token for bot login}
        {--qr : Log in by scanning a QR code from your Telegram app}';

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

            if ($this->option('qr')) {
                return $this->loginWithQr($auth);
            }

            $method = select('How do you want to log in?', ['account', 'bot', 'qr'], 'account');

            if ($method === 'bot') {
                $token = password('Enter your bot token (from @BotFather)');

                if (empty(trim((string) $token))) {
                    $this->components->error('Bot token is required.');
                    return self::FAILURE;
                }

                return $this->loginAsBot($auth, $token);
            }

            if ($method === 'qr') {
                return $this->loginWithQr($auth);
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
    protected function loginAsBot(Authorization $auth, string $token, bool $allowReset = true): int
    {
        try {
            $this->components->task('Logging in as bot', function () use ($auth, $token) {
                $auth->botLogin($token);
            });

            $this->components->info('✓ Bot login successful!');
            $this->components->warn(
                'A bot auth key is tied to this bot. Do not run the same token via webhook Bot-API and '
                .'MTProto at the same time - keep this session single-instance.'
            );
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $msg = $e->getMessage();

            $collision = str_contains($msg, 'AUTH_KEY_UNREGISTERED')
                || str_contains($msg, 'BOT_METHOD_INVALID')
                || str_contains($msg, 'SESSION_PASSWORD_NEEDED');

            if ($allowReset && $collision) {
                $this->components->warn(
                    "Session '{$this->session}' already holds a different authorization; "
                    .'a bot token cannot be imported onto it.'
                );

                $choice = select(
                    "Reset session '{$this->session}' and import the bot token on a fresh key?",
                    ['no', 'yes'],
                    'no'
                );

                if ($choice === 'yes') {
                    $fresh = $this->resetSession();

                    return $fresh === null
                        ? self::FAILURE
                        : $this->loginAsBot($fresh, $token, allowReset: false);
                }

                $this->components->error('Aborted. Use a different --session name for the bot.');
                return self::FAILURE;
            }

            if (str_contains($msg, 'ACCESS_TOKEN_INVALID') || str_contains($msg, 'ACCESS_TOKEN_EXPIRED')) {
                $this->components->error('Bot token is invalid or revoked. Get a fresh token from @BotFather.');
                return self::FAILURE;
            }

            $this->components->error("Bot login failed: {$msg}");
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
     * QR-code login: export a login token, render it as a scannable QR, and
     * poll until the user links this session from their Telegram app.
     */
    protected function loginWithQr(Authorization $auth): int
    {
        $qr = new \LaraGram\MTProto\Support\QrCode();
        $deadline = time() + 300;   // give the user 5 minutes total
        $lastToken = null;

        while (time() < $deadline) {
            try {
                $res = $auth->exportLoginToken();
            } catch (\Throwable $e) {
                if ($this->isPasswordNeeded($e)) {
                    return $this->handleQr2FA($auth);
                }
                $this->components->error("QR login failed: {$e->getMessage()}");
                return self::FAILURE;
            }

            switch ($res['_'] ?? '') {
                case 'auth.loginTokenSuccess':
                    $this->components->info('✓ QR login successful!');
                    return self::SUCCESS;

                case 'auth.loginTokenMigrateTo':
                    try {
                        $done = $auth->importLoginToken((string) $res['token'], (int) $res['dc_id']);
                    } catch (\Throwable $e) {
                        if ($this->isPasswordNeeded($e)) {
                            return $this->handleQr2FA($auth);
                        }
                        $this->components->error("QR login failed: {$e->getMessage()}");
                        return self::FAILURE;
                    }

                    if (($done['_'] ?? '') === 'auth.loginTokenSuccess') {
                        $this->components->info('✓ QR login successful!');
                        return self::SUCCESS;
                    }
                    break;

                case 'auth.loginToken':
                    $token = (string) $res['token'];
                    if ($token !== $lastToken) {
                        $lastToken = $token;
                        $this->renderQrScreen($qr, $auth->loginTokenUrl($token));
                    }

                    $expires = (int) ($res['expires'] ?? 0);
                    $remaining = $expires > 0 ? $expires - time() : 25;
                    sleep(max(3, min($remaining - 2, 25)));
                    break;

                default:
                    $this->components->error('Unexpected QR login response: ' . ($res['_'] ?? 'unknown'));
                    return self::FAILURE;
            }
        }

        $this->components->error('QR login timed out. Please try again.');
        return self::FAILURE;
    }

    /**
     * Clear the terminal and render exactly one live QR + instructions, so no
     * expired code lingers in scrollback for the user to scan by mistake.
     */
    protected function renderQrScreen(\LaraGram\MTProto\Support\QrCode $qr, string $url): void
    {
        if (function_exists('stream_isatty') && @stream_isatty(STDOUT)) {
            $this->output->write("\033[2J\033[H"); // clear screen + home
        }

        $this->components->info('Link this session via QR:');
        $this->line('  1) Open Telegram on your phone (already logged in)');
        $this->line('  2) Settings → Devices → Link Desktop Device');
        $this->line('  3) Point the phone at the code below (this screen), not a saved photo');
        $this->newLine();
        $this->output->write($qr->render($url));
        $this->newLine();
        $this->line("  {$url}");
        $this->components->info('Waiting for scan… keep this window open.');
        $this->line('  After scanning, confirming can take a few seconds (until the code refreshes).');
    }

    /**
     * Run the 2FA step for a QR login (the account has a cloud password).
     */
    protected function handleQr2FA(Authorization $auth): int
    {
        $auth->requirePassword();
        return $this->handle2FA($auth);
    }

    private function isPasswordNeeded(\Throwable $e): bool
    {
        return str_contains($e->getMessage(), 'SESSION_PASSWORD_NEEDED');
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
