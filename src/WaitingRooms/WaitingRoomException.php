<?php

namespace Hibira\Laravel\WaitingRooms;

use RuntimeException;

class WaitingRoomException extends RuntimeException
{
    /** @var int HTTP status from Hibira (0 when the request never got an answer) */
    public $status;

    public function __construct(string $message, int $status = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $status, $previous);
        $this->status = $status;
    }

    public function notFound(): bool
    {
        return $this->status === 404;
    }
}
