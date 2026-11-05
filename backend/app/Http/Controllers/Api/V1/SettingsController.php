<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Settings\CompanySettings;
use App\Domain\Settings\SettingsRules;
use App\Domain\Settings\SettingsService;
use App\Domain\Shared\Concurrency\ETag;
use App\Exceptions\ApiProblem;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Global Settings (plan 01 §4). Readers see only the sections their permissions
 * allow; writers need system.settings or kpi.configure for the fields they send.
 */
class SettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return $this->respond($request->user(), CompanySettings::current());
    }

    public function update(Request $request, SettingsService $service): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $input = $request->all();

        $unknown = array_diff(array_keys($input), SettingsRules::writableFields());
        if ($unknown !== []) {
            throw ApiProblem::unprocessable('UNKNOWN_FIELD', 'These fields can\'t be changed here: '.implode(', ', $unknown).'.');
        }
        if ($input === []) {
            throw ApiProblem::unprocessable('NOTHING_TO_UPDATE', 'Send at least one setting to change.');
        }

        $missing = array_unique(array_filter(array_map(
            fn (string $field) => $user->can($permission = (string) SettingsRules::permissionToWrite($field)) ? null : $permission,
            array_keys($input),
        )));
        if ($missing !== []) {
            throw new ApiProblem(403, 'FORBIDDEN', 'Forbidden', 'Changing these settings needs: '.implode(', ', $missing).'.');
        }

        $settings = $service->update($input, ETag::expectedVersion($request), $user);

        return $this->respond($user, $settings);
    }

    private function respond(User $user, CompanySettings $settings): JsonResponse
    {
        $sections = [];
        foreach (SettingsRules::READ_SECTIONS as $section => [$permissions, $fields]) {
            if ($permissions === null || collect($permissions)->contains(fn ($p) => $user->can($p))) {
                $sections[$section] = $settings->only($fields);
            }
        }

        $editable = array_keys(array_filter(
            SettingsRules::WRITE_PERMISSIONS,
            fn ($fields, $permission) => $user->can($permission),
            ARRAY_FILTER_USE_BOTH,
        ));

        return response()->json([
            'data' => $sections,
            'meta' => ['version' => $settings->version, 'can_edit' => $editable],
        ])->setEtag(trim(ETag::for($settings->version), '"'));
    }
}
