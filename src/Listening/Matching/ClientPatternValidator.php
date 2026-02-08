<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Listening\Matching;

use LaraGram\Listening\Listen;
use LaraGram\MTProto\Foundation\ClientRequest;

/**
 * Pattern matching for MTProto Client updates.
 */
class ClientPatternValidator
{
    /**
     * Validate a listen's pattern against a ClientRequest.
     *
     * @param Listen $listen
     * @param ClientRequest $request
     * @return bool
     */
    public function matchesClient(Listen $listen, ClientRequest $request): bool
    {
        $verb = $request->method();
        $pattern = $listen->pattern();

        // Compile the listen to get the regex
        $compiled = $listen->getCompiled();
        if ($compiled === null) {
            // Force compilation via reflection or try matching raw
            try {
                $refMethod = new \ReflectionMethod($listen, 'compileListen');
                $refMethod->setAccessible(true);
                $refMethod->invoke($listen);
                $compiled = $listen->getCompiled();
            } catch (\Throwable) {
                return false;
            }
        }

        $regex = $compiled?->getRegex();

        return match ($verb) {
            'NEW_MESSAGE'      => $this->matchText($request, $regex, $pattern),
            'EDIT_MESSAGE'     => $this->matchText($request, $regex, $pattern),
            'CALLBACK_QUERY'   => $this->matchCallbackData($request, $regex),
            'INLINE_QUERY'     => $this->matchInlineQuery($request, $regex),
            'DELETED_MESSAGES' => true,
            'TYPING'           => true,
            'READ_HISTORY'     => true,
            'REACTIONS'        => true,
            'USER_STATUS'      => true,
            'CHAT_PARTICIPANT' => true,
            'PRE_CHECKOUT'     => true,
            'SHIPPING'         => true,
            'UPDATE'           => true,
            default            => false,
        };
    }

    /**
     * Match against message text (for NEW_MESSAGE and EDIT_MESSAGE).
     */
    protected function matchText(ClientRequest $request, ?string $regex, string $pattern): bool
    {
        if ($regex === null) {
            return false;
        }

        $text = $request->text() ?? '';

        return (bool) preg_match($regex, $text);
    }

    /**
     * Match against callback query data.
     */
    protected function matchCallbackData(ClientRequest $request, ?string $regex): bool
    {
        if ($regex === null) {
            return false;
        }

        $data = $request->callbackData() ?? '';

        return (bool) preg_match($regex, $data);
    }

    /**
     * Match against inline query string.
     */
    protected function matchInlineQuery(ClientRequest $request, ?string $regex): bool
    {
        if ($regex === null) {
            return false;
        }

        $query = $request->inlineQuery() ?? '';

        return (bool) preg_match($regex, $query);
    }
}
