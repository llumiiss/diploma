<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Service\ReportService;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ReportScheduleTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function certificate(int $id, string $expiry, int $daysLeft, ?string $archivedAt = null): array
    {
        return ['id' => $id, 'expiry_date' => $expiry, 'days_left' => $daysLeft, 'archived_at' => $archivedAt];
    }

    public function testBucketsStartWithOverdueAndCoverRequestedMonths(): void
    {
        $today = new DateTimeImmutable('2026-09-17');
        $buckets = ReportService::scheduleBuckets([
            self::certificate(1, '2026-09-10', -7),
            self::certificate(2, '2026-09-30', 13),
            self::certificate(3, '2026-11-02', 46),
            self::certificate(4, '2026-11-20', 64),
            self::certificate(5, '2027-03-01', 165),
            self::certificate(6, '2026-10-01', 14, '2026-09-01 10:00:00'),
        ], $today, 3);

        $this->assertSame(['overdue', '2026-09', '2026-10', '2026-11'], array_column($buckets, 'key'));
        $this->assertTrue($buckets[0]['overdue']);
        $this->assertNull($buckets[0]['month']);
        $this->assertSame([1, 1, 0, 2], array_column($buckets, 'count'));
        $this->assertSame([3, 4], array_column($buckets[3]['certificates'], 'id'));
    }

    public function testMonthsRollOverTheYearEnd(): void
    {
        $buckets = ReportService::scheduleBuckets([
            self::certificate(1, '2027-01-31', 45),
        ], new DateTimeImmutable('2026-12-17'), 2);

        $this->assertSame(['overdue', '2026-12', '2027-01'], array_column($buckets, 'key'));
        $this->assertSame(1, $buckets[2]['count']);
    }

    public function testMonthsFromTheLastDayOfAMonthDoNotSkipFebruary(): void
    {
        $buckets = ReportService::scheduleBuckets([], new DateTimeImmutable('2027-01-31'), 3);

        $this->assertSame(['overdue', '2027-01', '2027-02', '2027-03'], array_column($buckets, 'key'));
    }
}
