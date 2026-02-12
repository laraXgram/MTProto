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
        // Messages
        'onmessage', 'ontext', 'oneditedmessage', 'ondeletedmessages',
        'onpinnedmessages', 'onscheduledmessage',

        // Media-filtered
        'onphoto', 'onvideo', 'onanimation', 'onsticker', 'ondocument',
        'onaudio', 'onvoice', 'onvideonote', 'oncontact', 'onlocation',
        'onvenue', 'ongame', 'ondice',

        // Callback / Inline
        'oncallbackquery', 'oncallbackquerydata', 'oninlinequery', 'onchoseninlineresult',

        // Typing / Read
        'ontyping', 'onreadhistory',

        // Reactions
        'onreactions',

        // Users
        'onuserstatus',

        // Participants
        'onchatparticipant', 'onchatjoinrequest', 'onchatboost',

        // Polls
        'onpoll', 'onpollvote',

        // Payments
        'onprecheckoutquery', 'onshippingquery',

        // Phone / Group calls
        'onphonecall', 'ongroupcall',

        // Stories
        'onstory',

        // Encrypted
        'onencryptedmessage',

        // Drafts
        'ondraft',

        // Notifications
        'onservicenotification',

        // Peers
        'onpeerblocked',

        // Bots
        'onbotstopped', 'onbotcommands', 'onbotreaction',

        // Bot Business
        'onbusinessmessage', 'onbusinessconnect',

        // Forum
        'onforumtopic',

        // Catch-all
        'onupdate',
    ];
}
