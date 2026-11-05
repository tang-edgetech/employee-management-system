<?php

namespace App\Domain\Settings;

use App\Domain\Access\RoleKey;
use Closure;
use DateTimeZone;
use Illuminate\Validation\Rule;

/**
 * Validation for company settings, mirroring the CHECK constraints on the table.
 * Updates are partial: only the fields sent are validated and changed.
 */
final class SettingsRules
{
    /**
     * Who may read each section (GET /settings). null = every signed-in user,
     * because the app needs them (e.g. "check-in required every N working days").
     * Otherwise any one of the listed permissions.
     */
    public const READ_SECTIONS = [
        'company' => [null, ['company_name', 'timezone', 'country', 'currency', 'working_days', 'holiday_region']],
        'work' => [null, ['checkin_every_workdays', 'checkin_mandatory', 'extension_approval', 'auto_late_delay']],
        'security' => [['system.settings'], ['two_fa_enabled', 'two_fa_roles', 'session_timeout_minutes']],
        'employment' => [['system.settings', 'people.lifecycle'], ['default_probation_months', 'probation_reminder_days', 'pip_default_days', 'data_review_month', 'data_review_window_days']],
        'recruitment' => [['system.settings', 'candidate.create'], ['require_resume', 'candidate_retention_months', 'former_employee_policy']],
        'kpi' => [['kpi.configure', 'kpi.view_all'], ['kpi_mode', 'kpi_weights']],
    ];

    /**
     * Which permission may change which fields (plan 01 §4, PATCH /settings).
     */
    public const WRITE_PERMISSIONS = [
        'system.settings' => [
            'company_name', 'timezone', 'country', 'currency', 'working_days', 'holiday_region',
            'two_fa_enabled', 'two_fa_roles', 'session_timeout_minutes',
            'default_probation_months', 'probation_reminder_days', 'pip_default_days',
            'checkin_every_workdays', 'checkin_mandatory', 'extension_approval', 'auto_late_delay',
            'data_review_month', 'data_review_window_days',
            'require_resume', 'candidate_retention_months', 'former_employee_policy',
        ],
        'kpi.configure' => ['kpi_mode', 'kpi_weights'],
    ];

    /** @return list<string> every field PATCH /settings accepts */
    public static function writableFields(): array
    {
        return array_merge(...array_values(self::WRITE_PERMISSIONS));
    }

    public static function permissionToWrite(string $field): ?string
    {
        foreach (self::WRITE_PERMISSIONS as $permission => $fields) {
            if (in_array($field, $fields, true)) {
                return $permission;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public static function update(): array
    {
        return [
            'company_name' => ['sometimes', 'required', 'string', 'max:150'],
            'timezone' => ['sometimes', 'string', Rule::in(DateTimeZone::listIdentifiers())],
            'country' => ['sometimes', 'string', 'regex:/^[A-Z]{2}$/'],
            'currency' => ['sometimes', 'string', 'regex:/^[A-Z]{3}$/'],
            'working_days' => ['sometimes', 'array', 'min:1', 'max:7'],
            'working_days.*' => ['integer', 'between:1,7', 'distinct'],
            'holiday_region' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Z]{2}(-[0-9A-Z]{1,3})?$/'],

            'two_fa_enabled' => ['sometimes', 'boolean'],
            'two_fa_roles' => ['sometimes', 'array'],
            'two_fa_roles.*' => ['string', Rule::in(RoleKey::values()), 'distinct'],
            'session_timeout_minutes' => ['sometimes', 'integer', Rule::in([15, 30, 60, 480])],

            'default_probation_months' => ['sometimes', 'integer', 'between:0,6'],
            'probation_reminder_days' => ['sometimes', 'integer', Rule::in([7, 14, 30])],
            'pip_default_days' => ['sometimes', 'integer', Rule::in([30, 60, 90])],

            'checkin_every_workdays' => ['sometimes', 'integer', 'between:1,5'],
            'checkin_mandatory' => ['sometimes', 'boolean'],
            'extension_approval' => ['sometimes', 'string', Rule::in(['both', 'pm', 'sup'])],
            'auto_late_delay' => ['sometimes', 'boolean'],

            'data_review_month' => ['sometimes', 'integer', 'between:1,12'],
            'data_review_window_days' => ['sometimes', 'integer', Rule::in([14, 30, 45])],

            'require_resume' => ['sometimes', 'boolean'],
            'candidate_retention_months' => ['sometimes', 'integer', Rule::in([6, 12, 24])],
            'former_employee_policy' => ['sometimes', 'string', Rule::in(['interview', 'choose', 'direct'])],

            'kpi_mode' => ['sometimes', 'string', Rule::in(['system', 'external'])],
            'kpi_weights' => ['sometimes', 'array:'.implode(',', CompanySettings::KPI_COMPONENTS), self::kpiWeightsSum()],
            'kpi_weights.*' => ['required', 'integer', 'between:0,100'],
        ];
    }

    /** All seven components present, each 0..100, adding up to exactly 100. */
    private static function kpiWeightsSum(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_array($value)) {
                return;
            }
            $missing = array_diff(CompanySettings::KPI_COMPONENTS, array_keys($value));
            if ($missing !== []) {
                $fail('KPI weights must include: '.implode(', ', $missing).'.');

                return;
            }
            $values = array_values($value);
            $allIntegers = array_filter($values, fn ($w) => is_int($w) || (is_string($w) && ctype_digit($w))) === $values;
            if ($allIntegers && array_sum(array_map('intval', $values)) !== 100) {
                $fail('KPI weights must add up to 100.');
            }
        };
    }
}
