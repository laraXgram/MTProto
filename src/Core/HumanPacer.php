<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Core;

final class HumanPacer
{
    private bool $enabled;

    /** Think-time window in seconds (uniform). */
    private float $minThink;
    private float $maxThink;

    /** Simulated typing speed (characters per second) and its delay cap. */
    private float $charsPerSecond;
    private float $maxTyping;

    /** Extra uniform jitter added to every paced action. */
    private float $jitter;

    /**
     * Method-name fragments that count as human-facing actions worth pacing.
     * Matched case-insensitively against the TL method (e.g. messages.sendMessage).
     */
    private const PACED_METHODS = [
        'sendmessage', 'sendmedia', 'sendmultimedia', 'forwardmessages',
        'sendreaction', 'sendinlinebotresult', 'editmessage',
        'readhistory', 'readmessagecontents', 'sendscreenshotnotification',
    ];

    /** Methods whose text length drives the typing-time component. */
    private const TYPING_METHODS = [
        'sendmessage', 'sendmedia', 'editmessage', 'sendinlinebotresult',
    ];

    /**
     * @param array<string,mixed> $config
     */
    public function __construct(array $config = [])
    {
        $this->enabled = (bool)($config['enabled'] ?? false);
        $this->minThink = (float)($config['min_think'] ?? 0.3);
        $this->maxThink = (float)($config['max_think'] ?? 1.5);
        $this->charsPerSecond = (float)($config['chars_per_second'] ?? 18.0);
        $this->maxTyping = (float)($config['max_typing'] ?? 4.0);
        $this->jitter = (float)($config['jitter'] ?? 0.4);

        if ($this->maxThink < $this->minThink) {
            $this->maxThink = $this->minThink;
        }
        if ($this->charsPerSecond <= 0.0) {
            $this->charsPerSecond = 18.0;
        }
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Compute the human-like delay (seconds) to wait before issuing $method.
     * Returns 0.0 for disabled pacing or non-human-facing methods.
     *
     * @param array<string,mixed> $params
     */
    public function delayFor(string $method, array $params): float
    {
        if (!$this->enabled) {
            return 0.0;
        }

        $needle = strtolower($method);
        if (!$this->matches($needle, self::PACED_METHODS)) {
            return 0.0;
        }

        $delay = $this->uniform($this->minThink, $this->maxThink);

        if ($this->matches($needle, self::TYPING_METHODS)) {
            $delay += $this->typingTime($params);
        }

        $delay += $this->uniform(0.0, $this->jitter);

        return $delay;
    }

    /**
     * Typing-time component: characters / typing-speed, capped. Adds small
     * per-char randomness so identical messages don't pace identically.
     *
     * @param array<string,mixed> $params
     */
    private function typingTime(array $params): float
    {
        $text = '';
        foreach (['message', 'text', 'caption', 'query'] as $key) {
            if (isset($params[$key]) && is_string($params[$key])) {
                $text = $params[$key];
                break;
            }
        }

        if ($text === '') {
            return 0.0;
        }

        $chars = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);

        // ±15% speed variance so cadence isn't a fixed chars/sec ratio.
        $speed = $this->charsPerSecond * $this->uniform(0.85, 1.15);

        return min($chars / $speed, $this->maxTyping);
    }

    /**
     * @param string[] $needles
     */
    private function matches(string $method, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($method, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Uniform random float in [$min, $max]. Uses mt_rand (non-crypto is fine,
     * this is timing noise, not a secret).
     */
    private function uniform(float $min, float $max): float
    {
        if ($max <= $min) {
            return $min;
        }

        return $min + (mt_rand(0, 1_000_000) / 1_000_000) * ($max - $min);
    }
}
