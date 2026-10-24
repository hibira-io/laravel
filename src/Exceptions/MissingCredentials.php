<?php

namespace Hibira\Laravel\Exceptions;

use RuntimeException;

class MissingCredentials extends RuntimeException
{
    protected const ENV = [
        'app_id' => 'HIBIRA_APP_ID',
        'key' => 'HIBIRA_APP_KEY',
        'secret' => 'HIBIRA_APP_SECRET',
        'instance_id' => 'HIBIRA_PUSH_INSTANCE_ID',
        'secret_key' => 'HIBIRA_PUSH_SECRET_KEY',
    ];

    public static function realtime(string $name): self
    {
        return new self(sprintf(
            'Hibira: hibira.%s is not set. Copy it from your app page on hibira.io into %s in .env.',
            $name, self::ENV[$name],
        ));
    }

    public static function push(string $name): self
    {
        return new self(sprintf(
            'Hibira: hibira.push.%s is not set. Enable push on your app page on hibira.io and copy it into %s in .env.',
            $name, self::ENV[$name],
        ));
    }
}
