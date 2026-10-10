<?php
declare(strict_types=1);
namespace App\Application\Services;
use App\Application\Ports\PasswordHasher;
use App\Application\Ports\TokenIssuer;
use App\Application\Ports\UserRepository;
use DomainException;
final readonly class LoginService {
    public const TOKEN_TTL_SECONDS = 28800;
    public function __construct(
        private UserRepository $users,
        private PasswordHasher $passwords,
        private TokenIssuer $tokens,
    ) {}
    /** @return array{access_token:string,token_type:string,expires_in:int,role:string} */
    public function execute(string $username, string $password): array {
        $user = $this->users->findByUsername(mb_strtolower(trim($username), 'UTF-8'));
        if ($user === null || !$this->passwords->verify($password, $user->passwordHash)) {
            throw new DomainException('INVALID_CREDENTIALS');
        }
        return [
            'access_token' => $this->tokens->issue($user, self::TOKEN_TTL_SECONDS),
            'token_type' => 'Bearer',
            'expires_in' => self::TOKEN_TTL_SECONDS,
            'role' => $user->role,
        ];
    }
}