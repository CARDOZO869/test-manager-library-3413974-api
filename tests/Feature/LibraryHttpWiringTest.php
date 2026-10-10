<?php
declare(strict_types=1);
namespace Tests\Feature;
use App\Application\Ports\BookRepository;
use App\Application\Ports\LendingRepository;
use App\Application\Ports\ReturnsRepository;
use App\Application\Ports\UserRepository;
use App\Application\Ports\PasswordHasher;
use App\Application\Ports\TokenIssuer;
use App\Application\Ports\MostBorrowedReportReader;
use App\Infrastructure\Persistence\PostgresBookRepository;
use App\Infrastructure\Persistence\PostgresLoanRepository;
use App\Infrastructure\Persistence\PostgresUserRepository;
use App\Infrastructure\Persistence\PostgresMostBorrowedReportReader;
use App\Infrastructure\Security\HmacTokenIssuer;
use Tests\TestCase;
final class LibraryHttpWiringTest extends TestCase {
    public function test_application_ports_are_connected_to_real_adapters(): void {
        self::assertInstanceOf(PostgresBookRepository::class,app(BookRepository::class));
        self::assertInstanceOf(PostgresLoanRepository::class,app(LendingRepository::class));
        self::assertInstanceOf(PostgresLoanRepository::class,app(ReturnsRepository::class));
        self::assertInstanceOf(PostgresUserRepository::class,app(UserRepository::class));
        self::assertInstanceOf(PostgresMostBorrowedReportReader::class,app(MostBorrowedReportReader::class));
        self::assertNotNull(app(PasswordHasher::class));
        self::assertInstanceOf(HmacTokenIssuer::class,app(TokenIssuer::class));
    }
    public function test_health_endpoint_is_public(): void {
        $this->getJson('/api/v1/health')->assertOk()->assertExactJson(['status'=>'ok']);
    }
}
