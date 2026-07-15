<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Core\Concerns;

/**
 * @mixin \LaraGram\MTProto\Core\Client
 */
trait ManagesCommunities
{
    /**
     * Create a new community anchored on `$peer`.
     */
    public function createCommunity(
        string $title,
        string|int|array $peer,
        ?string $about = null,
        bool $hidden = false,
    ): mixed {
        $params = [
            'title' => $title,
            'peer' => $peer,
        ];
        if ($about !== null) {
            $params['about'] = $about;
        }
        if ($hidden) {
            $params['hidden'] = true;
        }

        return $this->invoke('communities.create', $params);
    }

    /**
     * List the communities the current account has joined.
     */
    public function getJoinedCommunities(): mixed
    {
        return $this->invoke('communities.getJoinedCommunities');
    }

    /**
     * Link (or unlink) a peer into a community.
     *
     * Pass `$visible`/`$hidden` to control listing, or `$deleted: true` to
     * remove the link entirely.
     */
    public function toggleCommunityPeerLink(
        string|int|array $community,
        string|int|array $peer,
        bool $visible = false,
        bool $hidden = false,
        bool $deleted = false,
    ): mixed {
        $params = [
            'community' => $community,
            'peer' => $peer,
        ];
        if ($visible) {
            $params['visible'] = true;
        }
        if ($hidden) {
            $params['hidden'] = true;
        }
        if ($deleted) {
            $params['deleted'] = true;
        }

        return $this->invoke('communities.togglePeerLink', $params);
    }

    /**
     * Collapse/expand a community's entry in the dialog list.
     */
    public function toggleCommunityCollapsed(
        string|int|array $community,
        bool $collapsed = true,
    ): mixed {
        $params = ['community' => $community];
        if ($collapsed) {
            $params['collapsed'] = true;
        }

        return $this->invoke('communities.toggleCommunityCollapsedInDialogs', $params);
    }

    /**
     * Fetch pending peer-link requests for a community (paginated).
     */
    public function getCommunityPeerLinkRequests(
        string|int|array $community,
        string $offset = '',
        int $limit = 100,
    ): mixed {
        return $this->invoke('communities.getPeerLinkRequests', [
            'community' => $community,
            'offset' => $offset,
            'limit' => $limit,
        ]);
    }

    /**
     * Approve or reject a single pending peer-link request.
     */
    public function approveCommunityPeerLink(
        string|int|array $community,
        string|int|array $peer,
        bool $reject = false,
    ): mixed {
        $params = [
            'community' => $community,
            'peer' => $peer,
        ];
        if ($reject) {
            $params['reject'] = true;
        }

        return $this->invoke('communities.togglePeerLinkRequestApproval', $params);
    }

    /**
     * Approve or reject every pending peer-link request in one call.
     */
    public function approveAllCommunityPeerLinks(
        string|int|array $community,
        bool $reject = false,
    ): mixed {
        $params = ['community' => $community];
        if ($reject) {
            $params['reject'] = true;
        }

        return $this->invoke('communities.toggleAllPeerLinkRequestApproval', $params);
    }

    /**
     * Ban (or unban) a participant across the community.
     */
    public function toggleCommunityParticipantBanned(
        string|int|array $community,
        string|int|array $participant,
        bool $unban = false,
    ): mixed {
        $params = [
            'community' => $community,
            'participant' => $participant,
        ];
        if ($unban) {
            $params['unban'] = true;
        }

        return $this->invoke('communities.toggleParticipantBanned', $params);
    }

    /**
     * List the chats within a community that a participant has joined.
     */
    public function getCommunityParticipantJoinedChats(
        string|int|array $community,
        string|int|array $participant,
    ): mixed {
        return $this->invoke('communities.getParticipantJoinedChats', [
            'community' => $community,
            'participant' => $participant,
        ]);
    }
}
