<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/** The installed version (plan 01 §9.2). Shown in the app; the deploy script sets it (M8). */
class VersionController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json(['data' => [
            'version' => (string) config('meridian.version.number'),
            'build' => (string) config('meridian.version.build'),
            'commit' => (string) config('meridian.version.commit'),
        ]]);
    }
}
