<?php
declare(strict_types=1);
namespace App\Application\Ports;
use DateTimeImmutable;
interface MostBorrowedReportReader {
    /**
     * Fetch historical loan-item snapshots joined to their loan date.
     * Entries may carry a preaggregated count; otherwise each represents one item.
     * Dates are UTC and the range is [fromInclusive, untilExclusive).
     * @return list<array{book_id:string,book_title:string,category_name:string,times_borrowed?:int}>
     */
    public function borrowedSnapshots(DateTimeImmutable $fromInclusive, DateTimeImmutable $untilExclusive): array;
}
