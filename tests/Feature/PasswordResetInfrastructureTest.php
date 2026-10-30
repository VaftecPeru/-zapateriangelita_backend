<?php

namespace Tests\Feature;

use App\Mail\PasswordResetLinkMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PasswordResetInfrastructureTest extends TestCase
{
    use RefreshDatabase;

    public function test_smtp_connection_has_a_bounded_timeout(): void
    {
        $timeout = config('mail.mailers.smtp.timeout');

        $this->assertIsInt($timeout);
        $this->assertGreaterThan(0, $timeout);
        $this->assertLessThanOrEqual(15, $timeout);
    }

    public function test_password_broker_token_table_is_available_after_migrations(): void
    {
        $this->assertSame('password_reset_tokens', config('auth.passwords.users.table'));
        $this->assertTrue(Schema::hasTable('password_reset_tokens'));

        foreach (['email', 'token', 'created_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('password_reset_tokens', $column));
        }
    }

    public function test_forgot_password_generates_a_token_without_smtp_network_access(): void
    {
        Mail::fake();

        $user = User::factory()->create();

        $this->postJson('/api/forgot-password', ['email' => $user->email])
            ->assertOk()
            ->assertJsonPath(
                'message',
                'Si el correo está registrado, recibirás instrucciones para restablecer tu contraseña.'
            );

        $this->assertDatabaseHas('password_reset_tokens', [
            'email' => $user->email,
        ]);

        Mail::assertSent(PasswordResetLinkMail::class);
    }
    public function test_customer_can_complete_password_reset_with_email_token(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'password' => \Illuminate\Support\Facades\Hash::make('OriginalPassword123'),
        ]);

        $this->postJson('/api/forgot-password', ['email' => $user->email])->assertOk();

        $mail = Mail::assertSent(PasswordResetLinkMail::class, function ($mail) use ($user) {
            return $mail->hasTo($user->email);
        });

        $token = $mail->first()->token;

        $this->postJson('/api/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'NuevaClaveSegura123',
            'password_confirmation' => 'NuevaClaveSegura123',
        ])->assertOk();

        $this->assertTrue(
            \Illuminate\Support\Facades\Hash::check('NuevaClaveSegura123', $user->fresh()->password)
        );
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
    }

    public function test_forgot_password_does_not_disclose_unregistered_email(): void
    {
        Mail::fake();

        $response = $this->postJson('/api/forgot-password', [
            'email' => 'desconocido@example.com',
        ]);

        $response->assertOk()->assertJsonPath(
            'message',
            'Si el correo está registrado, recibirás instrucciones para restablecer tu contraseña.'
        );
        Mail::assertNothingSent();
    }

}
