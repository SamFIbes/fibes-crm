<?php

use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\AccountLockedNotification;
use App\Services\Audit\AuditLogger;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;

it('makes a seeded administrator change the setup password before using the app', function () {
    config([
        'fibes.admin.name' => 'FIBES Admin',
        'fibes.admin.email' => 'admin@fibes.test',
        'fibes.admin.password' => 'CorrectHorse12',
        'fibes.admin.locale' => 'en',
    ]);

    $this->seed(AdminUserSeeder::class);
    $admin = User::query()->first();

    expect($admin->role)->toBe('super_admin');
    expect($admin->must_change_password)->toBeTrue();
    expect(AuditLog::query()->where('event', 'auth.admin.seeded')->where('actor_id', $admin->id)->exists())->toBeTrue();
    expect(json_encode(AuditLog::query()->first()->metadata))->not->toContain('CorrectHorse12');

    $this->post('/login', [
        'email' => 'admin@fibes.test',
        'password' => 'CorrectHorse12',
    ])->assertRedirect(route('password.required'));

    $this->get('/dashboard')->assertRedirect(route('password.required'));
    $this->get('/account/password')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Account/PasswordRequired'));

    $this->put('/account/password/required', [
        'current_password' => 'CorrectHorse12',
        'password' => 'DifferentHorse12',
        'password_confirmation' => 'DifferentHorse12',
    ])->assertRedirect(route('dashboard'));

    $admin->refresh();
    expect($admin->must_change_password)->toBeFalse();
    expect(Hash::check('DifferentHorse12', $admin->password))->toBeTrue();
    expect(AuditLog::query()->where('event', 'auth.password.changed')->where('actor_id', $admin->id)->exists())->toBeTrue();

    $this->seed(AdminUserSeeder::class);
    $admin->refresh();
    expect(Hash::check('DifferentHorse12', $admin->password))->toBeTrue();
    expect($admin->must_change_password)->toBeFalse();
    expect($admin->name)->toBe('FIBES Admin');
});

it('requires an authenticator for a super admin after the grace period', function () {
    $overdue = User::factory()->superAdmin()->create([
        'created_at' => now()->subDays(8),
    ]);

    $this->actingAs($overdue)->get('/dashboard')->assertRedirect(route('account.security'));
    $this->actingAs($overdue)->get('/leads')->assertRedirect(route('account.security'));
    $this->actingAs($overdue)->get('/account/security')->assertOk();

    $withinGrace = User::factory()->superAdmin()->create([
        'created_at' => now()->subDay(),
    ]);
    $this->actingAs($withinGrace)->get('/dashboard')->assertOk();

    $staff = User::factory()->create([
        'created_at' => now()->subDays(30),
    ]);
    $this->actingAs($staff)->get('/dashboard')->assertOk();

    config(['fibes.two_factor.required_roles' => ['super_admin', 'finance']]);
    $finance = User::factory()->create([
        'role' => 'finance',
        'created_at' => now()->subDays(8),
    ]);
    $this->actingAs($finance)->get('/dashboard')->assertRedirect(route('account.security'));
});

it('refuses to turn off an authenticator when the role requires one', function () {
    $admin = User::factory()->superAdmin()->create([
        'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
        'two_factor_confirmed_at' => now(),
    ]);

    $this->actingAs($admin)->delete('/account/two-factor', [
        'current_password' => 'password',
    ])->assertSessionHasErrors('current_password');

    expect($admin->fresh()->hasTwoFactorEnabled())->toBeTrue();
});

it('lists and revokes sessions without letting a staff user end someone else', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    DB::table('sessions')->insert([
        [
            'id' => 'session-own',
            'user_id' => $user->id,
            'ip_address' => '203.0.113.10',
            'user_agent' => 'Mozilla/5.0 Chrome/120.0 Linux',
            'payload' => 'test',
            'last_activity' => now()->getTimestamp(),
        ],
        [
            'id' => 'session-other',
            'user_id' => $other->id,
            'ip_address' => '203.0.113.20',
            'user_agent' => 'Mozilla/5.0 Firefox/120.0',
            'payload' => 'test',
            'last_activity' => now()->getTimestamp(),
        ],
    ]);

    $this->actingAs($user)->get('/account/sessions')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Account/Sessions')
        ->has('sessions', 1)
        ->where('sessions.0.ip', '203.0.113.10')
        ->where('sessions.0.device', 'Chrome Linux')
        ->has('other_sessions', 0));

    $this->actingAs($user)->delete('/account/sessions/session-other')->assertNotFound();
    expect(DB::table('sessions')->where('id', 'session-other')->exists())->toBeTrue();

    $this->actingAs($user)->delete('/account/sessions/session-own')->assertRedirect();
    expect(DB::table('sessions')->where('id', 'session-own')->exists())->toBeFalse();
    expect(AuditLog::query()->where('event', 'auth.session.revoked')->where('actor_id', $user->id)->exists())->toBeTrue();

    $admin = User::factory()->superAdmin()->create();
    $this->actingAs($admin)->delete('/account/sessions/session-other')->assertRedirect();
    expect(DB::table('sessions')->where('id', 'session-other')->exists())->toBeFalse();
});

it('signs out an idle session and a session that has been open for twelve hours', function () {
    config([
        'fibes.session.idle_minutes' => 60,
        'fibes.session.absolute_minutes' => 720,
    ]);

    $user = User::factory()->create();

    $this->actingAs($user)->withSession([
        'auth.started_at' => now()->getTimestamp(),
        'auth.seen_at' => now()->subMinutes(61)->getTimestamp(),
    ])->get('/dashboard')->assertRedirect(route('login'));
    $this->assertGuest();
    expect(AuditLog::query()->where('event', 'auth.session.expired')->exists())->toBeTrue();

    $active = User::factory()->create();
    $this->actingAs($active)->withSession([
        'auth.started_at' => now()->subMinutes(721)->getTimestamp(),
        'auth.seen_at' => now()->getTimestamp(),
    ])->get('/dashboard')->assertRedirect(route('login'));
    $this->assertGuest();
});

it('pauses sign-in after ten wrong passwords and does not store the password', function () {
    Notification::fake();
    $user = User::factory()->create(['locale' => 'en']);
    $minute = 'login:'.sha1(strtolower($user->email).'|127.0.0.1');

    foreach (range(1, 10) as $attempt) {
        RateLimiter::clear($minute);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password-value',
        ])->assertSessionHasErrors('email');
    }

    Notification::assertSentTo($user, AccountLockedNotification::class, function (AccountLockedNotification $notification) use ($user) {
        return $notification->toMail($user)->subject === 'FIBES sign-in was paused';
    });

    RateLimiter::clear($minute);

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertSessionHasErrors('email');
    $this->assertGuest();

    $stored = AuditLog::query()->whereIn('event', ['auth.login.failed', 'auth.login.locked'])->get();
    expect($stored)->not->toBeEmpty();
    expect(json_encode($stored->pluck('metadata')))->not->toContain('wrong-password-value');
    expect(json_encode($stored->pluck('metadata')))->not->toContain('password');
});

it('writes security headers and sends hsts only on https', function () {
    $response = $this->get('/login');
    $csp = (string) $response->headers->get('Content-Security-Policy');

    expect($csp)->toContain("script-src 'self'");
    expect($csp)->toContain("'nonce-");
    expect($csp)->not->toMatch('/script-src[^;]*unsafe-inline/');
    $response->assertHeader('X-Frame-Options', 'DENY');
    $response->assertHeader('X-Content-Type-Options', 'nosniff');
    $response->assertHeaderMissing('Strict-Transport-Security');

    preg_match("/'nonce-([^']+)'/", $csp, $matches);
    $response->assertSee('nonce="'.$matches[1].'"', false);

    $this->withHeader('X-Forwarded-Proto', 'https')
        ->get('/login')
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
});

it('keeps the audit log append-only', function () {
    $user = User::factory()->create();
    $log = app(AuditLogger::class)->record('auth.logout', $user, [
        'password' => 'should-not-stick',
        'note' => 'signed out',
    ]);

    expect($log->metadata)->toBe(['note' => 'signed out']);

    expect(fn () => DB::table('audit_log')->where('id', $log->id)->update(['event' => 'tampered']))
        ->toThrow(QueryException::class);
    expect(fn () => DB::table('audit_log')->where('id', $log->id)->delete())
        ->toThrow(QueryException::class);
    expect(AuditLog::query()->whereKey($log->id)->value('event'))->toBe('auth.logout');
});

it('answers the application health route and proxies it in nginx', function () {
    $this->get('/up')->assertOk();

    $nginx = file_get_contents(base_path('docker/nginx/default.conf'));
    expect($nginx)->toContain('location = /up');
    expect($nginx)->toContain('fastcgi_pass app:9000');
    expect($nginx)->toContain('REQUEST_URI /up');
    expect($nginx)->not->toMatch('/location = \/up \{\s*access_log off;\s*\}/');
});
