<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenpayConfigurationPreflightTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cache.default' => 'array',
            'openpay.currency' => 'MXN',
            'openpay.merchant_id' => 'mnvcunjetomdonepaco1',
            'openpay.public_key' => 'pk_public_test',
            'openpay.private_key' => 'sk_private_test',
            'openpay.base_url' => 'https://api.openpay.mx',
            'openpay.redirect_url' => 'https://zapateriangelita.com/checkout',
            'openpay.sandbox' => false,
            'openpay.environment' => 'production',
        ]);

        Cache::flush();
    }

    public function test_config_reports_ready_when_provider_authenticates_merchant(): void
    {
        Http::fake([
            'https://api.openpay.mx/v1/mnvcunjetomdonepaco1' => Http::response([
                'id' => 'mnvcunjetomdonepaco1',
                'name' => 'Zapateria Angelita',
                'status' => 'active',
            ], 200),
        ]);

        $this->getJson('/api/openpay/config')
            ->assertOk()
            ->assertJsonPath('configured', true)
            ->assertJsonPath('credential_check.ok', true)
            ->assertJsonPath('credential_check.code', 'OPENPAY_OK')
            ->assertJsonPath('credential_check.provider_status', 200)
            ->assertJsonPath('environment', 'production')
            ->assertJsonMissing(['private_key' => 'sk_private_test']);

        Http::assertSentCount(1);
    }

    public function test_config_blocks_checkout_when_openpay_rejects_credentials(): void
    {
        Http::fake([
            'https://api.openpay.mx/v1/mnvcunjetomdonepaco1' => Http::response([
                'error_code' => 1002,
                'description' => 'Unauthorized',
            ], 401),
        ]);

        $this->getJson('/api/openpay/config')
            ->assertOk()
            ->assertJsonPath('configured', false)
            ->assertJsonPath('credential_check.ok', false)
            ->assertJsonPath('credential_check.code', 'OPENPAY_CREDENTIALS_INVALID')
            ->assertJsonPath('credential_check.provider_status', 401);
    }

    public function test_config_rejects_invalid_key_format_before_contacting_provider(): void
    {
        config([
            'openpay.public_key' => 'PUBLIC_KEY_WRONG',
            'openpay.private_key' => 'PRIVATE_KEY_WRONG',
        ]);

        Http::fake();

        $this->getJson('/api/openpay/config')
            ->assertOk()
            ->assertJsonPath('configured', false)
            ->assertJsonPath('credential_check.code', 'OPENPAY_CONFIG_INVALID');

        Http::assertNothingSent();
    }
}
