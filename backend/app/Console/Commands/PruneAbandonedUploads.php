<?php

namespace App\Console\Commands;

use App\Domain\Files\FileService;
use App\Domain\Files\StoredFile;
use Illuminate\Console\Command;

/** Removes uploads that were started but never completed (scheduled hourly). */
class PruneAbandonedUploads extends Command
{
    protected $signature = 'files:prune-abandoned';

    protected $description = 'Delete upload slots that were never completed';

    public function handle(FileService $files): int
    {
        $cutoff = now()->subHours((int) config('meridian.files.pending_expire_hours', 24));
        $count = 0;

        StoredFile::query()->whereNull('confirmed_at')->where('created_at', '<', $cutoff)
            ->eachById(function (StoredFile $file) use ($files, &$count) {
                $files->disk($file)->delete($file->storage_key);
                $file->delete();
                $count++;
            });

        $this->info("Removed {$count} abandoned upload(s).");

        return self::SUCCESS;
    }
}
