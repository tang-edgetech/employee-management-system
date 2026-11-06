<?php

namespace App\Domain\Settings;

use App\Domain\People\EmployeeNumberFormat;
use App\Domain\Shared\Concurrency\ETag;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class SettingsService
{
    /**
     * Applies a partial update if the caller saw the current version.
     * Locks the row, so two admins saving at once can't both win: the second
     * gets 412 and must reload. The audit row is written in the same transaction.
     *
     * @param  array<string, mixed>  $input
     */
    public function update(array $input, int $expectedVersion, ?Authenticatable $actor): CompanySettings
    {
        // Same as the HTTP TrimStrings/ConvertEmptyStringsToNull middleware, so callers
        // other than HTTP get identical results. Without it, Laravel would skip the
        // rules for a whitespace-only value and the database CHECK would be the only guard.
        $input = array_map(
            fn (mixed $value) => is_string($value) ? (trim($value) === '' ? null : trim($value)) : $value,
            $input,
        );

        $data = Validator::make($input, SettingsRules::update())->validate();

        if (isset($data['working_days'])) {
            $data['working_days'] = array_values(array_map('intval', $data['working_days']));
            sort($data['working_days']);
        }
        if (isset($data['two_fa_roles'])) {
            $data['two_fa_roles'] = array_values($data['two_fa_roles']);
        }
        if (isset($data['kpi_weights'])) {
            $data['kpi_weights'] = array_map('intval', array_merge(array_flip(CompanySettings::KPI_COMPONENTS), $data['kpi_weights']));
        }

        return DB::transaction(function () use ($data, $expectedVersion, $actor) {
            /** @var CompanySettings $settings */
            $settings = CompanySettings::query()->lockForUpdate()->findOrFail(1);

            ETag::assertMatches($expectedVersion, $settings->version);

            // The format and digit count are checked together, against the saved value
            // of whichever one this update doesn't change. Only new employees are affected.
            if (isset($data['employee_no_format']) || isset($data['employee_no_digits'])) {
                $problem = EmployeeNumberFormat::problem(
                    $data['employee_no_format'] ?? $settings->employee_no_format,
                    (int) ($data['employee_no_digits'] ?? $settings->employee_no_digits),
                );
                if ($problem !== null) {
                    throw ValidationException::withMessages([isset($data['employee_no_format']) ? 'employee_no_format' : 'employee_no_digits' => $problem]);
                }
            }

            $settings->fill($data);
            if ($settings->isDirty()) {
                $settings->version = $settings->version + 1;
                $settings->updated_by = $actor?->getAuthIdentifier();
                $settings->save();
            }

            return $settings;
        });
    }
}
