<?php
declare(strict_types=1);
namespace App\Application\Services;
use App\Application\Ports\BookRepository;
use App\Domain\Entities\Book;
use App\Domain\ValueObjects\Isbn;
use DateTimeImmutable;
use DomainException;
use InvalidArgumentException;
final readonly class BookCatalogService {
    public function __construct(private BookRepository $books) {}
    public function create(string $actorRole, string $id, string $title, string $author, string $isbn, string $categoryId, int $totalCopies): Book {
        $this->assertAdmin($actorRole);
        $this->assertText($title, 250);
        $this->assertText($author, 200);
        $this->assertText($categoryId, 100);
        $isbnValue = new Isbn($isbn);
        if ($this->books->findActiveByIsbn($isbnValue->value) !== null) {
            throw new DomainException('ISBN_TAKEN');
        }
        $book = new Book($id, trim($title), trim($author), $isbnValue, $categoryId, $totalCopies);
        $this->books->save($book);
        return $book;
    }
    public function get(string $id): Book {
        $book = $this->books->findById($id);
        if ($book === null || !$book->isActive()) {
            throw new DomainException('BOOK_NOT_FOUND');
        }
        return $book;
    }
    /** @return array{items:list<Book>,total:int} */
    public function search(?string $query = null, ?string $categoryId = null, int $page = 1, int $size = 20): array {
        if ($page < 1 || $size < 1 || $size > 50) {
            throw new InvalidArgumentException('INVALID_PAGINATION');
        }
        return $this->books->search($query, $categoryId, $page, $size);
    }
    public function update(string $actorRole, string $id, string $title, string $author, string $categoryId, int $totalCopies): Book {
        $this->assertAdmin($actorRole);
        $this->assertText($title, 250);
        $this->assertText($author, 200);
        $this->assertText($categoryId, 100);
        $book = $this->get($id);
        $book->changeTotalCopies($totalCopies);
        $book->title = trim($title);
        $book->author = trim($author);
        // Book.categoryId is readonly: category reassignment is performed by
        // reconstructing the aggregate while retaining stock and ISBN.
        $updated = new Book($book->id, $book->title, $book->author, $book->isbn, $categoryId, $book->totalCopies, $book->availableCopies, $book->coverKey, $book->deletedAt);
        $this->books->save($updated);
        return $updated;
    }
    public function delete(string $actorRole, string $id, DateTimeImmutable $when): void {
        $this->assertAdmin($actorRole);
        $book = $this->get($id);
        $book->softDelete($when, $this->books->hasOpenLoans($id));
        $this->books->save($book);
    }
    public function setCoverKey(string $actorRole, string $id, ?string $key): Book {
        $this->assertAdmin($actorRole);
        if ($key === '' || ($key !== null && mb_strlen($key) > 512)) {
            throw new InvalidArgumentException('INVALID_COVER_KEY');
        }
        $book = $this->get($id);
        $book->coverKey = $key;
        $this->books->save($book);
        return $book;
    }
    private function assertAdmin(string $role): void {
        if ($role !== 'admin') {
            throw new DomainException('FORBIDDEN');
        }
    }
    private function assertText(string $text, int $maxLength): void {
        if (trim($text) === '' || mb_strlen(trim($text)) > $maxLength) {
            throw new InvalidArgumentException('INVALID_BOOK_FIELD');
        }
    }
}