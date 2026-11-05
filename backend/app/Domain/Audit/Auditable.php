<?php

namespace App\Domain\Audit;

use Illuminate\Database\Eloquent\Model;

/**
 * Records created / updated / deleted entries for a model automatically.
 * Saves must run inside a transaction (see AuditLogger), which domain services do.
 * Action names are "<table>.<event>", e.g. "company_settings.updated".
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(function (Model $model): void {
            app(AuditLogger::class)->record(AuditLogger::subjectType($model).'.created', $model, [
                'after' => AuditLogger::snapshot($model),
            ]);
        });

        static::updated(function (Model $model): void {
            $diff = AuditLogger::diff($model);
            if ($diff['after'] !== []) {
                app(AuditLogger::class)->record(AuditLogger::subjectType($model).'.updated', $model, $diff);
            }
        });

        static::deleted(function (Model $model): void {
            app(AuditLogger::class)->record(AuditLogger::subjectType($model).'.deleted', $model, [
                'before' => AuditLogger::snapshot($model),
            ]);
        });
    }
}
