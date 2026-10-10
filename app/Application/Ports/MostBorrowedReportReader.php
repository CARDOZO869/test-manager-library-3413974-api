<?php
declare(strict_types=1);
namespace App\Application\Ports;
use DateTimeImmutable;
interface MostBorrowedReportReader {
    /**
     * Fetch historical loan-item snapshots joined to their loan date.
     * Each entry represents one borrowed item, including returned items.
     * Dates are UTC and the range is [fromInclusive, untilExclusive).
     * @return list<array{book_id:string,book_title:string,category_name:string}>
     */
    public function borrowedSnapshots(DateTimeImmutable $fromInclusive, DateTimeImmutable $untilExclusive): array;
}