<?php

use App\Domain\Audit\AuditLogger;
use App\Domain\Shared\Crypto\Encrypted;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

// Unit suite: no RefreshDatabase, so there is no wrapping test transaction here.

it('refuses to write an audit row outside a transaction', function () {
    expect(DB::transactionLevel())->toBe(0);

    app(AuditLogger::class)->record('test.untransacted');
})->throws(LogicException::class, 'inside the transaction');

it('redacts configured and encrypted attributes', function () {
    $model = new class extends Model
    {
        protected $casts = ['nric' => Encrypted::class, 'salary' => 'encrypted', 'name' => 'string'];
    };

    expect(AuditLogger::isSensitive($model, 'password'))->toBeTrue()
        ->and(AuditLogger::isSensitive($model, 'two_factor_secret'))->toBeTrue()
        ->and(AuditLogger::isSensitive($model, 'nric'))->toBeTrue()
        ->and(AuditLogger::isSensitive($model, 'salary'))->toBeTrue()
        ->and(AuditLogger::isSensitive($model, 'name'))->toBeFalse();
});
