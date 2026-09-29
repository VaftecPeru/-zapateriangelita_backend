<?php

namespace Tests\Feature;

use Tests\TestCase;

class GoogleConfigEndpointTest extends TestCase
{
    public function test_google_config_is_public_and_not_ip_throttled(): void
    {
        config([
            'services.google.client_id' => '1234567890-test.apps.googleusercontent.com',
        ]);

        for ($attempt = 0; $attempt < 70; $attempt++) {
            $this->getJson('/api/auth/google/config')
                ->assertOk()
                ->assertJsonPath('enabled', true)
                ->assertJsonPath('status', 'ready')
                ->assertJsonPath('client_id', '1234567890-test.apps.googleusercontent.com');
        }
    }
}
