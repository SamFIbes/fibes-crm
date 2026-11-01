<?php

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use App\Services\Auth\TwoFactorAuthentication;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use PragmaRX\Google2FA\Google2FA;

function flattenTranslations(array $tree, string $prefix = ''): array
{
    $flat = [];

    foreach ($tree as $key => $value) {
        $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

        if (is_array($value)) {
            $flat += flattenTranslations($value, $path);
        } else {
            $flat[$path] = $value;
        }
    }

    return $flat;
}

it('redirects guests to sign in and shows the login screen', function () {
    $this->get('/')->assertRedirect(route('login'));
    $this->get('/dashboard')->assertRedirect(route('login'));

    $this->get('/login')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Auth/Login')
        ->where('locale', 'en')
        ->where('dir', 'ltr'));
});

it('signs in an active user and signs them out', function () {
    $user = User::factory()->create();

    $this->post('/login', [
        'email' => strtoupper($user->email),
        'password' => 'password',
    ])->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->last_seen_at)->not->toBeNull();

    $this->post('/logout')->assertRedirect(route('login'));
    $this->assertGuest();
});

it('rejects a wrong password and a disabled account', function () {
    $user = User::factory()->create();

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'not-the-password',
    ])->assertSessionHasErrors('email');
    $this->assertGuest();

    $disabled = User::factory()->inactive()->create();

    $this->post('/login', [
        'email' => $disabled->email,
        'password' => 'password',
    ])->assertSessionHasErrors('email');
    $this->assertGuest();
});

it('rate limits repeated login attempts', function () {
    $user = User::factory()->create();

    foreach (range(1, 5) as $attempt) {
        $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertSessionHasErrors('email');
    }

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('rejects email addresses that contain control characters', function () {
    Notification::fake();

    $this->post('/forgot-password', [
        'email' => "agent@fibes.test\r\nBcc:evil@example.com",
    ])->assertSessionHasErrors('email');

    Notification::assertNothingSent();
});

it('sends a password reset and accepts the new password', function () {
    Notification::fake();
    $user = User::factory()->create(['locale' => 'ar']);

    $this->post('/forgot-password', ['email' => $user->email])
        ->assertSessionHas('status');

    $token = null;
    Notification::assertSentTo($user, ResetPasswordNotification::class, function (ResetPasswordNotification $notification) use (&$token) {
        $token = $notification->token;

        return true;
    });

    $mail = (new ResetPasswordNotification($token))->toMail($user->fresh());
    expect($mail->subject)->toBe('إعادة تعيين كلمة مرور FIBES');

    $this->post('/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'NewPassword123',
        'password_confirmation' => 'NewPassword123',
    ])->assertRedirect(route('login'));

    expect(Hash::check('NewPassword123', $user->fresh()->password))->toBeTrue();
});

it('requires a matching authenticator code when two-factor is on', function () {
    $engine = new Google2FA;
    $secret = $engine->generateSecretKey();
    $user = User::factory()->create([
        'two_factor_secret' => $secret,
        'two_factor_confirmed_at' => now(),
    ]);

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('two-factor.login'));
    $this->assertGuest();

    $this->post('/two-factor-challenge', [
        'code' => '000000',
        'mode' => 'code',
    ])->assertSessionHasErrors('code');
    $this->assertGuest();

    $this->post('/two-factor-challenge', [
        'code' => $engine->getCurrentOtp($secret),
        'mode' => 'code',
    ])->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs($user);
});

it('lets a user enable two-factor and sign in with a recovery code', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/account/two-factor')->assertRedirect();
    $user->refresh();

    $otp = (new Google2FA)->getCurrentOtp($user->two_factor_secret);
    $this->post('/account/two-factor/confirm', ['code' => $otp])
        ->assertRedirect()
        ->assertSessionHas('recovery_codes');

    $codes = session('recovery_codes');
    expect($user->fresh()->hasTwoFactorEnabled())->toBeTrue();
    expect($codes)->toHaveCount(8);

    $this->post('/logout');

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('two-factor.login'));

    $this->post('/two-factor-challenge', [
        'code' => $codes[0],
        'mode' => 'recovery',
    ])->assertRedirect(route('dashboard'));

    $this->post('/logout');
    $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);
    $this->post('/two-factor-challenge', [
        'code' => $codes[0],
        'mode' => 'recovery',
    ])->assertSessionHasErrors('code');
});

it('turns two-factor off with the current password', function () {
    $engine = new Google2FA;
    $user = User::factory()->create([
        'two_factor_secret' => $engine->generateSecretKey(),
        'two_factor_confirmed_at' => now(),
        'two_factor_recovery_codes' => [hash('sha256', 'AAAA-BBBB')],
    ]);

    $this->actingAs($user)->delete('/account/two-factor', [
        'current_password' => 'password',
    ])->assertRedirect();

    expect($user->fresh()->hasTwoFactorEnabled())->toBeFalse();
});

it('switches language for guests and signed-in users', function () {
    $this->post('/locale', ['locale' => 'ar'])->assertRedirect();

    $this->get('/login')->assertInertia(fn (Assert $page) => $page
        ->where('locale', 'ar')
        ->where('dir', 'rtl'));

    $this->post('/locale', ['locale' => 'fr'])->assertSessionHasErrors('locale');

    $user = User::factory()->create(['locale' => 'en']);
    $this->actingAs($user)->post('/locale', ['locale' => 'ar']);
    expect($user->fresh()->locale)->toBe('ar');
});

it('renders placeholder modules for an authenticated user', function () {
    $this->seed(RoleAndPermissionSeeder::class);
    $user = User::factory()->superAdmin()->create();

    foreach (['surveys', 'audit'] as $module) {
        $this->actingAs($user)->get('/'.$module)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Modules/Placeholder')
            ->where('module', $module));
    }

    $this->actingAs($user)->get('/quotes')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Quotes/Index'));
    $this->actingAs($user)->get('/billing')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Billing/Index'));
});

it('renders friendly error pages', function () {
    $this->get('/missing-page')->assertNotFound()->assertInertia(fn (Assert $page) => $page
        ->component('Errors/Error')
        ->where('status', 404)
        ->where('locale', 'en')
        ->where('auth.user', null)
        ->has('translations.errors.404_title'));

    Route::middleware('web')->get('/_testing/forbidden', fn () => abort(403));
    $this->get('/_testing/forbidden')->assertForbidden()->assertInertia(fn (Assert $page) => $page
        ->component('Errors/Error')
        ->where('status', 403));

    Route::middleware('web')->get('/_testing/boom', function () {
        throw new RuntimeException('boom');
    });

    $this->get('/_testing/boom')->assertStatus(500)->assertInertia(fn (Assert $page) => $page
        ->component('Errors/Error')
        ->where('status', 500));
});

it('keeps Arabic and English translation keys aligned', function () {
    foreach (['ui', 'mail'] as $file) {
        $english = array_keys(flattenTranslations(require lang_path("en/{$file}.php")));
        $arabic = array_keys(flattenTranslations(require lang_path("ar/{$file}.php")));
        sort($english);
        sort($arabic);

        expect($arabic)->toEqual($english);
    }
});

it('registers the domain modules and does not ship excel import', function () {
    foreach (config('fibes.modules') as $module) {
        expect(app()->getProvider($module['provider']))->not->toBeNull();
    }

    expect(config('fibes.sla.breach_notifies'))->toBe('supervisor');
    expect(config('fibes.sla.clock'))->toBe('calendar');
    expect(config('fibes.sla.priorities.P1.resolve_minutes'))->toBe(24 * 60);
    expect(config('fibes.sla.priorities.P4.resolve_minutes'))->toBe(48 * 60);
    expect(array_keys(config('fibes.service_types')))->toBe(['ftth', 'fttb', 'ptmp', 'ptp', 'internal', 'dsl']);
    expect(config('fibes.service_types'))->not->toHaveKey('hsi');
    expect(config('fibes.customer_types.reseller'))->toBe('Reseller');
    expect(Route::has('imports.store'))->toBeFalse();
});

it('seeds an admin only from configuration', function () {
    config([
        'fibes.admin.email' => null,
        'fibes.admin.password' => null,
    ]);
    $this->seed(AdminUserSeeder::class);
    expect(User::query()->count())->toBe(0);

    config([
        'fibes.admin.name' => 'FIBES Admin',
        'fibes.admin.email' => 'admin@fibes.test',
        'fibes.admin.password' => 'CorrectHorse12',
        'fibes.admin.locale' => 'ar',
    ]);

    $this->seed(AdminUserSeeder::class);
    $admin = User::query()->first();
    expect($admin->email)->toBe('admin@fibes.test');
    expect($admin->locale)->toBe('ar');
    expect(Hash::check('CorrectHorse12', $admin->password))->toBeTrue();

    $admin->forceFill(['name' => 'Renamed'])->save();
    $this->seed(AdminUserSeeder::class);
    expect($admin->fresh()->name)->toBe('Renamed');

    config(['fibes.admin.password' => 'short']);
    expect(fn () => $this->seed(AdminUserSeeder::class))
        ->toThrow(RuntimeException::class);

    $source = file_get_contents(database_path('seeders/AdminUserSeeder.php'));
    expect($source)->not->toMatch("/'password'\s*=>\s*'[^']+'/");
});

it('refuses destructive database commands outside local and testing', function () {
    try {
        DB::prohibitDestructiveCommands(true);
        $user = User::factory()->create();

        $this->artisan('migrate:fresh', ['--force' => true])->assertFailed();

        expect(User::query()->whereKey($user->id)->exists())->toBeTrue();
    } finally {
        DB::prohibitDestructiveCommands(false);
    }
});

it('runs on Laravel 13 with hardened cache and session defaults', function () {
    expect(app()->version())->toStartWith('13.');
    expect(config('cache.serializable_classes'))->toBeFalse();
    expect(config('session.serialization'))->toBe('json');

    $example = file_get_contents(base_path('.env.example'));
    expect($example)->toContain('SESSION_COOKIE=fibes_session');
    expect($example)->toContain('CACHE_PREFIX=fibes');
    expect($example)->toContain('REDIS_PREFIX=fibes_database_');
});

it('builds a qr code for a pending authenticator setup', function () {
    $user = User::factory()->create();
    $secret = app(TwoFactorAuthentication::class)->generateSecret();
    $svg = app(TwoFactorAuthentication::class)->qrSvg($user, $secret);

    expect($svg)->toContain('<svg');
    expect($svg)->not->toContain('<?xml');
});
