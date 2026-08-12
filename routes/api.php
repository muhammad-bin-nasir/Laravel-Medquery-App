<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AdminAuthController;
use App\Http\Controllers\Api\AiController;
use App\Http\Controllers\Api\AdminBusinessController;
use App\Http\Controllers\Api\AdminDocumentController;
use App\Http\Controllers\Api\AdminWorkspaceController;
use App\Http\Controllers\Api\AdminWorkspaceConfigController;
use App\Http\Controllers\Api\AdminPlanController;
use App\Http\Controllers\Api\AdminSubscriptionController;
use App\Http\Controllers\Api\AdminSystemConfigController;
use App\Http\Controllers\Api\AppSettingsController;
use App\Http\Controllers\Api\AdminSiteLogController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\HelpTicketController;
use App\Http\Controllers\Api\HelpNotificationController;
use App\Http\Controllers\Api\AdminUserController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\AuthChallengeController;
use App\Http\Controllers\Api\RagController;
use Illuminate\Support\Facades\Route;

Route::get('/auth/challenge', [AuthChallengeController::class, 'show'])
    ->middleware('throttle:30,1');

Route::prefix('admin/auth')->group(function (): void {
    Route::post('/login', [AdminAuthController::class, 'login'])
        ->middleware('throttle:auth-login');
    Route::post('/create-admin', [AdminAuthController::class, 'createAdmin'])
        ->middleware('throttle:auth-login');
    Route::post('/user-signup', [AdminAuthController::class, 'userSignup'])
        ->middleware('throttle:auth-signup');
    Route::middleware(['admin.auth'])->group(function (): void {
        Route::post('/create-user', [AdminAuthController::class, 'createUser']);
        Route::delete('/users/{user_id}', [AdminAuthController::class, 'deleteUser']);
    });
});

Route::prefix('admin/users')->middleware(['admin.auth'])->group(function (): void {
    Route::get('', [AdminUserController::class, 'index']);
    Route::post('', [AdminUserController::class, 'store']);
    Route::post('/sub-admins', [AdminUserController::class, 'storeSubAdmin']);
    Route::get('/{userId}', [AdminUserController::class, 'show']);
    Route::put('/{userId}', [AdminUserController::class, 'update']);
    Route::patch('/{userId}/status', [AdminUserController::class, 'setActive']);
    Route::delete('/{userId}', [AdminUserController::class, 'destroy']);
});

// Public endpoint: returns whether any users exist (used by frontend to decide signup vs login)
Route::get('/setup/status', [AdminAuthController::class, 'setupStatus']);

Route::post('aadmin/auth/login', [AdminAuthController::class, 'login'])
    ->middleware('throttle:auth-login');

// Public ingest for frontend / Python error reporting (throttled).
Route::post('/site-logs', [AdminSiteLogController::class, 'ingest'])
    ->middleware('throttle:60,1');

Route::prefix('admin/site-logs')->middleware(['admin.auth'])->group(function (): void {
    Route::get('', [AdminSiteLogController::class, 'index']);
    Route::delete('', [AdminSiteLogController::class, 'clear']);
    Route::get('/{logId}', [AdminSiteLogController::class, 'show']);
    Route::post('/{logId}/resolve', [AdminSiteLogController::class, 'resolve']);
    Route::delete('/{logId}', [AdminSiteLogController::class, 'destroy']);
});

Route::get('/app-settings', [AppSettingsController::class, 'show'])
    ->middleware('throttle:60,1');

Route::prefix('admin/system-config')->middleware(['admin.auth'])->group(function (): void {
    Route::get('/app-settings', [AdminSystemConfigController::class, 'getAppSettings']);
    Route::put('/app-settings', [AdminSystemConfigController::class, 'updateAppSettings']);
    Route::get('/openai-api-key', [AdminSystemConfigController::class, 'getOpenAiApiKeyStatus']);
    Route::get('/project-api', [AdminSystemConfigController::class, 'getProjectApiStatus']);
    Route::get('/runtime', [AdminSystemConfigController::class, 'getRuntimeStatus']);
    Route::get('/database', [AdminSystemConfigController::class, 'getDatabaseStatus']);
    Route::get('/auth-mode', [AdminSystemConfigController::class, 'getAuthModeStatus']);
    Route::get('/storage', [AdminSystemConfigController::class, 'getStorageStatus']);
    Route::put('/openai-api-key', [AdminSystemConfigController::class, 'updateOpenAiApiKey']);
    Route::delete('/openai-api-key', [AdminSystemConfigController::class, 'clearOpenAiApiKeyOverride']);
    Route::get('/site-user-defaults', [AdminSystemConfigController::class, 'getSiteUserDefaults']);
    Route::put('/site-user-defaults', [AdminSystemConfigController::class, 'updateSiteUserDefaults']);
    Route::get('/stripe', [AdminSystemConfigController::class, 'getStripeSettings']);
    Route::put('/stripe', [AdminSystemConfigController::class, 'updateStripeSettings']);
    Route::get('/turnstile', [AdminSystemConfigController::class, 'getTurnstileSettings']);
    Route::put('/turnstile', [AdminSystemConfigController::class, 'updateTurnstileSettings']);
    Route::get('/mail', [AdminSystemConfigController::class, 'getMailSettings']);
    Route::put('/mail', [AdminSystemConfigController::class, 'updateMailSettings']);
    Route::post('/mail/test', [AdminSystemConfigController::class, 'sendTestMail']);
});

Route::prefix('admin/businesses')->middleware(['admin.auth'])->group(function (): void {
    Route::post('', [AdminBusinessController::class, 'create']);
    Route::get('', [AdminBusinessController::class, 'index']);
    Route::get('/{business_client_id}', [AdminBusinessController::class, 'show']);
    Route::put('/{business_client_id}', [AdminBusinessController::class, 'update']);
    Route::delete('/{business_client_id}', [AdminBusinessController::class, 'delete']);

    Route::prefix('/{business_client_id}/workspaces')->group(function (): void {
        Route::post('', [AdminWorkspaceController::class, 'create']);
        Route::get('', [AdminWorkspaceController::class, 'index']);
        Route::get('/{workspace_id}', [AdminWorkspaceController::class, 'show']);
        Route::put('/{workspace_id}', [AdminWorkspaceController::class, 'update']);
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
    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:auth-login');
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])
        ->middleware('throttle:auth-signup');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])
        ->middleware('throttle:auth-signup');
    Route::post('/refresh', [AuthController::class, 'refresh'])->middleware(['token.refresh']);
    Route::middleware(['admin.auth'])->group(function (): void {
        Route::get('/me', [AuthController::class, 'me']);
        Route::patch('/me', [AuthController::class, 'updateProfile']);
        Route::post('/change-password', [AuthController::class, 'changePassword']);
    });
});

Route::prefix('chat')->group(function (): void {
    Route::get('/test-stream', [ChatController::class, 'testStream']);

    Route::middleware(['admin.auth', 'throttle:30,1'])->group(function (): void {
        Route::post('/headers', [ChatController::class, 'createChatHeader']);
        Route::patch('/headers/{chat_id}', [ChatController::class, 'renameChatHeader']);
        Route::delete('/headers/{chat_id}', [ChatController::class, 'deleteChatHeader']);
        Route::get('/headers/me', [ChatController::class, 'getMyChatHeaders']);
        Route::get('/threads/{chat_id}', [ChatController::class, 'getChatThread']);
        Route::get('/history/{user_id}', [ChatController::class, 'getUserChatHistory']);
        Route::post('/generate', [ChatController::class, 'generateChat']);
        Route::post('/stream', [ChatController::class, 'streamChat']);
    });
});

Route::prefix('rag')->middleware(['admin.auth'])->group(function (): void {
    Route::post('/retrieve', [RagController::class, 'retrieve']);
});

Route::prefix('ai')->middleware(['ai.error', 'admin.auth', 'app.chat', 'subscription.active', 'tenant.context', 'throttle:30,1'])->group(function (): void {
    Route::post('/chat', [AiController::class, 'chat']);
    Route::post('/chat/voice', [AiController::class, 'voice']);
    Route::post('/chat/stream', [AiController::class, 'stream']);
    Route::post('/retrieve', [AiController::class, 'retrieve']);
});

Route::get('/plans', [AdminPlanController::class, 'publicIndex']);
Route::get('/payments/publishable-key', [PaymentController::class, 'publishableKey']);

Route::prefix('payments')->middleware(['admin.auth'])->group(function (): void {
    Route::post('/create-intent', [PaymentController::class, 'createIntent']);
    Route::post('/confirm-plan', [PaymentController::class, 'confirmPlan']);
    Route::get('/my-subscription', [PaymentController::class, 'mySubscription']);
    Route::post('/cancel-subscription', [PaymentController::class, 'cancelSubscription']);
    Route::get('/payment-method', [PaymentController::class, 'paymentMethod']);
    Route::post('/setup-intent', [PaymentController::class, 'createSetupIntent']);
    Route::post('/confirm-payment-method', [PaymentController::class, 'confirmPaymentMethod']);
});

Route::prefix('admin/plans')->middleware(['admin.auth'])->group(function (): void {
    Route::get('', [AdminPlanController::class, 'index']);
    Route::post('', [AdminPlanController::class, 'store']);
    Route::post('/preview-price', [AdminPlanController::class, 'previewPrice']);
    Route::put('/{planId}', [AdminPlanController::class, 'update']);
    Route::delete('/{planId}', [AdminPlanController::class, 'destroy']);
});

Route::prefix('admin/subscriptions')->middleware(['admin.auth'])->group(function (): void {
    Route::get('', [AdminSubscriptionController::class, 'index']);
    Route::post('', [AdminSubscriptionController::class, 'store']);
    Route::get('/{subscriptionId}', [AdminSubscriptionController::class, 'show']);
    Route::put('/{subscriptionId}', [AdminSubscriptionController::class, 'update']);
    Route::delete('/{subscriptionId}', [AdminSubscriptionController::class, 'destroy']);
    Route::post('/{subscriptionId}/activate', [AdminSubscriptionController::class, 'activate']);
    Route::post('/{subscriptionId}/reactivate', [AdminSubscriptionController::class, 'reactivate']);
    Route::post('/{subscriptionId}/cancel', [AdminSubscriptionController::class, 'cancel']);
});

Route::prefix('help')->middleware(['admin.auth'])->group(function (): void {
    Route::post('/tickets', [HelpTicketController::class, 'store']);
    Route::get('/tickets/me', [HelpTicketController::class, 'myTickets']);
    Route::post('/tickets/{ticketId}/reply', [HelpTicketController::class, 'userReply']);
    Route::post('/tickets/{ticketId}/close', [HelpTicketController::class, 'close']);
    Route::post('/tickets/{ticketId}/reopen', [HelpTicketController::class, 'reopen']);
    Route::get('/tickets/{ticketId}/attachment', [HelpTicketController::class, 'downloadAttachment']);
    // Admin inbox — role checked inside controller methods.
    Route::get('/admin/tickets', [HelpTicketController::class, 'adminIndex']);
    Route::get('/admin/tickets/{ticketId}', [HelpTicketController::class, 'adminShow']);
    Route::post('/admin/tickets/{ticketId}/reply', [HelpTicketController::class, 'adminReply']);
    Route::post('/admin/tickets/{ticketId}/close', [HelpTicketController::class, 'close']);
    Route::post('/admin/tickets/{ticketId}/reopen', [HelpTicketController::class, 'reopen']);
    // In-app notifications for both users and admins.
    Route::get('/notifications', [HelpNotificationController::class, 'index']);
    Route::get('/notifications/summary', [HelpNotificationController::class, 'summary']);
    Route::post('/notifications/read-all', [HelpNotificationController::class, 'markAllRead']);
    Route::post('/notifications/{notificationId}/read', [HelpNotificationController::class, 'markRead']);
});
