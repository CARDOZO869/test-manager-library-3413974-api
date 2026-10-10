<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Entities\Book;
use App\Domain\ValueObjects\Isbn;
use DateTimeImmutable;
use DomainException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class BookTest extends TestCase
{
    private function book(int $total = 3, ?int $available = null): Book
    {
        return new Book('book-1', 'Título', 'Autor', new Isbn('9780306406157'), 'category-1', $total, $available);
    }

    public function test_new_book_starts_with_all_copies_available(): void
    {
        self::assertSame(3, $this->book()->availableCopies);
    }

    public function test_withdraw_and_restock_preserve_bounds(): void
    {
        $book = $this->book();
        $book->withdraw(2);
        self::assertSame(1, $book->availableCopies);
        $book->restock();
        self::assertSame(2, $book->availableCopies);
    }

    public function test_cannot_withdraw_beyond_available(): void
    {
        $book = $this->book(3, 1);
        $this->expectException(DomainException::class);
        $book->withdraw(2);
    }

    public function test_cannot_restock_above_total(): void
    {
        $book = $this->book();
        $this->expectException(DomainException::class);
        $book->restock();
    }

    public function test_changing_total_adjusts_available_by_same_difference(): void
    {
        $book = $this->book(5, 3);
        $book->changeTotalCopies(7);
        self::assertSame(7, $book->totalCopies);
        self::assertSame(5, $book->availableCopies);
    }

    public function test_cannot_reduce_total_below_borrowed_copies(): void
    {
        $book = $this->book(5, 1);
        $this->expectException(DomainException::class);
        $book->changeTotalCopies(3);
    }

    public function test_cannot_soft_delete_with_open_loans(): void
    {
        $book = $this->book();
        $this->expectException(DomainException::class);
        $book->softDelete(new DateTimeImmutable('2026-10-10T12:00:00+00:00'), true);
    }

    public function test_soft_delete_marks_book_inactive(): void
    {
        $book = $this->book();
        $book->softDelete(new DateTimeImmutable('2026-10-10T12:00:00+00:00'), false);
        self::assertFalse($book->isActive());
    }

    public function test_deleted_book_cannot_be_withdrawn(): void
    {
        $book = $this->book();
        $book->softDelete(new DateTimeImmutable('2026-10-10T12:00:00+00:00'), false);
        $this->expectException(DomainException::class);
        $book->withdraw();
    }

    public function test_invalid_initial_stock_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->book(3, 4);
    }

    public function test_nonpositive_quantity_is_rejected(): void
    {
        $book = $this->book();
        $this->expectException(InvalidArgumentException::class);
        $book->withdraw(0);
    }
}
