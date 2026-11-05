<?php

use App\Domain\Shared\Crypto\FieldEncrypter;
use Illuminate\Contracts\Encryption\DecryptException;

function dataKey(): string
{
    return random_bytes(32);
}

it('round-trips a value and never stores it in plain text', function () {
    $encrypter = new FieldEncrypter(['v1' => dataKey()]);

    $ciphertext = $encrypter->encrypt('900101-14-5524');

    expect($ciphertext)->toStartWith('v1:')
        ->not->toContain('900101')
        ->and($encrypter->decrypt($ciphertext))->toBe('900101-14-5524');
});

it('produces different ciphertext for the same value', function () {
    $encrypter = new FieldEncrypter(['v1' => dataKey()]);

    expect($encrypter->encrypt('5000.00'))->not->toBe($encrypter->encrypt('5000.00'));
});

it('still decrypts values written with a previous key after rotation', function () {
    $old = dataKey();
    $before = new FieldEncrypter(['v1' => $old]);
    $ciphertext = $before->encrypt('salary 7200');

    $after = new FieldEncrypter(['v2' => dataKey(), 'v1' => $old]);

    expect($after->decrypt($ciphertext))->toBe('salary 7200')
        ->and($after->needsReencrypt($ciphertext))->toBeTrue()
        ->and($after->encrypt('x'))->toStartWith('v2:')
        ->and($after->needsReencrypt($after->encrypt('x')))->toBeFalse();
});

it('refuses ciphertext from a key it does not have', function () {
    $ciphertext = (new FieldEncrypter(['v1' => dataKey()]))->encrypt('secret');

    (new FieldEncrypter(['v2' => dataKey()]))->decrypt($ciphertext);
})->throws(DecryptException::class);

it('detects tampering', function () {
    $encrypter = new FieldEncrypter(['v1' => dataKey()]);
    $payload = json_decode(base64_decode(substr($encrypter->encrypt('secret'), 3)), true);
    $payload['value'] = base64_encode('tampered');

    $encrypter->decrypt('v1:'.base64_encode(json_encode($payload)));
})->throws(DecryptException::class);

it('parses key configuration and rejects bad keys', function () {
    $k1 = base64_encode(dataKey());
    $k2 = base64_encode(dataKey());

    expect(array_keys(FieldEncrypter::parseKeys("v2:base64:{$k2}, v1:base64:{$k1}")))->toBe(['v2', 'v1']);

    expect(fn () => FieldEncrypter::parseKeys('v1:base64:'.base64_encode('short')))->toThrow(RuntimeException::class)
        ->and(fn () => FieldEncrypter::parseKeys("base64:{$k1}"))->toThrow(RuntimeException::class)
        ->and(fn () => FieldEncrypter::parseKeys("v1:base64:{$k1},v1:base64:{$k2}"))->toThrow(RuntimeException::class)
        ->and(fn () => new FieldEncrypter([]))->toThrow(RuntimeException::class);
});
