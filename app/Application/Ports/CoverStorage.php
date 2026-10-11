<?php
declare(strict_types=1);
namespace App\Application\Ports;
interface CoverStorage {
    public function put(string $bytes, string $extension): string;
    public function get(string $key): ?string;
    public function delete(string $key): void;
}
