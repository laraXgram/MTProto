<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Entities;

/**
 * Markdown / HTML ↔ MTProto MessageEntity[].
 *
 * Entity offsets/lengths are measured in UTF-16 code units (Telegram convention,
 * identical to the Bot API), not bytes or PHP characters - a codepoint above the
 * BMP counts as 2. Parsing returns the cleaned plain text plus the entity list
 * ready for messages.* (the `message` + `entities` fields).
 *
 * HTML tags: b/strong, i/em, u/ins, s/strike/del, code, pre (with optional
 * <code class="language-..">), a[href] (text_url or tg://user mention), span
 * class="tg-spoiler" / tg-spoiler, blockquote (expandable), tg-emoji[emoji-id].
 *
 * Markdown (double-delimiter, Pyrogram style): **bold**, __italic__, ~~strike~~,
 * ||spoiler||, `code`, ```lang\npre```, [text](url|tg://user?id=N).
 */
final class EntityParser
{
    public const MARKDOWN = 'markdown';
    public const HTML     = 'html';

    /**
     * Parse formatted text into [clean text, entities].
     *
     * @return array{text: string, entities: array<int, array<string, mixed>>}
     */
    public function parse(string $text, ?string $mode): array
    {
        return match (strtolower((string) $mode)) {
            self::MARKDOWN, 'markdownv2', 'md' => $this->parseMarkdown($text),
            self::HTML                         => $this->parseHtml($text),
            default                            => ['text' => $text, 'entities' => []],
        };
    }

    // ════════════════════════════════════════════════════════════════════
    //  HTML
    // ════════════════════════════════════════════════════════════════════

    /**
     * @return array{text: string, entities: array<int, array<string, mixed>>}
     */
    public function parseHtml(string $html): array
    {
        $entities = [];
        $stack    = [];
        $out      = '';
        $offset   = 0;
        $i        = 0;
        $len      = strlen($html);

        while ($i < $len) {
            $lt = strpos($html, '<', $i);

            if ($lt === false) {
                $chunk   = html_entity_decode(substr($html, $i), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $out    .= $chunk;
                $offset += $this->utf16Length($chunk);
                break;
            }

            if ($lt > $i) {
                $chunk   = html_entity_decode(substr($html, $i, $lt - $i), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $out    .= $chunk;
                $offset += $this->utf16Length($chunk);
            }

            $gt = strpos($html, '>', $lt);
            if ($gt === false) {
                $chunk   = html_entity_decode(substr($html, $lt), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $out    .= $chunk;
                $offset += $this->utf16Length($chunk);
                break;
            }

            $raw = trim(substr($html, $lt + 1, $gt - $lt - 1));
            $i   = $gt + 1;

            if ($raw === '' ) {
                continue;
            }

            if ($raw[0] === '/') {
                $name = strtolower(trim(substr($raw, 1)));
                if ($name === 'br') {
                    continue;
                }
                $this->closeHtmlTag($name, $offset, $stack, $entities);
                continue;
            }

            // Self-closing <br>
            $bare = rtrim($raw, '/');
            $name = strtolower(strtok($bare, " \t"));
            if ($name === 'br') {
                $out    .= "\n";
                $offset += 1;
                continue;
            }

            $type = $this->htmlTagToType($name, $raw);
            if ($type === null) {
                continue; // unknown tag - drop the markup, keep flow
            }

            $stack[] = [
                'type'   => $type,
                'offset' => $offset,
                'attrs'  => $this->htmlEntityAttrs($type, $raw),
            ];
        }

        // Any unclosed tags are discarded (no length) - emit what closed cleanly.
        return ['text' => $out, 'entities' => $this->sortEntities($entities)];
    }

    private function closeHtmlTag(string $name, int $offset, array &$stack, array &$entities): void
    {
        $type = $this->htmlTagToType($name, $name);
        if ($type === null) {
            return;
        }

        // Pop the nearest open frame of this type.
        for ($k = count($stack) - 1; $k >= 0; $k--) {
            if ($stack[$k]['type'] === $type) {
                $frame  = $stack[$k];
                array_splice($stack, $k, 1);

                $length = $offset - $frame['offset'];
                if ($length > 0) {
                    $entities[] = array_merge(
                        ['_' => $this->entityConstructor($type), 'offset' => $frame['offset'], 'length' => $length],
                        $frame['attrs'],
                    );
                }
                return;
            }
        }
    }

    private function htmlTagToType(string $name, string $raw): ?string
    {
        return match ($name) {
            'b', 'strong'        => 'bold',
            'i', 'em'            => 'italic',
            'u', 'ins'           => 'underline',
            's', 'strike', 'del' => 'strikethrough',
            'code'               => 'code',
            'pre'                => 'pre',
            'a'                  => 'text_url',
            'blockquote'         => 'blockquote',
            'tg-emoji'           => 'custom_emoji',
            'span'               => str_contains($raw, 'tg-spoiler') ? 'spoiler' : null,
            'tg-spoiler'         => 'spoiler',
            default              => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function htmlEntityAttrs(string $type, string $raw): array
    {
        if ($type === 'text_url') {
            // tg://user?id=N as a text_url is rendered as a real mention by clients
            // and serializes cleanly (url:string), unlike inputMessageEntityMentionName
            // which needs a resolved InputUser.
            return ['url' => $this->htmlAttr($raw, 'href') ?? ''];
        }
        if ($type === 'custom_emoji') {
            $id = $this->htmlAttr($raw, 'emoji-id') ?? $this->htmlAttr($raw, 'document-id') ?? '0';
            return ['document_id' => (int) $id];
        }
        if ($type === 'pre') {
            $lang = $this->htmlAttr($raw, 'language');
            return $lang !== null ? ['language' => $lang] : ['language' => ''];
        }
        if ($type === 'blockquote' && (str_contains($raw, 'expandable') || str_contains($raw, 'collapsed'))) {
            return ['collapsed' => true];
        }

        return [];
    }

    private function htmlAttr(string $raw, string $attr): ?string
    {
        if (preg_match('/\b' . preg_quote($attr, '/') . '\s*=\s*"([^"]*)"/i', $raw, $m)) {
            return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        if (preg_match("/\b" . preg_quote($attr, '/') . "\s*=\s*'([^']*)'/i", $raw, $m)) {
            return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return null;
    }

    // ════════════════════════════════════════════════════════════════════
    //  Markdown
    // ════════════════════════════════════════════════════════════════════

    /** @var array<string, string> Delimiter → entity type (longest first when scanning). */
    private const MD_DELIMS = [
        '```' => 'pre',
        '**'  => 'bold',
        '__'  => 'italic',
        '~~'  => 'strikethrough',
        '||'  => 'spoiler',
        '`'   => 'code',
    ];

    /**
     * @return array{text: string, entities: array<int, array<string, mixed>>}
     */
    public function parseMarkdown(string $md): array
    {
        $entities = [];
        $out      = '';
        $offset   = 0;
        $i        = 0;
        $len      = strlen($md);

        while ($i < $len) {
            $ch = $md[$i];

            // Backslash escape: emit next char literally.
            if ($ch === '\\' && $i + 1 < $len) {
                $next    = $md[$i + 1];
                $out    .= $next;
                $offset += $this->utf16Length($next);
                $i      += 2;
                continue;
            }

            // Link: [text](href)
            if ($ch === '[') {
                $parsed = $this->parseMarkdownLink($md, $i, $offset);
                if ($parsed !== null) {
                    [$entity, $rendered, $consumed] = $parsed;
                    $out      .= $rendered;
                    $offset   += $this->utf16Length($rendered);
                    $entities[] = $entity;
                    $i        += $consumed;
                    continue;
                }
            }

            // Fenced / inline code & pre: content is literal (no nested parsing).
            $delim = $this->matchDelimiter($md, $i);
            if ($delim !== null) {
                $type = self::MD_DELIMS[$delim];
                $end  = strpos($md, $delim, $i + strlen($delim));
                if ($end !== false) {
                    $inner = substr($md, $i + strlen($delim), $end - $i - strlen($delim));

                    if ($type === 'pre') {
                        [$lang, $inner] = $this->splitFenceLanguage($inner);
                    }

                    $clean  = ($type === 'code' || $type === 'pre')
                        ? $inner
                        : $this->stripEscapes($inner);

                    $start  = $offset;
                    $out   .= $clean;
                    $length = $this->utf16Length($clean);
                    $offset += $length;

                    if ($length > 0) {
                        $entity = ['_' => $this->entityConstructor($type), 'offset' => $start, 'length' => $length];
                        if ($type === 'pre') {
                            $entity['language'] = $lang;
                        }
                        $entities[] = $entity;
                    }

                    $i = $end + strlen($delim);
                    continue;
                }
            }

            // Consume a whole UTF-8 codepoint (delimiters above are ASCII).
            $charLen = $this->utf8SequenceLength($md[$i]);
            $char    = substr($md, $i, $charLen);
            $out    .= $char;
            $offset += $this->utf16Length($char);
            $i      += $charLen;
        }

        return ['text' => $out, 'entities' => $this->sortEntities($entities)];
    }

    /**
     * Byte length of the UTF-8 sequence whose lead byte is $byte.
     */
    private function utf8SequenceLength(string $byte): int
    {
        $b = ord($byte);

        return match (true) {
            $b < 0x80          => 1,
            ($b >> 5) === 0b110  => 2,
            ($b >> 4) === 0b1110 => 3,
            ($b >> 3) === 0b11110 => 4,
            default            => 1,
        };
    }

    /**
     * Parse `[text](href)` at $pos. Returns [entity, renderedText, bytesConsumed]
     * or null when it is not a well-formed link.
     *
     * @return array{0: array<string, mixed>, 1: string, 2: int}|null
     */
    private function parseMarkdownLink(string $md, int $pos, int $offset): ?array
    {
        if (!preg_match('/\G\[((?:\\\\.|[^\]\\\\])*)\]\(([^)]*)\)/', $md, $m, 0, $pos)) {
            return null;
        }

        $text = $this->stripEscapes($m[1]);

        // tg://user?id=N as text_url renders as a mention client-side and serializes
        // cleanly (url:string), unlike inputMessageEntityMentionName (needs InputUser).
        $entity = [
            '_'      => 'messageEntityTextUrl',
            'offset' => $offset,
            'length' => $this->utf16Length($text),
            'url'    => $m[2],
        ];

        return [$entity, $text, strlen($m[0])];
    }

    /**
     * Longest delimiter starting at $pos, or null.
     */
    private function matchDelimiter(string $md, int $pos): ?string
    {
        foreach (self::MD_DELIMS as $delim => $_) {
            if (substr($md, $pos, strlen($delim)) === $delim) {
                return $delim;
            }
        }
        return null;
    }

    /**
     * Split an optional ```lang\n header off fenced content.
     *
     * @return array{0: string, 1: string}
     */
    private function splitFenceLanguage(string $inner): array
    {
        $nl = strpos($inner, "\n");
        if ($nl !== false) {
            $first = substr($inner, 0, $nl);
            if ($first !== '' && !str_contains($first, ' ')) {
                return [$first, substr($inner, $nl + 1)];
            }
        }
        return ['', $inner];
    }

    private function stripEscapes(string $s): string
    {
        return preg_replace('/\\\\(.)/', '$1', $s) ?? $s;
    }

    // ════════════════════════════════════════════════════════════════════
    //  Shared
    // ════════════════════════════════════════════════════════════════════

    /**
     * Bot-API `type` string for a received TL MessageEntity (null if unmapped).
     *
     * @param array<string, mixed> $entity
     */
    public function botApiType(array $entity): ?string
    {
        return EntityType::toBotApi($entity);
    }

    private function entityConstructor(string $type): string
    {
        return match ($type) {
            'bold'          => 'messageEntityBold',
            'italic'        => 'messageEntityItalic',
            'underline'     => 'messageEntityUnderline',
            'strikethrough' => 'messageEntityStrike',
            'code'          => 'messageEntityCode',
            'pre'           => 'messageEntityPre',
            'text_url'      => 'messageEntityTextUrl',
            'spoiler'       => 'messageEntitySpoiler',
            'blockquote'    => 'messageEntityBlockquote',
            'custom_emoji'  => 'messageEntityCustomEmoji',
            default         => 'messageEntityUnknown',
        };
    }

    /**
     * UTF-16 code-unit length of a UTF-8 string (codepoints > U+FFFF count as 2).
     */
    private function utf16Length(string $s): int
    {
        if ($s === '') {
            return 0;
        }

        $units = 0;
        $chars = preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($chars as $char) {
            $units += mb_ord($char, 'UTF-8') > 0xFFFF ? 2 : 1;
        }
        return $units;
    }

    /**
     * @param array<int, array<string, mixed>> $entities
     * @return array<int, array<string, mixed>>
     */
    private function sortEntities(array $entities): array
    {
        usort($entities, static fn ($a, $b) => $a['offset'] <=> $b['offset']);

        foreach ($entities as &$entity) {
            unset($entity['__type']);
        }

        return $entities;
    }
}
