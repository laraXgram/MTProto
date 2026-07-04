<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Listening;

use LaraGram\Listening\Listen;

class ClientListen extends Listen
{
    /**
     * Specify that "direction:in" middleware should be applied to the listen.
     *
     * @return $this
     */
    public function incomming()
    {
        return $this->middleware(['direction:in']);
    }

    /**
     * Specify that "direction:out" middleware should be applied to the listen.
     *
     * @return $this
     */
    public function outgoing()
    {
        return $this->middleware(['direction:out']);
    }
}
