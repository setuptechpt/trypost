<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User as SocialiteUser;

function fakeAuthentikUser(string $sub, ?string $email, mixed $emailVerified = true): SocialiteUser
{
    $raw = ['sub' => $sub, 'email' => $email];

    if ($emailVerified !== null) {
        $raw['email_verified'] = $emailVerified;
    }

    return (new SocialiteUser)->setRaw($raw)->map(['id' => $sub, 'email' => $email, 'name' => 'Idp User']);
}

function mockAuthentikCallback(SocialiteUser|Throwable $result): void
{
    $driver = Mockery::mock(AbstractProvider::class);

    $expectation = $driver->shouldReceive('user');
    $result instanceof Throwable ? $expectation->andThrow($result) : $expectation->andReturn($result);

    Socialite::shouldReceive('driver')->with('authentik')->andReturn($driver);
}

beforeEach(function () {
    config([
        'trypost.authentik_auth_enabled' => true,
        'trypost.authentik_auth_label' => 'Setup Tech',
        'services.authentik.base_url' => 'https://idp.example.test',
        'services.authentik.client_id' => 'client-id',
        'services.authentik.client_secret' => 'client-secret',
        'services.authentik.redirect' => 'http://localhost/auth/authentik/callback',
    ]);
});

// --- disabled ---

test('redirect and callback are 404 when authentik is disabled', function () {
    config(['trypost.authentik_auth_enabled' => false]);

    $this->get(route('auth.authentik.redirect'))->assertNotFound();
    $this->get(route('auth.authentik.callback'))->assertNotFound();
});

test('authentik prop is false when disabled and the login page still renders', function () {
    config(['trypost.authentik_auth_enabled' => false]);

    $this->get(route('login'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('authentikAuthEnabled', false));
});

test('authentik props are shared with the label when enabled', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('authentikAuthEnabled', true)
            ->where('authentikAuthLabel', 'Setup Tech'));
});

// --- redirect ---

test('redirect sends guests to the identity provider with openid scopes and state', function () {
    $response = $this->get(route('auth.authentik.redirect'));

    $response->assertRedirectContains('https://idp.example.test/application/o/authorize/');

    $location = $response->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect($query['client_id'])->toBe('client-id')
        ->and($query['scope'])->toContain('openid')->toContain('email')
        ->and($query['state'])->not->toBeEmpty();
});

test('redirect is not available to authenticated users', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('auth.authentik.redirect'))
        ->assertRedirect();
});

// --- callback: login ---

test('callback signs in the account already linked by authentik_id', function () {
    $user = User::factory()->create(['email' => 'linked@example.com', 'authentik_id' => 'sub-1']);

    mockAuthentikCallback(fakeAuthentikUser('sub-1', 'other-address@example.com'));

    $this->get(route('auth.authentik.callback'))->assertRedirect(route('app.home'));

    $this->assertAuthenticatedAs($user);
});

test('callback links an existing account by email ignoring case when email_verified is true', function () {
    $user = User::factory()->create(['email' => 'Person@Example.com', 'authentik_id' => null]);

    mockAuthentikCallback(fakeAuthentikUser('sub-2', 'PERSON@example.COM', true));

    $this->get(route('auth.authentik.callback'))->assertRedirect(route('app.home'));

    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->authentik_id)->toBe('sub-2');
});

test('callback does not link an account when the email is not verified by the idp', function (mixed $verified) {
    $user = User::factory()->create(['email' => 'victim@example.com', 'authentik_id' => null]);

    mockAuthentikCallback(fakeAuthentikUser('sub-evil', 'victim@example.com', $verified));

    $this->get(route('auth.authentik.callback'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('email');

    $this->assertGuest();
    expect($user->fresh()->authentik_id)->toBeNull();
})->with([
    'false' => [false],
    'missing claim' => [null],
    'string "true"' => ['true'],
]);

test('callback refuses an email match when the account is already linked to a different authentik identity', function () {
    $user = User::factory()->create(['email' => 'reused@example.com', 'authentik_id' => 'sub-original']);

    mockAuthentikCallback(fakeAuthentikUser('sub-newcomer', 'reused@example.com', true));

    $this->get(route('auth.authentik.callback'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('email');

    $this->assertGuest();
    expect($user->fresh()->authentik_id)->toBe('sub-original');
});

test('callback with an unknown email goes back to login with an error and creates nothing', function () {
    User::factory()->create(['email' => 'someone@example.com']);
    $before = User::count();

    mockAuthentikCallback(fakeAuthentikUser('sub-new', 'nobody@example.com', true));

    $this->get(route('auth.authentik.callback'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['email' => __('auth.authentik_no_account')]);

    $this->assertGuest();
    expect(User::count())->toBe($before);
});

test('callback with an unknown email creates nothing even when registration is open', function () {
    config(['trypost.self_hosted' => true]);
    $before = User::count();

    mockAuthentikCallback(fakeAuthentikUser('sub-new', 'fresh@example.com', true));

    $this->get(route('auth.authentik.callback'))->assertRedirect(route('login'));

    expect(User::count())->toBe($before)
        ->and(User::where('email', 'fresh@example.com')->exists())->toBeFalse();
});

test('callback without an email claim does not sign anyone in', function () {
    User::factory()->create(['email' => 'someone@example.com']);

    mockAuthentikCallback(fakeAuthentikUser('sub-noemail', null, true));

    $this->get(route('auth.authentik.callback'))->assertRedirect(route('login'));

    $this->assertGuest();
});

test('callback failure returns to login with an error instead of a 500', function () {
    mockAuthentikCallback(new RuntimeException('invalid_grant'));

    $this->get(route('auth.authentik.callback'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('callback marks an unverified local email as verified for a linked account', function () {
    $user = User::factory()->unverified()->create(['email' => 'new@example.com']);

    mockAuthentikCallback(fakeAuthentikUser('sub-3', 'new@example.com', true));

    $this->get(route('auth.authentik.callback'))->assertRedirect(route('app.home'));

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

test('callback honours the intended url set by the auth middleware', function () {
    $user = User::factory()->create(['authentik_id' => 'sub-4']);

    mockAuthentikCallback(fakeAuthentikUser('sub-4', $user->email));

    $this->withSession(['url.intended' => url('/accounts')])
        ->get(route('auth.authentik.callback'))
        ->assertRedirect(url('/accounts'));
});

// --- callback: connect from settings ---

test('authenticated callback links authentik to the current user', function () {
    $user = User::factory()->create(['authentik_id' => null]);

    mockAuthentikCallback(fakeAuthentikUser('sub-5', 'whatever@example.com'));

    $this->actingAs($user)
        ->get(route('auth.authentik.callback'))
        ->assertRedirect(route('app.authentication.edit'))
        ->assertSessionHas('flash.success');

    expect($user->fresh()->authentik_id)->toBe('sub-5');
});

test('authenticated callback refuses an authentik identity already linked to another user', function () {
    User::factory()->create(['authentik_id' => 'sub-taken']);
    $user = User::factory()->create(['authentik_id' => null]);

    mockAuthentikCallback(fakeAuthentikUser('sub-taken', 'x@example.com'));

    $this->actingAs($user)
        ->get(route('auth.authentik.callback'))
        ->assertRedirect(route('app.authentication.edit'))
        ->assertSessionHas('flash.error');

    expect($user->fresh()->authentik_id)->toBeNull();
});

// --- account settings ---

test('connect-provider redirects to authentik when enabled', function () {
    $driver = Mockery::mock(AbstractProvider::class);
    $driver->shouldReceive('setScopes')->andReturnSelf();
    $driver->shouldReceive('redirect')->andReturn(redirect('https://idp.example.test/application/o/authorize/'));
    Socialite::shouldReceive('driver')->with('authentik')->andReturn($driver);

    $this->actingAs(User::factory()->create())
        ->get(route('app.authentication.connect-provider', 'authentik'))
        ->assertRedirect('https://idp.example.test/application/o/authorize/');
});

test('connect-provider 404s for authentik when disabled', function () {
    config(['trypost.authentik_auth_enabled' => false]);

    $this->actingAs(User::factory()->create())
        ->get(route('app.authentication.connect-provider', 'authentik'))
        ->assertNotFound();
});

test('settings hide authentik when disabled and unlinked', function () {
    config(['trypost.authentik_auth_enabled' => false]);

    $this->actingAs(User::factory()->create())
        ->get(route('app.authentication.edit'))
        ->assertInertia(fn ($page) => $page->where('connectedAccounts', fn ($accounts) => ! collect($accounts)->pluck('provider')->contains('authentik')));
});

test('settings keep authentik visible when disabled but already linked', function () {
    config(['trypost.authentik_auth_enabled' => false]);

    $this->actingAs(User::factory()->create(['authentik_id' => 'sub-6']))
        ->get(route('app.authentication.edit'))
        ->assertInertia(fn ($page) => $page->where('connectedAccounts', fn ($accounts) => collect($accounts)->pluck('provider')->contains('authentik')));
});

test('authentik can be disconnected when the user has a password', function () {
    $user = User::factory()->create(['authentik_id' => 'sub-7']);

    $this->actingAs($user)
        ->delete(route('app.authentication.disconnect-provider', 'authentik'))
        ->assertSessionHas('flash.success');

    expect($user->fresh()->authentik_id)->toBeNull();
});

test('authentik cannot be disconnected when it is the only sign-in method', function () {
    $user = User::factory()->create(['authentik_id' => 'sub-8', 'password' => null, 'google_id' => null, 'github_id' => null]);

    $this->actingAs($user)
        ->delete(route('app.authentication.disconnect-provider', 'authentik'))
        ->assertSessionHas('flash.error');

    expect($user->fresh()->authentik_id)->toBe('sub-8');
});
