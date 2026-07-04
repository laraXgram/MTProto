<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Listening;

use LaraGram\Listening\ListenRegistrar;

class ClientListenRegistrar extends ListenRegistrar
{
    /**
     * Scope the following listens to one or more sessions (accounts).
     *
     * @param  array|string  $sessions
     * @return $this
     */
    public function forSessions(array|string $sessions)
    {
        return $this->attribute('for_connections', (array) $sessions);
    }

    /**
     * Scope the following listens to incoming messages only (received, not sent by this session).
     *
     * @return $this
     */
    public function incomming()
    {
        return $this->attribute('middleware', array_merge(
            (array) ($this->attributes['middleware'] ?? []), ['direction:in']
        ));
    }

    /**
     * Scope the following listens to outgoing messages only (sent by this session).
     *
     * @return $this
     */
    public function outgoing()
    {
        return $this->attribute('middleware', array_merge(
            (array) ($this->attributes['middleware'] ?? []), ['direction:out']
        ));
    }


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
