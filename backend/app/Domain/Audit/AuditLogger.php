<?php

namespace App\Domain\Audit;

use App\Domain\Shared\Crypto\Encrypted;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Writes audit rows. Every write must happen inside the database transaction of
 * the change it describes, so a change can never be saved without its audit row
 * (and a rolled-back change leaves no row behind). Calling it outside a
 * transaction is a programming error and throws.
 */
final class AuditLogger
{
    public const REDACTED = '[redacted]';

    /**
     * @param  array<string, mixed>|null  $changes  e.g. ['before' => [...], 'after' => [...]]
     */
    public function record(string $action, ?Model $subject = null, ?array $changes = null): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException("Audit [{$action}] must be recorded inside the transaction of the change it describes.");
        }

        // Console commands (installer, seeders) have no real request: no IP or user agent.
        $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();
        $ip = $request?->ip();

        DB::table('audit_log')->insert([
            'occurred_at' => now('UTC')->format('Y-m-d H:i:s.u'),
            'actor_user_id' => auth()->id(),
            'ip' => $ip !== null ? (inet_pton($ip) ?: null) : null,
            'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 255) ?: null : null,
            'request_id' => Context::get('request_id'),
            'action' => $action,
            'subject_type' => $subject ? self::subjectType($subject) : null,
            'subject_id' => $subject?->getKey(),
            'changes' => $changes === null ? null : json_encode($changes, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]);
    }

    /**
     * Before/after values of the attributes that changed in the last save,
     * with sensitive values replaced by "[redacted]".
     *
     * @return array{before: array<string, mixed>, after: array<string, mixed>}
     */
    public static function diff(Model $model): array
    {
        $changed = array_diff(array_keys($model->getChanges()), [$model->getUpdatedAtColumn(), 'version']);
        $before = [];
        $after = [];

        foreach ($changed as $attribute) {
            if (self::isSensitive($model, $attribute)) {
                $before[$attribute] = self::REDACTED;
                $after[$attribute] = self::REDACTED;

                continue;
            }
            $before[$attribute] = self::plain($model, $attribute, $model->getOriginal($attribute));
            $after[$attribute] = self::plain($model, $attribute, $model->getAttribute($attribute));
        }

        return ['before' => $before, 'after' => $after];
    }

    /**
     * All attributes of a model, redacted, for create/delete entries.
     *
     * @return array<string, mixed>
     */
    public static function snapshot(Model $model): array
    {
        $values = [];
        foreach (array_keys($model->getAttributes()) as $attribute) {
            $values[$attribute] = self::isSensitive($model, $attribute)
                ? self::REDACTED
                : self::plain($model, $attribute, $model->getAttribute($attribute));
        }

        return $values;
    }

    public static function isSensitive(Model $model, string $attribute): bool
    {
        if (in_array($attribute, (array) config('meridian.audit.redact', []), true)) {
            return true;
        }

        $cast = $model->getCasts()[$attribute] ?? null;

        return $cast === Encrypted::class || (is_string($cast) && str_starts_with($cast, 'encrypted'));
    }

    public static function subjectType(Model $model): string
    {
        return $model->getMorphClass() === $model::class ? $model->getTable() : $model->getMorphClass();
    }

    private static function plain(Model $model, string $attribute, mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d\TH:i:s.uP');
        }
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        return $value;
    }
}
