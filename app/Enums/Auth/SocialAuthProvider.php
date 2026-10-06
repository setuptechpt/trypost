<?php

declare(strict_types=1);

namespace App\Enums\Auth;

enum SocialAuthProvider: string
{
    case Google = 'google';
    case GitHub = 'github';
    case Authentik = 'authentik';

    public function label(): string
    {
        return match ($this) {
            self::Google => 'Google',
            self::GitHub => 'GitHub',
            self::Authentik => (string) config('trypost.authentik_auth_label'),
        };
    }

    public function isEnabled(): bool
    {
        return (bool) config("trypost.{$this->value}_auth_enabled");
    }
}
