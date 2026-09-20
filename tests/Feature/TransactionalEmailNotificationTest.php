<?php

namespace Tests\Feature;

use App\Mail\OrderStatusMail;
use App\Mail\PasswordChangedMail;
use App\Mail\PasswordResetLinkMail;
use App\Mail\PurchaseConfirmedMail;
use App\Mail\TemporaryPasswordMail;
use App\Mail\WelcomeUserMail;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\PurchaseNotificationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TransactionalEmailNotificationTest extends TestCase
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

    public function test_manual_registration_sends_welcome_email(): void
    {
        Mail::fake();

        $this->postJson('/api/register', [
            'name' => 'Maria Lopez',
            'email' => 'maria@example.com',
            'password' => 'Nueva123',
            'password_confirmation' => 'Nueva123',
            'gender' => 'Femenino',
            'birthdate' => '1990-01-01',
            'phone' => '+525512345678',
        ])->assertOk();

        Mail::assertSent(WelcomeUserMail::class, fn ($mail) =>
            $mail->hasTo('maria@example.com')
        );
    }

    public function test_password_reset_notification_uses_branded_mail(): void
    {
        Mail::fake();
        $user = $this->user('reset@example.com');

        $user->sendPasswordResetNotification('token-angelita-123');

        Mail::assertSent(PasswordResetLinkMail::class, fn ($mail) =>
            $mail->hasTo('reset@example.com') &&
            $mail->token === 'token-angelita-123'
        );
    }

    public function test_password_reset_flow_sends_changed_confirmation(): void
    {
        Mail::fake();
        $user = $this->user('reset-completo@example.com');
        $token = Password::broker()->createToken($user);

        $this->postJson('/api/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'ClaveNueva123',
            'password_confirmation' => 'ClaveNueva123',
        ])->assertOk();

        $this->assertTrue(Hash::check('ClaveNueva123', $user->fresh()->password));

        Mail::assertSent(PasswordChangedMail::class, fn ($mail) =>
            $mail->hasTo('reset-completo@example.com') &&
            $mail->context === 'password_reset'
        );
    }

    public function test_google_welcome_template_uses_google_access_copy(): void
    {
        $user = $this->user('google@example.com');
        $html = (new WelcomeUserMail($user, 'google'))->render();

        $this->assertStringContainsString('Continuar con Google', $html);
        $this->assertStringContainsString('logo-angelita-horizontal.png', $html);
    }

    public function test_purchase_voucher_email_is_idempotent(): void
    {
        Mail::fake();
        $user = $this->user('compra@example.com');
        $order = $this->paidOrder($user);

        $service = app(PurchaseNotificationService::class);

        $this->assertTrue($service->send($order, $user));
        $this->assertTrue($service->send($order->fresh(), $user));

        Mail::assertSent(PurchaseConfirmedMail::class, 1);
        $this->assertNotNull($order->fresh()->purchase_email_sent_at);
    }

    public function test_admin_password_reset_always_notifies_customer(): void
    {
        Mail::fake();
        $superadmin = $this->user('soporte@vaftec.com.pe', 'superadmin');
        $customer = $this->user('cliente-reset@example.com');

        Sanctum::actingAs($superadmin);

        $this->postJson("/api/users/{$customer->id}/reset-password", [
            'send_email' => false,
        ])->assertOk()
          ->assertJsonPath('email_sent', true);

        Mail::assertSent(TemporaryPasswordMail::class, fn ($mail) =>
            $mail->hasTo('cliente-reset@example.com')
        );
    }

    public function test_customer_is_notified_after_changing_temporary_password(): void
    {
        Mail::fake();
        $customer = $this->user('temporal@example.com', 'user', true);

        Sanctum::actingAs($customer);

        $this->postJson('/api/user/change-temporary-password', [
            'password' => 'NuevaClave123',
            'password_confirmation' => 'NuevaClave123',
        ])->assertOk();

        Mail::assertSent(PasswordChangedMail::class, fn ($mail) =>
            $mail->hasTo('temporal@example.com') &&
            $mail->context === 'temporary_password_changed'
        );
    }

    public function test_order_status_change_notifies_customer(): void
    {
        Mail::fake();
        $admin = $this->user('admin@example.com', 'admin');
        $customer = $this->user('estado@example.com');
        $order = $this->paidOrder($customer);

        Sanctum::actingAs($admin);

        $this->putJson("/api/orders/{$order->id}/status", [
            'status' => 'preparado',
        ])->assertOk()
          ->assertJsonPath('status', 'preparado');

        Mail::assertSent(OrderStatusMail::class, fn ($mail) =>
            $mail->hasTo('estado@example.com')
        );
    }

    public function test_transactional_email_views_render_with_branding(): void
    {
        $user = $this->user('render@example.com');
        $order = $this->paidOrder($user);

        $purchaseHtml = (new PurchaseConfirmedMail($order->load('items'), $user))->render();
        $resetHtml = (new PasswordResetLinkMail($user, 'token-render'))->render();

        $this->assertStringContainsString('logo-angelita-horizontal.png', $purchaseHtml);
        $this->assertStringContainsString('Costo de delivery', $purchaseHtml);
        $this->assertStringContainsString('logo-angelita-horizontal.png', $resetHtml);
        $this->assertStringContainsString('/reset-password?token=', $resetHtml);
    }
}
