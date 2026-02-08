<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Listening;

use LaraGram\Listening\ListenRegistrar;

/**
 * Client Listen Registrar — fluent proxy for the ClientListener.
 */
class ClientListenRegistrar extends ListenRegistrar
{
    /**
     * The methods to dynamically pass through to the listener.
     *
     * @var string[]
     */
    protected $passthru = [
        'onmessage', 'ontext', 'oneditedmessage', 'ondeletedmessages',
        'oncallbackquery', 'oncallbackquerydata', 'oninlinequery',
        'ontyping', 'onreadhistory', 'onreactions',
        'onuserstatus', 'onchatparticipant',
        'onprecheckoutquery', 'onshippingquery',
        'onupdate',
    ];
}
