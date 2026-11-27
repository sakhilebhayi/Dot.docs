<?php

namespace App\Support;

use RuntimeException;

/**
 * File encryption for backups (EA-6 / O-01): libsodium secretstream
 * (XChaCha20-Poly1305), streamed in 64 KB chunks so a large file never sits in
 * memory, with the key derived from BACKUP_PASSPHRASE by Argon2id and a random
 * salt stored in the file's header. Any change to an encrypted file - or the
 * wrong passphrase - makes decryption fail loudly instead of returning garbage.
 *
 * File layout: "DOTBK1" | salt (16) | secretstream header (24) | chunks.
 */
class BackupCipher
{
    private const MAGIC = 'DOTBK1';

    private const CHUNK = 65536;

    public static function encryptFile(string $from, string $to, string $passphrase): void
    {
        $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $key = self::key($passphrase, $salt);
        [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);

        $in = fopen($from, 'rb');
        $out = fopen($to, 'wb');
        if ($in === false || $out === false) {
            throw new RuntimeException('Cannot open the backup files.');
        }

        fwrite($out, self::MAGIC.$salt.$header);
        $size = (int) filesize($from);
        $read = 0;
        do {
            $chunk = (string) fread($in, self::CHUNK);
            $read += strlen($chunk);
            $tag = $read >= $size
                ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL
                : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;
            fwrite($out, sodium_crypto_secretstream_xchacha20poly1305_push($state, $chunk, '', $tag));
        } while ($tag !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL);

        fclose($in);
        fclose($out);
        sodium_memzero($key);
    }

    public static function decryptFile(string $from, string $to, string $passphrase): void
    {
        $in = fopen($from, 'rb');
        if ($in === false) {
            throw new RuntimeException('Cannot open the encrypted backup.');
        }

        $magic = (string) fread($in, strlen(self::MAGIC));
        if ($magic !== self::MAGIC) {
            throw new RuntimeException('Not a Dot.docs encrypted backup.');
        }

        $salt = (string) fread($in, SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $header = (string) fread($in, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
        $key = self::key($passphrase, $salt);
        $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key);

        $out = fopen($to, 'wb');
        if ($out === false) {
            throw new RuntimeException('Cannot write the restored file.');
        }

        $final = false;
        while (! feof($in)) {
            $cipher = (string) fread($in, self::CHUNK + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES);
            if ($cipher === '') {
                break;
            }
            $result = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $cipher);
            if ($result === false) {
                fclose($out);
                @unlink($to);
                throw new RuntimeException('Decryption failed: wrong passphrase or a damaged file.');
            }
            [$plain, $tag] = $result;
            fwrite($out, $plain);
            if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                $final = true;
                break;
            }
        }

        fclose($in);
        fclose($out);
        sodium_memzero($key);

        if (! $final) {
            @unlink($to);
            throw new RuntimeException('The encrypted backup is truncated.');
        }
    }

    private static function key(string $passphrase, string $salt): string
    {
        return sodium_crypto_pwhash(
            SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES,
            $passphrase,
            $salt,
            SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
            SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE,
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13,
        );
    }
}
