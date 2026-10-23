<?php

namespace Tests\Feature;

use App\Mail\PasswordChangedMail;
use App\Models\PasswordResetAudit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserPasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_must_confirm_current_password(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'password' => Hash::make('Actual123!'),
            'must_change_password' => false,
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/user/change-password', [
            'current_password' => 'Incorrecta123!',
            'password' => 'NuevaClave123!',
            'password_confirmation' => 'NuevaClave123!',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.current_password.0', 'La contraseña actual no es correcta.');

        $this->assertTrue(Hash::check('Actual123!', $user->fresh()->password));
        $this->assertDatabaseCount('password_reset_audits', 0);
    }

    public function test_authenticated_user_can_change_password_and_audit_is_created(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'password' => Hash::make('Actual123!'),
            'must_change_password' => false,
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/user/change-password', [
            'current_password' => 'Actual123!',
            'password' => 'NuevaClave123!',
            'password_confirmation' => 'NuevaClave123!',
        ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertTrue(Hash::check('NuevaClave123!', $user->fresh()->password));

        $this->assertDatabaseHas('password_reset_audits', [
            'user_id' => $user->id,
            'admin_id' => null,
            'action' => 'user_changed_password',
        ]);
        Mail::assertSent(PasswordChangedMail::class);
    }

    public function test_temporary_password_flow_cannot_be_bypassed_by_regular_endpoint(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'password' => Hash::make('Temporal123!'),
            'must_change_password' => true,
            'temporary_password_set_at' => now(),
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/user/change-password', [
            'current_password' => 'Temporal123!',
            'password' => 'NuevaClave123!',
            'password_confirmation' => 'NuevaClave123!',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'TEMPORARY_PASSWORD_CHANGE_REQUIRED');

        $this->assertTrue($user->fresh()->must_change_password);
    }

    public function test_temporary_password_change_sends_notification_after_success(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'password' => Hash::make('Temporal123!'),
            'must_change_password' => true,
            'temporary_password_set_at' => now(),
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/user/change-temporary-password', [
            'password' => 'NuevaClave123!',
            'password_confirmation' => 'NuevaClave123!',
        ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertFalse($user->fresh()->must_change_password);
        $this->assertTrue(Hash::check('NuevaClave123!', $user->fresh()->password));
        Mail::assertSent(PasswordChangedMail::class);
    }
}
