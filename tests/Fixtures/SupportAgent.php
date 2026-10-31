<?php

namespace Hibira\Laravel\Tests\Fixtures;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

/** Loaded only when laravel/ai is installed. */
class SupportAgent implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'You answer support questions.';
    }
}
