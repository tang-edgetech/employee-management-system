<?php

namespace App\Domain\Files;

use App\Domain\Shared\HasPublicId;
use App\Domain\Shared\Model;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An uploaded file. Named StoredFile to avoid clashing with PHP and Laravel "File"
 * classes; the table is `files`.
 *
 * @property int $id
 * @property string $public_id
 * @property string $purpose
 * @property string $disk
 * @property string $storage_key
 * @property string $original_name
 * @property string $declared_mime
 * @property ?string $mime
 * @property int $size_bytes
 * @property ?string $sha256
 * @property string $scan_status
 * @property ?int $uploaded_by
 * @property ?Carbon $confirmed_at
 * @property ?Carbon $scanned_at
 * @property Carbon $created_at
 */
class StoredFile extends Model
{
    use HasPublicId;

    public const PENDING = 'pending';

    public const CLEAN = 'clean';

    public const INFECTED = 'infected';

    protected $table = 'files';

    protected $guarded = ['id'];

    public static function publicIdType(): string
    {
        return 'file';
    }

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'confirmed_at' => 'datetime',
            'scanned_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function isUploaded(): bool
    {
        return $this->confirmed_at !== null;
    }

    public function isScanClean(): bool
    {
        return $this->scan_status === self::CLEAN;
    }

    /** @return array{max_bytes: int, mimes: list<string>, permission: ?string, normalise: ?string} */
    public function rules(): array
    {
        return config('meridian.files.purposes.'.$this->purpose);
    }
}
