<?php

use App\Mail\Transport\Microsoft365OauthException;
use App\Mail\Transport\Microsoft365OauthTokenClient;
use App\Mail\Transport\Microsoft365OauthTransport;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Mail\Mailer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Transport\Smtp\Auth\XOAuth2Authenticator;

const TENANT_ID = '11111111-1111-1111-1111-111111111111';
const CLIENT_ID = '22222222-2222-2222-2222-222222222222';
const SECRET = 'test-m365-secret';
const MAILBOX = 'noreply@example.test';
const TOKEN = 'test-m365-access-token';
const TOKEN_URL = 'https://login.microsoftonline.com/'.TENANT_ID.'/oauth2/v2.0/token';

function setM365Config(): void
{
    config([
        'mail.default' => 'm365oauth',
        'mail.mailers.m365oauth.tenant_id' => TENANT_ID,
        'mail.mailers.m365oauth.client_id' => CLIENT_ID,
        'mail.mailers.m365oauth.client_secret' => SECRET,
        'mail.mailers.m365oauth.username' => MAILBOX,
        'mail.mailers.m365oauth.host' => 'smtp.office365.com',
        'mail.mailers.m365oauth.port' => 587,
        'mail.mailers.m365oauth.token_skew_seconds' => 60,
    ]);
}

beforeEach(function (): void {
    Cache::flush();
});

it('fetches an access token from the Microsoft token endpoint', function (): void {
    setM365Config();

    Http::fake([
        TOKEN_URL => Http::response([
            'access_token' => TOKEN,
            'expires_in' => 3600,
            'token_type' => 'Bearer',
        ], 200),
    ]);

    $client = new Microsoft365OauthTokenClient;
    $token = $client->getAccessToken();

    expect($token)->toBe(TOKEN);

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request): bool {
        return $request->url() === TOKEN_URL
            && $request['grant_type'] === 'client_credentials'
            && $request['scope'] === 'https://outlook.office365.com/.default'
            && $request['client_id'] === CLIENT_ID;
    });
});

it('caches the access token and makes only one HTTP request for two calls', function (): void {
    setM365Config();

    Http::fake([
        TOKEN_URL => Http::response([
            'access_token' => TOKEN,
            'expires_in' => 3600,
            'token_type' => 'Bearer',
        ], 200),
    ]);

    $client = new Microsoft365OauthTokenClient;
    $first = $client->getAccessToken();
    $second = $client->getAccessToken();

    expect($first)->toBe(TOKEN)
        ->and($second)->toBe(TOKEN);

    Http::assertSentCount(1);
});

it('does not cache the token when the remaining lifetime is within the skew', function (): void {
    setM365Config();
    config(['mail.mailers.m365oauth.token_skew_seconds' => 60]);

    Http::fake([
        TOKEN_URL => Http::response([
            'access_token' => TOKEN,
            'expires_in' => 30,
            'token_type' => 'Bearer',
        ], 200),
    ]);

    $client = new Microsoft365OauthTokenClient;
    $client->getAccessToken();
    $client->getAccessToken();

    Http::assertSentCount(2);
});

it('throws on HTTP failure and logs only the status and error code without secrets', function (): void {
    setM365Config();

    $logged = [];
    Log::listen(function (MessageLogged $event) use (&$logged): void {
        $logged[] = $event;
    });

    Http::fake([
        TOKEN_URL => Http::response([
            'error' => 'invalid_client',
            'error_description' => 'AADSTS70011: The provided value for the input parameter scope is not valid.',
            'access_token' => 'leaked-token-should-not-appear',
        ], 401),
    ]);

    $client = new Microsoft365OauthTokenClient;

    try {
        $client->getAccessToken();
        expect(false)->toBeTrue();
    } catch (Microsoft365OauthException $exception) {
        expect($exception->getMessage())
            ->not->toContain(SECRET)
            ->not->toContain('leaked-token-should-not-appear');
    }

    $matches = array_values(array_filter(
        $logged,
        fn (MessageLogged $event): bool => $event->message === 'mail.m365oauth.token_failed',
    ));

    expect($matches)->toHaveCount(1);
    $event = $matches[0];
    $serialised = json_encode($event->context);
    expect($event->level)->toBe('error')
        ->and($event->context['status'] ?? null)->toBe(401)
        ->and($event->context['error'] ?? null)->toBe('invalid_client')
        ->and($serialised)->toBeString()
        ->and($serialised)->not->toContain(SECRET)
        ->and($serialised)->not->toContain('leaked-token-should-not-appear')
        ->and($serialised)->not->toContain('AADSTS70011');
});

it('makeSmtpTransport configures host, port, username, password, and only XOAuth2 authenticator', function (): void {
    setM365Config();

    $transport = new Microsoft365OauthTransport(
        new Microsoft365OauthTokenClient,
        'smtp.office365.com',
        587,
        MAILBOX,
    );

    $smtp = $transport->makeSmtpTransport(TOKEN);

    expect($smtp->getStream()->getHost())->toBe('smtp.office365.com')
        ->and($smtp->getStream()->getPort())->toBe(587)
        ->and($smtp->getUsername())->toBe(MAILBOX)
        ->and($smtp->getPassword())->toBe(TOKEN);

    $ref = new ReflectionClass($smtp);
    $prop = $ref->getProperty('authenticators');
    $authenticators = $prop->getValue($smtp);

    expect($authenticators)->toHaveCount(1)
        ->and($authenticators[0])->toBeInstanceOf(XOAuth2Authenticator::class)
        ->and(strtoupper($authenticators[0]->getAuthKeyword()))->toBe('XOAUTH2');
});

it('leaves the smtp mailer transport intact and registers m365oauth transport key', function (): void {
    expect(config('mail.mailers.smtp.transport'))->toBe('smtp')
        ->and(config('mail.mailers.m365oauth.transport'))->toBe('m365oauth');
});

it('resolves the m365oauth mailer through the mail manager', function (): void {
    setM365Config();

    $mailer = Mail::mailer('m365oauth');

    expect($mailer)->toBeInstanceOf(Mailer::class);
});
