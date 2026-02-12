<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Listening;

use LaraGram\Listening\Listen;
use LaraGram\MTProto\Foundation\ClientRequest;
use LaraGram\Support\Arr;

/**
 * Parameter binder for MTProto Client listens.
 *
 * Replaces the default ListenParameterBinder which relies on the
 * global text() helper (Bot API only). This version extracts the
 * match-able text directly from the ClientRequest object.
 */
class ClientListenParameterBinder
{
    /**
     * The listen instance.
     */
    protected Listen $listen;

    public function __construct(Listen $listen)
    {
        $this->listen = $listen;
    }

    /**
     * Get the parameters for the listen.
     */
    public function parameters(ClientRequest $request): array
    {
        $parameters = $this->bindPathParameters($request);

        return $this->replaceDefaults($parameters);
    }

    /**
     * Get the parameter matches for the path portion.
     */
    protected function bindPathParameters(ClientRequest $request): array
    {
        $path = $this->extractContent($request);

        $compiled = $this->listen->getCompiled();
        if ($compiled === null) {
            return [];
        }

        preg_match($compiled->getRegex(), $path ?? '', $matches);

        return $this->matchToKeys(array_slice($matches, 1));
    }

    /**
     * Extract the matchable text content from a ClientRequest.
     *
     * Depending on the verb, we return:
     *   - NEW_MESSAGE / EDIT_MESSAGE: message text
     *   - CALLBACK_QUERY:            callback data
     *   - INLINE_QUERY:              query string
     *   - Media verbs (PHOTO etc.):  caption / message text
     *   - Other verbs:               empty string (no pattern matching)
     */
    protected function extractContent(ClientRequest $request): string
    {
        $verb = $request->method();

        return match ($verb) {
            'NEW_MESSAGE', 'EDIT_MESSAGE' => $request->text() ?? '',
            'CALLBACK_QUERY'              => $request->callbackData() ?? '',
            'INLINE_QUERY'                => $request->inlineQuery() ?? '',
            'PHOTO', 'VIDEO', 'ANIMATION', 'STICKER', 'DOCUMENT',
            'AUDIO', 'VOICE', 'VIDEO_NOTE', 'CONTACT_MEDIA',
            'LOCATION', 'VENUE', 'GAME', 'DICE' => $request->text() ?? '',
            default                       => '',
        };
    }

    /**
     * Combine a set of parameter matches with the listen's keys.
     */
    protected function matchToKeys(array $matches): array
    {
        $parameterNames = $this->listen->parameterNames();

        if (empty($parameterNames)) {
            return [];
        }

        $parameters = array_intersect_key($matches, array_flip($parameterNames));

        return array_filter($parameters, function ($value) {
            return is_string($value) && strlen($value) > 0;
        });
    }

    /**
     * Replace null parameters with their defaults.
     */
    protected function replaceDefaults(array $parameters): array
    {
        foreach ($parameters as $key => $value) {
            $parameters[$key] = $value ?? Arr::get($this->listen->defaults, $key);
        }

        foreach ($this->listen->defaults as $key => $value) {
            if (!isset($parameters[$key])) {
                $parameters[$key] = $value;
            }
        }

        return $parameters;
    }
}
