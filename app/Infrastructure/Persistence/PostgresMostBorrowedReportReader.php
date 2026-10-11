<?php
declare(strict_types=1);
namespace App\Infrastructure\Persistence;
use App\Application\Ports\MostBorrowedReportReader;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
final class PostgresMostBorrowedReportReader implements MostBorrowedReportReader {
    public function borrowedSnapshots(DateTimeImmutable $fromInclusive, DateTimeImmutable $untilExclusive): array {
        return DB::table('library.loan_item as li')
            ->join('library.loan as l','l.id','=','li.loan_id')
            ->where('l.loaned_at','>=',$fromInclusive->format('Y-m-d H:i:s.uP'))
            ->where('l.loaned_at','<',$untilExclusive->format('Y-m-d H:i:s.uP'))
            ->selectRaw('li.book_id, li.book_title, li.category_name, COUNT(*) AS times_borrowed')
            ->groupBy('li.book_id','li.book_title','li.category_name')
            ->get()
            ->map(static fn($r):array => ['book_id'=>$r->book_id,'book_title'=>$r->book_title,'category_name'=>$r->category_name,'times_borrowed'=>(int)$r->times_borrowed])
            ->all();
    }
}
