<?php

namespace App\Domain\Shared\Crypto;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use RuntimeException;

/**
 * Encrypts sensitive column values with AES-256-GCM using versioned data keys.
 *
 * Ciphertext is stored as "<version>:<Laravel payload>", so after a key rotation
 * old rows still decrypt with the key they were written with, and a re-encrypt
 * job can find them by prefix (ADR 0001 §5.17).
 */
final class FieldEncrypter
{
    private const CIPHER = 'aes-256-gcm';

    /** @var array<string, Encrypter> */
    private array $encrypters = [];

    private string $currentVersion;

    /**
     * @param  array<string, string>  $keys  version => raw 32-byte key; the first is current
     */
    public function __construct(array $keys)
    {
        if ($keys === []) {
            throw new RuntimeException('No data encryption key configured (MERIDIAN_DATA_KEYS).');
        }

        foreach ($keys as $version => $key) {
            $this->encrypters[$version] = new Encrypter($key, self::CIPHER);
        }
        $this->currentVersion = (string) array_key_first($keys);
    }

    /**
     * Parses "v2:base64:...,v1:base64:..." into [version => raw key].
     *
     * @return array<string, string>
     */
    public static function parseKeys(string $config): array
    {
        $keys = [];
        foreach (array_filter(array_map('trim', explode(',', $config))) as $entry) {
            if (! preg_match('/^(v\d+):base64:(.+)$/', $entry, $m)) {
                throw new RuntimeException('Malformed entry in MERIDIAN_DATA_KEYS; expected "v<n>:base64:<key>".');
            }
            $raw = base64_decode($m[2], true);
            if ($raw === false || strlen($raw) !== 32) {
                throw new RuntimeException("Data key {$m[1]} must be 32 bytes, base64 encoded.");
            }
            if (isset($keys[$m[1]])) {
                throw new RuntimeException("Data key {$m[1]} is listed twice.");
            }
            $keys[$m[1]] = $raw;
        }

        return $keys;
    }

    public function encrypt(string $plaintext): string
    {
        return $this->currentVersion.':'.$this->encrypters[$this->currentVersion]->encryptString($plaintext);
    }

    public function decrypt(string $ciphertext): string
    {
        $separator = strpos($ciphertext, ':');
        $version = $separator === false ? '' : substr($ciphertext, 0, $separator);

        if (! isset($this->encrypters[$version])) {
            throw new DecryptException("Unknown data key version [{$version}].");
        }

        return $this->encrypters[$version]->decryptString(substr($ciphertext, $separator + 1));
    }

    public function currentVersion(): string
    {
        return $this->currentVersion;
    }

    public function needsReencrypt(string $ciphertext): bool
    {
        return ! str_starts_with($ciphertext, $this->currentVersion.':');
    }
}
