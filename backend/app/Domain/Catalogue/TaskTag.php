<?php

namespace App\Domain\Catalogue;

use App\Domain\Audit\Auditable;
use App\Domain\Shared\HasPublicId;
use App\Domain\Shared\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A task tag with its difficulty levels (D21). A tag has one or more levels,
 * numbered 1..n, each worth some points.
 *
 * @property int $id
 * @property string $public_id
 * @property string $code
 * @property string $name
 * @property string $color
 * @property ?string $typical_duration
 * @property ?string $description
 * @property int $sort
 * @property int $version
 */
class TaskTag extends Model
{
    use Auditable, HasPublicId;

    protected $guarded = ['id', 'public_id', 'version'];

    public static function publicIdType(): string
    {
        return 'task_tag';
    }

    protected function casts(): array
    {
        return ['sort' => 'integer', 'version' => 'integer'];
    }

    /** @return HasMany<TaskTagLevel, $this> */
    public function levels(): HasMany
    {
        return $this->hasMany(TaskTagLevel::class)->orderBy('level');
    }
}
