<?php
declare(strict_types=1);
namespace Tests\Unit\Application;
use App\Application\Ports\BookRepository;
use App\Application\Services\BookCatalogService;
use App\Domain\Entities\Book;
use DateTimeImmutable;
use DomainException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
final class BookCatalogTest extends TestCase {
    private function setupCatalog(): array {
        $repo = new class implements BookRepository {
            public array $items = [];
            public bool $openLoans = false;
            public function findById(string $id): ?Book { return $this->items[$id] ?? null; }
            public function findActiveByIsbn(string $isbn): ?Book {
                foreach ($this->items as $book) {
                    if ($book->isActive() && $book->isbn->value === $isbn) return $book;
                }
                return null;
            }
            public function search(?string $query, ?string $categoryId, int $page, int $size): array {
                $items = array_values(array_filter($this->items, static function (Book $book) use ($query, $categoryId): bool {
                    return $book->isActive()
                        && ($categoryId === null || $book->categoryId === $categoryId)
                        && ($query === null || stripos($book->title.' '.$book->author, $query) !== false);
                }));
                usort($items, static fn (Book $a, Book $b): int => strcmp($a->title, $b->title));
                return ['items' => array_slice($items, ($page - 1) * $size, $size), 'total' => count($items)];
            }
            public function hasOpenLoans(string $bookId): bool { return $this->openLoans; }
            public function save(Book $book): void { $this->items[$book->id] = $book; }
        };
        return [$repo, new BookCatalogService($repo)];
    }
    private function create(BookCatalogService $service, string $id = 'b1', string $isbn = '9780306406157'): Book {
        return $service->create('admin', $id, ' Libro ', ' Autor ', $isbn, 'cat1', 4);
    }
    public function test_admin_creates_book_with_all_copies_available(): void {
        [, $service] = $this->setupCatalog();
        $book = $this->create($service);
        self::assertSame('Libro', $book->title);
        self::assertSame(4, $book->availableCopies);
    }
    public function test_duplicate_active_isbn_rejected(): void {
        [, $service] = $this->setupCatalog();
        $this->create($service);
        $this->expectExceptionMessage('ISBN_TAKEN');
        $this->create($service, 'b2');
    }
    public function test_librarian_cannot_create_book(): void {
        [, $service] = $this->setupCatalog();
        $this->expectExceptionMessage('FORBIDDEN');
        $service->create('librarian', 'b1', 'Libro', 'Autor', '9780306406157', 'cat1', 4);
    }
    public function test_search_filters_and_paginates_active_books(): void {
        [, $service] = $this->setupCatalog();
        $this->create($service);
        $service->create('admin', 'b2', 'Otra', 'Otro', '9780307474728', 'cat2', 2);
        $result = $service->search('lib', 'cat1', 1, 1);
        self::assertSame(1, $result['total']);
        self::assertCount(1, $result['items']);
    }
    public function test_search_rejects_page_size_above_fifty(): void {
        [, $service] = $this->setupCatalog();
        $this->expectException(InvalidArgumentException::class);
        $service->search(null, null, 1, 51);
    }
    public function test_update_adjusts_total_and_category(): void {
        [, $service] = $this->setupCatalog();
        $this->create($service);
        $book = $service->update('admin', 'b1', 'Nuevo', 'Nueva autora', 'cat2', 6);
        self::assertSame(6, $book->availableCopies);
        self::assertSame('cat2', $book->categoryId);
        self::assertSame('Nuevo', $service->get('b1')->title);
    }
    public function test_delete_hides_book_from_search_and_get(): void {
        [, $service] = $this->setupCatalog();
        $this->create($service);
        $service->delete('admin', 'b1', new DateTimeImmutable('2026-10-10T00:00:00+00:00'));
        self::assertSame(0, $service->search()['total']);
        $this->expectExceptionMessage('BOOK_NOT_FOUND');
        $service->get('b1');
    }
    public function test_delete_rejects_open_loans(): void {
        [$repo, $service] = $this->setupCatalog();
        $this->create($service);
        $repo->openLoans = true;
        $this->expectException(DomainException::class);
        $service->delete('admin', 'b1', new DateTimeImmutable('2026-10-10T00:00:00+00:00'));
    }
    public function test_cover_key_can_be_added_and_removed(): void {
        [, $service] = $this->setupCatalog();
        $this->create($service);
        self::assertSame('opaque-123', $service->setCoverKey('admin', 'b1', 'opaque-123')->coverKey);
        self::assertNull($service->setCoverKey('admin', 'b1', null)->coverKey);
    }
    public function test_empty_cover_key_is_rejected(): void {
        [, $service] = $this->setupCatalog();
        $this->create($service);
        $this->expectException(InvalidArgumentException::class);
        $service->setCoverKey('admin', 'b1', '');
    }
}
