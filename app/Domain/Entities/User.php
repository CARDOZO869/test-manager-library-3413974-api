<?php

declare(strict_types=1);

namespace App\Domain\Entities;

use InvalidArgumentException;

final readonly class User
{
    public string $username;

    public function __construct(
        public string $id,
        string $username,
        public string $passwordHash,
        public string $role,
    ) {
        $normalized = mb_strtolower(trim($username), 'UTF-8');
        if ($id === '' || $normalized === '' || $passwordHash === '') {
            throw new InvalidArgumentException('User ID, username and password hash are required.');
        }
        if (mb_strlen($normalized) > 120 || mb_strlen($passwordHash) > 512) {
            throw new InvalidArgumentException('User fields exceed maximum length.');
        }
        if (!in_array($role, ['admin', 'librarian'], true)) {
            throw new InvalidArgumentException('Invalid user role.');
        }
        $this->username = $normalized;
    }

    public static function librarian(string $id, string $username, string $passwordHash): self
    {
        return new self($id, $username, $passwordHash, 'librarian');
    }
}
