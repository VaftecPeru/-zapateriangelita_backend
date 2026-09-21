<?php

namespace Tests\Feature;

use App\Mail\PurchaseConfirmedMail;
use App\Mail\TemporaryPasswordMail;
use App\Models\Order;
use App\Models\User;
use App\Services\CpanelOrderNotificationService;
use App\Services\CpanelOpenpayReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CpanelCheckoutRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_paid_order_notifications_are_persisted_and_retried_without_queue_worker(): void
    {
        Mail::fake();

        $user = User::create([
            'name' => 'Cliente Prueba',
            'email' => 'cliente@example.com',
            'password' => Hash::make('Secret123!'),
            'role' => 'user',
        ]);

        $order = Order::create([
            'user_id' => $user->id,
            'code' => 'ZA-CPANEL-001',
            'status' => 'sin_preparar',
            'subtotal' => 100,
            'discount' => 0,
            'shipping_cost' => 0,
            'total' => 100,
            'payment_method' => 'openpay',
            'payment_status' => 'paid',
            'payment_reference' => 'ZA-CPANEL-001-REF',
            'payment_transaction_id' => 'tr_cpanel_001',
            'customer_name' => 'Cliente Prueba',
            'customer_email' => 'cliente@example.com',
            'shipping_address' => 'Calle 1',
            'shipping_city' => 'Ciudad',
            'shipping_country' => 'México',
            'shipping_state' => 'Hidalgo',
            'shipping_municipality' => 'Pachuca',
            'shipping_postal_code' => '42000',
            'shipping_phone' => '+525511223344',
        ]);

        $service = app(CpanelOrderNotificationService::class);
        $service->queue($order, 'TempPass123!', true);
        $this->assertNotNull($order->fresh()->credential_email_ciphertext);

        $this->assertTrue($service->processById((int) $order->id));

        Mail::assertSent(PurchaseConfirmedMail::class, 1);
        Mail::assertSent(TemporaryPasswordMail::class, 1);

        $order->refresh();
        $this->assertNotNull($order->purchase_email_sent_at);
        $this->assertNotNull($order->credential_email_sent_at);
        $this->assertNull($order->credential_email_ciphertext);
        $this->assertNull($order->notification_next_attempt_at);
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

        $user = User::create([
            'name' => 'Cliente Existente',
            'email' => 'existente@example.com',
            'password' => Hash::make('Secret123!'),
            'role' => 'user',
        ]);

        $order = Order::create([
            'user_id' => $user->id,
            'code' => 'ZA-CPANEL-002',
            'status' => 'sin_preparar',
            'subtotal' => 475,
            'discount' => 0,
            'shipping_cost' => 0,
            'total' => 475,
            'payment_method' => 'openpay',
            'payment_status' => 'processing',
            'payment_reference' => 'ZA-CPANEL-002-REF',
            'payment_transaction_id' => 'tr_cpanel_002',
            'customer_name' => 'Cliente Existente',
            'customer_email' => 'existente@example.com',
            'shipping_address' => 'Calle 2',
            'shipping_city' => 'Ciudad',
            'shipping_country' => 'México',
            'shipping_state' => 'Hidalgo',
            'shipping_municipality' => 'Pachuca',
            'shipping_postal_code' => '42000',
            'shipping_phone' => '+525511223344',
        ]);

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
}
