<?php
declare(strict_types=1);
namespace App\Infrastructure\Persistence;
use App\Application\Ports\UserRepository;
use App\Domain\Entities\User;
use Illuminate\Support\Facades\DB;
final class PostgresUserRepository implements UserRepository {
    public function findByUsername(string $username): ?User {
        $row = DB::table('library.user')->where('username', mb_strtolower(trim($username), 'UTF-8'))->first();
        return $row === null ? null : new User($row->id, $row->username, $row->password_hash, $row->role);
    }
    public function findById(string $id): ?User {
        $row = DB::table('library.user')->where('id', $id)->first();
        return $row === null ? null : new User($row->id, $row->username, $row->password_hash, $row->role);
    }
    public function save(User $user): void {
        DB::table('library.user')->upsert([[
            'id' => $user->id, 'username' => $user->username,
            'password_hash' => $user->passwordHash, 'role' => $user->role,
        ]], ['id'], ['username','password_hash','role']);
    }
}