<?php
declare(strict_types=1);
namespace Tests\Unit\Application;
use App\Application\Ports\BookRepository;
use App\Application\Ports\CoverStorage;
use App\Application\Services\BookCoverService;
use App\Domain\Entities\Book;
use App\Domain\ValueObjects\Isbn;
use DomainException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
final class BookCoverTest extends TestCase {
    private function fixture(): array {
        $book = new Book('book-1','Título','Autor',new Isbn('9780306406157'),'cat-1',2);
        $repo = new class($book) implements BookRepository {
            public function __construct(public Book $book) {}
            public function findById(string $id): ?Book { return $id === $this->book->id ? $this->book : null; }
            public function findActiveByIsbn(string $isbn): ?Book { return null; }
            public function search(?string $query, ?string $categoryId, int $page, int $size): array { return ['items'=>[],'total'=>0]; }
            public function hasOpenLoans(string $bookId): bool { return false; }
            public function save(Book $book): void { $this->book = $book; }
        };
        $storage = new class implements CoverStorage {
            public array $files = [];
            public function put(string $bytes, string $extension): string {
                $key = 'covers/opaque.'.$extension;
                $this->files[$key] = $bytes;
                return $key;
            }
            public function get(string $key): ?string { return $this->files[$key] ?? null; }
            public function delete(string $key): void { unset($this->files[$key]); }
        };
        return [$repo,$storage,new BookCoverService($repo,$storage)];
    }
    private function png(): string {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScL/nwAAAABJRU5ErkJggg==');
    }
    public function test_png_upload_stores_opaque_key(): void {
        [$repo,$storage,$service]=$this->fixture();
        $key=$service->upload('admin','book-1',$this->png());
        self::assertSame('covers/opaque.png',$key);
        self::assertSame($key,$repo->book->coverKey);
        self::assertSame($this->png(),$storage->get($key));
    }
    public function test_unsupported_file_is_rejected(): void {
        [,,$service]=$this->fixture();
        $this->expectExceptionMessage('INVALID_COVER_TYPE');
        $service->upload('admin','book-1','not an image');
    }
    public function test_more_than_two_megabytes_is_rejected(): void {
        [,,$service]=$this->fixture();
        $this->expectExceptionMessage('INVALID_COVER_SIZE');
        $service->upload('admin','book-1',str_repeat('a',BookCoverService::MAX_BYTES+1));
    }
    public function test_librarian_cannot_upload(): void {
        [,,$service]=$this->fixture();
        $this->expectExceptionMessage('FORBIDDEN');
        $service->upload('librarian','book-1',$this->png());
    }
    public function test_missing_book_is_rejected(): void {
        [,,$service]=$this->fixture();
        $this->expectExceptionMessage('BOOK_NOT_FOUND');
        $service->upload('admin','missing',$this->png());
    }
    public function test_empty_file_is_rejected(): void {
        [,,$service]=$this->fixture();
        $this->expectExceptionMessage('INVALID_COVER_SIZE');
        $service->upload('admin','book-1','');
    }
    public function test_removal_clears_reference_and_binary(): void {
        [$repo,$storage,$service]=$this->fixture();
        $key=$service->upload('admin','book-1',$this->png());
        $service->remove('admin','book-1');
        self::assertNull($repo->book->coverKey);
        self::assertNull($storage->get($key));
    }
}
