<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Session\Import;

/**
 * Decoder for one foreign session format (Pyrogram / Telethon / MadelineProto).
 */
interface SessionSource
{
    /**
     * Short machine name of the format: pyrogram|telethon|madeline.
     */
    public function name(): string;

    /**
     * Best-effort guess whether $input looks like this format. Used by the
     * `--from=auto` autodetection path; never throws.
     */
    public function supports(string $input): bool;

    /**
     * Decode the given input (session string or path) into a ForeignSession.
     *
     * @throws \RuntimeException on malformed / unsupported input.
     */
    public function parse(string $input): ForeignSession;
}
