<?php

namespace Tests\Feature;

use App\Http\Controllers\AuthController;
use App\Mail\WelcomeUserMail;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\DeferredOrderNotificationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CustomerSessionFlowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'mail.default' => 'array',
        ]);

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('role')->default('user');
            $table->string('gender')->nullable();
            $table->date('birthdate')->nullable();
            $table->string('phone')->nullable();
            $table->boolean('must_change_password')->default(false);
            $table->timestamp('temporary_password_set_at')->nullable();
            $table->string('google_sub')->nullable();
            $table->string('google_email')->nullable();
            $table->timestamp('google_linked_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('password_reset_audits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->string('action');
            $table->string('ip_address', 64)->nullable();
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('code')->unique();
            $table->string('status')->default('sin_preparar');
            $table->decimal('subtotal', 10, 2)->default(0);
            $table->decimal('discount', 10, 2)->default(0);
            $table->decimal('shipping_cost', 10, 2)->default(0);
            $table->decimal('total', 10, 2)->default(0);
            $table->string('payment_method')->nullable();
            $table->string('payment_status')->nullable();
            $table->string('payment_reference')->nullable();
            $table->string('payment_transaction_id')->nullable();
            $table->text('payment_error')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('purchase_email_sent_at')->nullable();
            $table->timestamp('stock_reserved_at')->nullable();
            $table->timestamp('stock_released_at')->nullable();
            $table->string('shipping_address')->nullable();
            $table->string('shipping_city')->nullable();
            $table->string('shipping_country')->nullable();
            $table->string('shipping_state')->nullable();
            $table->string('shipping_municipality')->nullable();
            $table->string('shipping_postal_code')->nullable();
            $table->string('shipping_colony')->nullable();
            $table->string('shipping_phone')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_email')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('product_name');
            $table->string('size')->nullable();
            $table->string('color')->nullable();
            $table->integer('quantity');
            $table->decimal('unit_price', 10, 2);
            $table->timestamps();
        });
        Schema::create('addresses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            foreach (['full_name', 'phone', 'country', 'state', 'municipality', 'city', 'postal_code', 'colony', 'address'] as $column) {
                $table->string($column)->nullable();
            }
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });
    }

    public function test_confirmed_guest_payment_returns_working_customer_session_and_retry_is_idempotent(): void
    {
        $this->mock(DeferredOrderNotificationService::class)->shouldReceive('schedule')->twice();
        $order = $this->paidOrder();
        $order->update(['payment_status' => 'processing', 'paid_at' => null]);
        config([
            'openpay.currency' => 'MXN', 'openpay.merchant_id' => 'merchant_test',
            'openpay.public_key' => 'pk_test', 'openpay.private_key' => 'sk_test',
            'openpay.base_url' => 'https://sandbox-api.openpay.mx',
            'openpay.redirect_url' => 'https://example.test/checkout',
        ]);
        Http::fake(['*' => Http::response([
            'id' => $order->payment_transaction_id, 'order_id' => $order->payment_reference,
            'amount' => 550, 'currency' => 'MXN', 'status' => 'completed',
        ])]);
        $url = $this->verifyUrl($order);
        $response = $this->getJson($url)->assertOk()
            ->assertJsonPath('payment_status', 'paid')
            ->assertJsonPath('account_created', true)
            ->assertJsonPath('requires_login', false)
            ->assertJsonPath('user.role', 'user');
        $token = $response->json('auth_token');
        $this->assertNotEmpty($token);
        $this->withToken($token)->getJson('/api/user')->assertOk()
            ->assertJsonPath('email', $order->customer_email);
        $this->withToken($token)->getJson('/api/my-orders')->assertOk()
            ->assertJsonPath('0.id', $order->id);
        $this->getJson($url)->assertOk()->assertJsonPath('auto_login_reissued', true);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('addresses', 1);
        Http::assertSentCount(1);
    }

    public function test_recovered_payment_without_checkout_proof_never_returns_a_session(): void
    {
        $this->mock(DeferredOrderNotificationService::class)->shouldReceive('schedule')->once();
        $order = $this->paidOrder($this->user('existing@gmail.com'));
        $this->getJson($this->verifyUrl($order, 'invalid-token'))
            ->assertOk()->assertJsonPath('requires_login', true)
            ->assertJsonMissingPath('auth_token')->assertJsonMissingPath('user');
    }

    private function verifyUrl(Order $order, ?string $token = null): string
    {
        $token ??= Crypt::encryptString(json_encode([
            'flow' => 'angelita_checkout_v2', 'order_id' => $order->id,
            'email' => $order->customer_email, 'guest' => true,
            'exp' => now()->addHour()->timestamp,
        ]));
        return '/api/checkout/payments/openpay/verify?' . http_build_query([
            'order_id' => $order->id, 'transaction_id' => $order->payment_transaction_id,
            'checkout_token' => $token,
        ]);
    }

    private function googleCredential(array $overrides = []): string
    {
        config(['services.google.client_id' => 'test.apps.googleusercontent.com']);
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $public = openssl_pkey_get_details($key)['key'];
        Cache::put('google_identity_x509_certs_v1', ['test-key' => $public], 300);
        $encode = fn ($value) => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
        $message = $encode(json_encode(['alg' => 'RS256', 'kid' => 'test-key'])) . '.' .
            $encode(json_encode(array_merge([
                'iss' => 'https://accounts.google.com', 'aud' => config('services.google.client_id'),
                'sub' => 'google-customer-123', 'email' => 'customer@gmail.com',
                'email_verified' => true, 'name' => 'Cliente Google',
                'iat' => time(), 'exp' => time() + 300,
            ], $overrides)));
        openssl_sign($message, $signature, $key, OPENSSL_ALGO_SHA256);
        return $message . '.' . $encode($signature);
    }

    public function test_google_registration_returns_session_without_waiting_for_email(): void
    {
        Mail::fake();
        Http::preventStrayRequests();
        $credential = $this->googleCredential();
        $response = $this->postJson('/api/auth/google/login', compact('credential'))
            ->assertOk()->assertJsonPath('account_created', true)
            ->assertJsonPath('welcome_email_scheduled', true)
            ->assertJsonPath('user.role', 'user');
        Mail::assertSent(WelcomeUserMail::class, fn ($mail) =>
            $mail->hasTo('customer@gmail.com') && $mail->registrationMethod === 'google'
        );
        $this->withToken($response->json('token'))->getJson('/api/user')
            ->assertOk()->assertJsonPath('email', 'customer@gmail.com');
        $this->postJson('/api/auth/google/login', compact('credential'))
            ->assertOk()->assertJsonPath('account_created', false);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_google_reuses_checkout_customer_and_keeps_its_purchase(): void
    {
        $user = $this->user('customer@gmail.com');
        $order = $this->paidOrder($user);
        $response = $this->postJson('/api/auth/google/login', ['credential' => $this->googleCredential()])
            ->assertOk()->assertJsonPath('user.id', $user->id)->assertJsonPath('account_created', false);
        $this->withToken($response->json('token'))->getJson('/api/my-orders')
            ->assertOk()->assertJsonPath('0.id', $order->id);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_google_rejects_unverified_email_and_wrong_audience(): void
    {
        foreach ([['email_verified' => false], ['aud' => 'another-client']] as $claims) {
            $this->postJson('/api/auth/google/login', ['credential' => $this->googleCredential($claims)])
                ->assertUnauthorized()->assertJsonPath('error_code', 'GOOGLE_CREDENTIAL_INVALID');
        }
        $this->assertDatabaseCount('users', 0);
    }

    public function test_google_certificate_outage_is_retryable_without_creating_an_account(): void
    {
        $credential = $this->googleCredential();
        Cache::forget('google_identity_x509_certs_v1');
        Http::fake(['*' => Http::response([], 503)]);
        $this->postJson('/api/auth/google/login', compact('credential'))
            ->assertStatus(503)->assertJsonPath('error_code', 'GOOGLE_TEMPORARILY_UNAVAILABLE');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_manual_registration_delivers_session_before_smtp(): void
    {
        Mail::fake();
        $request = Request::create('/api/register', 'POST', [
            'name' => 'Cliente Gmail', 'email' => 'manual@gmail.com',
            'password' => 'Nueva123', 'password_confirmation' => 'Nueva123',
            'gender' => 'Otro', 'birthdate' => '1990-01-01', 'phone' => '+525512345678',
        ]);
        $response = app(AuthController::class)->register($request);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertNotEmpty($response->getData(true)['token']);
        Mail::assertNothingSent();
        app()->terminate();
        Mail::assertSent(WelcomeUserMail::class, 1);
    }

    private function user(string $email, string $role = 'user', bool $mustChangePassword = false): User
    {
        return User::create([
            'name' => 'Cliente Angelita',
            'email' => $email,
            'password' => Hash::make('Secret123!'),
            'role' => $role,
            'must_change_password' => $mustChangePassword,
        ]);
    }

    private function paidOrder(?User $user = null): Order
    {
        $order = Order::create([
            'user_id' => $user?->id,
            'code' => 'ZA-MAIL-001',
            'status' => 'sin_preparar',
            'subtotal' => 500,
            'discount' => 0,
            'shipping_cost' => 50,
            'total' => 550,
            'payment_method' => 'openpay',
            'payment_status' => 'paid',
            'payment_reference' => 'REF-MAIL-001',
            'payment_transaction_id' => 'TRX-MAIL-001',
            'paid_at' => now(),
            'shipping_address' => 'Av. Prueba 123',
            'shipping_city' => 'Ciudad de México',
            'shipping_country' => 'México',
            'shipping_state' => 'Ciudad de México',
            'shipping_municipality' => 'Cuauhtémoc',
            'shipping_postal_code' => '06000',
            'shipping_colony' => 'Centro',
            'shipping_phone' => '+525512345678',
            'customer_name' => 'Cliente Angelita',
            'customer_email' => $user?->email ?? 'comprador@example.com',
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => null,
            'product_name' => 'Zapato de prueba',
            'size' => '38',
            'color' => 'Negro',
            'quantity' => 1,
            'unit_price' => 500,
        ]);

        return $order;
    }

}
