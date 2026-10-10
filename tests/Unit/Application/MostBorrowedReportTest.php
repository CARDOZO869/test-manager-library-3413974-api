<?php
declare(strict_types=1);
namespace Tests\Unit\Application;
use App\Application\Ports\MostBorrowedReportReader;
use App\Application\Services\MostBorrowedReportService;
use DateTimeImmutable;
use DomainException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
final class MostBorrowedReportTest extends TestCase {
    private function setupReport(): array {
        $reader = new class implements MostBorrowedReportReader {
            public array $entries = [];
            public function borrowedSnapshots(DateTimeImmutable $fromInclusive, DateTimeImmutable $untilExclusive): array {
                return array_values(array_map(
                    static fn (array $e): array => ['book_id'=>$e['book_id'],'book_title'=>$e['book_title'],'category_name'=>$e['category_name']],
                    array_filter($this->entries, static fn (array $e): bool => $e['loaned_at'] >= $fromInclusive && $e['loaned_at'] < $untilExclusive)
                ));
            }
        };
        return [$reader, new MostBorrowedReportService($reader)];
    }
    private function add(object $reader, string $id, string $title, string $category, string $date): void {
        $reader->entries[] = ['book_id'=>$id,'book_title'=>$title,'category_name'=>$category,'loaned_at'=>new DateTimeImmutable($date)];
    }
    public function test_counts_borrowed_items_and_orders_by_count(): void {
        [$reader, $service] = $this->setupReport();
        $this->add($reader,'b1','Zeta','Novela','2026-10-10T12:00:00+00:00');
        $this->add($reader,'b2','Alfa','Historia','2026-10-11T12:00:00+00:00');
        $this->add($reader,'b1','Zeta','Novela','2026-10-12T12:00:00+00:00');
        $result = $service->execute('admin','2026-10-10','2026-10-12');
        self::assertSame(2,$result['rows'][0]['times_borrowed']);
        self::assertSame('b1',$result['rows'][0]['book_id']);
        self::assertCount(2,$result['rows']);
    }
    public function test_date_range_is_inclusive_on_both_calendar_dates(): void {
        [$reader,$service] = $this->setupReport();
        $this->add($reader,'b1','Libro','Novela','2026-10-10T00:00:00+00:00');
        $this->add($reader,'b1','Libro','Novela','2026-10-12T23:59:59+00:00');
        $this->add($reader,'b1','Libro','Novela','2026-10-13T00:00:00+00:00');
        self::assertSame(2,$service->execute('admin','2026-10-10','2026-10-12')['rows'][0]['times_borrowed']);
    }
    public function test_historical_title_and_category_are_separate_groups(): void {
        [$reader,$service] = $this->setupReport();
        $this->add($reader,'b1','Título antiguo','Novela','2026-10-10T12:00:00+00:00');
        $this->add($reader,'b1','Título nuevo','Ensayo','2026-10-11T12:00:00+00:00');
        self::assertCount(2,$service->execute('admin','2026-10-01','2026-10-31')['rows']);
    }
    public function test_tie_breaks_by_title(): void {
        [$reader,$service] = $this->setupReport();
        $this->add($reader,'b1','Zeta','Novela','2026-10-10T12:00:00+00:00');
        $this->add($reader,'b2','Alfa','Novela','2026-10-10T12:00:00+00:00');
        self::assertSame('Alfa',$service->execute('admin','2026-10-01','2026-10-31')['rows'][0]['book_title']);
    }
    public function test_empty_range_returns_empty_rows(): void {
        [, $service] = $this->setupReport();
        self::assertSame([],$service->execute('admin','2026-10-01','2026-10-31')['rows']);
    }
    public function test_reversed_dates_are_rejected(): void {
        [, $service] = $this->setupReport();
        $this->expectException(InvalidArgumentException::class);
        $service->execute('admin','2026-10-31','2026-10-01');
    }
    public function test_invalid_calendar_date_is_rejected(): void {
        [, $service] = $this->setupReport();
        $this->expectExceptionMessage('INVALID_DATE');
        $service->execute('admin','2026-02-30','2026-10-31');
    }
    public function test_librarian_cannot_view_report(): void {
        [, $service] = $this->setupReport();
        $this->expectExceptionMessage('FORBIDDEN');
        $service->execute('librarian','2026-10-01','2026-10-31');
    }
    public function test_report_never_contains_member_or_librarian_fields(): void {
        [$reader,$service] = $this->setupReport();
        $this->add($reader,'b1','Libro','Novela','2026-10-10T12:00:00+00:00');
        $result = $service->execute('admin','2026-10-01','2026-10-31');
        self::assertSame(['book_id','book_title','category_name','times_borrowed'],array_keys($result['rows'][0]));
    }
}
