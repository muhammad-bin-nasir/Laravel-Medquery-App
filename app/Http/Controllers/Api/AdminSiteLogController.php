<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SiteLog;
use App\Models\User;
use App\Services\SiteLogService;
use App\Services\StaffAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminSiteLogController extends Controller
{
    public function __construct(private readonly SiteLogService $siteLogs)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $admin = $this->requireStaff($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $severity = trim((string) $request->query('severity', ''));
        $source = trim((string) $request->query('source', ''));
        $category = trim((string) $request->query('category', ''));
        $q = trim((string) $request->query('q', ''));
        $resolved = trim((string) $request->query('resolved', ''));
        $perPage = min(100, max(10, (int) $request->query('per_page', 50)));

        $query = SiteLog::query()->orderByDesc('created_at');

        if ($severity !== '' && in_array($severity, SiteLogService::SEVERITIES, true)) {
            $query->where('severity', $severity);
        }
        if ($source !== '' && in_array($source, SiteLogService::SOURCES, true)) {
            $query->where('source', $source);
        }
        if ($category !== '') {
            $query->where('category', $category);
        }
        if ($resolved === '1' || $resolved === 'true') {
            $query->whereNotNull('resolved_at');
        } elseif ($resolved === '0' || $resolved === 'false') {
            $query->whereNull('resolved_at');
        }
        if ($q !== '') {
            $like = '%'.$q.'%';
            $query->where(function ($inner) use ($like): void {
                $inner->where('message', 'like', $like)
                    ->orWhere('exception_class', 'like', $like)
                    ->orWhere('request_path', 'like', $like)
                    ->orWhere('user_email', 'like', $like)
                    ->orWhere('correlation_id', 'like', $like)
                    ->orWhere('category', 'like', $like);
            });
        }

        $paginator = $query->paginate($perPage);

        return response()->json([
            'logs' => collect($paginator->items())->map(fn (SiteLog $log) => $this->serialize($log, false))->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'filters' => [
                'severities' => SiteLogService::SEVERITIES,
                'sources' => SiteLogService::SOURCES,
            ],
        ]);
    }

    public function show(Request $request, string $logId): JsonResponse
    {
        $admin = $this->requireStaff($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $log = SiteLog::query()->find($logId);
        if (! $log) {
            return response()->json(['detail' => 'Log not found.', 'code' => 'not_found'], 404);
        }

        return response()->json(['log' => $this->serialize($log, true)]);
    }

    public function resolve(Request $request, string $logId): JsonResponse
    {
        $admin = $this->requireStaff($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $log = SiteLog::query()->find($logId);
        if (! $log) {
            return response()->json(['detail' => 'Log not found.', 'code' => 'not_found'], 404);
        }

        if (! $log->resolved_at) {
            $log->resolved_at = now();
            $log->save();
        }

        return response()->json(['log' => $this->serialize($log, true)]);
    }

    public function destroy(Request $request, string $logId): JsonResponse
    {
        $admin = $this->requireStaff($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $log = SiteLog::query()->find($logId);
        if (! $log) {
            return response()->json(['detail' => 'Log not found.', 'code' => 'not_found'], 404);
        }

        $log->delete();

        return response()->json(['status' => 'deleted']);
    }

    public function clear(Request $request): JsonResponse
    {
        $admin = $this->requireStaff($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $payload = $request->validate([
            'older_than_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'resolved_only' => ['nullable', 'boolean'],
            'severity' => ['nullable', 'string', 'in:'.implode(',', SiteLogService::SEVERITIES)],
            'source' => ['nullable', 'string', 'in:'.implode(',', SiteLogService::SOURCES)],
        ]);

        $query = SiteLog::query();

        if (array_key_exists('older_than_days', $payload) && $payload['older_than_days'] !== null) {
            $query->where('created_at', '<', now()->subDays((int) $payload['older_than_days']));
        }
        if (! empty($payload['resolved_only'])) {
            $query->whereNotNull('resolved_at');
        }
        if (! empty($payload['severity'])) {
            $query->where('severity', $payload['severity']);
        }
        if (! empty($payload['source'])) {
            $query->where('source', $payload['source']);
        }

        $deleted = $query->delete();

        return response()->json([
            'status' => 'cleared',
            'deleted' => $deleted,
        ]);
    }

    public function ingest(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'severity' => ['nullable', 'string', 'max:32'],
            'source' => ['nullable', 'string', 'max:32'],
            'category' => ['nullable', 'string', 'max:64'],
            'message' => ['required', 'string', 'max:5000'],
            'exception_class' => ['nullable', 'string', 'max:255'],
            'stack_trace' => ['nullable', 'string', 'max:50000'],
            'context' => ['nullable', 'array'],
            'correlation_id' => ['nullable', 'string', 'max:64'],
            'user_id' => ['nullable', 'uuid'],
            'user_email' => ['nullable', 'string', 'max:255'],
            'user_role' => ['nullable', 'string', 'max:32'],
            'request_method' => ['nullable', 'string', 'max:16'],
            'request_path' => ['nullable', 'string', 'max:512'],
            'request_url' => ['nullable', 'string', 'max:2000'],
            'status_code' => ['nullable', 'integer', 'min:100', 'max:599'],
        ]);

        $log = $this->siteLogs->record($payload, $request);

        return response()->json([
            'status' => 'recorded',
            'id' => $log?->id,
        ], 201);
    }

    private function requireStaff(Request $request): User|JsonResponse
    {
        /** @var User|null $admin */
        $admin = $request->attributes->get('admin');
        if (! StaffAccess::isStaff($admin)) {
            return response()->json(['detail' => 'Not allowed', 'code' => 'forbidden'], 403);
        }

        return $admin;
    }

    private function serialize(SiteLog $log, bool $full): array
    {
        $base = [
            'id' => $log->id,
            'severity' => $log->severity,
            'source' => $log->source,
            'category' => $log->category,
            'message' => $log->message,
            'exception_class' => $log->exception_class,
            'correlation_id' => $log->correlation_id,
            'user_id' => $log->user_id,
            'user_email' => $log->user_email,
            'user_role' => $log->user_role,
            'request_method' => $log->request_method,
            'request_path' => $log->request_path,
            'status_code' => $log->status_code,
            'resolved_at' => optional($log->resolved_at)?->toIso8601String(),
            'created_at' => optional($log->created_at)?->toIso8601String(),
        ];

        if (! $full) {
            return $base;
        }

        return array_merge($base, [
            'stack_trace' => $log->stack_trace,
            'context' => $log->context_json,
            'request_url' => $log->request_url,
            'ip_address' => $log->ip_address,
            'user_agent' => $log->user_agent,
            'updated_at' => optional($log->updated_at)?->toIso8601String(),
        ]);
    }
}
