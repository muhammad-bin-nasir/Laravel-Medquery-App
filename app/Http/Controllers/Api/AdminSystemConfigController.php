<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SystemConfig;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminSystemConfigController extends Controller
{
    public function getOpenAiApiKeyStatus(Request $request): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->attributes->get('admin');
        if (!$this->isAdminLike($admin)) {
            return response()->json(['detail' => 'Not allowed'], 403);
        }

        $row = SystemConfig::query()->where('key', 'OPENAI_API_KEY')->first();
        $value = trim((string) ($row?->value ?? env('OPENAI_API_KEY', '')));

        return response()->json([
            'set' => $value !== '',
            'masked_key' => $this->maskKey($value),
        ]);
    }

    public function updateOpenAiApiKey(Request $request): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->attributes->get('admin');
        if (!$this->isAdminLike($admin)) {
            return response()->json(['detail' => 'Not allowed'], 403);
        }

        $payload = $request->validate([
            'value' => ['required', 'string', 'max:2000'],
        ]);

        $value = trim((string) $payload['value']);
        if ($value === '') {
            return response()->json(['detail' => 'Value cannot be empty'], 400);
        }

        SystemConfig::query()->updateOrCreate(
            ['key' => 'OPENAI_API_KEY'],
            ['value' => $value]
        );

        return response()->json([
            'status' => 'ok',
            'message' => 'OpenAI API key saved.',
        ]);
    }

    private function isAdminLike(User $user): bool
    {
        return in_array($user->role, ['admin', 'super_admin'], true);
    }

    private function maskKey(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        $len = strlen($value);
        if ($len <= 8) {
            return str_repeat('*', $len);
        }

        return substr($value, 0, 4).str_repeat('*', max(4, $len - 8)).substr($value, -4);
    }
}
