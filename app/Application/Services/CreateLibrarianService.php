<?php
declare(strict_types=1);
namespace App\Application\Services;
use App\Application\Ports\PasswordHasher;
use App\Application\Ports\UserRepository;
use App\Domain\Entities\User;
use DomainException;
use InvalidArgumentException;
final readonly class CreateLibrarianService {
    public function __construct(private UserRepository $users, private PasswordHasher $passwords) {}
    public function execute(string $actorRole, string $id, string $username, string $password, string $role): User {
        if ($actorRole !== 'admin') {
            throw new DomainException('FORBIDDEN');
        }
        if ($role !== 'librarian') {
            throw new InvalidArgumentException('INVALID_ROLE');
        }
        $normalized = mb_strtolower(trim($username), 'UTF-8');
        if ($normalized === '' || $password === '') {
            throw new InvalidArgumentException('USERNAME_AND_PASSWORD_REQUIRED');
        }
        if ($this->users->findByUsername($normalized) !== null) {
            throw new DomainException('USERNAME_TAKEN');
        }
        $user = User::librarian($id, $normalized, $this->passwords->hash($password));
        $this->users->save($user);
        return $user;
    }
}