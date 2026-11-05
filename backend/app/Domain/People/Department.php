<?php

namespace App\Domain\People;

use App\Domain\Audit\Auditable;
use App\Domain\Shared\HasPublicId;
use App\Domain\Shared\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Minimal department (plan 01 §9.2, D12): a name and at most one head (HOD).
 * The Organization module adds colours, types and more.
 *
 * @property int $id
 * @property string $public_id
 * @property string $name
 * @property ?int $head_employee_id
 * @property int $sort
 * @property int $version
 */
class Department extends Model
{
    use Auditable, HasPublicId;

    /** CEO and GM sit here; the org chart shows it above the other departments. */
    public const MANAGEMENT = 'Management';

    protected $guarded = ['id', 'public_id', 'version'];

    public static function publicIdType(): string
    {
        return 'department';
    }

    /** @return BelongsTo<Employee, $this> */
    public function head(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'head_employee_id');
    }

    /** @return HasMany<Employee, $this> */
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    /** @return HasMany<Team, $this> */
    public function teams(): HasMany
    {
        return $this->hasMany(Team::class);
    }
}
