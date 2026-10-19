<?php

namespace Tests\Unit;

use App\Support\Volume;
use PHPUnit\Framework\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_meter_arithmetic_is_exact_without_floating_point(): void
    {
        $this->assertSame(50000, Volume::units('277') - Volume::units('227'));
        $this->assertSame(500000, Volume::charge(50000, 10000));
        $this->assertSame(1, Volume::charge(1, 500));
        $this->assertSame('0.001', Volume::decimal(1));
    }
}
