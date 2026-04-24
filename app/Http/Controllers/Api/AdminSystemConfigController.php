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
        $databaseValue = trim((string) ($row?->value ?? ''));
        $envValue = trim((string) env('OPENAI_API_KEY', ''));
        $value = $databaseValue !== '' ? $databaseValue : $envValue;

        return response()->json([
            'set' => $value !== '',
            'masked_key' => $this->maskKey($value),
            'source' => $databaseValue !== '' ? 'database' : ($envValue !== '' ? 'env' : null),
            'has_database_override' => $databaseValue !== '',
            'updated_at' => $row?->updated_at?->toISOString(),
        ]);
    }

    public function getProjectApiStatus(Request $request): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->attributes->get('admin');
        if (!$this->isAdminLike($admin)) {
            return response()->json(['detail' => 'Not allowed'], 403);
        }

        $baseUrl = trim((string) config('services.project.base_url', ''));

        return response()->json([
            'configured' => $baseUrl !== '',
            'base_url' => $baseUrl !== '' ? $baseUrl : null,
            'host' => $baseUrl !== '' ? parse_url($baseUrl, PHP_URL_HOST) : null,
        ]);
    }

    public function getRuntimeStatus(Request $request): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->attributes->get('admin');
        if (!$this->isAdminLike($admin)) {
            return response()->json(['detail' => 'Not allowed'], 403);
        }

        return response()->json([
            'app_env' => config('app.env'),
            'app_debug' => (bool) config('app.debug'),
            'session_driver' => config('session.driver'),
            'queue_connection' => config('queue.default'),
        ]);
    }

    public function getDatabaseStatus(Request $request): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->attributes->get('admin');
        if (!$this->isAdminLike($admin)) {
            return response()->json(['detail' => 'Not allowed'], 403);
        }

        $defaultConnection = (string) config('database.default');
        $defaultConfig = (array) config('database.connections.'.$defaultConnection, []);
        $projectConfig = (array) config('database.connections.project_pgsql', []);

        return response()->json([
            'default_connection' => $defaultConnection !== '' ? $defaultConnection : null,
            'default_driver' => $defaultConfig['driver'] ?? null,
            'default_host' => $defaultConfig['host'] ?? null,
            'default_port' => $defaultConfig['port'] ?? null,
            'default_database' => $defaultConfig['database'] ?? null,
            'default_socket_configured' => !empty($defaultConfig['unix_socket'] ?? null),
            'project_connection_configured' => !empty($projectConfig),
            'project_driver' => $projectConfig['driver'] ?? null,
            'project_host' => $projectConfig['host'] ?? null,
            'project_port' => $projectConfig['port'] ?? null,
            'project_database' => $projectConfig['database'] ?? null,
        ]);
    }

    public function getAuthModeStatus(Request $request): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->attributes->get('admin');
        if (!$this->isAdminLike($admin)) {
            return response()->json(['detail' => 'Not allowed'], 403);
        }

        return response()->json([
            'admin_middleware_alias' => 'admin.auth',
            'token_optional' => true,
            'fallback_admin_enabled' => true,
            'current_admin_role' => $admin->role,
            'current_admin_email' => $admin->email,
        ]);
    }

    public function getStorageStatus(Request $request): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->attributes->get('admin');
        if (!$this->isAdminLike($admin)) {
            return response()->json(['detail' => 'Not allowed'], 403);
        }

        $publicStorageLink = public_path('storage');
        $publicStorageTarget = storage_path('app/public');

        return response()->json([
            'default_disk' => config('filesystems.default'),
            'public_disk_url' => config('filesystems.disks.public.url'),
            'public_storage_link_path' => $publicStorageLink,
            'public_storage_target_path' => $publicStorageTarget,
            'public_storage_link_exists' => file_exists($publicStorageLink),
            'public_storage_link_is_symlink' => is_link($publicStorageLink),
            'public_storage_target_exists' => file_exists($publicStorageTarget),
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

    public function clearOpenAiApiKeyOverride(Request $request): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->attributes->get('admin');
        if (!$this->isAdminLike($admin)) {
            return response()->json(['detail' => 'Not allowed'], 403);
        }

        $deleted = SystemConfig::query()->where('key', 'OPENAI_API_KEY')->delete();

        return response()->json([
            'status' => 'ok',
            'message' => $deleted > 0
                ? 'OpenAI API key database override cleared. Falling back to env when available.'
                : 'No OpenAI API key database override was set.',
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
