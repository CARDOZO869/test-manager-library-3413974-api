<?php

declare(strict_types=1);

namespace App\Domain\Entities;

use DateTimeImmutable;
use DomainException;
use InvalidArgumentException;

final class LoanItem
{
    private ?DateTimeImmutable $returnedAt;

    public function __construct(
        public readonly string $id,
        public readonly string $bookId,
        public readonly string $bookTitle,
        public readonly string $isbn,
        public readonly string $categoryName,
        public readonly DateTimeImmutable $dueAt,
        ?DateTimeImmutable $returnedAt = null,
    ) {
        if ($id === '' || $bookId === '' || $bookTitle === '' || $categoryName === '' || preg_match('/^[0-9]{13}$/D', $isbn) !== 1) {
            throw new InvalidArgumentException('Invalid loan item snapshot.');
        }
        $this->returnedAt = $returnedAt;
    }

    public function isReturned(): bool
    {
        return $this->returnedAt !== null;
    }

    public function returnedAt(): ?DateTimeImmutable
    {
        return $this->returnedAt;
    }

    public function isOverdue(DateTimeImmutable $now): bool
    {
        return !$this->isReturned() && $this->dueAt < $now;
    }

    public function returnAt(DateTimeImmutable $when): void
    {
        if ($this->isReturned()) {
            throw new DomainException('ITEM_ALREADY_RETURNED');
        }
        $this->returnedAt = $when;
    }
}
