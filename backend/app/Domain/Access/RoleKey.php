<?php

namespace App\Domain\Access;

/**
 * The five access roles of brief §5.1. The access_roles table (M4) holds their
 * names and permissions; the keys themselves are fixed in code.
 */
enum RoleKey: string
{
    case Sysadmin = 'sysadmin';
    case Exec = 'exec';
    case Hr = 'hr';
    case Lead = 'lead';
    case Staff = 'staff';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
