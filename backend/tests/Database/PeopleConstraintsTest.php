<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

function insertEmployee(string $no, ?int $superior = null): int
{
    return DB::table('employees')->insertGetId([
        'public_id' => 'emp_'.str_pad($no, 8, '0', STR_PAD_LEFT),
        'employee_no' => $no,
        'full_name' => "Person {$no}",
        'work_email' => "p{$no}@example.com",
        'superior_id' => $superior,
        'status' => 'Permanent',
        'join_date' => '2026-01-01',
    ]);
}

beforeEach(function () {
    // a <- b <- c <- d  (d reports to c, which reports to b, which reports to a)
    $this->a = insertEmployee('1');
    $this->b = insertEmployee('2', $this->a);
    $this->c = insertEmployee('3', $this->b);
    $this->d = insertEmployee('4', $this->c);
});

it('refuses an employee reporting to themselves', function () {
    DB::table('employees')->where('id', $this->b)->update(['superior_id' => $this->b]);
})->throws(QueryException::class, 'REPORTING_LOOP');

it('refuses a reporting line that loops back through others', function () {
    DB::table('employees')->where('id', $this->a)->update(['superior_id' => $this->d]);
})->throws(QueryException::class, 'REPORTING_LOOP');

it('allows moving someone to a different, non-looping superior', function () {
    DB::table('employees')->where('id', $this->d)->update(['superior_id' => $this->a]);

    expect(DB::table('employees')->where('id', $this->d)->value('superior_id'))->toBe($this->a);
});

it('refuses a superior that does not exist', function () {
    DB::table('employees')->where('id', $this->d)->update(['superior_id' => 999999]);
})->throws(QueryException::class, 'foreign key');

it('refuses deleting someone who still has reports', function () {
    DB::table('employees')->where('id', $this->c)->delete();
})->throws(QueryException::class, 'foreign key');

it('stores work emails in lower case only', function () {
    DB::table('employees')->where('id', $this->a)->update(['work_email' => 'Mixed@Example.com']);
})->throws(QueryException::class, 'employees_email_lowercase');

it('refuses a leaving date before the joining date', function () {
    DB::table('employees')->where('id', $this->a)->update(['left_at' => '2025-12-31']);
})->throws(QueryException::class, 'employees_left_after_join');

it('refuses a duplicate team membership', function () {
    $team = DB::table('teams')->insertGetId(['public_id' => 'team_00000001', 'name' => 'T']);
    DB::table('team_members')->insert(['team_id' => $team, 'employee_id' => $this->a]);
    DB::table('team_members')->insert(['team_id' => $team, 'employee_id' => $this->a]);
})->throws(QueryException::class, 'Duplicate');
