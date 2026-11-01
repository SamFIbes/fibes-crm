<?php

namespace App\Services\Auth;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
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

        return $this->engine->verifyKey($secret, $normalized, 1);
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
        $codes = $user->two_factor_recovery_codes ?? [];

        if (! in_array($hash, $codes, true)) {
            return false;
        }

        $user->two_factor_recovery_codes = array_values(array_filter(
            $codes,
            fn (string $stored) => $stored !== $hash,
        ));
        $user->save();

        return true;
    }

    private function normalizeRecoveryCode(string $code): string
    {
        return strtoupper(preg_replace('/\s+/', '', trim($code)) ?? '');
    }
}
