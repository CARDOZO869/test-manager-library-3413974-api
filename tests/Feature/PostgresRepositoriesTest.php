<?php
declare(strict_types=1);
namespace Tests\Feature;
use App\Domain\Entities\Book;
use App\Domain\Entities\Member;
use App\Domain\Entities\User;
use App\Domain\ValueObjects\Isbn;
use App\Infrastructure\Persistence\PostgresBookRepository;
use App\Infrastructure\Persistence\PostgresMemberRepository;
use App\Infrastructure\Persistence\PostgresUserRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
final class PostgresRepositoriesTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        if (DB::getDriverName() !== 'pgsql') $this->markTestSkipped('Requires PostgreSQL.');
        if (!DB::selectOne("SELECT to_regclass('library.book') AS table_name")->table_name) {
            $this->markTestSkipped('Run the library schema migration first.');
        }
        DB::beginTransaction();
    }
    protected function tearDown(): void {
        if (DB::transactionLevel() > 0) DB::rollBack();
        parent::tearDown();
    }
    public function test_book_round_trip_search_update_and_soft_delete(): void {
        $repo = new PostgresBookRepository();
        $id = (string) Str::uuid();
        $book = new Book($id, 'Prueba integración', 'Autor', new Isbn('9780306406157'),
            '11111111-1111-4111-8111-111111111111', 3);
        $repo->save($book);
        self::assertSame(3, $repo->findById($id)->availableCopies);
        self::assertSame($id, $repo->findActiveByIsbn('9780306406157')->id);
        self::assertSame(1, $repo->search('integración', null, 1, 20)['total']);
        $book->withdraw();
        $repo->save($book);
        self::assertSame(2, $repo->findById($id)->availableCopies);
        $book->softDelete(new \DateTimeImmutable('2026-10-10T12:00:00+00:00'), false);
        $repo->save($book);
        self::assertNull($repo->findActiveByIsbn('9780306406157'));
    }
    public function test_member_round_trip(): void {
        $repo = new PostgresMemberRepository();
        $id = (string) Str::uuid();
        $member = new Member($id, '  Ana Prueba  ', 'TEST-'.Str::random(10));
        $repo->save($member);
        self::assertSame('Ana Prueba', $repo->findById($id)->fullName);
        self::assertSame($id, $repo->findByDocument($member->documentNumber)->id);
    }
    public function test_user_round_trip_and_normalization(): void {
        $repo = new PostgresUserRepository();
        $id = (string) Str::uuid();
        $user = User::librarian($id, 'TEST_'.Str::random(10), 'hashed-password-for-test');
        $repo->save($user);
        self::assertSame($id, $repo->findByUsername(strtoupper($user->username))->id);
        self::assertSame('hashed-password-for-test', $repo->findById($id)->passwordHash);
    }
}
