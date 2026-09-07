<?php

namespace App\Documents\Schema;

final class BlockId
{
    private const ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    public static function generate(): string
    {
        $out = '';
        for ($i = 0; $i < 8; $i++) {
            $out .= self::ALPHABET[random_int(0, 61)];
        }

        return $out;
    }

    public static function isValid(mixed $id): bool
    {
        return is_string($id) && preg_match('/^[0-9A-Za-z]{8}$/', $id) === 1;
    }
}
