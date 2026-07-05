<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Core;

use LaraGram\MTProto\Exceptions\MTProtoException;

final class ChatRights
{
    /** @var list<string> chatAdminRights#5fb224d5 flags */
    public const ADMIN_FLAGS = [
        'change_info', 'post_messages', 'edit_messages', 'delete_messages',
        'ban_users', 'invite_users', 'pin_messages', 'add_admins', 'anonymous',
        'manage_call', 'other', 'manage_topics', 'post_stories', 'edit_stories',
        'delete_stories', 'manage_direct_messages', 'manage_ranks',
    ];

    /** @var list<string> chatBannedRights#9f120418 flags */
    public const BANNED_FLAGS = [
        'view_messages', 'send_messages', 'send_media', 'send_stickers',
        'send_gifs', 'send_games', 'send_inline', 'embed_links', 'send_polls',
        'change_info', 'invite_users', 'pin_messages', 'manage_topics',
        'send_photos', 'send_videos', 'send_roundvideos', 'send_audios',
        'send_voices', 'send_docs', 'send_plain', 'edit_rank', 'send_reactions',
    ];

    /**
     * A reasonable default admin grant used when {@see promote} is called with
     * no explicit rights (everything an admin usually needs, minus the
     * dangerous add_admins/anonymous).
     */
    private const DEFAULT_ADMIN = [
        'change_info', 'delete_messages', 'ban_users', 'invite_users',
        'pin_messages', 'manage_call', 'manage_topics',
    ];

    /**
     * Build a `chatAdminRights` object. Pass a list of flag names, an assoc
     * `[flag => bool]` map, or the string "all". An empty list yields the
     * {@see DEFAULT_ADMIN} set; use {@see demote} for an all-false object.
     *
     * @param list<string>|array<string,bool>|string $rights
     */
    public static function admin(array|string $rights = []): array
    {
        if ($rights === 'all') {
            $flags = self::ADMIN_FLAGS;
        } elseif ($rights === []) {
            $flags = self::DEFAULT_ADMIN;
        } else {
            $flags = self::normalise((array) $rights, self::ADMIN_FLAGS, 'admin');
        }

        return self::assemble('chatAdminRights', $flags);
    }

    /**
     * All-false admin rights - strips a user of admin status.
     */
    public static function demote(): array
    {
        return ['_' => 'chatAdminRights'];
    }

    /**
     * Build a `chatBannedRights` object from a list of *restricted* abilities.
     * `until_date` of 0 means permanent. Pass "all" to restrict everything
     * except viewing (a mute); use {@see banAll} to also revoke viewing.
     *
     * @param list<string>|array<string,bool>|string $restrictions
     */
    public static function banned(array|string $restrictions, int $until = 0): array
    {
        if ($restrictions === 'all') {
            $flags = array_values(array_filter(
                self::BANNED_FLAGS,
                static fn (string $f): bool => $f !== 'view_messages',
            ));
        } else {
            $flags = self::normalise((array) $restrictions, self::BANNED_FLAGS, 'banned');
        }

        return self::assemble('chatBannedRights', $flags, ['until_date' => $until]);
    }

    /**
     * Full ban (view_messages set) - kicks the user and blocks rejoin until
     * `$until` (0 = forever).
     */
    public static function banAll(int $until = 0): array
    {
        return self::assemble('chatBannedRights', self::BANNED_FLAGS, ['until_date' => $until]);
    }

    /**
     * All-false banned rights - lifts every restriction (unban / unmute).
     */
    public static function unban(): array
    {
        return ['_' => 'chatBannedRights', 'until_date' => 0];
    }

    /**
     * @param list<string>|array<string,bool> $input
     * @param list<string> $allowed
     * @return list<string>
     */
    private static function normalise(array $input, array $allowed, string $kind): array
    {
        $flags = [];

        foreach ($input as $key => $value) {
            // list form: [0 => 'send_media']; assoc form: ['send_media' => true]
            $flag = is_int($key) ? $value : $key;
            $on = is_int($key) ? true : (bool) $value;

            if (!$on) {
                continue;
            }
            if (!is_string($flag) || !in_array($flag, $allowed, true)) {
                throw new MTProtoException("Unknown {$kind} right: " . var_export($flag, true));
            }
            $flags[$flag] = true;
        }

        return array_keys($flags);
    }

    /**
     * @param list<string> $flags
     * @param array<string,mixed> $extra
     */
    private static function assemble(string $ctor, array $flags, array $extra = []): array
    {
        $out = ['_' => $ctor];
        foreach ($flags as $flag) {
            $out[$flag] = true;
        }

        return $out + $extra;
    }
}
