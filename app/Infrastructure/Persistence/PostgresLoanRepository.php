<?php
declare(strict_types=1);
namespace App\Infrastructure\Persistence;
use App\Application\Ports\LendingRepository;
use App\Application\Ports\ReturnsRepository;
use App\Domain\Entities\Book;
use App\Domain\Entities\Loan;
use App\Domain\ValueObjects\Isbn;
use DateTimeImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
final class PostgresLoanRepository implements LendingRepository, ReturnsRepository {
    private function date(DateTimeImmutable $value): string { return $value->format('Y-m-d H:i:s.uP'); }
    public function memberExists(string $memberId): bool {
        return DB::table('library.member')->where('id', $memberId)->exists();
    }
    public function outstandingItemsCount(string $memberId): int {
        return DB::table('library.loan_item as li')->join('library.loan as l','l.id','=','li.loan_id')
            ->where('l.member_id',$memberId)->whereNull('li.returned_at')->count();
    }
    public function memberHasOverdueItems(string $memberId, DateTimeImmutable $now): bool {
        return DB::table('library.loan_item as li')->join('library.loan as l','l.id','=','li.loan_id')
            ->where('l.member_id',$memberId)->whereNull('li.returned_at')->where('li.due_at','<',$this->date($now))->exists();
    }
    public function categoryName(string $categoryId): ?string {
        return DB::table('library.category')->where('id',$categoryId)->value('name');
    }
    public function commitLoan(Loan $loan, array $books): void {
        DB::transaction(function () use ($loan, $books): void {
            // Serializes all lending to the same member, including different books.
            $member = DB::table('library.member')->where('id',$loan->memberId)->lockForUpdate()->first();
            if ($member === null) throw new DomainException('MEMBER_NOT_FOUND');
            if ($this->memberHasOverdueItems($loan->memberId, $loan->loanedAt)) throw new DomainException('MEMBER_HAS_OVERDUE_ITEMS');
            $count = $this->outstandingItemsCount($loan->memberId);
            if ($count + count($loan->items()) > 3) throw new DomainException('MEMBER_LOAN_LIMIT:'.$count);
            $ids = array_map(static fn (Book $book): string => $book->id, $books);
            sort($ids, SORT_STRING);
            $versions = [];
            foreach ($books as $book) $versions[$book->id] = $book->version;
            $locked = DB::table('library.book')->whereIn('id',$ids)->orderBy('id')->get()->keyBy('id');
            foreach ($loan->items() as $item) {
                $row = $locked->get($item->bookId);
                if ($row === null || $row->deleted_at !== null) throw new DomainException('BOOK_NOT_FOUND');
                if ((int)$row->available_copies < 1) throw new DomainException('BOOK_OUT_OF_STOCK');
                // Never persist a snapshot based on an outdated catalog read.
                if ($row->title !== $item->bookTitle || trim($row->isbn) !== $item->isbn ||
                    $this->categoryName($row->category_id) !== $item->categoryName) {
                    throw new DomainException('BOOK_CHANGED_RETRY');
                }
            }
            DB::table('library.loan')->insert([
                'id'=>$loan->id,'member_id'=>$loan->memberId,'loaned_by_user_id'=>$loan->loanedByUserId,
                'loaned_at'=>$this->date($loan->loanedAt),
            ]);
            $items = $loan->items();
            usort($items, static fn($a, $b) => strcmp($a->bookId, $b->bookId));
            foreach ($items as $item) {
                $changed = DB::table('library.book')->where('id',$item->bookId)->whereNull('deleted_at')
                    ->whereRaw('xmin::text = ?', [$versions[$item->bookId]])
                    ->where('available_copies','>',0)->decrement('available_copies');
                if ($changed !== 1) throw new DomainException('BOOK_OUT_OF_STOCK');
                DB::table('library.loan_item')->insert([
                    'id'=>$item->id,'loan_id'=>$loan->id,'book_id'=>$item->bookId,
                    'book_title'=>$item->bookTitle,'isbn'=>$item->isbn,'category_name'=>$item->categoryName,
                    'due_at'=>$this->date($item->dueAt),'returned_at'=>null,
                ]);
            }
        }, 3);
    }
    public function findLoan(string $loanId): ?Loan {
        $row = DB::table('library.loan')->where('id',$loanId)->first();
        if ($row === null) return null;
        $loan = new Loan($row->id,$row->member_id,$row->loaned_by_user_id,new DateTimeImmutable($row->loaned_at));
        $items = DB::table('library.loan_item')->where('loan_id',$loanId)->orderBy('id')->get();
        foreach ($items as $item) {
            $loan->addItem($item->id,$item->book_id,$item->book_title,trim($item->isbn),$item->category_name);
            if ($item->returned_at !== null) $loan->returnItem($item->id,new DateTimeImmutable($item->returned_at));
        }
        return $loan;
    }
    public function findBook(string $bookId): ?Book {
        $row = DB::table('library.book')->selectRaw('*, xmin::text as row_version')->where('id',$bookId)->first();
        return $row === null ? null : new Book($row->id,$row->title,$row->author,new Isbn(trim($row->isbn)),
            $row->category_id,(int)$row->total_copies,(int)$row->available_copies,$row->cover_key,
            $row->deleted_at === null ? null : new DateTimeImmutable($row->deleted_at), $row->row_version);
    }
    public function commitReturn(string $loanId, string $itemId, DateTimeImmutable $when): void {
        DB::transaction(function () use ($loanId,$itemId,$when): void {
            $item = DB::table('library.loan_item')->where('loan_id',$loanId)->where('id',$itemId)->first();
            if ($item === null) throw new DomainException('LOAN_ITEM_NOT_FOUND');
            // Lock book first, same lock order as lending.
            $book = DB::table('library.book')->where('id',$item->book_id)->lockForUpdate()->first();
            if ($book === null) throw new DomainException('BOOK_NOT_FOUND');
            $item = DB::table('library.loan_item')->where('loan_id',$loanId)->where('id',$itemId)->lockForUpdate()->first();
            if ($item->returned_at !== null) throw new DomainException('ITEM_ALREADY_RETURNED');
            if ((int)$book->available_copies >= (int)$book->total_copies) throw new DomainException('BOOK_STOCK_FULL');
            $changed = DB::table('library.loan_item')->where('id',$itemId)->whereNull('returned_at')
                ->update(['returned_at'=>$this->date($when)]);
            if ($changed !== 1) throw new DomainException('ITEM_ALREADY_RETURNED');
            DB::table('library.book')->where('id',$book->id)->increment('available_copies');
        }, 3);
    }
    public function overdueItems(DateTimeImmutable $now): array {
        $rows = DB::table('library.loan_item as li')->join('library.loan as l','l.id','=','li.loan_id')
            ->join('library.member as m','m.id','=','l.member_id')
            ->whereNull('li.returned_at')->where('li.due_at','<',$this->date($now))
            ->orderBy('li.due_at')->get(['l.id as loan_id','li.id as item_id','li.book_title','m.full_name as member_name','li.due_at']);
        return $rows->map(static fn ($row): array => [
            'loan_id'=>$row->loan_id,'item_id'=>$row->item_id,'book_title'=>$row->book_title,
            'member_name'=>$row->member_name,'due_at'=>new DateTimeImmutable($row->due_at),
        ])->all();
    }
}
