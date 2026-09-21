<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyPaymentServiceSignature;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PaymentServiceSignatureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.payment_service.shared_secret' => 'test-payment-shared-secret-123456789',
            'services.payment_service.callback_ttl' => 300,
        ]);
        Cache::flush();
    }

    public function test_valid_hmac_signature_is_accepted_and_nonce_cannot_be_replayed(): void
    {
        $body = json_encode(['transaction_id' => 'tr_test_001'], JSON_UNESCAPED_SLASHES);
        $timestamp = (string) now()->timestamp;
        $nonce = 'nonce_test_1234567890';
        $path = 'api/internal/payment/orders/1/complete';
        $signature = hash_hmac(
            'sha256',
            implode("\n", [$timestamp, $nonce, 'POST', $path, $body]),
            config('services.payment_service.shared_secret')
        );

        $request = Request::create('/' . $path, 'POST', [], [], [], [], $body);
        $request->headers->set('Content-Type', 'application/json');
        $request->headers->set('X-Payment-Timestamp', $timestamp);
        $request->headers->set('X-Payment-Nonce', $nonce);
        $request->headers->set('X-Payment-Signature', $signature);

        $middleware = app(VerifyPaymentServiceSignature::class);
        $response = $middleware->handle(
            $request,
            fn () => response()->json(['accepted' => true])
        );

        $this->assertSame(200, $response->getStatusCode());

        $replay = $middleware->handle(
            $request,
            fn () => response()->json(['accepted' => true])
        );

        $this->assertSame(409, $replay->getStatusCode());
    }

    public function test_invalid_signature_is_rejected(): void
    {
        $body = json_encode(['transaction_id' => 'tr_test_002'], JSON_UNESCAPED_SLASHES);
        $request = Request::create('/api/internal/payment/orders/2/complete', 'POST', [], [], [], [], $body);
        $request->headers->set('Content-Type', 'application/json');
        $request->headers->set('X-Payment-Timestamp', (string) now()->timestamp);
        $request->headers->set('X-Payment-Nonce', 'nonce_test_abcdefghijk');
        $request->headers->set('X-Payment-Signature', str_repeat('a', 64));

        $response = app(VerifyPaymentServiceSignature::class)->handle(
            $request,
            fn () => response()->json(['accepted' => true])
        );

        $this->assertSame(401, $response->getStatusCode());
    }
}
