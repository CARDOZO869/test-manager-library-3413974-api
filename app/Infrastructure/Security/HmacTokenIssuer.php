<?php
declare(strict_types=1);
namespace App\Infrastructure\Security;
use App\Application\Ports\TokenIssuer;
use App\Domain\Entities\User;
use DateTimeImmutable;
use DomainException;
final readonly class HmacTokenIssuer implements TokenIssuer {
    public function __construct(private string $secret) {
        if (strlen($secret) < 32) throw new DomainException('TOKEN_SECRET_TOO_SHORT');
    }
    public function issue(User $user, int $expiresInSeconds): string {
        if ($expiresInSeconds < 1) throw new DomainException('INVALID_TOKEN_TTL');
        $now = time();
        $header = $this->encode(['alg'=>'HS256','typ'=>'JWT']);
        $payload = $this->encode([
            'sub'=>$user->id,'iat'=>$now,'exp'=>$now+$expiresInSeconds,
        ]);
        $signature = $this->base64url(hash_hmac('sha256', $header.'.'.$payload, $this->secret, true));
        return $header.'.'.$payload.'.'.$signature;
    }
    /** @return array{sub:string,iat:int,exp:int} */
    public function verify(string $token): array {
        $parts = explode('.', $token);
        if (count($parts) !== 3) throw new DomainException('INVALID_TOKEN');
        [$header,$payload,$signature] = $parts;
        $expected = $this->base64url(hash_hmac('sha256', $header.'.'.$payload, $this->secret, true));
        if (!hash_equals($expected,$signature)) throw new DomainException('INVALID_TOKEN');
        $head = $this->decode($header);
        if (($head['alg'] ?? null) !== 'HS256' || ($head['typ'] ?? null) !== 'JWT') throw new DomainException('INVALID_TOKEN');
        $claims = $this->decode($payload);
        if (!is_string($claims['sub'] ?? null) || $claims['sub'] === '' ||
            !is_int($claims['iat'] ?? null) || !is_int($claims['exp'] ?? null) ||
            $claims['iat'] > time() || $claims['exp'] <= time() ||
            $claims['exp'] <= $claims['iat'] || $claims['exp'] - $claims['iat'] > 28800) {
            throw new DomainException('INVALID_TOKEN');
        }
        return ['sub'=>$claims['sub'],'iat'=>$claims['iat'],'exp'=>$claims['exp']];
    }
    private function base64url(string $data): string {
        return rtrim(strtr(base64_encode($data),'+/','-_'),'=');
    }
    private function encode(array $data): string {
        return $this->base64url(json_encode($data, JSON_THROW_ON_ERROR));
    }
    private function decode(string $data): array {
        if ($data === '' || preg_match('/^[A-Za-z0-9_-]+$/D',$data) !== 1) throw new DomainException('INVALID_TOKEN');
        $raw = base64_decode(strtr($data,'-_','+/'),true);
        if ($raw === false) throw new DomainException('INVALID_TOKEN');
        $json = json_decode($raw,true);
        if (!is_array($json)) throw new DomainException('INVALID_TOKEN');
        return $json;
    }
}