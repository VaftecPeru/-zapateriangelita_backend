<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class SecurityConfigurationTest extends TestCase
{
    public function test_sanctum_tokens_have_a_finite_default_lifetime(): void
    {
        $config = file_get_contents(__DIR__ . '/../../config/sanctum.php');

        $this->assertIsString($config);
        $this->assertStringContainsString('SANCTUM_EXPIRATION_MINUTES', $config);
        $this->assertStringNotContainsString("'expiration' => null", $config);
    }

    public function test_password_recovery_does_not_use_exists_rule(): void
    {
        $controller = file_get_contents(__DIR__ . '/../../app/Http/Controllers/AuthController.php');

        $this->assertIsString($controller);
        $this->assertStringNotContainsString("exists:users,email", $controller);
        $this->assertStringContainsString('Si el correo está registrado', $controller);
    }

    public function test_registration_email_does_not_receive_plaintext_password(): void
    {
        $controller = file_get_contents(__DIR__ . '/../../app/Http/Controllers/AuthController.php');
        $mailable = file_get_contents(__DIR__ . '/../../app/Mail/WelcomeUserMail.php');

        $this->assertStringContainsString('new WelcomeUserMail($user)', $controller);
        $this->assertStringNotContainsString('public $password', $mailable);
    }
}
