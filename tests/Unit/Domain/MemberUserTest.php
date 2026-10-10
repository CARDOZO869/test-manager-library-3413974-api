<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Entities\Member;
use App\Domain\Entities\User;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MemberUserTest extends TestCase
{
    public function test_member_trims_name_and_document(): void
    {
        $member = new Member('m-1', '  Ana Pérez  ', ' 12345 ');
        self::assertSame('Ana Pérez', $member->fullName);
        self::assertSame('12345', $member->documentNumber);
    }

    public function test_member_requires_name(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Member('m-1', '   ', '12345');
    }

    public function test_member_rejects_overlong_document(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Member('m-1', 'Ana', str_repeat('1', 31));
    }

    public function test_user_normalizes_username(): void
    {
        $user = new User('u-1', '  Ana  ', 'hashed-password', 'librarian');
        self::assertSame('ana', $user->username);
    }

    public function test_user_accepts_admin_role_for_bootstrap(): void
    {
        $user = new User('u-1', 'Admin', 'hashed-password', 'admin');
        self::assertSame('admin', $user->role);
    }

    public function test_user_rejects_invalid_role(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new User('u-1', 'ana', 'hashed-password', 'superadmin');
    }

    public function test_librarian_factory_never_creates_admin(): void
    {
        $user = User::librarian('u-1', '  ANA ', 'hashed-password');
        self::assertSame('librarian', $user->role);
        self::assertSame('ana', $user->username);
    }

    public function test_user_requires_password_hash(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new User('u-1', 'ana', '', 'librarian');
    }
}
