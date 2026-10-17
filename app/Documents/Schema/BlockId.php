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

    /**
     * An id derived from `$seed` alone: the same seed always gives the same
     * id. Used to replace a duplicate so that a client which keeps sending
     * the duplicate is not given a new id on every save.
     */
    public static function derive(string $seed): string
    {
        $bytes = hash('sha256', $seed, true);
        $out = '';
        for ($i = 0; $i < 8; $i++) {
            $out .= self::ALPHABET[ord($bytes[$i]) % 62];
        }

        return $out;
    }

    public static function isValid(mixed $id): bool
    {
        return is_string($id) && preg_match('/^[0-9A-Za-z]{8}$/', $id) === 1;
    }
}
