<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Core;

use LaraGram\MTProto\TL\TLParser;

/**
 * Automatic parameter preprocessor for Telegram API methods.
 *
 * Before each RPC call the preprocessor inspects the TL method schema
 * and applies these transformations:
 *
 *  1. **InputPeer / InputUser / InputChannel resolution**
 *     If a parameter has an Input* TL type and the user passed a simple
 *     int or string, it is automatically resolved via PeerResolver.
 *
 *  2. **random_id auto-generation**
 *     If the method requires `random_id:long` (or a vector of them) and
 *     the caller did not provide it, a cryptographically random value is
 *     generated automatically.
 *
 *  3. **reply_to shorthand**
 *     If `reply_to` is passed as a plain int, it is wrapped into
 *     `inputReplyToMessage{reply_to_msg_id: ...}` automatically.
 *
 *  4. **InputMedia shorthand**
 *     (Reserved for future expansion.)
 */
class ParamPreprocessor
{
    /**
     * TL types that should be auto-resolved from int|string.
     */
    private const PEER_TYPES = [
        'InputPeer',
        'InputUser',
        'InputChannel',
    ];

    public function __construct(
        private readonly PeerResolver $resolver,
        private readonly TLParser     $parser,
    ) {}

    /**
     * Process method params before serialisation.
     *
     * @param  string $method  e.g. "messages.sendMessage"
     * @param  array  $params  User-supplied parameters
     * @return array           Transformed parameters
     */
    public function process(string $method, array $params): array
    {
        $methodDef = $this->parser->getMethod($method);
        if ($methodDef === null) {
            return $params; // unknown method — pass through
        }

        foreach ($methodDef->getParams() as $param) {
            $name = $param->getName();
            $type = $param->getType();

            // ── Skip flags field ────────────────────────────────────────
            if ($type === '#' || $type === 'true') {
                continue;
            }

            // ── Auto-resolve peer types ─────────────────────────────────
            if (in_array($type, self::PEER_TYPES, true)) {
                if (isset($params[$name]) && !$this->isAlreadyTLObject($params[$name])) {
                    $params[$name] = $this->resolvePeerParam($params[$name], $type);
                }

                // Also handle Vector<InputPeer>, Vector<InputUser>, etc.
                if ($param->isVector() && isset($params[$name]) && is_array($params[$name])) {
                    $params[$name] = array_map(function ($item) use ($type) {
                        return $this->isAlreadyTLObject($item) ? $item : $this->resolvePeerParam($item, $type);
                    }, $params[$name]);
                }

                continue;
            }

            // ── Auto-generate random_id ─────────────────────────────────
            if ($name === 'random_id' && $type === 'long' && !isset($params[$name])) {
                if ($param->isVector()) {
                    // For forwardMessages: need one random_id per forwarded message
                    $count = count($params['id'] ?? []);
                    $params[$name] = [];
                    for ($i = 0; $i < max(1, $count); $i++) {
                        $params[$name][] = random_int(PHP_INT_MIN, PHP_INT_MAX);
                    }
                } else {
                    $params[$name] = random_int(PHP_INT_MIN, PHP_INT_MAX);
                }
                continue;
            }

            // ── reply_to shorthand (int → inputReplyToMessage) ──────────
            if ($name === 'reply_to' && $type === 'InputReplyTo') {
                if (isset($params[$name]) && is_int($params[$name])) {
                    $params[$name] = [
                        '_'               => 'inputReplyToMessage',
                        'reply_to_msg_id' => $params[$name],
                    ];
                }
                continue;
            }
        }

        return $params;
    }

    // ================================================================
    //  Helpers
    // ================================================================

    /**
     * Check if a value is already a TL object array (has '_' key).
     */
    private function isAlreadyTLObject(mixed $value): bool
    {
        return is_array($value) && isset($value['_']);
    }

    /**
     * Resolve a simple peer value based on the expected TL type.
     */
    private function resolvePeerParam(int|string $value, string $type): array
    {
        return match ($type) {
            'InputPeer'    => $this->resolver->resolveInputPeer($value),
            'InputUser'    => $this->resolver->resolveInputUser($value),
            'InputChannel' => $this->resolver->resolveInputChannel($value),
            default        => throw new \LogicException("Unsupported auto-resolve type: {$type}"),
        };
    }
}
