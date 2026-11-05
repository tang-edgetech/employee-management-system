<?php

namespace App\Domain\People;

use App\Domain\Audit\Auditable;
use App\Domain\Shared\HasPublicId;
use App\Domain\Shared\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Membership and the lead are changed through TeamService, which keeps the lead
 * a member of the team.
 *
 * @property int $id
 * @property string $public_id
 * @property string $name
 * @property ?int $lead_employee_id
 * @property int $version
 */
class Team extends Model
{
    use Auditable, HasPublicId;

    protected $guarded = ['id', 'public_id', 'lead_employee_id', 'version'];

    public static function publicIdType(): string
    {
        return 'team';
    }

    /** @return BelongsTo<Employee, $this> */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'lead_employee_id');
    }

    /** @return BelongsToMany<Employee, $this> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'team_members');
    }
}
