<?php

it('answers the Meta verify check with 401, not 500, when the query values are arrays', function () {
    config(['fibes.webhooks.meta_verify_token' => 'expected-token']);

    $this->get('/api/v1/hooks/meta?hub_mode[]=subscribe&hub_verify_token[]=expected-token&hub_challenge[]=abc')
        ->assertStatus(401);
});

it('still echoes the Meta challenge for normal string values', function () {
    config(['fibes.webhooks.meta_verify_token' => 'expected-token']);

    $this->get('/api/v1/hooks/meta?hub_mode=subscribe&hub_verify_token=expected-token&hub_challenge=abc123')
        ->assertOk()
        ->assertSee('abc123');
});

it('answers the WhatsApp verify check with 403, not 500, when the query values are arrays', function () {
    config(['fibes.whatsapp.verify_token' => 'expected-token']);

    $this->get('/api/v1/hooks/whatsapp?hub_mode[]=subscribe&hub_verify_token[]=expected-token&hub_challenge[]=abc')
        ->assertForbidden();
});

it('still echoes the WhatsApp challenge for normal string values', function () {
    config(['fibes.whatsapp.verify_token' => 'expected-token']);

    $this->get('/api/v1/hooks/whatsapp?hub_mode=subscribe&hub_verify_token=expected-token&hub_challenge=abc123')
        ->assertOk()
        ->assertSee('abc123');
});
