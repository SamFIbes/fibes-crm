<?php

namespace App\Mail\Transport;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\Smtp\Auth\XOAuth2Authenticator;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

class Microsoft365OauthTransport implements TransportInterface
{
    public function __construct(
        private readonly Microsoft365OauthTokenClient $tokenClient,
        private readonly string $host,
        private readonly int $port,
        private readonly string $username,
    ) {
        if ($this->username === '') {
            throw new Microsoft365OauthException('m365oauth: username (mailbox address) is not configured.');
        }
    }

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        $token = $this->tokenClient->getAccessToken();
        $smtp = $this->makeSmtpTransport($token);

        return $smtp->send($message, $envelope);
    }

    public function makeSmtpTransport(string $accessToken): EsmtpTransport
    {
        $transport = new EsmtpTransport($this->host, $this->port);
        $transport->setUsername($this->username);
        $transport->setPassword($accessToken);
        $transport->setAuthenticators([new XOAuth2Authenticator]);

        return $transport;
    }

    public function __toString(): string
    {
        return 'm365oauth://'.$this->host;
    }
}
