<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\People\EmployeeNumbers;
use App\Domain\Settings\CompanySettings;
use App\Domain\Shared\Concurrency\ETag;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Employee numbers (plan 01 §9.4, D22). Changing the format for new employees is a
 * normal settings update; this endpoint renumbers everyone. If-Match is the
 * settings version, like PATCH /settings.
 */
class EmployeeNumberController extends Controller
{
    public function preview(EmployeeNumbers $numbers): JsonResponse
    {
        $settings = CompanySettings::current();

        return response()->json(['data' => [
            'format' => $settings->employee_no_format,
            'digits' => $settings->employee_no_digits,
            'next' => $settings->employee_no_next,
            'next_number' => $numbers->preview(),
        ]]);
    }

    public function renumber(Request $request, EmployeeNumbers $numbers): JsonResponse
    {
        $data = $request->validate([
            'format' => ['required', 'string', 'max:20'],
            'digits' => ['required', 'integer', 'between:3,8'],
        ]);

        $changed = $numbers->renumberAll($data['format'], (int) $data['digits'], ETag::expectedVersion($request));
        $settings = CompanySettings::current();

        return response()->json(['data' => [
            'changed' => count($changed),
            'next_number' => $numbers->preview(),
            'settings_version' => $settings->version,
        ]])->setEtag((string) $settings->version);
    }
}
