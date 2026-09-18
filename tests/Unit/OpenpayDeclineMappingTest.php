<?php

namespace Tests\Unit;

use App\Http\Controllers\CheckoutController;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class OpenpayDeclineMappingTest extends TestCase
{
    /**
     * @dataProvider declineProvider
     */
    public function test_decline_codes_have_expected_customer_messages(
        string $code,
        string $title,
        string $key
    ): void {
        $controller = new CheckoutController();
        $method = new ReflectionMethod($controller, 'normalizeOpenpayDecline');
        $method->setAccessible(true);

        $result = $method->invoke($controller, ['error_code' => $code]);

        $this->assertSame($code, $result['code']);
        $this->assertSame($title, $result['title']);
        $this->assertSame($key, $result['key']);
        $this->assertNotSame('', trim((string) $result['message']));
    }

    public static function declineProvider(): array
    {
        return [
            '3001 rejected' => ['3001', 'Tarjeta rechazada', 'CARD_DECLINED'],
            '3002 expired' => ['3002', 'Tarjeta expirada', 'CARD_EXPIRED'],
            '3003 funds' => ['3003', 'Fondos insuficientes', 'INSUFFICIENT_FUNDS'],
            '3004 stolen' => ['3004', 'Tarjeta robada', 'CARD_REPORTED_STOLEN'],
            '3005 antifraud' => ['3005', 'Tarjeta rechazada por fraude/antifraude', 'ANTIFRAUD_REJECTED'],
        ];
    }
}
