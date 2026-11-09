<?php

namespace Hibira\Laravel\WaitingRooms;

/**
 * A verified waiting room pass: the visitor's queue number came up.
 */
class Pass
{
    /** @var string */
    public $appId;

    /** @var string */
    public $room;

    /** @var int */
    public $number;

    /** @var string */
    public $ticketId;

    /** @var int Unix seconds */
    public $issuedAt;

    /** @var int Unix seconds */
    public $expiresAt;

    public function __construct(array $claims)
    {
        $this->appId = (string) ($claims['app'] ?? '');
        $this->room = (string) ($claims['room'] ?? '');
        $this->number = (int) ($claims['n'] ?? 0);
        $this->ticketId = (string) ($claims['tid'] ?? '');
        $this->issuedAt = (int) ($claims['iat'] ?? 0);
        $this->expiresAt = (int) ($claims['exp'] ?? 0);
    }

    /** Seconds until the pass expires. */
    public function secondsLeft(?int $now = null): int
    {
        return max(0, $this->expiresAt - ($now ?? time()));
    }
}
