<?php
declare(strict_types=1);
namespace App\Infrastructure\Storage;
use App\Application\Ports\CoverStorage;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
final class LocalCoverStorage implements CoverStorage {
    public function put(string $bytes, string $extension): string {
        if (!in_array($extension,['jpg','png'],true)) throw new RuntimeException('INVALID_EXTENSION');
        $key = 'covers/'.Str::uuid().'.'.$extension;
        if (!Storage::disk('local')->put($key,$bytes)) throw new RuntimeException('COVER_STORAGE_FAILED');
        return $key;
    }
    public function get(string $key): ?string {
        if (preg_match('~^covers/[0-9a-f-]{36}\.(jpg|png)$~D',$key) !== 1) return null;
        return Storage::disk('local')->exists($key) ? Storage::disk('local')->get($key) : null;
    }
}