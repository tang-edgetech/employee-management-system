<?php

namespace App\Domain\Catalogue;

use App\Domain\Audit\AuditLogger;
use App\Domain\Shared\Concurrency\ETag;
use App\Exceptions\ApiProblem;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The career ladder (D19). Levels run 0..n-1 with no gaps, lowest first.
 *
 * The order is changed only as a whole: the client sends every designation in the
 * new order, together with the ladder version it last read (ladderVersion()). A
 * reorder bumps every rung's version, so the ladder version always moves on.
 */
final class DesignationService
{
    /** @return Collection<int, Designation> */
    public function ladder(): Collection
    {
        return Designation::query()->withCount('employees')->orderBy('level')->get();
    }

    /** The ETag for the ladder's order: changes on every reorder, add or delete. */
    public function ladderVersion(): int
    {
        return (int) Designation::query()->max('version') + 1000 * Designation::query()->count();
    }

    /**
     * New designations go on the top rung; move them with reorder().
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Designation
    {
        return DB::transaction(function () use ($data) {
            // Locks the ladder so two creates can't pick the same level.
            $top = Designation::query()->lockForUpdate()->max('level');
            $designation = new Designation($data);
            $designation->forceFill(['level' => $top === null ? 0 : (int) $top + 1])->save();

            return $designation;
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(Designation $designation, array $data, int $expectedVersion): Designation
    {
        return DB::transaction(function () use ($designation, $data, $expectedVersion) {
            /** @var Designation $locked */
            $locked = Designation::query()->lockForUpdate()->findOrFail($designation->id);
            ETag::assertMatches($expectedVersion, $locked->version);

            $locked->fill($data);
            if ($locked->isDirty()) {
                $locked->version++;
                $locked->save();
            }

            return $locked;
        });
    }

    /** Only an unused designation can be deleted; the rungs above move down one. */
    public function delete(Designation $designation, int $expectedVersion): void
    {
        DB::transaction(function () use ($designation, $expectedVersion) {
            $all = Designation::query()->lockForUpdate()->orderBy('level')->get();
            /** @var Designation $locked */
            $locked = $all->firstWhere('id', $designation->id);
            ETag::assertMatches($expectedVersion, $locked->version);

            $inUse = $locked->employees()->count();
            if ($inUse > 0) {
                throw ApiProblem::conflict('DESIGNATION_IN_USE', "{$locked->name} is held by {$inUse} employee(s). Change their designation first.");
            }

            $locked->delete();
            $this->writeLevels($all->reject(fn (Designation $d) => $d->id === $locked->id)->values(), bumpAll: false);
        });
    }

    /**
     * @param  list<string>  $publicIds  every designation, lowest rung first
     * @return Collection<int, Designation>
     */
    public function reorder(array $publicIds, int $expectedLadderVersion): Collection
    {
        return DB::transaction(function () use ($publicIds, $expectedLadderVersion) {
            $all = Designation::query()->lockForUpdate()->orderBy('level')->get();
            if ($this->ladderVersion() !== $expectedLadderVersion) {
                throw ApiProblem::preconditionFailed('The ladder was changed by someone else. Reload it and try again.');
            }

            $byPublicId = $all->keyBy('public_id');
            if (count($publicIds) !== $all->count() || array_diff($publicIds, $byPublicId->keys()->all()) !== []) {
                throw ApiProblem::unprocessable('LADDER_INCOMPLETE', 'Send every designation exactly once, lowest rung first.');
            }

            $before = $all->pluck('public_id')->all();
            if ($before === $publicIds) {
                return $all;
            }

            $ordered = collect($publicIds)->map(fn (string $id) => $byPublicId[$id])->values();
            $this->writeLevels($ordered, bumpAll: true);

            app(AuditLogger::class)->record('designations.reordered', null, [
                'before' => ['order' => $all->pluck('name')->all()],
                'after' => ['order' => $ordered->pluck('name')->all()],
            ]);

            return $this->ladder();
        });
    }

    /**
     * Writes levels 0..n-1 in the given order. `level` is unique, so rungs first move
     * out of the way (above the current maximum), then to their final level.
     *
     * @param  \Illuminate\Support\Collection<int, Designation>  $ordered
     */
    private function writeLevels(\Illuminate\Support\Collection $ordered, bool $bumpAll): void
    {
        $offset = (int) Designation::query()->max('level') + $ordered->count() + 1;
        foreach ($ordered as $i => $designation) {
            Designation::query()->whereKey($designation->id)->update(['level' => $offset + $i]);
        }

        $nextVersion = (int) Designation::query()->max('version') + 1;
        foreach ($ordered as $i => $designation) {
            $changes = ['level' => $i];
            if ($bumpAll) {
                $changes['version'] = $nextVersion;
            } elseif ($designation->level !== $i) {
                $changes['version'] = $designation->version + 1;
            }
            Designation::query()->whereKey($designation->id)->update($changes);
        }
    }
}
