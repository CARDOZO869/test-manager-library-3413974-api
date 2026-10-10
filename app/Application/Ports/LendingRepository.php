<?php
declare(strict_types=1);
namespace App\Application\Ports;
use App\Domain\Entities\Loan;
use App\Domain\Entities\Book;
use DateTimeImmutable;
interface LendingRepository {
    public function memberExists(string $memberId): bool;
    public function outstandingItemsCount(string $memberId): int;
    public function memberHasOverdueItems(string $memberId, DateTimeImmutable $now): bool;
    public function categoryName(string $categoryId): ?string;
    /** @param list<Book> $books */
    public function commitLoan(Loan $loan, array $books): void;
}
