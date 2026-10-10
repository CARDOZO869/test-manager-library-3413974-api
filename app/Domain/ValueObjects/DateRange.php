<?php

declare(strict_types=1);

namespace App\Domain\ValueObjects;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class DateRange
{
    public function __construct(
        public DateTimeImmutable $start,
        public DateTimeImmutable $end,
    ) {
        if ($end < $start) {
            throw new InvalidArgumentException('Date range end must be greater than or equal to start.');
        }
    }

    public function contains(DateTimeImmutable $instant): bool
    {
        return $instant >= $this->start && $instant <= $this->end;
    }
}
