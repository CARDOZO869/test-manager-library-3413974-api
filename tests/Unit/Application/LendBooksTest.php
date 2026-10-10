<?php
declare(strict_types=1);
namespace Tests\Unit\Application;
use App\Application\Ports\BookRepository;
use App\Application\Ports\LendingRepository;
use App\Application\Services\LendBooksService;
use App\Domain\Entities\Book;
use App\Domain\Entities\Loan;
use App\Domain\ValueObjects\Isbn;
use DateTimeImmutable;
use DomainException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
final class LendBooksTest extends TestCase {
    private function setupService(): array {
        $books = new class implements BookRepository {
            public array $items = [];
            public function findById(string $id): ?Book { return $this->items[$id] ?? null; }
            public function findActiveByIsbn(string $isbn): ?Book { return null; }
            public function search(?string $query, ?string $categoryId, int $page, int $size): array { return ['items' => [], 'total' => 0]; }
            public function hasOpenLoans(string $bookId): bool { return false; }
            public function save(Book $book): void { $this->items[$book->id] = $book; }
        };
        $lending = new class implements LendingRepository {
            public bool $exists = true;
            public bool $overdue = false;
            public int $outstanding = 0;
            public array $saved = [];
            public function memberExists(string $memberId): bool { return $this->exists; }
            public function outstandingItemsCount(string $memberId): int { return $this->outstanding; }
            public function memberHasOverdueItems(string $memberId, DateTimeImmutable $now): bool { return $this->overdue; }
            public function categoryName(string $categoryId): ?string { return 'Novela'; }
            public function commitLoan(Loan $loan, array $books): void {
                foreach ($books as $book) {
                    if ($book->availableCopies < 1) throw new DomainException('BOOK_OUT_OF_STOCK');
                }
                foreach ($books as $book) $book->withdraw();
                $this->saved[] = $loan;
            }
        };
        $books->save(new Book('b1', 'Libro uno', 'Autor', new Isbn('9780306406157'), 'cat', 1));
        $books->save(new Book('b2', 'Libro dos', 'Autor', new Isbn('9780307474728'), 'cat', 2));
        return [$books, $lending, new LendBooksService($books, $lending)];
    }
    private function lend(LendBooksService $service, array $bookIds = ['b1'], array $itemIds = ['i1']): Loan {
        return $service->execute('loan1', 'member1', 'user1', $bookIds, $itemIds, new DateTimeImmutable('2026-10-10T12:00:00+00:00'));
    }
    public function test_valid_loan_decrements_stock_and_freezes_snapshot(): void {
        [$books, $lending, $service] = $this->setupService();
        $loan = $this->lend($service, ['b1', 'b2'], ['i1', 'i2']);
        self::assertSame(0, $books->items['b1']->availableCopies);
        self::assertSame(1, $books->items['b2']->availableCopies);
        self::assertSame('Libro uno', $loan->items()[0]->bookTitle);
        self::assertSame('Novela', $loan->items()[0]->categoryName);
        self::assertCount(1, $lending->saved);
    }
    public function test_empty_loan_is_rejected(): void {
        [, , $service] = $this->setupService();
        $this->expectException(InvalidArgumentException::class);
        $this->lend($service, [], []);
    }
    public function test_duplicate_book_rejected_without_stock_change(): void {
        [$books, $lending, $service] = $this->setupService();
        try { $this->lend($service, ['b1', 'b1'], ['i1', 'i2']); self::fail('Expected exception'); }
        catch (InvalidArgumentException $e) { self::assertSame(1, $books->items['b1']->availableCopies); self::assertCount(0, $lending->saved); }
    }
    public function test_more_than_three_books_rejected(): void {
        [, , $service] = $this->setupService();
        $this->expectException(InvalidArgumentException::class);
        $this->lend($service, ['b1', 'b2', 'b3', 'b4'], ['i1', 'i2', 'i3', 'i4']);
    }
    public function test_member_with_overdue_items_is_blocked(): void {
        [, $lending, $service] = $this->setupService();
        $lending->overdue = true;
        $this->expectExceptionMessage('MEMBER_HAS_OVERDUE_ITEMS');
        $this->lend($service);
    }
    public function test_member_cannot_exceed_three_outstanding_books(): void {
        [, $lending, $service] = $this->setupService();
        $lending->outstanding = 3;
        $this->expectExceptionMessage('MEMBER_LOAN_LIMIT:3');
        $this->lend($service);
    }
    public function test_out_of_stock_rejected_without_writing_loan(): void {
        [$books, $lending, $service] = $this->setupService();
        $books->items['b1']->withdraw();
        $this->expectExceptionMessage('BOOK_OUT_OF_STOCK');
        try { $this->lend($service); }
        finally { self::assertCount(0, $lending->saved); }
    }
    public function test_missing_book_rejected_without_stock_change(): void {
        [$books, $lending, $service] = $this->setupService();
        $this->expectExceptionMessage('BOOK_NOT_FOUND');
        try { $this->lend($service, ['b1', 'missing'], ['i1', 'i2']); }
        finally { self::assertSame(1, $books->items['b1']->availableCopies); self::assertCount(0, $lending->saved); }
    }
    public function test_missing_member_is_rejected(): void {
        [, $lending, $service] = $this->setupService();
        $lending->exists = false;
        $this->expectExceptionMessage('MEMBER_NOT_FOUND');
        $this->lend($service);
    }
    public function test_loan_due_date_is_fourteen_days(): void {
        [, , $service] = $this->setupService();
        $loan = $this->lend($service);
        self::assertSame('2026-10-24', $loan->items()[0]->dueAt->format('Y-m-d'));
    }
}
