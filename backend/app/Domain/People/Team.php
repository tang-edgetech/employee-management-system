<?php

namespace App\Domain\People;

use App\Domain\Audit\Auditable;
use App\Domain\Shared\HasPublicId;
use App\Domain\Shared\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A group inside a department. It has zero, one or several team leads (plan 01
 * §9.2); every team lead is also a member. Membership and leads change through
 * TeamService.
 *
 * @property int $id
 * @property string $public_id
 * @property string $name
 * @property ?int $department_id
 * @property int $version
 */
class Team extends Model
{
    use Auditable, HasPublicId;

    protected $guarded = ['id', 'public_id', 'version'];

    public static function publicIdType(): string
    {
        return 'team';
    }

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** @return BelongsToMany<Employee, $this> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'team_members');
    }

    /** @return BelongsToMany<Employee, $this> */
    public function leads(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'team_leads');
    }
}
