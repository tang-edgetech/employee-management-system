<?php

namespace App\Domain\Catalogue;

use App\Domain\Audit\Auditable;
use App\Domain\People\Employee;
use App\Domain\Shared\HasPublicId;
use App\Domain\Shared\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A company-defined employment type (D20), e.g. Permanent, Contract, Part-time.
 *
 * @property int $id
 * @property string $public_id
 * @property string $name
 * @property string $icon
 * @property int $default_probation_months
 * @property ?string $typical_duration
 * @property bool $has_fixed_end
 * @property ?int $renewal_reminder_days
 * @property ?string $notes
 * @property int $sort
 * @property int $version
 */
class EmploymentType extends Model
{
    use Auditable, HasPublicId;

    public const ICONS = ['briefcase', 'graduation-cap', 'file-clock', 'shield-check', 'clock', 'users', 'handshake', 'laptop'];

    protected $guarded = ['id', 'public_id', 'version'];

    public static function publicIdType(): string
    {
        return 'employment_type';
    }

    protected function casts(): array
    {
        return [
            'default_probation_months' => 'integer',
            'has_fixed_end' => 'boolean',
            'renewal_reminder_days' => 'integer',
            'sort' => 'integer',
            'version' => 'integer',
        ];
    }

    /**
     * Types an employee of this type may be converted to.
     *
     * @return BelongsToMany<EmploymentType, $this>
     */
    public function convertsTo(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'employment_type_conversions', 'from_type_id', 'to_type_id');
    }

    /** @return HasMany<Employee, $this> */
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }
}
