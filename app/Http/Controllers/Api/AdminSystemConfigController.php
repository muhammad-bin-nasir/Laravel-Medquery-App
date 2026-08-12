<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SystemConfig;
use App\Models\User;
use App\Services\SystemConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminSystemConfigController extends Controller
{
    public function __construct(private readonly SystemConfigService $systemConfig)
    {
    }

    public function getAppSettings(Request $request): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->attributes->get('admin');
        if (!$this->isAdminLike($admin)) {
            return response()->json(['detail' => 'Not allowed'], 403);
        }

        return response()->json([
            'settings' => $this->systemConfig->allAppSettings(),
        ]);
    }

    public function updateAppSettings(Request $request): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->attributes->get('admin');
        if (!$this->isAdminLike($admin)) {
            return response()->json(['detail' => 'Not allowed'], 403);
        }

        $request->merge([
            'support_email' => filled($request->input('support_email'))
                ? trim((string) $request->input('support_email'))
                : null,
        ]);

        $rules = [
            'site_name' => ['sometimes', 'string', 'max:120'],
            'support_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'maintenance_message' => ['sometimes', 'string', 'max:1000'],
        ];
        foreach (SystemConfigService::BOOLEAN_KEYS as $key) {
            $rules[$this->systemConfig->toApiKey($key)] = ['sometimes', Rule::in([true, false, 0, 1, '0', '1', 'true', 'false'])];
        }

        $payload = $request->validate($rules);
        $settings = $this->systemConfig->updateAppSettings($payload);

        return response()->json([
            'status' => 'ok',
            'settings' => $settings,
            'message' => 'Application settings updated.',
        ]);
    }

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

    public function getSiteUserDefaults(Request $request): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->attributes->get('admin');
        if (!$this->isAdminLike($admin)) {
            return response()->json(['detail' => 'Not allowed'], 403);
        }

        $businessClientId = $this->systemConfig->get('SITE_USER_BUSINESS_CLIENT_ID');
        $workspaceId = $this->systemConfig->get('SITE_USER_WORKSPACE_ID');

        return response()->json([
            'business_client_id' => $businessClientId !== '' ? $businessClientId : null,
            'workspace_id' => $workspaceId !== '' ? $workspaceId : null,
        ]);
    }

    public function updateSiteUserDefaults(Request $request): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->attributes->get('admin');
        if (!$this->isAdminLike($admin)) {
            return response()->json(['detail' => 'Not allowed'], 403);
        }

        $payload = $request->validate([
            'business_client_id' => ['required', 'string', 'max:100'],
            'workspace_id' => ['required', 'string', 'max:100'],
        ]);

        $businessClientId = trim($payload['business_client_id']);
        $workspaceId = trim($payload['workspace_id']);

        $this->systemConfig->set('SITE_USER_BUSINESS_CLIENT_ID', $businessClientId);
        $this->systemConfig->set('SITE_USER_WORKSPACE_ID', $workspaceId);

        return response()->json([
            'status' => 'ok',
            'business_client_id' => $businessClientId,
            'workspace_id' => $workspaceId,
            'message' => 'Site user business and workspace updated. New signups will use this tenant.',
        ]);
    }

    public function getStripeSettings(Request $request): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->attributes->get('admin');
        if (! $this->isAdminLike($admin)) {
            return response()->json(['detail' => 'Not allowed'], 403);
        }

        return response()->json($this->systemConfig->stripeSettingsStatus());
    }

    public function updateStripeSettings(Request $request): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->attributes->get('admin');
        if (! $this->isAdminLike($admin)) {
            return response()->json(['detail' => 'Not allowed'], 403);
        }

        $payload = $request->validate([
            'enabled' => ['sometimes', Rule::in([true, false, 0, 1, '0', '1', 'true', 'false'])],
            'environment' => ['sometimes', Rule::in(['test', 'live'])],
            'test_publishable_key' => ['sometimes', 'nullable', 'string', 'max:255'],
            'test_secret_key' => ['sometimes', 'nullable', 'string', 'max:255'],
            'live_publishable_key' => ['sometimes', 'nullable', 'string', 'max:255'],
            'live_secret_key' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $status = $this->systemConfig->updateStripeSettings($payload);

        return response()->json([
            'status' => 'ok',
            'message' => 'Stripe settings saved.',
            ...$status,
        ]);
    }

    public function getTurnstileSettings(Request $request): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->attributes->get('admin');
        if (! $this->isAdminLike($admin)) {
            return response()->json(['detail' => 'Not allowed'], 403);
        }

        return response()->json($this->systemConfig->turnstileSettingsStatus());
    }

    public function updateTurnstileSettings(Request $request): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->attributes->get('admin');
        if (! $this->isAdminLike($admin)) {
            return response()->json(['detail' => 'Not allowed'], 403);
        }

        $payload = $request->validate([
            'enabled' => ['sometimes', Rule::in([true, false, 0, 1, '0', '1', 'true', 'false'])],
            'site_key' => ['sometimes', 'nullable', 'string', 'max:255'],
            'secret_key' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $status = $this->systemConfig->updateTurnstileSettings($payload);

        return response()->json([
            'status' => 'ok',
            'message' => 'Cloudflare Turnstile settings saved.',
            ...$status,
        ]);
    }

    public function getMailSettings(Request $request): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->attributes->get('admin');
        if (! $this->isAdminLike($admin)) {
            return response()->json(['detail' => 'Not allowed'], 403);
        }

        $mail = app(\App\Services\MailSettingsService::class);

        return response()->json([
            ...$mail->status(),
            'frontend_url' => $mail->frontendUrl(),
        ]);
    }

    public function updateMailSettings(Request $request): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->attributes->get('admin');
        if (! $this->isAdminLike($admin)) {
            return response()->json(['detail' => 'Not allowed'], 403);
        }

        $payload = $request->validate([
            'enabled' => ['sometimes', Rule::in([true, false, 0, 1, '0', '1', 'true', 'false'])],
            'mailer' => ['sometimes', 'string', Rule::in(['smtp', 'log', 'array'])],
            'host' => ['sometimes', 'nullable', 'string', 'max:255'],
            'port' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:65535'],
            'username' => ['sometimes', 'nullable', 'string', 'max:255'],
            'password' => ['sometimes', 'nullable', 'string', 'max:255'],
            'encryption' => ['sometimes', 'nullable', 'string', Rule::in(['tls', 'ssl', 'none', ''])],
            'from_address' => ['sometimes', 'nullable', 'email', 'max:255'],
            'from_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'frontend_url' => ['sometimes', 'nullable', 'url', 'max:500'],
        ]);

        if (array_key_exists('encryption', $payload) && ($payload['encryption'] === 'none' || $payload['encryption'] === '')) {
            $payload['encryption'] = '';
        }

        if (array_key_exists('frontend_url', $payload)) {
            $url = trim((string) ($payload['frontend_url'] ?? ''));
            if ($url !== '') {
                $this->systemConfig->set('FRONTEND_URL', rtrim($url, '/'));
            }
        }

        $mail = app(\App\Services\MailSettingsService::class);
        $status = $mail->update($payload);

        return response()->json([
            'status' => 'ok',
            'message' => 'SMTP settings saved.',
            ...$status,
            'frontend_url' => $mail->frontendUrl(),
        ]);
    }

    public function sendTestMail(Request $request): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->attributes->get('admin');
        if (! $this->isAdminLike($admin)) {
            return response()->json(['detail' => 'Not allowed'], 403);
        }

        $payload = $request->validate([
            'to' => ['nullable', 'email'],
        ]);

        $to = trim((string) ($payload['to'] ?? $admin->email));
        if ($to === '') {
            return response()->json(['detail' => 'Enter a destination email.'], 422);
        }

        try {
            $mail = app(\App\Services\MailSettingsService::class);
            $siteName = $this->systemConfig->get('SITE_NAME') ?: 'NursingAI';
            $mail->send(function () use ($to, $siteName): void {
                \Illuminate\Support\Facades\Mail::raw(
                    "This is a test email from {$siteName}. SMTP settings are working.",
                    function ($message) use ($to, $siteName): void {
                        $message->to($to)->subject("{$siteName} SMTP test");
                    }
                );
            });
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'detail' => $e->getMessage() ?: 'Failed to send test email.',
                'code' => 'mail_send_failed',
            ], 503);
        }

        return response()->json([
            'status' => 'ok',
            'message' => "Test email sent to {$to}.",
        ]);
    }

    private function isAdminLike(User $user): bool
    {
        return in_array($user->role, ['admin', 'super_admin'], true);
    }

    private function maskKey(string $value): ?string
    {
        return $this->systemConfig->maskSecret($value);
    }
}
