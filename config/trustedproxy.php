<?php

/*
|--------------------------------------------------------------------------
| Trusted proxies
|--------------------------------------------------------------------------
|
| X-Forwarded-For / -Proto / -Host / -Port are only believed when the request
| arrives *directly* from one of these addresses. Anyone else's forwarded
| headers are ignored, so a client cannot fake its IP (rate limits, lockouts,
| audit log) or pretend to be on https.
|
| Default:
|   100.0.0.0/8      Railway's edge proxy. Railway publishes no fixed list;
|                    this is the range Railway's own templates trust, and the
|                    edge overwrites any forwarded headers the client sent.
|   127.0.0.0/8, ::1 a proxy on the same machine (nginx, Apache).
|   10/8, 172.16/12, 192.168/16, fc00::/7
|                    private networks (docker-compose, internal load balancers).
|
| Override with TRUSTED_PROXIES as a comma-separated list of IPs/CIDRs.
| "*" trusts every peer (the old behaviour) and should only be used as a
| temporary escape hatch.
|
*/

return [
    'proxies' => env('TRUSTED_PROXIES') ?: '100.0.0.0/8,127.0.0.0/8,::1,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,fc00::/7',
];
