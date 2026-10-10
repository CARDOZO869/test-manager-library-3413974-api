<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Entities\Loan;
use DateTimeImmutable;
use DomainException;
use PHPUnit\Framework\TestCase;

final class LoanTest extends TestCase
{
    private function loan(): Loan
    {
        return new Loan('loan-1', 'member-1', 'user-1', new DateTimeImmutable('2026-10-01T12:00:00+00:00'));
    }

    private function add(Loan $loan, int $number): void
    {
        $loan->addItem("item-$number", "book-$number", "Title $number", '9780306406157', 'Novela');
    }

    public function test_loan_requires_at_least_one_item_before_registration(): void
    {
        $this->expectException(DomainException::class);
        $this->loan()->assertReadyToRegister();
    }

    public function test_loan_accepts_one_to_three_items(): void
    {
        $loan = $this->loan();
        for ($i = 1; $i <= 3; $i++) {
            $this->add($loan, $i);
        }
        $loan->assertReadyToRegister();
        self::assertCount(3, $loan->items());
    }

    public function test_loan_rejects_fourth_item(): void
    {
        $loan = $this->loan();
        for ($i = 1; $i <= 3; $i++) {
            $this->add($loan, $i);
        }
        $this->expectException(DomainException::class);
        $this->add($loan, 4);
    }

    public function test_loan_rejects_duplicate_book(): void
    {
        $loan = $this->loan();
        $this->add($loan, 1);
        $this->expectException(DomainException::class);
        $loan->addItem('item-2', 'book-1', 'Other', '9780306406157', 'Novela');
    }

    public function test_due_date_is_fourteen_days_after_loan(): void
    {
        $loan = $this->loan();
        $this->add($loan, 1);
        self::assertSame('2026-10-15T12:00:00+00:00', $loan->items()[0]->dueAt->format('Y-m-d\TH:i:sP'));
    }

    public function test_status_is_open_before_due_date(): void
    {
        $loan = $this->loan();
        $this->add($loan, 1);
        self::assertSame('open', $loan->status(new DateTimeImmutable('2026-10-15T12:00:00+00:00')));
    }

    public function test_status_is_overdue_after_due_date(): void
    {
        $loan = $this->loan();
        $this->add($loan, 1);
        self::assertSame('overdue', $loan->status(new DateTimeImmutable('2026-10-15T12:00:01+00:00')));
    }

    public function test_status_is_closed_after_all_items_are_returned(): void
    {
        $loan = $this->loan();
        $this->add($loan, 1);
        $this->add($loan, 2);
        $loan->returnItem('item-1', new DateTimeImmutable('2026-10-16T12:00:00+00:00'));
        self::assertSame('overdue', $loan->status(new DateTimeImmutable('2026-10-16T12:00:00+00:00')));
        $loan->returnItem('item-2', new DateTimeImmutable('2026-10-16T12:00:00+00:00'));
        self::assertSame('closed', $loan->status(new DateTimeImmutable('2026-10-20T12:00:00+00:00')));
    }

    public function test_item_cannot_be_returned_twice(): void
    {
        $loan = $this->loan();
        $this->add($loan, 1);
        $loan->returnItem('item-1', new DateTimeImmutable('2026-10-10T12:00:00+00:00'));
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('ITEM_ALREADY_RETURNED');
        $loan->returnItem('item-1', new DateTimeImmutable('2026-10-11T12:00:00+00:00'));
    }

    public function test_snapshot_values_remain_unchanged(): void
    {
        $loan = $this->loan();
        $loan->addItem('item-1', 'book-1', 'Original', '9780306406157', 'Historia');
        self::assertSame('Original', $loan->items()[0]->bookTitle);
        self::assertSame('Historia', $loan->items()[0]->categoryName);
    }
}
