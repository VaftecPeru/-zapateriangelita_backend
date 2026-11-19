<?php

namespace Tests\Feature;

use App\Mail\NewsletterMail;
use App\Models\NewsletterSubscriber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class NewsletterTest extends TestCase
{
    use RefreshDatabase;

    public function test_subscription_sends_one_welcome_and_supports_signed_opt_out(): void
    {
        Mail::fake();

        $this->postJson('/api/newsletter/subscribe', ['email' => 'NEWS@example.com'])
            ->assertCreated()->assertJsonPath('already_subscribed', false);
        $this->postJson('/api/newsletter/subscribe', ['email' => 'news@example.com'])
            ->assertOk()->assertJsonPath('already_subscribed', true);

        $subscriber = NewsletterSubscriber::where('email', 'news@example.com')->sole();
        $this->assertNotNull($subscriber->welcome_due_at);
        $this->artisan('newsletter:send', ['kind' => 'welcome'])->assertSuccessful();
        $this->artisan('newsletter:send', ['kind' => 'welcome'])->assertSuccessful();
        Mail::assertSent(NewsletterMail::class, fn ($mail) => $mail->hasTo('news@example.com'));
        Mail::assertSentCount(1);
        $this->assertNull($subscriber->refresh()->welcome_due_at);

        $url = URL::signedRoute('newsletter.unsubscribe', ['subscriber' => $subscriber->id]);
        $this->post(route('newsletter.unsubscribe', $subscriber))->assertForbidden();
        $this->get($url)->assertOk()->assertSee('Confirmar baja');
        $this->assertSame('active', $subscriber->refresh()->status);
        $this->post($url)->assertOk()->assertSee('Cancelamos tu suscripción');
        $this->assertSame('unsubscribed', $subscriber->refresh()->status);
    }
}
