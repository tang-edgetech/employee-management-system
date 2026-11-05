<?php

use App\Domain\Shared\PublicId;

it('generates a prefix plus 8 base62 characters', function () {
    $id = PublicId::generate('emp');

    expect($id)->toMatch('/^emp_[0-9A-Za-z]{8}$/')
        ->and(strlen($id))->toBeLessThanOrEqual(PublicId::MAX_LENGTH)
        ->and(PublicId::isValid($id, 'emp'))->toBeTrue()
        ->and(PublicId::isValid($id, 'usr'))->toBeFalse();
});

it('gives different IDs on each call', function () {
    $ids = array_map(fn () => PublicId::generate('usr'), range(1, 500));

    expect(array_unique($ids))->toHaveCount(500);
});

it('rejects prefixes that are malformed or too long to fit', function (string $prefix) {
    PublicId::generate($prefix);
})->with(['Emp', 'e', 'emp1', 'toolongx', 'em_p'])->throws(InvalidArgumentException::class);

it('rejects strings that are not public IDs', function (string $value) {
    expect(PublicId::isValid($value))->toBeFalse();
})->with(['42', 'emp_1234567', 'emp_123456789', 'emp-12345678', 'emp_1234567!', 'EMP_12345678']);
