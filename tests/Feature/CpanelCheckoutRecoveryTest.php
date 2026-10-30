<?php

namespace Tests\Feature;

use App\Mail\PurchaseConfirmedMail;
use App\Mail\TemporaryPasswordMail;
use App\Models\Order;
use App\Models\User;
use App\Services\CpanelOrderNotificationService;
use App\Services\CpanelOpenpayReconciliationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CpanelCheckoutRecoveryTest extends TestCase
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
            $table->string('password');
            $table->string('role')->default('user');
            $table->string('phone')->nullable();
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
            $table->text('credential_email_ciphertext')->nullable();
            $table->timestamp('credential_email_sent_at')->nullable();
            $table->unsignedSmallInteger('notification_attempts')->default(0);
            $table->timestamp('notification_next_attempt_at')->nullable();
            $table->text('notification_last_error')->nullable();
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

    private function user(string $email): User
    {
        return User::create([
            'name' => 'Cliente Prueba',
            'email' => $email,
            'password' => Hash::make('Secret123!'),
            'role' => 'user',
        ]);
    }

    private function order(User $user, string $code, string $status, string $reference, string $transaction): Order
    {
        return Order::create([
            'user_id' => $user->id,
            'code' => $code,
            'status' => 'sin_preparar',
            'subtotal' => 475,
            'discount' => 0,
            'shipping_cost' => 0,
            'total' => 475,
            'payment_method' => 'openpay',
            'payment_status' => $status,
            'payment_reference' => $reference,
            'payment_transaction_id' => $transaction,
            'customer_name' => $user->name,
            'customer_email' => $user->email,
            'shipping_address' => 'Calle 1',
            'shipping_city' => 'Ciudad',
            'shipping_country' => 'México',
            'shipping_state' => 'Hidalgo',
            'shipping_municipality' => 'Pachuca',
            'shipping_postal_code' => '42000',
            'shipping_phone' => '+525511223344',
        ]);
    }

    public function test_paid_order_notifications_are_persisted_and_retried_without_queue_worker(): void
    {
        Mail::fake();
        $user = $this->user('cliente@example.com');
        $order = $this->order($user, 'ZA-CPANEL-001', 'paid', 'ZA-CPANEL-001-REF', 'tr_cpanel_001');

        $service = app(CpanelOrderNotificationService::class);
        $service->queue($order, 'TempPass123!', true);
        $this->assertNotNull($order->fresh()->credential_email_ciphertext);
        $this->assertTrue($order->fresh()->credential_email_pending);
        $this->assertArrayNotHasKey('credential_email_ciphertext', $order->fresh()->toArray());

        $this->assertTrue($service->processById((int) $order->id));

        Mail::assertSent(PurchaseConfirmedMail::class, 1);
        Mail::assertSent(PurchaseConfirmedMail::class, function (PurchaseConfirmedMail $mail): bool {
            $attachments = $mail->attachments();

            return count($attachments) === 1;
        });
        Mail::assertSent(TemporaryPasswordMail::class, 1);

        $order->refresh();
        $this->assertNotNull($order->purchase_email_sent_at);
        $this->assertNotNull($order->credential_email_sent_at);
        $this->assertNull($order->credential_email_ciphertext);
        $this->assertNull($order->notification_next_attempt_at);
    }


    public function test_scheduled_reconciliation_command_promotes_stale_processing_order_to_paid(): void
    {
        Mail::fake();
        config([
            'openpay.merchant_id' => 'merchant_test',
            'openpay.private_key' => 'private_test',
            'openpay.base_url' => 'https://sandbox-api.openpay.mx',
            'openpay.currency' => 'MXN',
            'openpay.cpanel_reconcile_after_minutes' => 10,
            'services.payment_service.enabled' => false,
        ]);

        $user = $this->user('cron-reconcile@example.com');
        $order = $this->order(
            $user,
            'ZA-CPANEL-CRON-001',
            'processing',
            'ZA-CPANEL-CRON-001-REF',
            'tr_cpanel_cron_001'
        );

        DB::table('orders')
            ->where('id', $order->id)
            ->update(['updated_at' => now()->subMinutes(20)]);

        Http::fake([
            'https://sandbox-api.openpay.mx/v1/merchant_test/charges/tr_cpanel_cron_001' => Http::response([
                'id' => 'tr_cpanel_cron_001',
                'order_id' => 'ZA-CPANEL-CRON-001-REF',
                'amount' => 475.00,
                'currency' => 'MXN',
                'status' => 'completed',
            ], 200),
        ]);

        $this->artisan('checkout:reconcile-openpay --limit=20')
            ->assertExitCode(0);

        $order->refresh();
        $this->assertSame('paid', $order->payment_status);
        $this->assertNotNull($order->paid_at);
    }

    public function test_cpanel_reconciliation_marks_known_completed_charge_as_paid(): void
    {
        Mail::fake();
        config([
            'openpay.merchant_id' => 'merchant_test',
            'openpay.private_key' => 'private_test',
            'openpay.base_url' => 'https://sandbox-api.openpay.mx',
            'openpay.currency' => 'MXN',
            'services.payment_service.enabled' => false,
        ]);

        $user = $this->user('existente@example.com');
        $order = $this->order($user, 'ZA-CPANEL-002', 'processing', 'ZA-CPANEL-002-REF', 'tr_cpanel_002');

        Http::fake([
            'https://sandbox-api.openpay.mx/v1/merchant_test/charges/tr_cpanel_002' => Http::response([
                'id' => 'tr_cpanel_002',
                'order_id' => 'ZA-CPANEL-002-REF',
                'amount' => 475.00,
                'currency' => 'MXN',
                'status' => 'completed',
            ], 200),
        ]);

        $result = app(CpanelOpenpayReconciliationService::class)->reconcile($order);

        $this->assertSame('paid', $result);
        $this->assertSame('paid', $order->fresh()->payment_status);
    }
    public function test_pending_test_order_cannot_be_reconciled_or_notified(): void
    {
        Mail::fake();
        Http::fake();

        $user = $this->user('pending-sandbox@example.com');
        $order = $this->order(
            $user,
            'ZA-20261021-D7E6',
            'pending',
            'ZA-20261021-D7E6-REF',
            ''
        );

        $result = app(CpanelOpenpayReconciliationService::class)->reconcile($order);
        $notified = app(CpanelOrderNotificationService::class)->processById((int) $order->id);

        $this->assertSame('skipped', $result);
        $this->assertFalse($notified);
        $this->assertSame('pending', $order->fresh()->payment_status);
        $this->assertNull($order->fresh()->paid_at);
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_openpay_mismatch_does_not_mark_order_paid_or_send_email(): void
    {
        Mail::fake();
        config([
            'openpay.merchant_id' => 'merchant_test',
            'openpay.private_key' => 'private_test',
            'openpay.base_url' => 'https://sandbox-api.openpay.mx',
            'openpay.currency' => 'MXN',
            'services.payment_service.enabled' => false,
        ]);

        $user = $this->user('mismatch-sandbox@example.com');
        $order = $this->order(
            $user,
            'ZA-MISMATCH-001',
            'processing',
            'ZA-MISMATCH-001-REF',
            'tr_mismatch_001'
        );

        Http::fake([
            'https://sandbox-api.openpay.mx/v1/merchant_test/charges/tr_mismatch_001' => Http::response([
                'id' => 'tr_mismatch_001',
                'order_id' => 'ZA-OTHER-ORDER',
                'amount' => 475.00,
                'currency' => 'MXN',
                'status' => 'completed',
            ], 200),
        ]);

        $result = app(CpanelOpenpayReconciliationService::class)->reconcile($order);

        $this->assertSame('mismatch', $result);
        $this->assertSame('processing', $order->fresh()->payment_status);
        $this->assertNull($order->fresh()->paid_at);
        Mail::assertNothingSent();
    }

    public function test_voucher_html_contains_order_payment_reference_amount_and_escaped_customer(): void
    {
        $user = $this->user('voucher-content@example.com');
        $order = $this->order(
            $user,
            'ZA-VOUCHER-TEST-001',
            'paid',
            'REF-VOUCHER-001',
            'tr_voucher_001'
        );
        $order->update(['customer_name' => '<script>alert(1)</script>']);

        $mail = new PurchaseConfirmedMail($order->fresh('items'), $user);
        $renderVoucher = new \ReflectionMethod(PurchaseConfirmedMail::class, 'voucherHtml');
        $html = $renderVoucher->invoke($mail, $order->fresh('items'));

        $this->assertStringContainsString('ZA-VOUCHER-TEST-001', $html);
        $this->assertStringContainsString('REF-VOUCHER-001', $html);
        $this->assertStringContainsString('tr_voucher_001', $html);
        $this->assertStringContainsString('475.00', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

}
