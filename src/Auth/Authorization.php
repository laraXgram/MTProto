<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Auth;

use LaraGram\MTProto\Core\Client;
use LaraGram\MTProto\Exceptions\MTProtoException;

/**
 * Authorization handler for Telegram login.
 *
 * Handles phone login, bot login, 2FA, and session management.
 *
 * Supports full interactive login flow:
 *   sendCode() → signIn() → checkPassword() → signUp()
 *
 * Or use the interactive() method for a guided CLI login.
 */
class Authorization
{
    // Authorization states
    public const STATE_NONE = 'none';
    public const STATE_WAITING_CODE = 'waiting_code';
    public const STATE_WAITING_PASSWORD = 'waiting_password';
    public const STATE_WAITING_SIGNUP = 'waiting_signup';
    public const STATE_AUTHORIZED = 'authorized';

    /**
     * MTProto client.
     */
    private Client $client;

    /**
     * Current authorization state.
     */
    private string $state = self::STATE_NONE;

    /**
     * Phone number for login.
     */
    private ?string $phoneNumber = null;

    /**
     * Phone code hash from sendCode.
     */
    private ?string $phoneCodeHash = null;

    /**
     * Create a new Authorization instance.
     */
    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    /**
     * Get current authorization state.
     */
    public function getState(): string
    {
        return $this->state;
    }

    /**
     * Force the "2FA password needed" state so {@see checkPassword()} can run
     * after a non-phone login (QR / token) surfaced SESSION_PASSWORD_NEEDED.
     */
    public function requirePassword(): void
    {
        $this->state = self::STATE_WAITING_PASSWORD;
    }

    /**
     * Send verification code to phone.
     *
     * @param string $phoneNumber Phone number in international format
     * @return array Sent code info (type, phone_code_hash, next_type, timeout)
     * @throws MTProtoException On failure (except PHONE_MIGRATE which is handled automatically)
     */
    public function sendCode(string $phoneNumber): array
    {
        $this->phoneNumber = $phoneNumber;

        try {
            $result = $this->client->invoke('auth.sendCode', [
                'phone_number' => $phoneNumber,
                'api_id' => $this->client->getApiId(),
                'api_hash' => $this->client->getApiHash(),
                'settings' => ['_' => 'codeSettings'],
            ]);
        } catch (MTProtoException $e) {
            // Handle PHONE_MIGRATE_X error - need to switch to different DC
            if (preg_match('/PHONE_MIGRATE_(\d+)/', $e->getMessage(), $matches)) {
                $newDcId = (int) $matches[1];

                // Switch to the correct DC (void; throws on failure).
                try {
                    $this->client->switchDc($newDcId);
                } catch (\Throwable $se) {
                    throw new MTProtoException("Failed to switch to DC{$newDcId}: {$se->getMessage()}");
                }

                // Retry sendCode on the new DC
                return $this->sendCode($phoneNumber);
            }

            throw $e;
        }

        // Handle auth.sentCodeSuccess (auto-authorized, e.g. test numbers)
        if (($result['_'] ?? '') === 'auth.sentCodeSuccess') {
            $this->state = self::STATE_AUTHORIZED;
            return $result;
        }

        $this->phoneCodeHash = $result['phone_code_hash'];
        $this->state = self::STATE_WAITING_CODE;

        return $result;
    }

    /**
     * Resend verification code.
     *
     * @return array New sent code info
     */
    public function resendCode(): array
    {
        if ($this->phoneNumber === null || $this->phoneCodeHash === null) {
            throw new MTProtoException('No pending phone login. Call sendCode() first.');
        }

        $result = $this->client->invoke('auth.resendCode', [
            'phone_number' => $this->phoneNumber,
            'phone_code_hash' => $this->phoneCodeHash,
        ]);

        $this->phoneCodeHash = $result['phone_code_hash'];

        return $result;
    }

    /**
     * Sign in with code.
     *
     * @param string $code Verification code
     * @return array auth.authorization or auth.authorizationSignUpRequired
     * @throws MTProtoException With code SESSION_PASSWORD_NEEDED if 2FA is enabled
     */
    public function signIn(string $code): array
    {
        if ($this->phoneNumber === null || $this->phoneCodeHash === null) {
            throw new MTProtoException('No pending phone login. Call sendCode() first.');
        }

        try {
            $result = $this->client->invoke('auth.signIn', [
                'phone_number' => $this->phoneNumber,
                'phone_code_hash' => $this->phoneCodeHash,
                'phone_code' => $code,
            ]);

            if (isset($result['_']) && $result['_'] === 'auth.authorizationSignUpRequired') {
                $this->state = self::STATE_WAITING_SIGNUP;
                return $result;
            }

            $this->state = self::STATE_AUTHORIZED;
            return $result;

        } catch (MTProtoException $e) {
            if (str_contains($e->getMessage(), 'SESSION_PASSWORD_NEEDED')) {
                $this->state = self::STATE_WAITING_PASSWORD;
                throw $e; // Let caller handle password entry
            }

            throw $e;
        }
    }

    /**
     * Check 2FA password.
     *
     * @param string $password 2FA password
     * @return array auth.authorization
     * @throws MTProtoException On wrong password (PASSWORD_HASH_INVALID) or other errors
     */
    public function checkPassword(string $password): array
    {
        if ($this->state !== self::STATE_WAITING_PASSWORD) {
            throw new MTProtoException('2FA not required. Current state: ' . $this->state);
        }

        // Get password settings
        $passwordInfo = $this->client->invoke('account.getPassword', []);

        // Compute SRP parameters
        $srp = $this->computeSrpParams($password, $passwordInfo);

        $result = $this->client->invoke('auth.checkPassword', [
            'password' => [
                '_' => 'inputCheckPasswordSRP',
                'srp_id' => $passwordInfo['srp_id'],
                'A' => $srp['A'],
                'M1' => $srp['M1'],
            ],
        ]);

        $this->state = self::STATE_AUTHORIZED;
        return $result;
    }

    /**
     * Get password info (hint, has_recovery, etc.)
     *
     * @return array account.password
     */
    public function getPasswordInfo(): array
    {
        return $this->client->invoke('account.getPassword', []);
    }

    /**
     * Request password recovery via email.
     *
     * @return array auth.passwordRecovery (contains email_pattern)
     */
    public function requestPasswordRecovery(): array
    {
        return $this->client->invoke('auth.requestPasswordRecovery', []);
    }

    /**
     * Recover password using email recovery code.
     *
     * @param string $code Recovery code from email
     * @return array auth.authorization
     */
    public function recoverPassword(string $code): array
    {
        $result = $this->client->invoke('auth.recoverPassword', [
            'code' => $code,
        ]);

        $this->state = self::STATE_AUTHORIZED;
        return $result;
    }

    /**
     * Sign up new account.
     *
     * @param string $firstName First name
     * @param string $lastName Last name
     * @return array User info
     */
    public function signUp(string $firstName, string $lastName = ''): array
    {
        if ($this->phoneNumber === null || $this->phoneCodeHash === null) {
            throw new MTProtoException('No pending phone login. Call sendCode() first.');
        }

        $result = $this->client->invoke('auth.signUp', [
            'phone_number' => $this->phoneNumber,
            'phone_code_hash' => $this->phoneCodeHash,
            'first_name' => $firstName,
            'last_name' => $lastName,
        ]);

        $this->state = self::STATE_AUTHORIZED;
        return $result;
    }

    /**
     * Login as bot.
     *
     * @param string $token Bot token from @BotFather
     * @return array Bot user info
     */
    public function botLogin(string $token, bool $allowMigrate = true): array
    {
        $token = trim($token);

        if ($token === '') {
            throw new MTProtoException('Bot token is empty.');
        }

        try {
            $result = $this->client->invoke('auth.importBotAuthorization', [
                'flags' => 0,
                'api_id' => $this->client->getApiId(),
                'api_hash' => $this->client->getApiHash(),
                'bot_auth_token' => $token,
            ]);
        } catch (MTProtoException $e) {
            // Bots migrate via USER_MIGRATE_X (not PHONE_MIGRATE).
            if ($allowMigrate && preg_match('/USER_MIGRATE_(\d+)/', $e->getMessage(), $m)) {
                $newDcId = (int) $m[1];

                try {
                    $this->client->switchDc($newDcId); // void; throws on failure
                } catch (\Throwable $se) {
                    throw new MTProtoException("Failed to switch to DC{$newDcId} for bot login: {$se->getMessage()}");
                }

                return $this->botLogin($token, allowMigrate: false);
            }

            throw $e;
        }

        $this->state = self::STATE_AUTHORIZED;
        return $result;
    }

    /**
     * Export login link for QR code login.
     *
     * @return array QR code data
     */
    public function exportLoginToken(): array
    {
        return $this->client->invoke('auth.exportLoginToken', [
            'api_id' => $this->client->getApiId(),
            'api_hash' => $this->client->getApiHash(),
            'except_ids' => [],
        ]);
    }

    /**
     * Complete a QR login after the server signalled a DC migration.
     *
     * @return array auth.loginTokenSuccess | auth.loginToken
     */
    public function importLoginToken(string $token, ?int $dcId = null): array
    {
        if ($dcId !== null && $dcId !== $this->client->getDcId()) {
            try {
                $this->client->switchDc($dcId); // void; throws on failure
            } catch (\Throwable $se) {
                throw new MTProtoException("Failed to switch to DC{$dcId} for QR login: {$se->getMessage()}");
            }
        }

        $result = $this->client->invoke('auth.importLoginToken', ['token' => $token]);

        if (($result['_'] ?? '') === 'auth.loginTokenSuccess') {
            $this->state = self::STATE_AUTHORIZED;
        }

        return $result;
    }

    /**
     * Build the `tg://login?token=…` URL a Telegram app scans to link this
     * session (Settings → Devices → Link Desktop Device).
     */
    public function loginTokenUrl(string $rawToken): string
    {
        return 'tg://login?token=' . rtrim(strtr(base64_encode($rawToken), '+/', '-_'), '=');
    }

    /**
     * Log out from current session.
     */
    public function logOut(): bool
    {
        try {
            $this->client->invoke('auth.logOut', []);
            $this->state = self::STATE_NONE;
            $this->phoneNumber = null;
            $this->phoneCodeHash = null;
            return true;
        } catch (MTProtoException $e) {
            return false;
        }
    }

    /**
     * Cancel current phone login.
     */
    public function cancelCode(): bool
    {
        if ($this->phoneNumber === null || $this->phoneCodeHash === null) {
            return false;
        }

        try {
            $this->client->invoke('auth.cancelCode', [
                'phone_number' => $this->phoneNumber,
                'phone_code_hash' => $this->phoneCodeHash,
            ]);
        } catch (MTProtoException $e) {
            // Ignore errors
        }

        $this->state = self::STATE_NONE;
        $this->phoneNumber = null;
        $this->phoneCodeHash = null;

        return true;
    }

    /**
     * Compute SRP parameters for 2FA.
     *
     * @param string $password User password
     * @param array $passwordInfo Password info from account.getPassword
     * @return array SRP parameters (A, M1)
     */
    private function computeSrpParams(string $password, array $passwordInfo): array
    {
        $algo = $passwordInfo['current_algo'];

        if ($algo['_'] !== 'passwordKdfAlgoSHA256SHA256PBKDF2HMACSHA512iter100000SHA256ModPow') {
            throw new MTProtoException('Unknown password algorithm: ' . $algo['_']);
        }

        $salt1 = $algo['salt1'];
        $salt2 = $algo['salt2'];
        $g = gmp_init($algo['g']);
        $p = gmp_import($algo['p'], 1, GMP_MSW_FIRST | GMP_BIG_ENDIAN);

        $srpB = gmp_import($passwordInfo['srp_B'], 1, GMP_MSW_FIRST | GMP_BIG_ENDIAN);

        // x = H(salt1 || password || salt1)
        $hash1 = hash('sha256', $salt1 . $password . $salt1, true);

        // x = H(salt2 || x || salt2)
        $hash2 = hash('sha256', $salt2 . $hash1 . $salt2, true);

        // x = PBKDF2(hash2, salt1, 100000, 64, SHA512)
        $pbkdf2 = hash_pbkdf2('sha512', $hash2, $salt1, 100000, 64, true);

        // x = H(salt2 || pbkdf2 || salt2)
        $x = hash('sha256', $salt2 . $pbkdf2 . $salt2, true);
        $xNum = gmp_import($x, 1, GMP_MSW_FIRST | GMP_BIG_ENDIAN);

        // Generate random a (256 bits)
        $a = gmp_import(random_bytes(256), 1, GMP_MSW_FIRST | GMP_BIG_ENDIAN);
        $a = gmp_mod($a, gmp_sub($p, gmp_init(1)));

        if (gmp_cmp($a, 0) === 0) {
            $a = gmp_init(1);
        }

        // A = g^a mod p
        $A = gmp_powm($g, $a, $p);

        // k = H(p || g)
        $pBytes = str_pad(gmp_export($p, 1, GMP_MSW_FIRST | GMP_BIG_ENDIAN), 256, "\x00", STR_PAD_LEFT);
        $gBytes = str_pad(gmp_export($g, 1, GMP_MSW_FIRST | GMP_BIG_ENDIAN), 256, "\x00", STR_PAD_LEFT);
        $k = gmp_import(hash('sha256', $pBytes . $gBytes, true), 1, GMP_MSW_FIRST | GMP_BIG_ENDIAN);

        // u = H(A || B)
        $ABytes = str_pad(gmp_export($A, 1, GMP_MSW_FIRST | GMP_BIG_ENDIAN), 256, "\x00", STR_PAD_LEFT);
        $BBytes = str_pad(gmp_export($srpB, 1, GMP_MSW_FIRST | GMP_BIG_ENDIAN), 256, "\x00", STR_PAD_LEFT);
        $u = gmp_import(hash('sha256', $ABytes . $BBytes, true), 1, GMP_MSW_FIRST | GMP_BIG_ENDIAN);

        // S = (B - k * g^x)^(a + u * x) mod p
        $gx = gmp_powm($g, $xNum, $p);
        $kgx = gmp_mod(gmp_mul($k, $gx), $p);
        $diff = gmp_mod(gmp_sub($srpB, $kgx), $p);

        if (gmp_cmp($diff, 0) < 0) {
            $diff = gmp_add($diff, $p);
        }

        $exp = gmp_mod(gmp_add($a, gmp_mul($u, $xNum)), gmp_sub($p, gmp_init(1)));
        $S = gmp_powm($diff, $exp, $p);

        // K = H(S)
        $SBytes = str_pad(gmp_export($S, 1, GMP_MSW_FIRST | GMP_BIG_ENDIAN), 256, "\x00", STR_PAD_LEFT);
        $K = hash('sha256', $SBytes, true);

        // M1 = H(H(p) XOR H(g) || H(salt1) || H(salt2) || A || B || K)
        $hp = hash('sha256', $pBytes, true);
        $hg = hash('sha256', $gBytes, true);
        $hpXorHg = $hp ^ $hg;

        $hsalt1 = hash('sha256', $salt1, true);
        $hsalt2 = hash('sha256', $salt2, true);

        $M1 = hash('sha256', $hpXorHg . $hsalt1 . $hsalt2 . $ABytes . $BBytes . $K, true);

        return [
            'A' => $ABytes,
            'M1' => $M1,
        ];
    }
}
