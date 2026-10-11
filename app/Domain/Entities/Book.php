<?php

declare(strict_types=1);

namespace App\Domain\Entities;

use App\Domain\ValueObjects\Isbn;
use DateTimeImmutable;
use DomainException;
use InvalidArgumentException;

final class Book
{
    public int $availableCopies;
    public ?DateTimeImmutable $deletedAt;

    public function __construct(
        public readonly string $id,
        public string $title,
        public string $author,
        public readonly Isbn $isbn,
        public readonly string $categoryId,
        public int $totalCopies,
        ?int $availableCopies = null,
        public ?string $coverKey = null,
        ?DateTimeImmutable $deletedAt = null,
        public ?string $version = null,
    ) {
        $this->availableCopies = $availableCopies ?? $totalCopies;
        $this->deletedAt = $deletedAt;
        $this->assertInvariants();
    }

    public function withdraw(int $quantity = 1): void
    {
        $this->assertActive();
        $this->assertPositive($quantity);
        if ($quantity > $this->availableCopies) {
            throw new DomainException('Not enough available copies.');
        }
        $this->availableCopies -= $quantity;
    }

    public function restock(int $quantity = 1): void
    {
        $this->assertActive();
        $this->assertPositive($quantity);
        if ($quantity > $this->totalCopies - $this->availableCopies) {
            throw new DomainException('Restock would exceed total copies.');
        }
        $this->availableCopies += $quantity;
    }

    public function changeTotalCopies(int $newTotal): void
    {
        $this->assertActive();
        $this->assertPositive($newTotal);
        $newAvailable = $this->availableCopies + ($newTotal - $this->totalCopies);
        if ($newAvailable < 0) {
            throw new DomainException('New total is below the number of borrowed copies.');
        }
        $this->totalCopies = $newTotal;
        $this->availableCopies = $newAvailable;
    }

    public function softDelete(DateTimeImmutable $when, bool $hasOpenLoans): void
    {
        $this->assertActive();
        if ($hasOpenLoans) {
            throw new DomainException('Book with open loans cannot be deleted.');
        }
        $this->deletedAt = $when;
    }

    public function isActive(): bool
    {
        return $this->deletedAt === null;
    }

    private function assertInvariants(): void
    {
        if ($this->totalCopies < 1) {
            throw new InvalidArgumentException('Total copies must be at least one.');
        }
        if ($this->availableCopies < 0 || $this->availableCopies > $this->totalCopies) {
            throw new InvalidArgumentException('Available copies must be between zero and total copies.');
        }
        if ($this->coverKey === '') {
            throw new InvalidArgumentException('Cover key cannot be empty.');
        }
    }

    private function assertActive(): void
    {
        if (!$this->isActive()) {
            throw new DomainException('Cannot modify a deleted book.');
        }
    }

    private function assertPositive(int $quantity): void
    {
        if ($quantity < 1) {
            throw new InvalidArgumentException('Quantity must be greater than zero.');
        }
    }
}
