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
 *     int|string → auto-resolved via PeerResolver
 *
 *  2. **random_id auto-generation**
 *     long (or Vector<long>) → cryptographically random value
 *
 *  3. **random_bytes auto-generation**
 *     bytes → random_bytes(256) for secure methods
 *
 *  4. **reply_to shorthand**
 *     int → inputReplyToMessage{reply_to_msg_id: ...}
 *
 *  5. **DataJSON auto-wrapping**
 *     mixed → ['_' => 'dataJSON', 'data' => json_encode($value)]
 *
 *  6. **InputMessage int shorthand**
 *     int → ['_' => 'inputMessageID', 'id' => $value]
 *
 *  7. **InputDialogPeer auto-wrapping**
 *     int|string → resolves peer then wraps in inputDialogPeer
 *
 *  8. **TextWithEntities string shorthand**
 *     string → ['_' => 'textWithEntities', 'text' => $value, 'entities' => []]
 *
 *  9. **DateTime → timestamp conversion**
 *     DateTimeInterface → int unix timestamp for schedule_date etc.
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
                if (isset($params[$name])) {
                    if ($param->isVector() && is_array($params[$name])) {
                        // Vector<InputPeer> — resolve each element
                        $params[$name] = array_map(function ($item) use ($type) {
                            return $this->isAlreadyTLObject($item) ? $item : $this->resolvePeerParam($item, $type);
                        }, $params[$name]);
                    } elseif (!$this->isAlreadyTLObject($params[$name])) {
                        // Single peer
                        $params[$name] = $this->resolvePeerParam($params[$name], $type);
                    }
                }
                continue;
            }

            // ── Auto-generate random_id ─────────────────────────────────
            if ($name === 'random_id' && in_array($type, ['long', 'int'], true) && !isset($params[$name])) {
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

            // ── Auto-generate random_bytes ──────────────────────────────
            // Covers both standalone 'random_bytes' param and 'random_id' of type 'bytes'
            if ($type === 'bytes' && in_array($name, ['random_bytes', 'random_id'], true) && !isset($params[$name])) {
                $params[$name] = random_bytes(256);
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

            // ── DataJSON auto-wrapping (mixed → dataJSON constructor) ───
            if ($type === 'DataJSON' && isset($params[$name])) {
                if (!$this->isAlreadyTLObject($params[$name])) {
                    $params[$name] = [
                        '_'    => 'dataJSON',
                        'data' => is_string($params[$name]) ? $params[$name] : json_encode($params[$name]),
                    ];
                }
                continue;
            }

            // ── InputMessage shorthand (int → inputMessageID) ───────────
            if ($type === 'InputMessage' && isset($params[$name])) {
                if (is_int($params[$name])) {
                    $params[$name] = [
                        '_'  => 'inputMessageID',
                        'id' => $params[$name],
                    ];
                }
                // Handle Vector<InputMessage>
                if ($param->isVector() && is_array($params[$name])) {
                    $params[$name] = array_map(function ($item) {
                        if (is_int($item)) {
                            return ['_' => 'inputMessageID', 'id' => $item];
                        }
                        return $item;
                    }, $params[$name]);
                }
                continue;
            }

            // ── InputDialogPeer auto-wrapping ───────────────────────────
            if ($type === 'InputDialogPeer' && isset($params[$name])) {
                if (!$this->isAlreadyTLObject($params[$name])) {
                    $peer = $this->resolvePeerParam($params[$name], 'InputPeer');
                    $params[$name] = [
                        '_'    => 'inputDialogPeer',
                        'peer' => $peer,
                    ];
                }
                // Handle Vector<InputDialogPeer>
                if ($param->isVector() && is_array($params[$name])) {
                    $params[$name] = array_map(function ($item) {
                        if (!$this->isAlreadyTLObject($item)) {
                            $peer = $this->resolvePeerParam($item, 'InputPeer');
                            return ['_' => 'inputDialogPeer', 'peer' => $peer];
                        }
                        return $item;
                    }, $params[$name]);
                }
                continue;
            }

            // ── TextWithEntities string shorthand ───────────────────────
            if ($type === 'TextWithEntities' && isset($params[$name])) {
                if (is_string($params[$name])) {
                    $params[$name] = [
                        '_'        => 'textWithEntities',
                        'text'     => $params[$name],
                        'entities' => [],
                    ];
                }
                continue;
            }

            // ── DateTime → timestamp conversion ─────────────────────────
            if ($type === 'int' && isset($params[$name]) && $params[$name] instanceof \DateTimeInterface) {
                $params[$name] = $params[$name]->getTimestamp();
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
