<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Session\Import;

/**
 * Bot-API peer-id marking helpers.
 */
final class PeerMarking
{
    /**
     * The channel-id shift used by the bot API: a channel/supergroup marked id
     * is `-(1_000_000_000_000 + raw_id)`.
     */
    private const CHANNEL_SHIFT = 1_000_000_000_000;

    /**
     * Split a marked bot-API id into [rawId, defaultType].
     *
     * The type returned is a best guess from the id range only; callers that
     * have a more authoritative type (e.g. Pyrogram's explicit `type` column)
     * should prefer that and only use this for the raw id.
     *
     * @return array{0:int,1:string}  [rawId, type]  type ∈ user|chat|channel
     */
    public static function unmark(int $marked): array
    {
        if ($marked >= 0) {
            return [$marked, 'user'];
        }

        // Channel / supergroup: -(10^12 + raw). These are the most negative ids.
        if ($marked <= -self::CHANNEL_SHIFT) {
            return [-self::CHANNEL_SHIFT - $marked, 'channel'];
        }

        // Basic group: -raw.
        return [-$marked, 'chat'];
    }

    /**
     * Unmark using an explicit source type ("user"/"bot"/"group"/"supergroup"/
     * "channel"). Falls back to range detection when the type is unknown.
     */
    public static function unmarkWithType(int $marked, ?string $type): array
    {
        $type = $type !== null ? strtolower($type) : null;

        switch ($type) {
            case 'user':
            case 'bot':
                return [$marked >= 0 ? $marked : -$marked, $type === 'bot' ? 'bot' : 'user'];

            case 'group':
            case 'chat':
                return [$marked < 0 ? -$marked : $marked, 'chat'];

            case 'channel':
            case 'supergroup':
                $raw = $marked <= -self::CHANNEL_SHIFT ? (-self::CHANNEL_SHIFT - $marked) : abs($marked);
                return [$raw, $type === 'supergroup' ? 'supergroup' : 'channel'];
        }

        return self::unmark($marked);
    }
}
