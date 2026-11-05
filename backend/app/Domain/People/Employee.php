<?php

namespace App\Domain\People;

use App\Domain\Audit\Auditable;
use App\Domain\Shared\HasPublicId;
use App\Domain\Shared\Model;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * People core (D1). Reporting lines are changed through EmployeeHierarchy, which
 * rejects loops; a database trigger rejects them too.
 *
 * @property int $id
 * @property string $public_id
 * @property string $employee_no
 * @property string $full_name
 * @property string $work_email
 * @property ?int $department_id
 * @property ?int $superior_id
 * @property string $status
 * @property Carbon $join_date
 * @property ?Carbon $left_at
 * @property int $version
 */
class Employee extends Model
{
    use Auditable, HasPublicId, SoftDeletes;

    public const STATUSES = ['Permanent', 'Probation', 'Promotion Probation', 'PIP', 'Contract', 'Internship', 'Resigned', 'Terminated'];

    protected $guarded = ['id', 'public_id', 'superior_id', 'version'];

    public static function publicIdType(): string
    {
        return 'employee';
    }

    protected function casts(): array
    {
        return [
            'join_date' => 'date',
            'left_at' => 'date',
            'version' => 'integer',
        ];
    }

    public function setWorkEmailAttribute(string $value): void
    {
        $this->attributes['work_email'] = mb_strtolower(trim($value));
    }

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** @return BelongsTo<Employee, $this> */
    public function superior(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superior_id');
    }

    /** @return HasMany<Employee, $this> */
    public function directReports(): HasMany
    {
        return $this->hasMany(self::class, 'superior_id');
    }

    /** @return BelongsToMany<Team, $this> */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'team_members');
    }

    /** @return HasOne<User, $this> */
    public function user(): HasOne
    {
        return $this->hasOne(User::class);
    }
}
