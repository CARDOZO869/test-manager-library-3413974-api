<?php
declare(strict_types=1);
namespace App\Infrastructure\Time;
use App\Application\Ports\Clock;
use DateTimeImmutable;
use DateTimeZone;
final readonly class SystemClock implements Clock {
    public function __construct(private ?DateTimeImmutable $fixed = null) {}
    public function now(): DateTimeImmutable { return $this->fixed ?? new DateTimeImmutable('now',new DateTimeZone('UTC')); }
}
