<?php
declare(strict_types=1);
namespace App\Application\Ports;
use App\Domain\Entities\Loan;
use App\Domain\Entities\Book;
use DateTimeImmutable;
interface ReturnsRepository {
    public function findLoan(string $loanId): ?Loan;
    public function findBook(string $bookId): ?Book;
    /**
     * Implement atomically: lock loan item and book, verify not returned,
     * set returned_at and increment available copies once, then commit.
     */
    public function commitReturn(string $loanId, string $itemId, DateTimeImmutable $when): void;
    /**
     * Return only outstanding items with due_at < now, with member names.
     * @return list<array{loan_id:string,item_id:string,book_title:string,member_name:string,due_at:DateTimeImmutable}>
     */
    public function overdueItems(DateTimeImmutable $now): array;
}