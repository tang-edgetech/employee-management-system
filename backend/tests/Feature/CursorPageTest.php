<?php

use App\Domain\Shared\Pagination\CursorPage;
use App\Exceptions\ApiProblem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\DB;

class AuditRowForPaging extends Model
{
    protected $table = 'audit_log';
}

beforeEach(function () {
    foreach (range(1, 5) as $i) {
        DB::table('audit_log')->insert(['occurred_at' => now('UTC')->format('Y-m-d H:i:s.u'), 'action' => "page.row{$i}"]);
    }
});

function pageOf(array $query)
{
    return CursorPage::paginate(AuditRowForPaging::query()->where('action', 'like', 'page.%')->orderBy('id'), Request::create('/', 'GET', $query));
}

it('walks every row exactly once with encrypted cursors', function () {
    $seen = [];
    $cursor = null;

    do {
        $page = pageOf(array_filter(['per_page' => 2, 'cursor' => $cursor]));
        $seen = array_merge($seen, $page->pluck('action')->all());
        $cursor = CursorPage::meta($page)['next_cursor'];
    } while ($cursor !== null);

    expect($seen)->toBe(['page.row1', 'page.row2', 'page.row3', 'page.row4', 'page.row5']);
});

it('does not reveal internal IDs in the cursor', function () {
    $page = pageOf(['per_page' => 2]);
    $token = CursorPage::meta($page)['next_cursor'];
    $plainLaravelCursor = $page->nextCursor()->encode();

    expect($token)->not->toBe($plainLaravelCursor)
        ->and(base64_decode(strtr($token, '-_', '+/')))->not->toContain('"id"')
        ->and($token)->toMatch('/^[A-Za-z0-9_-]+$/');
});

it('rejects a forged or plain cursor', function () {
    $plain = (new Cursor(['id' => 1]))->encode();

    expect(fn () => pageOf(['cursor' => $plain]))->toThrow(ApiProblem::class, 'invalid or expired');
});

it('caps the page size', function () {
    config(['meridian.pagination.max_per_page' => 3]);

    expect(pageOf(['per_page' => 1000])->perPage())->toBe(3)
        ->and(pageOf(['per_page' => 0])->perPage())->toBe(1);
});
