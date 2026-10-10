<?php

declare(strict_types=1);

namespace App\Domain\ValueObjects;

use InvalidArgumentException;

final readonly class Isbn
{
    public function __construct(public string $value)
    {
        if (preg_match('/^[0-9]{13}$/D', $value) !== 1) {
            throw new InvalidArgumentException('ISBN must contain exactly 13 digits.');
        }
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
