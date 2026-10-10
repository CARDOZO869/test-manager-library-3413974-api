<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\ValueObjects\DateRange;
use App\Domain\ValueObjects\Isbn;
use App\Domain\ValueObjects\Quantity;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ValueObjectsTest extends TestCase
{
    public function test_isbn_accepts_exactly_thirteen_digits(): void
    {
        self::assertSame('9780306406157', (string) new Isbn('9780306406157'));
    }

    public function test_isbn_rejects_invalid_characters_and_lengths(): void
    {
        foreach (['978030640615', '97803064061570', '978030640615X', ' 9780306406157'] as $invalid) {
            try {
                new Isbn($invalid);
                self::fail('Expected invalid ISBN: '.$invalid);
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_quantity_must_be_positive(): void
    {
        self::assertSame(3, (new Quantity(3))->value);
        foreach ([0, -1] as $invalid) {
            try {
                new Quantity($invalid);
                self::fail('Expected invalid quantity: '.$invalid);
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_date_range_accepts_equal_boundaries(): void
    {
        $date = new DateTimeImmutable('2026-10-10T12:00:00+00:00');
        $range = new DateRange($date, $date);
        self::assertTrue($range->contains($date));
    }

    public function test_date_range_rejects_end_before_start(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new DateRange(
            new DateTimeImmutable('2026-10-11T00:00:00+00:00'),
            new DateTimeImmutable('2026-10-10T00:00:00+00:00'),
        );
    }

    public function test_date_range_contains_only_instants_within_bounds(): void
    {
        $range = new DateRange(
            new DateTimeImmutable('2026-10-10T00:00:00+00:00'),
            new DateTimeImmutable('2026-10-12T00:00:00+00:00'),
        );
        self::assertTrue($range->contains(new DateTimeImmutable('2026-10-11T00:00:00+00:00')));
        self::assertFalse($range->contains(new DateTimeImmutable('2026-10-13T00:00:00+00:00')));
    }
}
