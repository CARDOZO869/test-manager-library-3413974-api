<?php
declare(strict_types=1);
namespace Tests\Feature;
use App\Domain\Entities\Loan;
use App\Infrastructure\Persistence\PostgresLoanRepository;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
final class PostgresLoanRepositoryTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        if (DB::getDriverName() !== 'pgsql') $this->markTestSkipped('Requires PostgreSQL');
        if (!DB::selectOne("SELECT to_regclass('library.loan_item') AS name")->name) {
            $this->markTestSkipped('Library migration missing');
        }
        DB::beginTransaction();
    }
    protected function tearDown(): void {
        if (DB::transactionLevel() > 0) DB::rollBack();
        parent::tearDown();
    }
    private function fixtures(int $stock = 1): array {
        $memberId=(string)Str::uuid(); $userId=(string)Str::uuid(); $bookId=(string)Str::uuid();
        DB::table('library.member')->insert(['id'=>$memberId,'full_name'=>'Prueba','document_number'=>'T'.Str::random(15)]);
        DB::table('library.user')->insert(['id'=>$userId,'username'=>'test'.Str::random(12),'password_hash'=>'hash','role'=>'librarian']);
        DB::table('library.book')->insert([
            'id'=>$bookId,'title'=>'Libro de prueba','author'=>'Autor','isbn'=>'9780306406157',
            'category_id'=>'11111111-1111-4111-8111-111111111111',
            'total_copies'=>1,'available_copies'=>$stock,
        ]);
        return [$memberId,$userId,$bookId];
    }
    public function test_lending_and_return_are_atomic_and_return_only_once(): void {
        [$memberId,$userId,$bookId]=$this->fixtures();
        $repo=new PostgresLoanRepository();
        $when=new DateTimeImmutable('2026-10-10T12:00:00+00:00');
        $loan=new Loan((string)Str::uuid(),$memberId,$userId,$when);
        $itemId=(string)Str::uuid();
        $loan->addItem($itemId,$bookId,'Libro de prueba','9780306406157','Novela');
        $repo->commitLoan($loan,[$repo->findBook($bookId)]);
        self::assertSame(0,$repo->findBook($bookId)->availableCopies);
        self::assertSame(1,$repo->outstandingItemsCount($memberId));
        self::assertCount(1,$repo->findLoan($loan->id)->items());
        $repo->commitReturn($loan->id,$itemId,$when->modify('+1 day'));
        self::assertSame(1,$repo->findBook($bookId)->availableCopies);
        self::assertSame(0,$repo->outstandingItemsCount($memberId));
        $this->expectExceptionMessage('ITEM_ALREADY_RETURNED');
        $repo->commitReturn($loan->id,$itemId,$when->modify('+2 days'));
    }
    public function test_out_of_stock_rolls_back_loan(): void {
        [$memberId,$userId,$bookId]=$this->fixtures(0);
        $repo=new PostgresLoanRepository();
        $loan=new Loan((string)Str::uuid(),$memberId,$userId,new DateTimeImmutable('2026-10-10T12:00:00+00:00'));
        $loan->addItem((string)Str::uuid(),$bookId,'Libro de prueba','9780306406157','Novela');
        try {
            $repo->commitLoan($loan,[$repo->findBook($bookId)]);
            self::fail('Expected stock rejection');
        } catch (\DomainException $e) {
            self::assertSame('BOOK_OUT_OF_STOCK',$e->getMessage());
            self::assertNull($repo->findLoan($loan->id));
        }
    }
    public function test_overdue_query_uses_snapshot_and_member_name(): void {
        [$memberId,$userId,$bookId]=$this->fixtures();
        $repo=new PostgresLoanRepository();
        $when=new DateTimeImmutable('2026-10-01T12:00:00+00:00');
        $loan=new Loan((string)Str::uuid(),$memberId,$userId,$when);
        $loan->addItem((string)Str::uuid(),$bookId,'Libro de prueba','9780306406157','Novela');
        $repo->commitLoan($loan,[$repo->findBook($bookId)]);
        self::assertCount(1,$repo->overdueItems(new DateTimeImmutable('2026-10-20T12:00:00+00:00')));
        self::assertSame('Libro de prueba',$repo->overdueItems(new DateTimeImmutable('2026-10-20T12:00:00+00:00'))[0]['book_title']);
    }
}
