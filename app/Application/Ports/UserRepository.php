<?php
declare(strict_types=1);
namespace App\Application\Ports;
use App\Domain\Entities\User;
interface UserRepository {
    public function findByUsername(string $username): ?User;
    public function save(User $user): void;
}