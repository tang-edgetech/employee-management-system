<?php

namespace App\Domain\Settings;

use App\Domain\Audit\Auditable;
use App\Domain\Shared\Model;

/**
 * The single row of company-wide settings for this install (ADR 0002).
 *
 * @property int $id
 * @property string $company_name
 * @property ?int $logo_file_id
 * @property string $timezone
 * @property string $country
 * @property string $currency
 * @property list<int> $working_days
 * @property ?string $holiday_region
 * @property bool $two_fa_enabled
 * @property list<string> $two_fa_roles
 * @property int $session_timeout_minutes
 * @property int $default_probation_months
 * @property int $probation_reminder_days
 * @property int $pip_default_days
 * @property int $checkin_every_workdays
 * @property bool $checkin_mandatory
 * @property string $extension_approval
 * @property bool $auto_late_delay
 * @property int $data_review_month
 * @property int $data_review_window_days
 * @property bool $require_resume
 * @property int $candidate_retention_months
 * @property string $former_employee_policy
 * @property string $kpi_mode
 * @property array<string, int> $kpi_weights
 * @property int $version
 * @property ?int $updated_by
 */
class CompanySettings extends Model
{
    use Auditable;

    public const KPI_COMPONENTS = ['delivery', 'ontime', 'delay', 'checkin', 'quality', 'rating', 'meeting'];

    protected $table = 'company_settings';

    public $incrementing = false;

    protected $guarded = ['id', 'version', 'created_at', 'updated_at', 'updated_by'];

    protected function casts(): array
    {
        return [
            'working_days' => 'array',
            'two_fa_roles' => 'array',
            'kpi_weights' => 'array',
            'two_fa_enabled' => 'boolean',
            'checkin_mandatory' => 'boolean',
            'auto_late_delay' => 'boolean',
            'require_resume' => 'boolean',
            'logo_file_id' => 'integer',
            'session_timeout_minutes' => 'integer',
            'default_probation_months' => 'integer',
            'probation_reminder_days' => 'integer',
            'pip_default_days' => 'integer',
            'checkin_every_workdays' => 'integer',
            'data_review_month' => 'integer',
            'data_review_window_days' => 'integer',
            'candidate_retention_months' => 'integer',
            'version' => 'integer',
        ];
    }

    public static function current(): self
    {
        return self::query()->findOrFail(1);
    }
}
