<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
| The database itself rejects bad settings, even if the app's validation is
| bypassed (plan 01 §7, "Database" tests).
*/

it('starts with exactly one settings row', function () {
    expect(DB::table('company_settings')->count())->toBe(1)
        ->and(DB::table('company_settings')->value('id'))->toBe(1);
});

it('cannot hold a second settings row', function () {
    DB::table('company_settings')->insert([
        'id' => 2,
        'company_name' => 'Second',
        'working_days' => '[1,2,3,4,5]',
        'two_fa_roles' => '[]',
        'kpi_weights' => DB::table('company_settings')->value('kpi_weights'),
    ]);
})->throws(QueryException::class, 'company_settings_singleton');

it('rejects values outside the allowed set', function (string $column, mixed $value, string $constraint) {
    expect(fn () => DB::table('company_settings')->where('id', 1)->update([$column => $value]))
        ->toThrow(QueryException::class, $constraint);
})->with([
    'blank company name' => ['company_name', '   ', 'company_settings_name'],
    'lower-case country' => ['country', 'my', 'company_settings_country'],
    'two-letter currency' => ['currency', 'MY', 'company_settings_currency'],
    'empty working week' => ['working_days', '[]', 'company_settings_working_days'],
    'working days not a list' => ['working_days', '{"mon":1}', 'company_settings_working_days'],
    'invalid JSON' => ['two_fa_roles', 'not json', 'two_fa_roles'],
    '45-minute session timeout' => ['session_timeout_minutes', 45, 'company_settings_session_timeout'],
    '7-month probation' => ['default_probation_months', 7, 'company_settings_probation_months'],
    '10-day probation reminder' => ['probation_reminder_days', 10, 'company_settings_probation_reminder'],
    '45-day PIP' => ['pip_default_days', 45, 'company_settings_pip_days'],
    'check-in every 6 days' => ['checkin_every_workdays', 6, 'company_settings_checkin_every'],
    'month 13' => ['data_review_month', 13, 'company_settings_review_month'],
    '20-day review window' => ['data_review_window_days', 20, 'company_settings_review_window'],
    '18-month retention' => ['candidate_retention_months', 18, 'company_settings_retention'],
    'version 0' => ['version', 0, 'company_settings_version'],
]);

it('rejects KPI weights with a missing component', function () {
    DB::table('company_settings')->where('id', 1)->update([
        'kpi_weights' => '{"delivery":40,"ontime":20,"delay":15,"checkin":10,"quality":10,"rating":5}',
    ]);
})->throws(QueryException::class, 'company_settings_kpi_weights');

it('rejects KPI weights that do not add up to 100', function () {
    DB::table('company_settings')->where('id', 1)->update([
        'kpi_weights' => '{"delivery":31,"ontime":20,"delay":15,"checkin":10,"quality":10,"rating":10,"meeting":5}',
    ]);
})->throws(QueryException::class, 'company_settings_kpi_weights_sum');

it('accepts a valid change', function () {
    DB::table('company_settings')->where('id', 1)->update([
        'session_timeout_minutes' => 240,
        'kpi_weights' => '{"delivery":25,"ontime":25,"delay":15,"checkin":10,"quality":10,"rating":10,"meeting":5}',
    ]);

    expect(DB::table('company_settings')->value('session_timeout_minutes'))->toBe(240);
});
