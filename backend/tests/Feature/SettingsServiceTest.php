<?php

use App\Domain\Settings\CompanySettings;
use App\Domain\Settings\SettingsService;
use App\Exceptions\ApiProblem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

function settingsService(): SettingsService
{
    return app(SettingsService::class);
}

function auditRows(string $action): array
{
    return DB::table('audit_log')->where('action', $action)->get()->all();
}

it('updates only the fields sent and bumps the version', function () {
    $settings = settingsService()->update(['session_timeout_minutes' => 60, 'company_name' => 'Acme Sdn Bhd'], 1, null);

    expect($settings->version)->toBe(2)
        ->and($settings->session_timeout_minutes)->toBe(60)
        ->and($settings->company_name)->toBe('Acme Sdn Bhd')
        ->and($settings->checkin_every_workdays)->toBe(2)
        ->and(CompanySettings::current()->version)->toBe(2);
});

it('writes one redacted-where-needed audit row with before and after values', function () {
    settingsService()->update(['session_timeout_minutes' => 60], 1, null);

    $rows = auditRows('company_settings.updated');
    expect($rows)->toHaveCount(1);

    $changes = json_decode($rows[0]->changes, true);
    expect($changes)->toBe([
        'before' => ['session_timeout_minutes' => 30],
        'after' => ['session_timeout_minutes' => 60],
    ])->and($rows[0]->subject_type)->toBe('company_settings')
        ->and((int) $rows[0]->subject_id)->toBe(1);
});

it('refuses a stale version with 412 and changes nothing', function () {
    settingsService()->update(['session_timeout_minutes' => 60], 1, null);

    expect(fn () => settingsService()->update(['session_timeout_minutes' => 15], 1, null))
        ->toThrow(ApiProblem::class, 'changed by someone else');

    expect(CompanySettings::current()->session_timeout_minutes)->toBe(60)
        ->and(auditRows('company_settings.updated'))->toHaveCount(1);
});

it('does not bump the version or audit when nothing changes', function () {
    $settings = settingsService()->update(['session_timeout_minutes' => 30], 1, null);

    expect($settings->version)->toBe(1)
        ->and(auditRows('company_settings.updated'))->toBeEmpty();
});

it('stores working days sorted and KPI weights in a fixed order', function () {
    $settings = settingsService()->update([
        'working_days' => [5, 1, 3],
        'kpi_weights' => ['meeting' => 5, 'rating' => 10, 'quality' => 10, 'checkin' => 10, 'delay' => 15, 'ontime' => 20, 'delivery' => 30],
    ], 1, null);

    expect($settings->working_days)->toBe([1, 3, 5])
        ->and(array_keys($settings->kpi_weights))->toBe(CompanySettings::KPI_COMPONENTS);
});

it('rejects invalid values with field errors', function (array $input, string $field) {
    try {
        settingsService()->update($input, 1, null);
        $this->fail('Expected a validation error.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey($field);
    }

    expect(CompanySettings::current()->version)->toBe(1);
})->with([
    'blank name' => [['company_name' => '  '], 'company_name'],
    'blank timezone' => [['timezone' => ' '], 'timezone'],
    'blank switch' => [['two_fa_enabled' => ' '], 'two_fa_enabled'],
    'blank role list' => [['two_fa_roles' => ' '], 'two_fa_roles'],
    'blank weights' => [['kpi_weights' => ''], 'kpi_weights'],
    'unknown timezone' => [['timezone' => 'Mars/Olympus'], 'timezone'],
    'lower-case country' => [['country' => 'my'], 'country'],
    'day 8' => [['working_days' => [1, 8]], 'working_days.1'],
    'repeated day' => [['working_days' => [1, 1]], 'working_days.0'],
    'empty week' => [['working_days' => []], 'working_days'],
    'unknown role' => [['two_fa_roles' => ['admin']], 'two_fa_roles.0'],
    '45-minute timeout' => [['session_timeout_minutes' => 45], 'session_timeout_minutes'],
    '6-day check-in' => [['checkin_every_workdays' => 6], 'checkin_every_workdays'],
    'bad approval mode' => [['extension_approval' => 'hr'], 'extension_approval'],
    'bad holiday region' => [['holiday_region' => 'Selangor'], 'holiday_region'],
    'weights not 100' => [['kpi_weights' => ['delivery' => 31, 'ontime' => 20, 'delay' => 15, 'checkin' => 10, 'quality' => 10, 'rating' => 10, 'meeting' => 5]], 'kpi_weights'],
    'weight missing' => [['kpi_weights' => ['delivery' => 35, 'ontime' => 20, 'delay' => 15, 'checkin' => 10, 'quality' => 10, 'rating' => 10]], 'kpi_weights'],
    'extra weight' => [['kpi_weights' => ['delivery' => 30, 'ontime' => 20, 'delay' => 15, 'checkin' => 10, 'quality' => 10, 'rating' => 10, 'meeting' => 5, 'kudos' => 0]], 'kpi_weights'],
    'weight over 100' => [['kpi_weights' => ['delivery' => 130, 'ontime' => 0, 'delay' => 0, 'checkin' => 0, 'quality' => 0, 'rating' => 0, 'meeting' => -30]], 'kpi_weights.delivery'],
]);

it('leaves no audit row when the change is rolled back', function () {
    try {
        DB::transaction(function () {
            $settings = CompanySettings::current();
            $settings->session_timeout_minutes = 240;
            $settings->save();

            throw new RuntimeException('something failed after the save');
        });
    } catch (RuntimeException) {
    }

    expect(auditRows('company_settings.updated'))->toBeEmpty()
        ->and(CompanySettings::current()->session_timeout_minutes)->toBe(30);
});
