<?php

namespace App\Mail\Transport;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class Microsoft365OauthTokenClient
{
    private const TOKEN_URL = 'https://login.microsoftonline.com/%s/oauth2/v2.0/token';

    public function getAccessToken(): string
    {
        $config = config('mail.mailers.m365oauth');

        $tenantId = (string) ($config['tenant_id'] ?? '');
        $clientId = (string) ($config['client_id'] ?? '');
        $clientSecret = (string) ($config['client_secret'] ?? '');
        $skew = (int) ($config['token_skew_seconds'] ?? 60);

        if ($tenantId === '') {
            throw new Microsoft365OauthException('m365oauth: tenant_id is not configured.');
        }

        if ($clientId === '') {
            throw new Microsoft365OauthException('m365oauth: client_id is not configured.');
        }

        if ($clientSecret === '') {
            throw new Microsoft365OauthException('m365oauth: client_secret is not configured.');
        }

        if (strpbrk($tenantId, "/\\?# \t") !== false) {
            throw new Microsoft365OauthException('m365oauth: tenant_id contains invalid characters.');
        }

        $cacheKey = 'mail:m365oauth:token:'.sha1($tenantId."\0".$clientId);

        $cached = Cache::get($cacheKey);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $url = sprintf(self::TOKEN_URL, $tenantId);

        $response = Http::asForm()
            ->timeout(15)
            ->acceptJson()
            ->post($url, [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'grant_type' => 'client_credentials',
                'scope' => 'https://outlook.office365.com/.default',
            ]);

        if ($response->failed()) {
            $body = $response->json();
            $errorCode = is_array($body) ? (string) ($body['error'] ?? '') : '';

            Log::error('mail.m365oauth.token_failed', [
                'status' => $response->status(),
                'error' => $errorCode !== '' ? $errorCode : null,
            ]);

            throw new Microsoft365OauthException(
                'Failed to obtain Microsoft 365 access token (HTTP '.$response->status().').'
            );
        }

        $body = $response->json();

        if (! is_array($body) || ! isset($body['access_token']) || ! is_string($body['access_token']) || $body['access_token'] === '') {
            Log::error('mail.m365oauth.token_failed', [
                'status' => $response->status(),
                'error' => 'missing_access_token',
            ]);

            throw new Microsoft365OauthException('Microsoft 365 token response did not contain an access token.');
        }

        $token = $body['access_token'];

        $expiresIn = $body['expires_in'] ?? null;

        if (! is_numeric($expiresIn) || (int) $expiresIn <= 0) {
            throw new Microsoft365OauthException('Microsoft 365 token response has missing or invalid expires_in.');
        }

        $ttl = (int) $expiresIn - $skew;

        if ($ttl > 0) {
            Cache::put($cacheKey, $token, $ttl);
        }

        return $token;
    }
}
