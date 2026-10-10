<?php
declare(strict_types=1);
namespace Tests\Unit\Application;
use App\Application\Ports\ReturnsRepository;
use App\Application\Services\ListOverdueService;
use App\Application\Services\ReturnBookService;
use App\Domain\Entities\Book;
use App\Domain\Entities\Loan;
use App\Domain\ValueObjects\Isbn;
use DateTimeImmutable;
use DomainException;
use PHPUnit\Framework\TestCase;
final class ReturnsTest extends TestCase {
    private function fixtures(): array {
        $repo = new class implements ReturnsRepository {
            public ?Loan $loan = null;
            public ?Book $book = null;
            public array $overdue = [];
            public int $commits = 0;
            public function findLoan(string $loanId): ?Loan { return $this->loan?->id === $loanId ? $this->loan : null; }
            public function findBook(string $bookId): ?Book { return $this->book?->id === $bookId ? $this->book : null; }
            public function commitReturn(string $loanId, string $itemId, DateTimeImmutable $when): void {
                $loan = $this->findLoan($loanId);
                if ($loan === null || $this->book === null) throw new DomainException('NOT_FOUND');
                foreach ($loan->items() as $item) {
                    if ($item->id !== $itemId) continue;
                    if ($item->isReturned()) throw new DomainException('ITEM_ALREADY_RETURNED');
                    if ($this->book->availableCopies >= $this->book->totalCopies) throw new DomainException('BOOK_STOCK_FULL');
                    $loan->returnItem($itemId, $when);
                    $this->book->restock();
                    $this->commits++;
                    return;
                }
                throw new DomainException('LOAN_ITEM_NOT_FOUND');
            }
            public function overdueItems(DateTimeImmutable $now): array { return $this->overdue; }
        };
        $repo->book = new Book('book1', 'Libro', 'Autor', new Isbn('9780306406157'), 'cat1', 2, 1);
        $repo->loan = new Loan('loan1', 'member1', 'user1', new DateTimeImmutable('2026-10-01T12:00:00+00:00'));
        $repo->loan->addItem('item1', 'book1', 'Libro', '9780306406157', 'Novela');
        return [$repo, new ReturnBookService($repo), new ListOverdueService($repo)];
    }
    public function test_return_marks_item_and_restocks_exactly_one_copy(): void {
        [$repo, $service] = $this->fixtures();
        $item = $service->execute('loan1', 'item1', new DateTimeImmutable('2026-10-12T12:00:00+00:00'));
        self::assertTrue($item->isReturned());
        self::assertSame(2, $repo->book->availableCopies);
        self::assertSame(1, $repo->commits);
        self::assertSame('closed', $repo->loan->status(new DateTimeImmutable('2026-10-12T12:00:00+00:00')));
    }
    public function test_return_twice_is_rejected_without_extra_stock(): void {
        [$repo, $service] = $this->fixtures();
        $when = new DateTimeImmutable('2026-10-12T12:00:00+00:00');
        $service->execute('loan1', 'item1', $when);
        try {
            $service->execute('loan1', 'item1', $when);
            self::fail('Expected duplicate return error');
        } catch (DomainException $e) {
            self::assertSame('ITEM_ALREADY_RETURNED', $e->getMessage());
            self::assertSame(2, $repo->book->availableCopies);
            self::assertSame(1, $repo->commits);
        }
    }
    public function test_unknown_loan_is_rejected(): void {
        [, $service] = $this->fixtures();
        $this->expectExceptionMessage('LOAN_NOT_FOUND');
        $service->execute('unknown', 'item1', new DateTimeImmutable('2026-10-12T12:00:00+00:00'));
    }
    public function test_unknown_item_is_rejected(): void {
        [, $service] = $this->fixtures();
        $this->expectExceptionMessage('LOAN_ITEM_NOT_FOUND');
        $service->execute('loan1', 'unknown', new DateTimeImmutable('2026-10-12T12:00:00+00:00'));
    }
    public function test_missing_book_is_rejected(): void {
        [$repo, $service] = $this->fixtures();
        $repo->book = null;
        $this->expectExceptionMessage('BOOK_NOT_FOUND');
        $service->execute('loan1', 'item1', new DateTimeImmutable('2026-10-12T12:00:00+00:00'));
    }
    public function test_overdue_excludes_due_today_and_sorts_oldest_first(): void {
        [$repo, , $service] = $this->fixtures();
        $now = new DateTimeImmutable('2026-10-20T12:00:00+00:00');
        $repo->overdue = [
            ['loan_id'=>'l2','item_id'=>'i2','book_title'=>'Segundo','member_name'=>'Ana','due_at'=>new DateTimeImmutable('2026-10-19T12:00:00+00:00')],
            ['loan_id'=>'l3','item_id'=>'i3','book_title'=>'Hoy','member_name'=>'Juan','due_at'=>$now],
            ['loan_id'=>'l1','item_id'=>'i1','book_title'=>'Primero','member_name'=>'Luz','due_at'=>new DateTimeImmutable('2026-10-15T12:00:00+00:00')],
        ];
        $rows = $service->execute($now);
        self::assertCount(2, $rows);
        self::assertSame('l1', $rows[0]['loan_id']);
        self::assertSame(5, $rows[0]['days_late']);
        self::assertSame(1, $rows[1]['days_late']);
    }
    public function test_overdue_empty_result(): void {
        [, , $service] = $this->fixtures();
        self::assertSame([], $service->execute(new DateTimeImmutable('2026-10-20T12:00:00+00:00')));
    }
}
