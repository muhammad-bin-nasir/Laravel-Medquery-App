<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SystemConfigService;
use Illuminate\Http\JsonResponse;

class AppSettingsController extends Controller
{
    public function __construct(private readonly SystemConfigService $systemConfig)
    {
    }

    /**
     * Public (no-auth) application settings used by user and admin UIs.
     */
    public function show(): JsonResponse
    {
        return response()->json([
            'settings' => $this->systemConfig->publicSettings(),
        ]);
    }
}
