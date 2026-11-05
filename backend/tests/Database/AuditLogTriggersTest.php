<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    DB::table('audit_log')->insert([
        'occurred_at' => now('UTC')->format('Y-m-d H:i:s.u'),
        'action' => 'test.created',
    ]);
});

it('accepts new rows', function () {
    expect(DB::table('audit_log')->where('action', 'test.created')->count())->toBe(1);
});

it('refuses to update an audit row', function () {
    DB::table('audit_log')->update(['action' => 'test.tampered']);
})->throws(QueryException::class, 'append-only');

it('refuses to delete an audit row', function () {
    DB::table('audit_log')->delete();
})->throws(QueryException::class, 'append-only');
