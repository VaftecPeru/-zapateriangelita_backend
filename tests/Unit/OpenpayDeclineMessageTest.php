<?php

namespace Tests\Unit;

use App\Http\Controllers\CheckoutController;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

class OpenpayDeclineMessageTest extends TestCase
{
    #[Test]
    public function error_3004_is_presented_as_generic_card_rejection(): void
    {
        $controller = app(CheckoutController::class);
        $method = new ReflectionMethod(CheckoutController::class, 'normalizeOpenpayDecline');

        $result = $method->invoke($controller, [
            'error_code' => 3004,
            'description' => 'The card was reported stolen',
        ]);

        $this->assertSame('3004', $result['code']);
        $this->assertSame('CARD_DECLINED', $result['key']);
        $this->assertSame('Tarjeta rechazada', $result['title']);
        $this->assertSame('La tarjeta fue rechazada.', $result['message']);
    }
}
