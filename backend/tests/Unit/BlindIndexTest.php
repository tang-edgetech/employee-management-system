<?php

use App\Domain\Shared\Crypto\BlindIndex;

beforeEach(function () {
    $this->index = new BlindIndex(random_bytes(32));
});

it('gives the same hash for an NRIC with or without dashes and spaces', function () {
    $a = $this->index->hash(BlindIndex::NRIC, '900101-14-5524');

    expect($this->index->hash(BlindIndex::NRIC, '900101145524'))->toBe($a)
        ->and($this->index->hash(BlindIndex::NRIC, ' 900101 14 5524 '))->toBe($a)
        ->and($a)->toMatch('/^[0-9a-f]{64}$/')
        ->and($this->index->hash(BlindIndex::NRIC, '900101-14-5525'))->not->toBe($a);
});

it('normalises passports to upper case without separators', function () {
    expect($this->index->hash(BlindIndex::PASSPORT, 'a1234 567'))
        ->toBe($this->index->hash(BlindIndex::PASSPORT, 'A1234567'));
});

it('gives unrelated hashes for the same value in different columns', function () {
    expect($this->index->hash(BlindIndex::NRIC, '123456'))
        ->not->toBe($this->index->hash(BlindIndex::PASSPORT, '123456'));
});

it('depends on the key, so hashes are useless without it', function () {
    $other = new BlindIndex(random_bytes(32));

    expect($other->hash(BlindIndex::NRIC, '900101145524'))
        ->not->toBe($this->index->hash(BlindIndex::NRIC, '900101145524'));
});

it('returns null for values that are empty after normalising', function () {
    expect($this->index->hash(BlindIndex::NRIC, '--'))->toBeNull();
});

it('refuses short keys and unknown contexts', function () {
    expect(fn () => new BlindIndex('short'))->toThrow(RuntimeException::class)
        ->and(fn () => $this->index->hash('salary', '1'))->toThrow(InvalidArgumentException::class);
});
