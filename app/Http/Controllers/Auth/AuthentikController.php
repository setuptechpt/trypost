<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\Auth\SocialAuthProvider;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Contracts\User as SocialUser;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

/**
 * OIDC login against Authentik. Signs in accounts that already exist; it never
 * creates users or workspaces, even when open registration is enabled.
 */
class AuthentikController extends Controller
{
    public function redirect(): RedirectResponse
    {
        abort_unless(SocialAuthProvider::Authentik->isEnabled(), 404);

        return Socialite::driver('authentik')
            ->setScopes(['openid', 'email', 'profile'])
            ->redirect();
    }

    public function callback(): RedirectResponse
    {
        abort_unless(SocialAuthProvider::Authentik->isEnabled(), 404);

        try {
            $authentikUser = Socialite::driver('authentik')->user();
        } catch (Throwable $e) {
            report($e);

            return $this->backToLogin(__('auth.authentik_failed', ['provider' => SocialAuthProvider::Authentik->label()]));
        }

        $authentikId = (string) $authentikUser->getId();

        // `guest` middleware gates login; `auth` gates the settings connect flow.
        if (Auth::check()) {
            return $this->connectToCurrentUser(Auth::user(), $authentikId);
        }

        $user = User::where('authentik_id', $authentikId)->first() ?? $this->findByVerifiedEmail($authentikUser);

        if (! $user) {
            return $this->backToLogin(__('auth.authentik_no_account'));
        }

        if (! $user->authentik_id) {
            $user->update(['authentik_id' => $authentikId]);
        }

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        Auth::login($user, remember: true);

        return redirect()->intended(route('app.home'));
    }

    /**
     * Linking by email is only safe when the IdP vouches for the address.
     */
    private function findByVerifiedEmail(SocialUser $authentikUser): ?User
    {
        $email = $authentikUser->getEmail();

        if (blank($email) || ($authentikUser->user['email_verified'] ?? false) !== true) {
            return null;
        }

        return User::whereRaw('LOWER(email) = ?', [mb_strtolower($email)])->first();
    }

    private function connectToCurrentUser(User $user, string $authentikId): RedirectResponse
    {
        $provider = SocialAuthProvider::Authentik->label();

        $existing = User::where('authentik_id', $authentikId)
            ->where('id', '!=', $user->id)
            ->first();

        if ($existing) {
            return redirect()->route('app.authentication.edit')
                ->with('flash.error', __('settings.authentication.providers.flash_already_linked', ['provider' => $provider]));
        }

        if ($user->authentik_id !== $authentikId) {
            $user->update(['authentik_id' => $authentikId]);
        }

        return redirect()->route('app.authentication.edit')
            ->with('flash.success', __('settings.authentication.providers.flash_connected', ['provider' => $provider]));
    }

    private function backToLogin(string $message): RedirectResponse
    {
        return redirect()->route('login')->withErrors(['email' => $message]);
    }
}
