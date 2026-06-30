<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Core;

use LaraGram\MTProto\Entities\EntityParser;
use LaraGram\MTProto\TL\TLParser;

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

    private ?EntityParser $entityParser = null;

    public function __construct(
        private readonly PeerResolver $resolver,
        private readonly TLParser     $parser,
    )
    {
    }

    /**
     * Convert `parse_mode` ("markdown"|"html") into a `message` + `entities` pair
     * for any method that carries both fields. Explicit `entities` win; the
     * non-TL `parse_mode` key is stripped either way.
     */
    private function applyParseMode($methodDef, array $params): array
    {
        if (!array_key_exists('parse_mode', $params)) {
            return $params;
        }

        $mode = $params['parse_mode'];
        unset($params['parse_mode']);

        $hasEntities = false;
        $textField = null;
        foreach ($methodDef->getParams() as $param) {
            $n = $param->getName();
            if ($n === 'entities') {
                $hasEntities = true;
            }
            if ($n === 'message' || $n === 'caption') {
                $textField = $n;
            }
        }

        if (!$hasEntities || $textField === null || !is_string($params[$textField] ?? null)) {
            return $params;
        }
        if (!empty($params['entities'])) {
            return $params; // caller supplied entities explicitly
        }

        $this->entityParser ??= new EntityParser();
        $parsed = $this->entityParser->parse($params[$textField], is_string($mode) ? $mode : null);

        $params[$textField] = $parsed['text'];
        if ($parsed['entities'] !== []) {
            $params['entities'] = $parsed['entities'];
        }

        return $params;
    }

    /**
     * Process method params before serialisation.
     *
     * @param string $method
     * @param array $params
     * @return array
     */
    public function process(string $method, array $params): array
    {
        $methodDef = $this->parser->getMethod($method);
        if ($methodDef === null) {
            return $params;
        }

        $params = $this->applyParseMode($methodDef, $params);

        foreach ($methodDef->getParams() as $param) {
            $name = $param->getName();
            $type = $param->getType();

            if ($type === '#' || $type === 'true') {
                continue;
            }

            if (in_array($type, self::PEER_TYPES, true)) {
                if (isset($params[$name])) {
                    if ($param->isVector() && is_array($params[$name])) {
                        $params[$name] = array_map(function ($item) use ($type) {
                            return $this->isAlreadyTLObject($item) ? $item : $this->resolvePeerParam($item, $type);
                        }, $params[$name]);
                    } elseif (!$this->isAlreadyTLObject($params[$name])) {
                        $params[$name] = $this->resolvePeerParam($params[$name], $type);
                    }
                }
                continue;
            }

            if ($name === 'random_id' && in_array($type, ['long', 'int'], true) && !isset($params[$name])) {
                if ($param->isVector()) {
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

            if ($type === 'bytes' && in_array($name, ['random_bytes', 'random_id'], true) && !isset($params[$name])) {
                $params[$name] = random_bytes(256);
                continue;
            }

            if ($name === 'reply_to' && $type === 'InputReplyTo') {
                if (isset($params[$name]) && is_int($params[$name])) {
                    $params[$name] = [
                        '_' => 'inputReplyToMessage',
                        'reply_to_msg_id' => $params[$name],
                    ];
                }
                continue;
            }

            if ($type === 'DataJSON' && isset($params[$name])) {
                if (!$this->isAlreadyTLObject($params[$name])) {
                    $params[$name] = [
                        '_' => 'dataJSON',
                        'data' => is_string($params[$name]) ? $params[$name] : json_encode($params[$name]),
                    ];
                }
                continue;
            }

            if ($type === 'InputMessage' && isset($params[$name])) {
                if (is_int($params[$name])) {
                    $params[$name] = [
                        '_' => 'inputMessageID',
                        'id' => $params[$name],
                    ];
                }

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

            if ($type === 'InputDialogPeer' && isset($params[$name])) {
                if (!$this->isAlreadyTLObject($params[$name])) {
                    $peer = $this->resolvePeerParam($params[$name], 'InputPeer');
                    $params[$name] = [
                        '_' => 'inputDialogPeer',
                        'peer' => $peer,
                    ];
                }
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

            if ($type === 'TextWithEntities' && isset($params[$name])) {
                if (is_string($params[$name])) {
                    $params[$name] = [
                        '_' => 'textWithEntities',
                        'text' => $params[$name],
                        'entities' => [],
                    ];
                }
                continue;
            }

            if ($type === 'ReplyMarkup' && isset($params[$name])) {
                $v = $params[$name];

                if (is_object($v) && method_exists($v, 'get')) {
                    $v = $v->get(true);
                }

                if (is_string($v)) {
                    $decoded = json_decode($v, true);
                    if (is_array($decoded)) {
                        $v = $decoded;
                    }
                }

                if (is_array($v) && !$this->isAlreadyTLObject($v)) {
                    $params[$name] = \LaraGram\MTProto\Foundation\ReplyMarkup::toTl($v);
                } else {
                    $params[$name] = $v;
                }
                continue;
            }

            if ($type === 'int' && isset($params[$name]) && $params[$name] instanceof \DateTimeInterface) {
                $params[$name] = $params[$name]->getTimestamp();
                continue;
            }
        }

        return $params;
    }

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
            'InputPeer' => $this->resolver->resolveInputPeer($value),
            'InputUser' => $this->resolver->resolveInputUser($value),
            'InputChannel' => $this->resolver->resolveInputChannel($value),
            default => throw new \LogicException("Unsupported auto-resolve type: {$type}"),
        };
    }
}
