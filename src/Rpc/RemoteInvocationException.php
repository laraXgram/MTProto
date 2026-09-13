<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Rpc;

use LaraGram\MTProto\Exceptions\MTProtoException;

class RemoteInvocationException extends MTProtoException
{
    public function __construct(string $message, int $code = 0, public readonly string $remoteClass = MTProtoException::class)
    {
        parent::__construct($message, $code);
    }
}
