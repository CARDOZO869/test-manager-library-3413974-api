<?php
declare(strict_types=1);
namespace App\Infrastructure\Security;
use App\Application\Ports\PasswordHasher;
use Illuminate\Support\Facades\Hash;
final class LaravelPasswordHasher implements PasswordHasher {
    public function hash(string $plainPassword): string {
        return Hash::driver('argon2id')->make($plainPassword);
    }
    public function verify(string $plainPassword, string $passwordHash): bool {
        return password_verify($plainPassword, $passwordHash);
    }
}
