<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalogue\TaskTag;
use App\Domain\Catalogue\TaskTagLevel;
use App\Domain\Catalogue\TaskTagService;
use App\Domain\Shared\Concurrency\ETag;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/** Task tags and points (plan 01 §9.4, D21). Read: signed in. Change: org.task_tags.manage. */
class TaskTagController extends Controller
{
    public function index(): JsonResponse
    {
        $tags = TaskTag::query()->with('levels')->orderBy('sort')->orderBy('id')->get();

        return response()->json(['data' => $tags->map(fn (TaskTag $t) => $this->present($t))->all()]);
    }

    public function store(Request $request, TaskTagService $service): JsonResponse
    {
        [$data, $levels] = $this->validated($request, null);
        $tag = $service->create($data, $levels ?? []);

        return response()->json(['data' => $this->present($tag)], 201)->setEtag((string) $tag->version);
    }

    public function update(Request $request, TaskTag $taskTag, TaskTagService $service): JsonResponse
    {
        [$data, $levels] = $this->validated($request, $taskTag);
        $tag = $service->update($taskTag, $data, $levels, ETag::expectedVersion($request));

        return response()->json(['data' => $this->present($tag)])->setEtag((string) $tag->version);
    }

    public function destroy(Request $request, TaskTag $taskTag, TaskTagService $service): Response
    {
        $service->delete($taskTag, ETag::expectedVersion($request));

        return response()->noContent();
    }

    /** @return array{0: array<string, mixed>, 1: ?list<array{name: string, points: int}>} */
    private function validated(Request $request, ?TaskTag $tag): array
    {
        if ($request->has('code') && is_string($request->input('code'))) {
            $request->merge(['code' => strtoupper(trim($request->input('code')))]);
        }
        $sometimes = $tag ? 'sometimes' : 'required';

        $data = $request->validate([
            // The code is fixed once created: tasks refer to it as e.g. "C·L3".
            'code' => $tag ? ['prohibited'] : ['required', 'string', 'regex:/^[A-Z][A-Z0-9]{0,2}$/', Rule::unique('task_tags', 'code')],
            'name' => [$sometimes, 'string', 'max:60'],
            'color' => [$sometimes, 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'typical_duration' => ['sometimes', 'nullable', 'string', 'max:60'],
            'description' => ['sometimes', 'nullable', 'string', 'max:300'],
            'levels' => [$sometimes, 'array', 'min:1', 'max:20'],
            'levels.*.name' => ['required', 'string', 'max:60'],
            'levels.*.points' => ['required', 'integer', 'between:0,1000'],
        ], ['code.regex' => 'The code is 1 to 3 capital letters or digits, starting with a letter.']);

        $levels = null;
        if (array_key_exists('levels', $data)) {
            $levels = array_map(fn (array $l) => ['name' => (string) $l['name'], 'points' => (int) $l['points']], array_values($data['levels']));
            unset($data['levels']);
        }

        return [$data, $levels];
    }

    /** @return array<string, mixed> */
    private function present(TaskTag $t): array
    {
        return [
            'id' => $t->public_id,
            'code' => $t->code,
            'name' => $t->name,
            'color' => $t->color,
            'typical_duration' => $t->typical_duration,
            'description' => $t->description,
            'levels' => $t->levels->map(fn (TaskTagLevel $l) => ['level' => $l->level, 'name' => $l->name, 'points' => $l->points])->values()->all(),
            'version' => $t->version,
        ];
    }
}
