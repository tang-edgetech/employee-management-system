<?php

namespace App\Domain\Shared;

use Illuminate\Database\Eloquent\Model as EloquentModel;

/**
 * Base model for Meridian tables: timestamps are DATETIME(6) in UTC
 * (ADR 0001 §5.10), so dates carry microseconds.
 */
abstract class Model extends EloquentModel
{
    protected $dateFormat = 'Y-m-d H:i:s.u';
}
