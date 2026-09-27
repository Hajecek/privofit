<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Env;
use App\Services\Billing\StripeFee;
use PHPUnit\Framework\TestCase;

final class StripeFeeTest extends TestCase
{
    protected function setUp(): void
    {
        Env::set('STRIPE_FEE_PERCENT', '1.5');
        Env::set('STRIPE_FEE_FIXED_CZK', '6.50');
    }

    public function testEuropeanCardOnTenEntries(): void
    {
        $priced = StripeFee::coverFor('1100.00', 'CZ');
        $this->assertSame('23.35', $priced['fee']);
        $this->assertSame('1123.35', $priced['charge']);
        $this->assertSame('eea', StripeFee::band('CZ'));
    }

    public function testBritishCard(): void
    {
        $priced = StripeFee::coverFor('1100.00', 'GB');
        $this->assertSame('34.87', $priced['fee']);
        $this->assertSame('1134.87', $priced['charge']);
        $this->assertSame('gb', StripeFee::band('UK'));
    }

    public function testInternationalCardMatchesLinkCharge(): void
    {
        $priced = StripeFee::coverFor('1100.00', 'US');
        $this->assertSame('42.49', $priced['fee']);
        $this->assertSame('1142.49', $priced['charge']);
        $this->assertSame('international', StripeFee::band('US'));
    }

    public function testLinkWithoutCountryUsesInternationalRate(): void
    {
        $link = StripeFee::coverForMethod('1100.00', [
            'type' => 'link',
            'link' => ['email' => 'zakaznik@example.com'],
        ]);
        $card = StripeFee::coverFor('1100.00', 'US');
        $this->assertSame($card['charge'], $link['charge']);
        $inspected = StripeFee::inspectMethod(['type' => 'link', 'link' => []]);
        $this->assertTrue($inspected['assumed']);
        $this->assertSame('US', $inspected['country']);
    }

    public function testLinkWalletKeepsRealCardCountry(): void
    {
        $priced = StripeFee::coverForMethod('1100.00', [
            'type' => 'card',
            'card' => [
                'country' => 'CZ',
                'wallet' => ['type' => 'link'],
            ],
        ]);
        $this->assertSame('1123.35', $priced['charge']);
        $inspected = StripeFee::inspectMethod([
            'type' => 'card',
            'card' => ['country' => 'CZ', 'wallet' => ['type' => 'link']],
        ]);
        $this->assertFalse($inspected['assumed']);
    }

    public function testApplePayCzechCardOn1490UsesEuropeanFee(): void
    {
        $priced = StripeFee::coverForMethod('1490.00', [
            'type' => 'card',
            'card' => [
                'country' => 'CZ',
                'brand' => 'visa',
                'wallet' => ['type' => 'apple_pay'],
            ],
        ]);
        $this->assertSame('29.29', $priced['fee']);
        $this->assertSame('1519.29', $priced['charge']);
        $this->assertSame('1545.17', StripeFee::coverFor('1490.00', 'US')['charge']);
    }

    public function testEmptyCountryStaysEuropean(): void
    {
        $priced = StripeFee::coverForMethod('1100.00', [
            'type' => 'card',
            'card' => ['country' => ''],
        ]);
        $this->assertSame(StripeFee::cover('1100.00')['charge'], $priced['charge']);
    }
}
