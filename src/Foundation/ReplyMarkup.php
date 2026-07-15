<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Foundation;

final class ReplyMarkup
{
    /**
     * @param array<string, mixed> $markup
     * @return array
     */
    public static function toTl(array $markup): array
    {
        if (isset($markup['inline_keyboard'])) {
            return [
                '_' => 'replyInlineMarkup',
                'rows' => self::rows($markup['inline_keyboard'], true),
            ];
        }

        if (isset($markup['remove_keyboard'])) {
            $m = ['_' => 'replyKeyboardHide'];
            if (!empty($markup['selective'])) {
                $m['selective'] = true;
            }

            return $m;
        }

        if (isset($markup['force_reply'])) {
            $m = ['_' => 'replyKeyboardForceReply'];
            if (!empty($markup['selective'])) {
                $m['selective'] = true;
            }
            if (($markup['input_field_placeholder'] ?? '') !== '') {
                $m['placeholder'] = $markup['input_field_placeholder'];
            }

            return $m;
        }

        $m = [
            '_' => 'replyKeyboardMarkup',
            'rows' => self::rows($markup['keyboard'] ?? [], false),
        ];
        if (!empty($markup['resize_keyboard'])) {
            $m['resize'] = true;
        }
        if (!empty($markup['one_time_keyboard'])) {
            $m['single_use'] = true;
        }
        if (!empty($markup['is_persistent'])) {
            $m['persistent'] = true;
        }
        if (!empty($markup['selective'])) {
            $m['selective'] = true;
        }
        if (($markup['input_field_placeholder'] ?? '') !== '') {
            $m['placeholder'] = $markup['input_field_placeholder'];
        }

        return $m;
    }

    /**
     * @param array<int, array<int, array>> $rows
     * @return array<int, array>
     */
    private static function rows(array $rows, bool $inline): array
    {
        $out = [];
        foreach ($rows as $row) {
            $buttons = [];
            foreach ((array)$row as $btn) {
                $buttons[] = $inline ? self::inlineButton((array)$btn) : self::replyButton((array)$btn);
            }
            $out[] = ['_' => 'keyboardButtonRow', 'buttons' => $buttons];
        }

        return $out;
    }

    /**
     * Bot-API inline button -> TL keyboardButton* constructor.
     *
     * @param array<string, mixed> $b
     */
    private static function inlineButton(array $b): array
    {
        $text = (string)($b['text'] ?? '');

        if (isset($b['callback_data'])) {
            return ['_' => 'keyboardButtonCallback', 'text' => $text, 'data' => (string)$b['callback_data']];
        }
        if (isset($b['url'])) {
            return ['_' => 'keyboardButtonUrl', 'text' => $text, 'url' => (string)$b['url']];
        }
        if (isset($b['login_url']['url'])) {
            $btn = ['_' => 'keyboardButtonUrlAuth', 'text' => $text, 'url' => (string)$b['login_url']['url']];
            if (($b['login_url']['forward_text'] ?? null) !== null && $b['login_url']['forward_text'] !== '') {
                $btn['fwd_text'] = (string)$b['login_url']['forward_text'];
            }

            return $btn;
        }
        if (isset($b['switch_inline_query'])) {
            return ['_' => 'keyboardButtonSwitchInline', 'text' => $text, 'query' => (string)$b['switch_inline_query']];
        }
        if (isset($b['switch_inline_query_current_chat'])) {
            return [
                '_' => 'keyboardButtonSwitchInline',
                'same_peer' => true,
                'text' => $text,
                'query' => (string)$b['switch_inline_query_current_chat'],
            ];
        }
        if (isset($b['web_app']['url'])) {
            return ['_' => 'keyboardButtonWebView', 'text' => $text, 'url' => (string)$b['web_app']['url']];
        }
        if (isset($b['copy_text']['text'])) {
            return ['_' => 'keyboardButtonCopy', 'text' => $text, 'copy_text' => (string)$b['copy_text']['text']];
        }
        if (!empty($b['pay'])) {
            return ['_' => 'keyboardButtonBuy', 'text' => $text];
        }

        // Fallback - a plain labelled button.
        return ['_' => 'keyboardButton', 'text' => $text];
    }

    /**
     * Bot-API reply (text) button -> TL keyboardButton* constructor.
     *
     * @param array<string, mixed> $b
     */
    private static function replyButton(array $b): array
    {
        $text = (string)($b['text'] ?? '');

        if (!empty($b['request_contact'])) {
            return ['_' => 'keyboardButtonRequestPhone', 'text' => $text];
        }
        if (!empty($b['request_location'])) {
            return ['_' => 'keyboardButtonRequestGeoLocation', 'text' => $text];
        }
        if (isset($b['request_poll'])) {
            $btn = ['_' => 'keyboardButtonRequestPoll', 'text' => $text];
            $type = $b['request_poll']['type'] ?? '';
            if ($type === 'quiz') {
                $btn['quiz'] = true;
            }
            if ($type === 'regular') {
                $btn['quiz'] = false;
            }

            return $btn;
        }
        if (isset($b['web_app']['url'])) {
            return ['_' => 'keyboardButtonSimpleWebView', 'text' => $text, 'url' => (string)$b['web_app']['url']];
        }

        return ['_' => 'keyboardButton', 'text' => $text];
    }
}
