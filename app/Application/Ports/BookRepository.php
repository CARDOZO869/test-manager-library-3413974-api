<?php
declare(strict_types=1);
namespace App\Application\Ports;
use App\Domain\Entities\Book;
interface BookRepository {
    public function findById(string $id): ?Book;
    public function findActiveByIsbn(string $isbn): ?Book;
    /** @return array{items:list<Book>,total:int} */
    public function search(?string $query, ?string $categoryId, int $page, int $size): array;
    public function hasOpenLoans(string $bookId): bool;
    public function save(Book $book): void;
}