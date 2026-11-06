<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalogue\EmploymentType;
use App\Domain\Catalogue\EmploymentTypeService;
use App\Domain\Shared\Concurrency\ETag;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Employment types (plan 01 §9.4, D20). Read: signed in. Change: org.structure.manage. */
class EmploymentTypeController extends Controller
{
    public function index(): JsonResponse
    {
        $types = EmploymentType::query()->with('convertsTo:id,public_id,name')->withCount('employees')->orderBy('sort')->orderBy('id')->get();

        return response()->json(['data' => $types->map(fn (EmploymentType $t) => $this->present($t))->all()]);
    }

    public function store(Request $request, EmploymentTypeService $service): JsonResponse
    {
        [$data, $convertsTo] = $this->validated($request, null);
        $type = $service->create($data, $convertsTo ?? []);

        return $this->show($type, 201);
    }

    public function update(Request $request, EmploymentType $employmentType, EmploymentTypeService $service): JsonResponse
    {
        [$data, $convertsTo] = $this->validated($request, $employmentType);
        $type = $service->update($employmentType, $data, $convertsTo, ETag::expectedVersion($request));

        return $this->show($type);
    }

    /** DELETE, with `move_to` (a type id) when employees still have this type. */
    public function destroy(Request $request, EmploymentType $employmentType, EmploymentTypeService $service): JsonResponse
    {
        $data = $request->validate(['move_to' => ['sometimes', 'nullable', 'string', Rule::exists('employment_types', 'public_id')]]);
        $moveTo = isset($data['move_to']) ? EmploymentType::query()->where('public_id', $data['move_to'])->firstOrFail() : null;

        $moved = $service->delete($employmentType, $moveTo, ETag::expectedVersion($request));

        return response()->json(['data' => ['deleted' => $employmentType->public_id, 'employees_moved' => $moved, 'moved_to' => $moveTo?->public_id]]);
    }

    /** @return array{0: array<string, mixed>, 1: ?list<int>} */
    private function validated(Request $request, ?EmploymentType $type): array
    {
        $sometimes = $type ? 'sometimes' : 'required';
        $data = $request->validate([
            'name' => [$sometimes, 'string', 'max:60', Rule::unique('employment_types', 'name')->ignore($type?->id)],
            'icon' => ['sometimes', 'string', Rule::in(EmploymentType::ICONS)],
            'default_probation_months' => ['sometimes', 'integer', 'between:0,6'],
            'typical_duration' => ['sometimes', 'nullable', 'string', 'max:60'],
            'has_fixed_end' => ['sometimes', 'boolean'],
            'renewal_reminder_days' => ['sometimes', 'nullable', 'integer', Rule::in([14, 30, 60])],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
            'converts_to' => ['sometimes', 'array'],
            'converts_to.*' => ['string', 'distinct', Rule::exists('employment_types', 'public_id')],
        ]);

        $convertsTo = null;
        if (array_key_exists('converts_to', $data)) {
            $convertsTo = EmploymentType::query()->whereIn('public_id', $data['converts_to'])->pluck('id')->map(fn ($id) => (int) $id)->all();
            unset($data['converts_to']);
        }

        return [$data, $convertsTo];
    }

    private function show(EmploymentType $type, int $status = 200): JsonResponse
    {
        $type->load('convertsTo:id,public_id,name')->loadCount('employees');

        return response()->json(['data' => $this->present($type)], $status)->setEtag((string) $type->version);
    }

    /** @return array<string, mixed> */
    private function present(EmploymentType $t): array
    {
        return [
            'id' => $t->public_id,
            'name' => $t->name,
            'icon' => $t->icon,
            'default_probation_months' => $t->default_probation_months,
            'typical_duration' => $t->typical_duration,
            'has_fixed_end' => $t->has_fixed_end,
            'renewal_reminder_days' => $t->renewal_reminder_days,
            'notes' => $t->notes,
            'converts_to' => $t->convertsTo->map(fn (EmploymentType $c) => ['id' => $c->public_id, 'name' => $c->name])->values()->all(),
            'employees' => (int) ($t->employees_count ?? 0),
            'version' => $t->version,
        ];
    }
}
