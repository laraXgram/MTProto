<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Console;

use LaraGram\Console\Command;
use LaraGram\MTProto\Auth\Authorization;
use LaraGram\MTProto\Foundation\ClientManager;

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
        {--session=default : Session name}
        {--bot= : Bot token for bot login (skips phone flow)}';

    protected $description = 'Authenticate an MTProto client session (interactive phone/bot login)';

    public function handle(): int
    {
        $session = $this->option('session');
        $botToken = $this->option('bot');

        /** @var ClientManager $manager */
        $manager = $this->laragram['mtproto.manager'];

        $this->components->info("Authenticating session: {$session}");

        // Connect (creates auth key if needed)
        try {
            $manager->connect($session);
        } catch (\Throwable $e) {
            $this->components->error("Connection failed: {$e->getMessage()}");
            return self::FAILURE;
        }

        $auth = $manager->authorization($session);

        // Bot login
        if ($botToken) {
            return $this->loginAsBot($auth, $botToken);
        }

        // Interactive phone login
        return $this->loginWithPhone($auth);
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

    /**
     * Interactive phone login flow.
     */
    protected function loginWithPhone(Authorization $auth): int
    {
        // Step 1: Phone number
        $phone = $this->components->ask('Enter your phone number (international format, e.g. +1234567890)');

        if (empty($phone)) {
            $this->components->error('Phone number is required.');
            return self::FAILURE;
        }

        // Step 2: Send verification code
        try {
            $this->components->task('Sending verification code', function () use ($auth, $phone) {
                $auth->sendCode($phone);
            });
        } catch (\Throwable $e) {
            $this->components->error("Failed to send code: {$e->getMessage()}");
            return self::FAILURE;
        }

        // Step 3: Enter code
        $code = $this->components->ask('Enter the verification code you received');

        if (empty($code)) {
            $this->components->error('Verification code is required.');
            return self::FAILURE;
        }

        // Step 4: Sign in
        try {
            $result = $auth->signIn($code);

            // Check if sign up is required
            if (isset($result['_']) && $result['_'] === 'auth.authorizationSignUpRequired') {
                return $this->handleSignUp($auth);
            }

            $this->components->info('✓ Login successful!');
            return self::SUCCESS;

        } catch (\Throwable $e) {
            // Check for 2FA
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

        // Get password hint
        try {
            $pwInfo = $auth->getPasswordInfo();
            if (!empty($pwInfo['hint'])) {
                $this->components->info("Password hint: {$pwInfo['hint']}");
            }
        } catch (\Throwable) {
            // Ignore hint retrieval errors
        }

        $password = $this->components->secret('Enter your 2FA password');

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

        $firstName = $this->components->ask('Enter your first name');
        $lastName  = $this->components->ask('Enter your last name (optional)', '');

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
