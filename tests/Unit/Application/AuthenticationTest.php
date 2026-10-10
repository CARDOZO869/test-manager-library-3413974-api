<?php
declare(strict_types=1);
namespace Tests\Unit\Application;
use App\Application\Ports\PasswordHasher;
use App\Application\Ports\TokenIssuer;
use App\Application\Ports\UserRepository;
use App\Application\Services\CreateLibrarianService;
use App\Application\Services\LoginService;
use App\Domain\Entities\User;
use DomainException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
final class AuthenticationTest extends TestCase {
    private function fakes(): array {
        $users = new class implements UserRepository {
            public array $items = [];
            public function findByUsername(string $username): ?User { return $this->items[$username] ?? null; }
            public function save(User $user): void { $this->items[$user->username] = $user; }
        };
        $passwords = new class implements PasswordHasher {
            public function hash(string $plainPassword): string { return 'hashed:'.$plainPassword; }
            public function verify(string $plainPassword, string $passwordHash): bool { return hash_equals($passwordHash, $this->hash($plainPassword)); }
        };
        $tokens = new class implements TokenIssuer {
            public int $lastTtl = 0;
            public function issue(User $user, int $expiresInSeconds): string { $this->lastTtl = $expiresInSeconds; return 'fake-token-'.$user->id; }
        };
        return [$users, $passwords, $tokens];
    }
    public function test_login_returns_eight_hour_token_metadata(): void {
        [$users, $passwords, $tokens] = $this->fakes();
        $users->save(User::librarian('u1', 'ana', $passwords->hash('secret')));
        $result = (new LoginService($users, $passwords, $tokens))->execute(' ANA ', 'secret');
        self::assertSame(28800, $result['expires_in']);
        self::assertSame(28800, $tokens->lastTtl);
        self::assertSame('Bearer', $result['token_type']);
        self::assertSame('librarian', $result['role']);
        self::assertSame('fake-token-u1', $result['access_token']);
    }
    public function test_login_rejects_unknown_username_without_disclosure(): void {
        [$users, $passwords, $tokens] = $this->fakes();
        $this->expectExceptionMessage('INVALID_CREDENTIALS');
        (new LoginService($users, $passwords, $tokens))->execute('unknown', 'secret');
    }
    public function test_login_rejects_wrong_password_with_same_error(): void {
        [$users, $passwords, $tokens] = $this->fakes();
        $users->save(User::librarian('u1', 'ana', $passwords->hash('secret')));
        $this->expectExceptionMessage('INVALID_CREDENTIALS');
        (new LoginService($users, $passwords, $tokens))->execute('ana', 'wrong');
    }
    public function test_admin_creates_librarian_with_hashed_password(): void {
        [$users, $passwords] = $this->fakes();
        $user = (new CreateLibrarianService($users, $passwords))->execute('admin', 'u1', ' ANA ', 'secret', 'librarian');
        self::assertSame('ana', $user->username);
        self::assertSame('hashed:secret', $user->passwordHash);
        self::assertSame('librarian', $user->role);
        self::assertSame($user, $users->findByUsername('ana'));
    }
    public function test_non_admin_cannot_create_librarian(): void {
        [$users, $passwords] = $this->fakes();
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('FORBIDDEN');
        (new CreateLibrarianService($users, $passwords))->execute('librarian', 'u1', 'ana', 'secret', 'librarian');
    }
    public function test_api_cannot_create_admin(): void {
        [$users, $passwords] = $this->fakes();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('INVALID_ROLE');
        (new CreateLibrarianService($users, $passwords))->execute('admin', 'u1', 'ana', 'secret', 'admin');
    }
    public function test_duplicate_username_is_rejected_case_insensitively(): void {
        [$users, $passwords] = $this->fakes();
        $service = new CreateLibrarianService($users, $passwords);
        $service->execute('admin', 'u1', 'Ana', 'secret', 'librarian');
        $this->expectExceptionMessage('USERNAME_TAKEN');
        $service->execute('admin', 'u2', ' ANA ', 'other', 'librarian');
    }
    public function test_created_user_has_no_plain_password(): void {
        [$users, $passwords] = $this->fakes();
        $user = (new CreateLibrarianService($users, $passwords))->execute('admin', 'u1', 'ana', 'secret', 'librarian');
        self::assertNotSame('secret', $user->passwordHash);
    }
}
