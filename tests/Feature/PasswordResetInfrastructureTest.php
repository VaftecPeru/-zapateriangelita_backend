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
}
