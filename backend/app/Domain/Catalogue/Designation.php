<?php

namespace App\Domain\Catalogue;

use App\Domain\Audit\Auditable;
use App\Domain\People\Employee;
use App\Domain\Shared\HasPublicId;
use App\Domain\Shared\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One rung of the career ladder (D19). `level` is its position (0 = lowest) and is
 * only changed by DesignationService::reorder, which rewrites the whole ladder.
 *
 * @property int $id
 * @property string $public_id
 * @property string $name
 * @property string $track
 * @property int $level
 * @property bool $can_lead_team
 * @property ?string $salary_min
 * @property ?string $salary_max
 * @property ?int $kpi_target_points
 * @property int $version
 */
class Designation extends Model
{
    use Auditable, HasPublicId;

    public const TRACKS = ['Entry', 'Individual Contributor', 'Management', 'Executive'];

    protected $guarded = ['id', 'public_id', 'level', 'version'];

    public static function publicIdType(): string
    {
        return 'designation';
    }

    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'can_lead_team' => 'boolean',
            'salary_min' => 'decimal:2',
            'salary_max' => 'decimal:2',
            'kpi_target_points' => 'integer',
            'version' => 'integer',
        ];
    }

    /** @return HasMany<Employee, $this> */
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }
}
