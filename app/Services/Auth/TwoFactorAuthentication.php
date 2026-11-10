<?php

namespace App\Services\Auth;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

class TwoFactorAuthentication
{
    public function __construct(private Google2FA $engine) {}

    public function generateSecret(): string
    {
        return $this->engine->generateSecretKey();
    }

    public function qrSvg(User $user, string $secret): string
    {
        $url = $this->engine->getQRCodeUrl(
            (string) config('app.name', 'FIBES'),
            $user->email,
            $secret,
        );

        $writer = new Writer(new ImageRenderer(
            new RendererStyle(192, 1),
            new SvgImageBackEnd,
        ));

        $svg = $writer->writeString($url);

        return preg_replace('/<\?xml[^>]*>/', '', $svg) ?? $svg;
    }

    public function verifyCode(User $user, string $code): bool
    {
        $secret = $user->two_factor_secret;

        if (! is_string($secret) || $secret === '') {
            return false;
        }

        $normalized = preg_replace('/\s+/', '', $code) ?? '';

        if (! preg_match('/^\d{6}$/', $normalized)) {
            return false;
        }

        $key = 'two-factor:last-step:'.$user->getKey();

        try {
            return Cache::lock($key.':lock', 5)->block(3, function () use ($secret, $normalized, $key): bool {
                $last = Cache::get($key);
                // With an old step given, the library returns the matched step, or false
                // when the code is wrong or not newer than the last accepted one.
                $step = $this->engine->verifyKeyNewer($secret, $normalized, is_int($last) ? $last : 0, 1);

                if (! is_int($step)) {
                    return false;
                }

                Cache::put($key, $step, now()->addMinutes(5));

                return true;
            });
        } catch (LockTimeoutException) {
            return false;
        }
    }

    /**
     * @return list<string>
     */
    public function freshRecoveryCodes(): array
    {
        return collect(range(1, 8))
            ->map(fn () => Str::upper(Str::random(4).'-'.Str::random(4)))
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $plainCodes
     */
    public function storeRecoveryCodes(User $user, array $plainCodes): void
    {
        $user->two_factor_recovery_codes = collect($plainCodes)
            ->map(fn (string $code) => hash('sha256', $this->normalizeRecoveryCode($code)))
            ->values()
            ->all();
    }

    public function consumeRecoveryCode(User $user, string $code): bool
    {
        $hash = hash('sha256', $this->normalizeRecoveryCode($code));

        // Two requests with the same code must not both succeed, and a request
        // holding an old copy of the user must not write a used code back. So:
        // one at a time per user (cache lock, also covers SQLite), and always
        // re-read the codes from the database under a row lock before changing them.
        try {
            return Cache::lock('two-factor:recovery:'.$user->getKey().':lock', 5)->block(3, fn (): bool => DB::transaction(function () use ($user, $hash): bool {
                $fresh = User::query()->whereKey($user->getKey())->lockForUpdate()->first();
                $codes = $fresh?->two_factor_recovery_codes ?? [];

                if (! $fresh || ! in_array($hash, $codes, true)) {
                    $user->two_factor_recovery_codes = $codes ?: null;
                    $user->syncOriginalAttribute('two_factor_recovery_codes');

                    return false;
                }

                $fresh->two_factor_recovery_codes = array_values(array_filter(
                    $codes,
                    fn (string $stored) => $stored !== $hash,
                ));
                $fresh->save();

                $user->two_factor_recovery_codes = $fresh->two_factor_recovery_codes;
                $user->syncOriginalAttribute('two_factor_recovery_codes');

                return true;
            }));
        } catch (LockTimeoutException) {
            return false;
        }
    }

    private function normalizeRecoveryCode(string $code): string
    {
        return strtoupper(preg_replace('/\s+/', '', trim($code)) ?? '');
    }
}
