<?php
declare(strict_types=1);
namespace App\Infrastructure\Persistence;
use App\Application\Ports\BookRepository;
use App\Domain\Entities\Book;
use App\Domain\ValueObjects\Isbn;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
final class PostgresBookRepository implements BookRepository {
    private function hydrate(object $row): Book {
        return new Book($row->id, $row->title, $row->author, new Isbn(trim($row->isbn)), $row->category_id,
            (int) $row->total_copies, (int) $row->available_copies, $row->cover_key,
            $row->deleted_at === null ? null : new DateTimeImmutable($row->deleted_at));
    }
    public function findById(string $id): ?Book {
        $row = DB::table('library.book')->where('id', $id)->first();
        return $row === null ? null : $this->hydrate($row);
    }
    public function findActiveByIsbn(string $isbn): ?Book {
        $row = DB::table('library.book')->where('isbn', $isbn)->whereNull('deleted_at')->first();
        return $row === null ? null : $this->hydrate($row);
    }
    public function search(?string $query, ?string $categoryId, int $page, int $size): array {
        $builder = DB::table('library.book')->whereNull('deleted_at');
        if ($categoryId !== null) $builder->where('category_id', $categoryId);
        if ($query !== null && trim($query) !== '') {
            $term = '%'.str_replace(['\\','%','_'], ['\\\\','\\%','\\_'], trim($query)).'%';
            $builder->where(static fn ($q) => $q->whereRaw("title ILIKE ? ESCAPE '\\'", [$term])
                ->orWhereRaw("author ILIKE ? ESCAPE '\\'", [$term]));
        }
        $total = (clone $builder)->count();
        $items = $builder->orderBy('title')->orderBy('id')->offset(($page - 1) * $size)->limit($size)->get();
        return ['items' => $items->map(fn ($row) => $this->hydrate($row))->all(), 'total' => $total];
    }
    public function hasOpenLoans(string $bookId): bool {
        return DB::table('library.loan_item')->where('book_id', $bookId)->whereNull('returned_at')->exists();
    }
    public function save(Book $book): void {
        DB::table('library.book')->upsert([[
            'id' => $book->id, 'title' => $book->title, 'author' => $book->author,
            'isbn' => $book->isbn->value, 'category_id' => $book->categoryId,
            'total_copies' => $book->totalCopies, 'available_copies' => $book->availableCopies,
            'cover_key' => $book->coverKey, 'deleted_at' => $book->deletedAt?->format('Y-m-d H:i:s.uP'),
        ]], ['id'], ['title','author','isbn','category_id','total_copies','available_copies','cover_key','deleted_at']);
    }
}