<?php

namespace App\Domain\Catalogue;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $task_tag_id
 * @property int $level
 * @property string $name
 * @property int $points
 */
class TaskTagLevel extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['level' => 'integer', 'points' => 'integer'];
    }

    /** @return BelongsTo<TaskTag, $this> */
    public function tag(): BelongsTo
    {
        return $this->belongsTo(TaskTag::class, 'task_tag_id');
    }
}
