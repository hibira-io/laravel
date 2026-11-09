<?php

namespace Hibira\Laravel\WaitingRooms;

use DateTimeInterface;

/**
 * One waiting room. Every method except url() and verify() calls Hibira and
 * returns the room as the API answers it:
 * room, title, state, admitted, issued, waiting, admit_per_minute,
 * starts_at, notice, channel, burst, pass_ttl_seconds, return_urls, created_at,
 * updated_at.
 */
class WaitingRoom
{
    /** @var WaitingRooms */
    protected $rooms;

    /** @var string */
    protected $name;

    public function __construct(WaitingRooms $rooms, string $name)
    {
        if (! preg_match('/^[A-Za-z0-9_-]{1,64}$/', $name)) {
            throw new WaitingRoomException("A waiting room name is 1 to 64 letters, digits, '-' or '_' (got \"$name\").", 400);
        }
        $this->rooms = $rooms;
        $this->name = $name;
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * Creates the room, or changes it if it exists.
     *
     * @param  array  $options  title, state, starts_at, burst, pass_ttl_seconds, return_urls
     */
    public function create(int $admitPerMinute, array $options = []): array
    {
        return $this->update(['admit_per_minute' => $admitPerMinute] + $options);
    }

    /** Changes any of admit_per_minute, state, starts_at, burst, pass_ttl_seconds, title, notice, return_urls. */
    public function update(array $fields): array
    {
        if (array_key_exists('starts_at', $fields) && $fields['starts_at'] instanceof DateTimeInterface) {
            $fields['starts_at'] = $fields['starts_at']->format(DATE_RFC3339);
        } elseif (array_key_exists('starts_at', $fields) && $fields['starts_at'] === null) {
            $fields['starts_at'] = '';
        }

        return $this->rooms->request('POST', $this->path(), $fields);
    }

    /** The room and its live counters. */
    public function get(): array
    {
        return $this->rooms->request('GET', $this->path());
    }

    /** The room, or null when it does not exist. */
    public function find(): ?array
    {
        try {
            return $this->get();
        } catch (WaitingRoomException $e) {
            if ($e->notFound()) {
                return null;
            }
            throw $e;
        }
    }

    /** Admit at the room's rate. */
    public function open(): array
    {
        return $this->update(['state' => 'open']);
    }

    /** Keep the queue (and new joins) but admit nobody. */
    public function pause(): array
    {
        return $this->update(['state' => 'paused']);
    }

    /** Refuse new queue numbers (sold out). Passes already issued stay valid. */
    public function close(): array
    {
        return $this->update(['state' => 'closed']);
    }

    /** New admissions per minute. */
    public function rate(int $admitPerMinute): array
    {
        return $this->update(['admit_per_minute' => $admitPerMinute]);
    }

    /**
     * An announcement on the hosted waiting page (at most 280 characters;
     * null or "" removes it). Needs a Hibira server from November 2026 on.
     */
    public function notice(?string $text): array
    {
        return $this->update(['notice' => (string) $text]);
    }

    /** Move the line forward by $count numbers now (works while paused). */
    public function admit(int $count): array
    {
        return $this->rooms->request('POST', $this->path('/admit'), ['count' => $count]);
    }

    public function delete(): void
    {
        $this->rooms->request('DELETE', $this->path());
    }

    /** The hosted waiting page, which sends admitted visitors to $returnUrl. */
    public function url(?string $returnUrl = null, ?string $lang = null): string
    {
        return $this->rooms->url($this->name, $returnUrl, $lang);
    }

    /** Checks a pass for this room locally. */
    public function verify(?string $pass): ?Pass
    {
        return $this->rooms->verifyPass($pass, $this->name);
    }

    protected function path(string $suffix = ''): string
    {
        return '/waiting_rooms/'.$this->name.$suffix;
    }
}
