<?php
declare(strict_types=1);
namespace App\Application\Ports;
use App\Domain\Entities\User;
interface TokenIssuer {
    public function issue(User $user, int $expiresInSeconds): string;
}