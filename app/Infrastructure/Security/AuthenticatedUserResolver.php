<?php
declare(strict_types=1);
namespace App\Infrastructure\Security;
use App\Domain\Entities\User;
use App\Infrastructure\Persistence\PostgresUserRepository;
use DomainException;
final readonly class AuthenticatedUserResolver {
    public function __construct(private HmacTokenIssuer $tokens, private PostgresUserRepository $users) {}
    public function resolve(string $authorizationHeader): User {
        if (preg_match('/^Bearer ([^\s]+)$/D',$authorizationHeader,$matches) !== 1) {
            throw new DomainException('UNAUTHENTICATED');
        }
        try {
            $claims = $this->tokens->verify($matches[1]);
        } catch (DomainException $e) {
            throw new DomainException('UNAUTHENTICATED',0,$e);
        }
        $user = $this->users->findById($claims['sub']);
        if ($user === null) throw new DomainException('UNAUTHENTICATED');
        return $user;
    }
    public function requireRole(User $user, array $roles): void {
        if (!in_array($user->role,$roles,true)) throw new DomainException('FORBIDDEN');
    }
}