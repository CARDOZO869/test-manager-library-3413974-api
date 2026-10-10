<?php

declare(strict_types=1);

namespace App\Domain\Entities;

use DateInterval;
use DateTimeImmutable;
use DomainException;
use InvalidArgumentException;

final class Loan
{
    /** @var array<string, LoanItem> */
    private array $items = [];

    public function __construct(
        public readonly string $id,
        public readonly string $memberId,
        public readonly string $loanedByUserId,
        public readonly DateTimeImmutable $loanedAt,
    ) {
        if ($id === '' || $memberId === '' || $loanedByUserId === '') {
            throw new InvalidArgumentException('Loan identifiers are required.');
        }
    }

    public function addItem(
        string $itemId,
        string $bookId,
        string $bookTitle,
        string $isbn,
        string $categoryName,
    ): LoanItem {
        if (count($this->items) >= 3) {
            throw new DomainException('LOAN_MAX_THREE_ITEMS');
        }
        foreach ($this->items as $item) {
            if ($item->bookId === $bookId) {
                throw new DomainException('DUPLICATE_BOOK_IN_LOAN');
            }
        }
        if (isset($this->items[$itemId])) {
            throw new DomainException('DUPLICATE_LOAN_ITEM');
        }

        $item = new LoanItem(
            $itemId,
            $bookId,
            $bookTitle,
            $isbn,
            $categoryName,
            $this->loanedAt->add(new DateInterval('P14D')),
        );
        $this->items[$itemId] = $item;
        return $item;
    }

    /** @return list<LoanItem> */
    public function items(): array
    {
        return array_values($this->items);
    }

    public function assertReadyToRegister(): void
    {
        if (count($this->items) < 1 || count($this->items) > 3) {
            throw new DomainException('LOAN_REQUIRES_ONE_TO_THREE_ITEMS');
        }
    }

    public function returnItem(string $itemId, DateTimeImmutable $when): void
    {
        if (!isset($this->items[$itemId])) {
            throw new DomainException('LOAN_ITEM_NOT_FOUND');
        }
        $this->items[$itemId]->returnAt($when);
    }

    public function status(DateTimeImmutable $now): string
    {
        if ($this->items !== [] && array_reduce($this->items, fn (bool $all, LoanItem $item): bool => $all && $item->isReturned(), true)) {
            return 'closed';
        }
        foreach ($this->items as $item) {
            if ($item->isOverdue($now)) {
                return 'overdue';
            }
        }
        return 'open';
    }
}
