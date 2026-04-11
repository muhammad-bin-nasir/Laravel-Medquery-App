<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceConfig;
use App\Services\DocumentIngestService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AdminDocumentController extends Controller
{
    public function __construct(private readonly DocumentIngestService $documentIngestService)
    {
    }

    public function upload(Request $request, string $business_client_id, string $workspace_id): JsonResponse
    {
        [$business, $workspace, $accessError] = $this->resolveScopeAndAccess($request, $business_client_id, $workspace_id);
        if ($accessError !== null) {
            return $accessError;
        }

        $validated = $request->validate([
            'file' => ['required', 'file'],
            'chunk_words' => ['nullable', 'integer', 'min:1'],
            'overlap_words' => ['nullable', 'integer', 'min:0'],
        ]);

        /** @var UploadedFile $file */
        $file = $validated['file'];
        $original = $file->getClientOriginalName();
        if (!$original) {
            return response()->json(['detail' => 'Filename is required'], 400);
        }

        $extension = strtolower((string) $file->getClientOriginalExtension());
        if (!in_array($extension, ['pdf', 'txt'], true)) {
            return response()->json(['detail' => 'Unsupported file type. Only PDF and TXT files are supported.'], 400);
        }

        $maxSize = 50 * 1024 * 1024;
        if ($file->getSize() !== null && $file->getSize() > $maxSize) {
            return response()->json([
                'detail' => 'File too large. Maximum allowed: 50MB. Please split the document into smaller files.',
            ], 400);
        }

        $storedPath = $file->storeAs('documents', Str::uuid().'_'.$original);

        $document = Document::query()->create([
            'business_id' => $business->id,
            'workspace_id' => $workspace->id,
            'filename' => $original,
            'file_type' => $extension,
            'storage_path' => $storedPath,
            'status' => 'processing',
        ]);

        $config = WorkspaceConfig::query()->where('workspace_id', $workspace->id)->first();
        $this->documentIngestService->ingestDocument(
            $document,
            $config,
            isset($validated['chunk_words']) ? (int) $validated['chunk_words'] : null,
            isset($validated['overlap_words']) ? (int) $validated['overlap_words'] : null
        );

        return response()->json([
            'document_id' => (string) $document->id,
            'status' => $document->status,
            'business_client_id' => $business_client_id,
            'workspace_id' => $workspace_id,
        ]);
    }

    public function reindex(Request $request, string $business_client_id, string $workspace_id, string $document_id): JsonResponse
    {
        [$business, $workspace, $accessError] = $this->resolveScopeAndAccess($request, $business_client_id, $workspace_id);
        if ($accessError !== null) {
            return $accessError;
        }

        $document = Document::query()
            ->where('id', $document_id)
            ->where('business_id', $business->id)
            ->where('workspace_id', $workspace->id)
            ->first();

        if (!$document) {
            return response()->json(['detail' => 'Document not found'], 404);
        }

        $config = WorkspaceConfig::query()->where('workspace_id', $workspace->id)->first();
        $this->documentIngestService->ingestDocument($document, $config);

        return response()->json(['status' => 'reindexed']);
    }

    public function reindexAll(Request $request, string $business_client_id, string $workspace_id): JsonResponse
    {
        [$business, $workspace, $accessError] = $this->resolveScopeAndAccess($request, $business_client_id, $workspace_id);
        if ($accessError !== null) {
            return $accessError;
        }

        $config = WorkspaceConfig::query()->where('workspace_id', $workspace->id)->first();

        $documents = Document::query()
            ->where('business_id', $business->id)
            ->where('workspace_id', $workspace->id)
            ->get();

        foreach ($documents as $document) {
            $this->documentIngestService->ingestDocument($document, $config);
        }

        return response()->json(['status' => 'reindexed_all']);
    }

    public function list(Request $request, string $business_client_id, string $workspace_id): JsonResponse
    {
        [$business, $workspace, $accessError] = $this->resolveScopeAndAccess($request, $business_client_id, $workspace_id);
        if ($accessError !== null) {
            return $accessError;
        }

        $documents = Document::query()
            ->where('business_id', $business->id)
            ->where('workspace_id', $workspace->id)
            ->get();

        $result = $documents->map(function (Document $doc): array {
            $chunkCount = DocumentChunk::query()->where('document_id', $doc->id)->count();

            return [
                'id' => $doc->id,
                'filename' => $doc->filename,
                'file_type' => $doc->file_type,
                'status' => $doc->status,
                'chunk_count' => $chunkCount,
                'indexed_at' => $doc->indexed_at ? Carbon::parse($doc->indexed_at)->toISOString() : null,
                'meta_json' => $doc->meta_json,
            ];
        })->values();

        return response()->json($result);
    }

    public function show(Request $request, string $business_client_id, string $workspace_id, string $document_id): JsonResponse
    {
        [$business, $workspace, $accessError] = $this->resolveScopeAndAccess($request, $business_client_id, $workspace_id);
        if ($accessError !== null) {
            return $accessError;
        }

        $document = Document::query()
            ->where('id', $document_id)
            ->where('business_id', $business->id)
            ->where('workspace_id', $workspace->id)
            ->first();

        if (!$document) {
            return response()->json(['detail' => 'Document not found'], 404);
        }

        $chunkCount = DocumentChunk::query()->where('document_id', $document->id)->count();

        return response()->json([
            'id' => $document->id,
            'filename' => $document->filename,
            'file_type' => $document->file_type,
            'status' => $document->status,
            'chunk_count' => $chunkCount,
            'indexed_at' => $document->indexed_at ? Carbon::parse($document->indexed_at)->toISOString() : null,
            'meta_json' => $document->meta_json,
        ]);
    }

    public function delete(Request $request, string $business_client_id, string $workspace_id, string $document_id): JsonResponse
    {
        [$business, $workspace, $accessError] = $this->resolveScopeAndAccess($request, $business_client_id, $workspace_id);
        if ($accessError !== null) {
            return $accessError;
        }

        $document = Document::query()
            ->where('id', $document_id)
            ->where('business_id', $business->id)
            ->where('workspace_id', $workspace->id)
            ->first();

        if (!$document) {
            return response()->json(['detail' => 'Document not found'], 404);
        }

        $filename = $document->filename;

        DB::transaction(function () use ($document): void {
            DocumentChunk::query()->where('document_id', $document->id)->delete();
            $document->delete();
        });

        return response()->json([
            'status' => 'deleted',
            'message' => "Document '{$filename}' and all its chunks have been deleted.",
        ]);
    }

    public function listChunks(Request $request, string $business_client_id, string $workspace_id, string $document_id): JsonResponse
    {
        [$business, $workspace, $accessError] = $this->resolveScopeAndAccess($request, $business_client_id, $workspace_id);
        if ($accessError !== null) {
            return $accessError;
        }

        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
            'offset' => ['nullable', 'integer', 'min:0'],
        ]);

        $limit = (int) ($validated['limit'] ?? 50);
        $offset = (int) ($validated['offset'] ?? 0);

        $chunks = DocumentChunk::query()
            ->where('document_id', $document_id)
            ->where('business_id', $business->id)
            ->where('workspace_id', $workspace->id)
            ->orderBy('chunk_index')
            ->offset($offset)
            ->limit($limit)
            ->get()
            ->map(fn (DocumentChunk $chunk): array => [
                'id' => $chunk->id,
                'document_id' => $chunk->document_id,
                'chunk_index' => $chunk->chunk_index,
                'page_number' => $chunk->page_number,
                'content' => $chunk->content,
            ])
            ->values();

        return response()->json($chunks);
    }

    public function cancel(Request $request, string $business_client_id, string $workspace_id, string $document_id): JsonResponse
    {
        [$business, $workspace, $accessError] = $this->resolveScopeAndAccess($request, $business_client_id, $workspace_id);
        if ($accessError !== null) {
            return $accessError;
        }

        $document = Document::query()
            ->where('id', $document_id)
            ->where('business_id', $business->id)
            ->where('workspace_id', $workspace->id)
            ->first();

        if (!$document) {
            return response()->json(['detail' => 'Document not found'], 404);
        }

        if (in_array($document->status, ['processing', 'cancelling'], true)) {
            $document->status = 'cancelled';
            $document->meta_json = 'Processing cancelled by user';
            $document->save();

            return response()->json([
                'status' => 'cancellation_requested',
                'message' => 'Document cancelled. Background processing will stop at the next checkpoint.',
            ]);
        }

        if ($document->status === 'cancelled') {
            return response()->json([
                'status' => 'already_cancelled',
                'message' => 'Document is already cancelled.',
            ]);
        }

        return response()->json([
            'status' => 'cannot_cancel',
            'message' => "Cannot cancel document with status: {$document->status}. Only documents in 'processing' status can be cancelled.",
        ]);
    }

    public function reset(Request $request, string $business_client_id, string $workspace_id, string $document_id): JsonResponse
    {
        [$business, $workspace, $accessError] = $this->resolveScopeAndAccess($request, $business_client_id, $workspace_id);
        if ($accessError !== null) {
            return $accessError;
        }

        $document = Document::query()
            ->where('id', $document_id)
            ->where('business_id', $business->id)
            ->where('workspace_id', $workspace->id)
            ->first();

        if (!$document) {
            return response()->json(['detail' => 'Document not found'], 404);
        }

        if ($document->status === 'processing') {
            $document->status = 'processing';
            $document->meta_json = 'Reset for retry';
            $document->save();

            return response()->json([
                'status' => 'reset',
                'message' => 'Document reset. You can retry processing.',
            ]);
        }

        return response()->json([
            'status' => 'unchanged',
            'message' => "Document status is {$document->status}, no reset needed.",
        ]);
    }

    public function resetStuck(Request $request, string $business_client_id, string $workspace_id): JsonResponse
    {
        [$business, $workspace, $accessError] = $this->resolveScopeAndAccess($request, $business_client_id, $workspace_id);
        if ($accessError !== null) {
            return $accessError;
        }

        $cutoff = now()->subHour();

        $documents = Document::query()
            ->where('business_id', $business->id)
            ->where('workspace_id', $workspace->id)
            ->where('status', 'processing')
            ->where(function ($query) use ($cutoff): void {
                $query->whereNull('indexed_at')->orWhere('indexed_at', '<', $cutoff);
            })
            ->get();

        $resetCount = 0;
        foreach ($documents as $document) {
            $chunkCount = DocumentChunk::query()->where('document_id', $document->id)->count();
            if ($chunkCount === 0) {
                $document->status = 'failed';
                $document->meta_json = 'Reset: Stuck in processing (likely crashed). Can be retried.';
                $document->save();
                $resetCount++;
            }
        }

        return response()->json([
            'status' => 'completed',
            'reset_count' => $resetCount,
            'message' => "Reset {$resetCount} stuck document(s). They can now be retried.",
        ]);
    }

    private function resolveScopeAndAccess(Request $request, string $business_client_id, string $workspace_id): array
    {
        /** @var User $admin */
        $admin = $request->attributes->get('admin');

        $business = Business::query()->where('business_client_id', $business_client_id)->first();
        if (!$business) {
            return [null, null, response()->json(['detail' => 'Business not found'], 404)];
        }

        if (!$this->ensureAccess($admin, $business)) {
            return [null, null, response()->json(['detail' => 'Not allowed'], 403)];
        }

        $workspace = Workspace::query()
            ->where('business_client_id', $business->business_client_id)
            ->where('workspace_id', $workspace_id)
            ->first();

        if (!$workspace) {
            return [null, null, response()->json(['detail' => 'Workspace not found'], 404)];
        }

        return [$business, $workspace, null];
    }

    private function ensureAccess(User $admin, Business $business): bool
    {
        if ($admin->role === 'super_admin') {
            return true;
        }

        if ($admin->role === 'admin' && $business->admin_id === $admin->id) {
            return true;
        }

        return false;
    }

}
