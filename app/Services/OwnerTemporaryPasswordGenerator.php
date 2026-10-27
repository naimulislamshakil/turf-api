<?php

namespace App\Services;

class OwnerTemporaryPasswordGenerator
{
    public function generate(): string
    {
        return $this->format(random_int(0, 99999999));
    }

    public function format(int $value): string
    {
        if ($value < 0 || $value > 99999999) {
            throw new \InvalidArgumentException('Temporary password value must be an 8-digit range integer.');
        }

        return str_pad((string) $value, 8, '0', STR_PAD_LEFT);
    }
}
