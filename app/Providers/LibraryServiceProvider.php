<?php
declare(strict_types=1);
namespace App\Providers;
use App\Application\Ports\BookRepository;
use App\Application\Ports\LendingRepository;
use App\Application\Ports\ReturnsRepository;
use App\Application\Ports\UserRepository;
use App\Application\Ports\PasswordHasher;
use App\Application\Ports\TokenIssuer;
use App\Application\Ports\CoverStorage;
use App\Application\Ports\MostBorrowedReportReader;
use App\Infrastructure\Persistence\PostgresBookRepository;
use App\Infrastructure\Persistence\PostgresLoanRepository;
use App\Infrastructure\Persistence\PostgresUserRepository;
use App\Infrastructure\Persistence\PostgresMostBorrowedReportReader;
use App\Infrastructure\Security\LaravelPasswordHasher;
use App\Infrastructure\Security\HmacTokenIssuer;
use App\Infrastructure\Storage\LocalCoverStorage;
use Illuminate\Support\ServiceProvider;
final class LibraryServiceProvider extends ServiceProvider {
    public function register(): void {
        $this->app->bind(BookRepository::class,PostgresBookRepository::class);
        $this->app->bind(LendingRepository::class,PostgresLoanRepository::class);
        $this->app->bind(ReturnsRepository::class,PostgresLoanRepository::class);
        $this->app->bind(UserRepository::class,PostgresUserRepository::class);
        $this->app->bind(PasswordHasher::class,LaravelPasswordHasher::class);
        $this->app->bind(CoverStorage::class,LocalCoverStorage::class);
        $this->app->bind(MostBorrowedReportReader::class,PostgresMostBorrowedReportReader::class);
        $this->app->singleton(HmacTokenIssuer::class,static function (): HmacTokenIssuer {
            $secret=(string)config('app.key','');
            if (str_starts_with($secret,'base64:')) {
                $decoded=base64_decode(substr($secret,7),true);
                $secret=$decoded===false?'':$decoded;
            }
            return new HmacTokenIssuer($secret);
        });
        $this->app->bind(TokenIssuer::class,HmacTokenIssuer::class);
    }
}