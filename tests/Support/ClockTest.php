<?php

declare(strict_types=1);

namespace Golovanov\Weft\Tests\Support;

use Golovanov\Weft\Support\Clock;
use Golovanov\Weft\Tests\TestCase;

final class ClockTest extends TestCase
{
    public function testTimezoneFallsBackToUtcWithoutAppTimezoneConstant(): void
    {
        // APP_TIMEZONE is not defined in the test bootstrap.
        $this->assertSame('UTC', Clock::timezone()->getName());
    }

    public function testNowUsesTheApplicationTimezone(): void
    {
        $now = Clock::now();
        $this->assertSame('UTC', $now->getTimezone()->getName());
    }

    public function testDateAndTimestampFormats(): void
    {
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', Clock::date());
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', Clock::timestamp());
    }

    public function testOffsetFormats(): void
    {
        $this->assertSame('+00:00', Clock::numericOffset());
        $this->assertSame('UTC+00:00', Clock::offset());
    }
}
