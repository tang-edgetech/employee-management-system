<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Version
    |--------------------------------------------------------------------------
    |
    | Semantic version of this install, plus the build date and commit written by
    | the deploy script (M8). Shown to users and by GET /api/v1/version.
    |
    */

    'version' => [
        'number' => env('MERIDIAN_VERSION', '0.6.0'),
        'build' => env('MERIDIAN_BUILD', 'dev'),
        'commit' => env('MERIDIAN_COMMIT', 'local'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Public IDs
    |--------------------------------------------------------------------------
    |
    | Integer keys never leave the server (ADR 0001 §5.11). Every exposed table
    | carries a public_id: "<prefix>_" followed by 8 random base62 characters.
    | Prefixes are fixed once data exists; never reuse or rename one.
    |
    */

    'public_id' => [
        'length' => 8,
        'max_attempts' => 5,
        'prefixes' => [
            'user' => 'usr',
            'employee' => 'emp',
            'team' => 'team',
            'file' => 'file',
            'department' => 'dept',
            'device' => 'dev',
            'designation' => 'desig',
            'employment_type' => 'etype',
            'task_tag' => 'tag',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Field encryption
    |--------------------------------------------------------------------------
    |
    | Sensitive columns (NRIC, salary, bank details...) are encrypted with a
    | dedicated data key, separate from APP_KEY, so rotating one doesn't touch
    | the other. Format: "<version>:base64:<32 bytes>", comma separated, the
    | first entry is used for new writes and older ones still decrypt.
    |
    */

    'data_keys' => env('MERIDIAN_DATA_KEYS', ''),

    /*
    |--------------------------------------------------------------------------
    | Blind index
    |--------------------------------------------------------------------------
    |
    | HMAC key used to make encrypted values searchable for exact matches
    | (e.g. NRIC duplicate checks) without storing them in plain text.
    | Changing it requires re-indexing every blind-index column.
    |
    */

    'blind_index_key' => env('MERIDIAN_BLIND_INDEX_KEY', ''),

    /*
    |--------------------------------------------------------------------------
    | Idempotency
    |--------------------------------------------------------------------------
    */

    'idempotency' => [
        'ttl_hours' => 24,
        'lock_seconds' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Pagination
    |--------------------------------------------------------------------------
    */

    'pagination' => [
        'default_per_page' => 25,
        'max_per_page' => 100,
    ],

    /*
    |--------------------------------------------------------------------------
    | Files
    |--------------------------------------------------------------------------
    |
    | Uploads go straight to storage through a short-lived URL, are checked by
    | their real content (not the name or the browser's claim), virus-scanned,
    | and only then usable (brief §4 Uploads). Each purpose sets its own limits
    | and the permission needed to upload; "null" means any signed-in user.
    | Photos are re-encoded to 240×240 WebP, which also strips EXIF data.
    |
    */

    'files' => [
        'disk' => env('UPLOADS_DISK', 'uploads'),
        'upload_url_minutes' => 10,
        'download_url_minutes' => 5,
        'pending_expire_hours' => 24,

        // "none" marks files clean without scanning: development only, refused in production.
        'scanner' => env('FILE_SCANNER', 'none'),
        'clamav' => [
            'host' => env('CLAMAV_HOST', '127.0.0.1'),
            'port' => (int) env('CLAMAV_PORT', 3310),
            'timeout' => 30,
        ],

        'purposes' => [
            'photo' => [
                'max_bytes' => 3 * 1024 * 1024,
                'mimes' => ['image/jpeg', 'image/png', 'image/webp'],
                'permission' => 'people.edit',
                'normalise' => 'photo',
            ],
            // A user's own picture: shown only to themselves (others see the first letter of their display name).
            'avatar' => [
                'max_bytes' => 3 * 1024 * 1024,
                'mimes' => ['image/jpeg', 'image/png', 'image/webp'],
                'permission' => null,
                'normalise' => 'photo',
            ],
            'logo' => [
                'max_bytes' => 2 * 1024 * 1024,
                'mimes' => ['image/jpeg', 'image/png', 'image/webp'],
                'permission' => 'system.settings',
                'normalise' => 'logo',
            ],
            'resume' => [
                'max_bytes' => 5 * 1024 * 1024,
                'mimes' => ['application/pdf', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
                'permission' => 'candidate.create',
                'normalise' => null,
            ],
            'evidence' => [
                'max_bytes' => 10 * 1024 * 1024,
                'mimes' => ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'],
                'permission' => null,
                'normalise' => null,
            ],
            'import' => [
                'max_bytes' => 5 * 1024 * 1024,
                'mimes' => ['text/csv', 'text/plain'],
                'permission' => 'people.import',
                'normalise' => null,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Audit log
    |--------------------------------------------------------------------------
    |
    | Attributes whose values are never written to the audit log. The log still
    | records that they changed. Encrypted casts are redacted automatically.
    |
    */

    'audit' => [
        'redact' => [
            'password',
            'remember_token',
            'two_factor_secret',
            'two_factor_recovery_codes',
        ],
    ],

];
