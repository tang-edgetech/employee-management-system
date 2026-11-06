<?php

namespace App\Domain\Catalogue;

use App\Domain\Audit\AuditLogger;
use App\Domain\Shared\Concurrency\ETag;
use App\Exceptions\ApiProblem;
use Illuminate\Support\Facades\DB;

/**
 * Task tags and their levels (D21). A tag has one or more levels; the levels are
 * always written as a whole list and numbered 1..n in the order given.
 *
 * Tasks don't exist yet. When the Tasks module adds them, deleting a tag in use
 * will require a tag to move its tasks to, and removing levels will move tasks to
 * the highest remaining level (plan 01 §9.4).
 */
final class TaskTagService
{
    /**
     * @param  array<string, mixed>  $data
     * @param  list<array{name: string, points: int}>  $levels
     */
    public function create(array $data, array $levels): TaskTag
    {
        return DB::transaction(function () use ($data, $levels) {
            $tag = new TaskTag($data);
            $tag->sort = (int) TaskTag::query()->max('sort') + 10;
            $tag->save();
            $this->writeLevels($tag, $levels);

            return $tag->load('levels');
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  ?list<array{name: string, points: int}>  $levels  null leaves the levels unchanged
     */
    public function update(TaskTag $tag, array $data, ?array $levels, int $expectedVersion): TaskTag
    {
        return DB::transaction(function () use ($tag, $data, $levels, $expectedVersion) {
            /** @var TaskTag $locked */
            $locked = TaskTag::query()->lockForUpdate()->findOrFail($tag->id);
            ETag::assertMatches($expectedVersion, $locked->version);

            $locked->fill($data);
            $levelsChanged = false;
            if ($levels !== null) {
                $before = $locked->levels()->get(['level', 'name', 'points'])->map(fn (TaskTagLevel $l) => [$l->name, $l->points])->all();
                $after = array_map(fn (array $l) => [$l['name'], (int) $l['points']], $levels);
                if ($before !== $after) {
                    $this->writeLevels($locked, $levels);
                    $levelsChanged = true;
                    app(AuditLogger::class)->record('task_tags.levels_changed', $locked, [
                        'before' => ['levels' => $before],
                        'after' => ['levels' => $after],
                    ]);
                }
            }

            if ($locked->isDirty() || $levelsChanged) {
                $locked->version++;
                $locked->save();
            }

            return $locked->load('levels');
        });
    }

    public function delete(TaskTag $tag, int $expectedVersion): void
    {
        DB::transaction(function () use ($tag, $expectedVersion) {
            /** @var TaskTag $locked */
            $locked = TaskTag::query()->lockForUpdate()->findOrFail($tag->id);
            ETag::assertMatches($expectedVersion, $locked->version);

            if (TaskTag::query()->count() === 1) {
                throw ApiProblem::conflict('LAST_TASK_TAG', 'Keep at least one task tag.');
            }

            $locked->delete();
        });
    }

    /** @param  list<array{name: string, points: int}>  $levels */
    private function writeLevels(TaskTag $tag, array $levels): void
    {
        if ($levels === []) {
            throw ApiProblem::unprocessable('TAG_NEEDS_A_LEVEL', 'A tag needs at least one level.');
        }

        $tag->levels()->delete();
        foreach ($levels as $i => $level) {
            $tag->levels()->create(['level' => $i + 1, 'name' => trim($level['name']), 'points' => (int) $level['points']]);
        }
    }
}
