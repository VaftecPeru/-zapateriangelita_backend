<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class NewsletterSubscriptionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);

        Schema::create('newsletter_subscribers', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('status', 20)->default('active');
            $table->timestamp('subscribed_at')->nullable();
            $table->timestamp('unsubscribed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('store_interactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('type', 50);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function test_newsletter_subscription_is_persisted_and_idempotent(): void
    {
        $this->postJson('/api/newsletter/subscribe', [
            'email' => 'cliente@example.com',
        ])->assertCreated()->assertJson([
            'success' => true,
            'already_subscribed' => false,
        ]);

        $this->postJson('/api/newsletter/subscribe', [
            'email' => 'CLIENTE@example.com',
        ])->assertOk()->assertJson([
            'success' => true,
            'already_subscribed' => true,
        ]);

        $this->assertDatabaseCount('newsletter_subscribers', 1);
        $this->assertDatabaseHas('newsletter_subscribers', [
            'email' => 'cliente@example.com',
            'status' => 'active',
        ]);
    }

    public function test_newsletter_rejects_invalid_email(): void
    {
        $this->postJson('/api/newsletter/subscribe', [
            'email' => 'correo-invalido',
        ])->assertStatus(422);
    }
}
