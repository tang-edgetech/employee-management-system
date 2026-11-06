<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalogue\Designation;
use App\Domain\Catalogue\DesignationService;
use App\Domain\Shared\Concurrency\ETag;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The career ladder (plan 01 §9.4, D19). Everyone signed in can read it (forms
 * need it); salary bands only with people.salary.view. Changes need
 * org.structure.manage. The list's ETag is the ladder version used by reorder.
 */
class DesignationController extends Controller
{
    public function index(Request $request, DesignationService $service): JsonResponse
    {
        $salary = $this->user($request)->can('people.salary.view');

        return response()->json(['data' => $service->ladder()->map(fn (Designation $d) => $this->present($d, $salary))->all()])
            ->setEtag((string) $service->ladderVersion());
    }

    public function store(Request $request, DesignationService $service): JsonResponse
    {
        $designation = $service->create($this->validated($request, null));

        return response()->json(['data' => $this->present($designation->loadCount('employees'), true)], 201)
            ->setEtag((string) $designation->version);
    }

    public function update(Request $request, Designation $designation, DesignationService $service): JsonResponse
    {
        $designation = $service->update($designation, $this->validated($request, $designation), ETag::expectedVersion($request));

        return response()->json(['data' => $this->present($designation->loadCount('employees'), true)])
            ->setEtag((string) $designation->version);
    }

    public function destroy(Request $request, Designation $designation, DesignationService $service): Response
    {
        $service->delete($designation, ETag::expectedVersion($request));

        return response()->noContent();
    }

    /** PUT /designations/order with the whole ladder, lowest rung first, and If-Match: the ladder version. */
    public function reorder(Request $request, DesignationService $service): JsonResponse
    {
        $data = $request->validate([
            'order' => ['required', 'array', 'min:1'],
            'order.*' => ['required', 'string', 'distinct'],
        ]);

        $service->reorder(array_values($data['order']), ETag::expectedVersion($request));

        return $this->index($request, $service);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Designation $designation): array
    {
        $sometimes = $designation ? 'sometimes' : 'required';

        $data = $request->validate([
            'name' => [$sometimes, 'string', 'max:100', Rule::unique('designations', 'name')->ignore($designation?->id)],
            'track' => [$sometimes, 'string', Rule::in(Designation::TRACKS)],
            'can_lead_team' => ['sometimes', 'boolean'],
            'salary_min' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999'],
            'salary_max' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999'],
            'kpi_target_points' => ['sometimes', 'nullable', 'integer', 'between:0,10000'],
        ]);

        $min = array_key_exists('salary_min', $data) ? $data['salary_min'] : $designation?->salary_min;
        $max = array_key_exists('salary_max', $data) ? $data['salary_max'] : $designation?->salary_max;
        if ($min !== null && $max !== null && (float) $min > (float) $max) {
            throw ValidationException::withMessages(['salary_max' => 'The band maximum must not be below the minimum.']);
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function present(Designation $d, bool $salary): array
    {
        return [
            'id' => $d->public_id,
            'name' => $d->name,
            'track' => $d->track,
            'level' => $d->level,
            'can_lead_team' => $d->can_lead_team,
            'kpi_target_points' => $d->kpi_target_points,
            'employees' => (int) ($d->employees_count ?? 0),
            'version' => $d->version,
        ] + ($salary ? ['salary_min' => $d->salary_min, 'salary_max' => $d->salary_max] : []);
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
