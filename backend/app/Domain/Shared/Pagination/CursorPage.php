<?php

namespace App\Domain\Shared\Pagination;

use App\Exceptions\ApiProblem;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Support\Facades\Crypt;

/**
 * Cursor pagination whose cursors are encrypted, because Laravel's own cursor is
 * only base64 and would reveal internal IDs (ADR 0001 §5.11).
 *
 * The query must be ordered by a unique combination, e.g. created_at then id.
 */
final class CursorPage
{
    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return CursorPaginator<int, TModel>
     */
    public static function paginate(Builder $query, Request $request): CursorPaginator
    {
        $max = (int) config('meridian.pagination.max_per_page', 100);
        $perPage = max(1, min($max, (int) $request->query('per_page', (string) config('meridian.pagination.default_per_page', 25))));

        return $query->cursorPaginate($perPage, ['*'], 'cursor', self::decode($request->query('cursor')));
    }

    /**
     * @param  CursorPaginator<int, Model>  $page
     * @return array{next_cursor: ?string, prev_cursor: ?string, per_page: int}
     */
    public static function meta(CursorPaginator $page): array
    {
        return [
            'next_cursor' => self::encode($page->nextCursor()),
            'prev_cursor' => self::encode($page->previousCursor()),
            'per_page' => $page->perPage(),
        ];
    }

    public static function encode(?Cursor $cursor): ?string
    {
        return $cursor === null ? null : rtrim(strtr(Crypt::encryptString($cursor->encode()), '+/', '-_'), '=');
    }

    public static function decode(mixed $token): ?Cursor
    {
        if ($token === null || $token === '') {
            return null;
        }

        try {
            $padded = strtr((string) $token, '-_', '+/');
            $padded .= str_repeat('=', (4 - strlen($padded) % 4) % 4);
            $cursor = Cursor::fromEncoded(Crypt::decryptString($padded));
        } catch (DecryptException) {
            $cursor = null;
        }

        if ($cursor === null) {
            throw ApiProblem::badRequest('INVALID_CURSOR', 'The pagination cursor is invalid or expired.');
        }

        return $cursor;
    }
}
