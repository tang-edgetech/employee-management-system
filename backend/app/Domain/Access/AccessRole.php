<?php

namespace App\Domain\Access;

use App\Domain\Shared\Model;

/**
 * @property int $id
 * @property string $key
 * @property string $name
 * @property ?string $note
 * @property bool $locked
 * @property int $version
 */
class AccessRole extends Model
{
    protected $table = 'access_roles';

    protected $guarded = ['id', 'key', 'locked', 'version'];

    protected function casts(): array
    {
        return [
            'locked' => 'boolean',
            'version' => 'integer',
        ];
    }

    public function roleKey(): RoleKey
    {
        return RoleKey::from($this->key);
    }

    public static function idFor(RoleKey $key): int
    {
        return (int) self::query()->where('key', $key->value)->value('id');
    }
}
