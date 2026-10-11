<?php
declare(strict_types=1);
namespace App\Application\Services;
use App\Application\Ports\BookRepository;
use App\Application\Ports\CoverStorage;
use DomainException;
use InvalidArgumentException;
final readonly class BookCoverService {
    public const MAX_BYTES = 2097152;
    public function __construct(private BookRepository $books, private CoverStorage $storage) {}
    public function upload(string $actorRole, string $bookId, string $bytes): string {
        if ($actorRole !== 'admin') throw new DomainException('FORBIDDEN');
        $length = strlen($bytes);
        if ($length === 0 || $length > self::MAX_BYTES) throw new InvalidArgumentException('INVALID_COVER_SIZE');
        $image = @getimagesizefromstring($bytes);
        if ($image === false || !in_array($image[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
            throw new InvalidArgumentException('INVALID_COVER_TYPE');
        }
        $book = $this->books->findById($bookId);
        if ($book === null || !$book->isActive()) throw new DomainException('BOOK_NOT_FOUND');
        $extension = $image[2] === IMAGETYPE_JPEG ? 'jpg' : 'png';
        $key = $this->storage->put($bytes, $extension);
        $previousKey = $book->coverKey;
        $book->coverKey = $key;
        $this->books->save($book);
        if ($previousKey !== null && $previousKey !== $key) $this->storage->delete($previousKey);
        return $key;
    }
    public function remove(string $actorRole, string $bookId): void {
        if ($actorRole !== 'admin') throw new DomainException('FORBIDDEN');
        $book = $this->books->findById($bookId);
        if ($book === null || !$book->isActive()) throw new DomainException('BOOK_NOT_FOUND');
        $key = $book->coverKey;
        $book->coverKey = null;
        $this->books->save($book);
        if ($key !== null) $this->storage->delete($key);
    }
}
