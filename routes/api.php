<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AdminAuthController;
use App\Http\Controllers\Api\AdminBusinessController;
use App\Http\Controllers\Api\AdminDocumentController;
use App\Http\Controllers\Api\AdminWorkspaceController;
use App\Http\Controllers\Api\AdminWorkspaceConfigController;
use App\Http\Controllers\Api\AdminSystemConfigController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\RagController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin/auth')->group(function (): void {
    Route::post('/login', [AdminAuthController::class, 'login']);
    Route::post('/create-admin', [AdminAuthController::class, 'createAdmin']);
    Route::middleware(['admin.auth'])->group(function (): void {
        Route::post('/create-user', [AdminAuthController::class, 'createUser']);
    });
});

Route::post('aadmin/auth/login', [AdminAuthController::class, 'login']);

Route::prefix('admin/system-config')->middleware(['admin.auth'])->group(function (): void {
    Route::get('/openai-api-key', [AdminSystemConfigController::class, 'getOpenAiApiKeyStatus']);
    Route::put('/openai-api-key', [AdminSystemConfigController::class, 'updateOpenAiApiKey']);
});

Route::prefix('admin/businesses')->middleware(['admin.auth'])->group(function (): void {
    Route::post('', [AdminBusinessController::class, 'create']);
    Route::get('', [AdminBusinessController::class, 'index']);
    Route::get('/{business_client_id}', [AdminBusinessController::class, 'show']);

    Route::prefix('/{business_client_id}/workspaces')->group(function (): void {
        Route::post('', [AdminWorkspaceController::class, 'create']);
        Route::get('', [AdminWorkspaceController::class, 'index']);
        Route::get('/{workspace_id}', [AdminWorkspaceController::class, 'show']);
        Route::delete('/{workspace_id}', [AdminWorkspaceController::class, 'delete']);
        Route::get('/{workspace_id}/config', [AdminWorkspaceConfigController::class, 'show']);
        Route::put('/{workspace_id}/config', [AdminWorkspaceConfigController::class, 'update']);

        Route::post('/{workspace_id}/documents/upload', [AdminDocumentController::class, 'upload']);
        Route::post('/{workspace_id}/documents/{document_id}/reindex', [AdminDocumentController::class, 'reindex']);
        Route::post('/{workspace_id}/reindex-all', [AdminDocumentController::class, 'reindexAll']);
        Route::get('/{workspace_id}/documents', [AdminDocumentController::class, 'list']);
        Route::get('/{workspace_id}/documents/{document_id}', [AdminDocumentController::class, 'show']);
        Route::delete('/{workspace_id}/documents/{document_id}', [AdminDocumentController::class, 'delete']);
        Route::get('/{workspace_id}/documents/{document_id}/chunks', [AdminDocumentController::class, 'listChunks']);
        Route::post('/{workspace_id}/documents/{document_id}/cancel', [AdminDocumentController::class, 'cancel']);
        Route::post('/{workspace_id}/documents/{document_id}/reset', [AdminDocumentController::class, 'reset']);
        Route::post('/{workspace_id}/documents/reset-stuck', [AdminDocumentController::class, 'resetStuck']);
    });
});

Route::prefix('auth')->group(function (): void {
    Route::post('/login', [AuthController::class, 'login']);
    Route::middleware(['admin.auth'])->group(function (): void {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/refresh', [AuthController::class, 'refresh']);
    });
});

Route::prefix('chat')->group(function (): void {
    Route::get('/test-stream', [ChatController::class, 'testStream']);

    Route::middleware(['admin.auth', 'throttle:30,1'])->group(function (): void {
        Route::delete('/headers/{chat_id}', [ChatController::class, 'deleteChatHeader']);
        Route::get('/headers/me', [ChatController::class, 'getMyChatHeaders']);
        Route::get('/history/{user_id}', [ChatController::class, 'getUserChatHistory']);
        Route::post('/generate', [ChatController::class, 'generateChat']);
        Route::post('/stream', [ChatController::class, 'streamChat']);
    });
});

Route::prefix('rag')->middleware(['admin.auth'])->group(function (): void {
    Route::post('/retrieve', [RagController::class, 'retrieve']);
});
