<?php
declare(strict_types=1);
namespace Tests\Unit\Infrastructure;
use App\Domain\Entities\User;
use App\Infrastructure\Security\HmacTokenIssuer;
use DomainException;
use PHPUnit\Framework\TestCase;
final class SecurityTest extends TestCase {
    public function test_token_has_eight_hour_lifetime(): void {
        $issuer = new HmacTokenIssuer(str_repeat('s', 48));
        $user = User::librarian('user-1', 'librarian', 'hash');
        $claims = $issuer->verify($issuer->issue($user, 28800));
        self::assertSame('user-1', $claims['sub']);
        self::assertSame(28800, $claims['exp'] - $claims['iat']);
    }
    public function test_invalid_signature_is_rejected(): void {
        $issuer = new HmacTokenIssuer(str_repeat('s', 48));
        $user = User::librarian('user-1', 'librarian', 'hash');
        $token = $issuer->issue($user, 28800);
        $parts = explode('.', $token);
        $parts[2] = str_repeat('x', strlen($parts[2]));
        $this->expectException(DomainException::class);
        $issuer->verify(implode('.', $parts));
    }
    public function test_secret_must_be_long_enough(): void {
        $this->expectExceptionMessage('TOKEN_SECRET_TOO_SHORT');
        new HmacTokenIssuer('short');
    }
    public function test_invalid_ttl_is_rejected(): void {
        $issuer = new HmacTokenIssuer(str_repeat('s', 48));
        $this->expectExceptionMessage('INVALID_TOKEN_TTL');
        $issuer->issue(User::librarian('user-1', 'librarian', 'hash'), 0);
    }
}
