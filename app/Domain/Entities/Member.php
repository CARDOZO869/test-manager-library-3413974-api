<?php

declare(strict_types=1);

namespace App\Domain\Entities;

use InvalidArgumentException;

final readonly class Member
{
    public string $fullName;
    public string $documentNumber;

    public function __construct(
        public string $id,
        string $fullName,
        string $documentNumber,
    ) {
        $name = trim($fullName);
        $document = trim($documentNumber);
        if ($id === '' || $name === '' || $document === '') {
            throw new InvalidArgumentException('Member ID, name and document are required.');
        }
        if (mb_strlen($name) > 200 || mb_strlen($document) > 30) {
            throw new InvalidArgumentException('Member fields exceed maximum length.');
        }
        $this->fullName = $name;
        $this->documentNumber = $document;
    }
}
