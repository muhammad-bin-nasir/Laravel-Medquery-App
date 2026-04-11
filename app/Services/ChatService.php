<?php

namespace App\Services;

use App\Models\ChatHeader;
use App\Models\ChatRequest;
use App\Models\ChatResponse;
use App\Models\User;
use App\Models\WorkspaceConfig;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class ChatService
{
    private const NO_RAG_ANSWER = "I'm sorry, I couldn't find an answer to your question in the available knowledge base. Medical and biological questions can only be answered using the uploaded documents, and no relevant information was found for your query.";

    private const MED_BIO_KEYWORDS = [
        'disease', 'diagnosis', 'treatment', 'medication', 'symptom', 'patient', 'doctor', 'surgery', 'medical',
        'cancer', 'infection', 'biology', 'biological', 'cell', 'dna', 'rna', 'gene', 'genetic', 'protein',
        'bacteria', 'virus', 'anatomy', 'physiology', 'immune', 'antibody', 'pathogen',
    ];

    public function __construct(private readonly RagRetrievalService $ragRetrievalService)
    {
    }

    public function generateResponse(
        User $admin,
        string $businessId,
        string $workspaceId,
        WorkspaceConfig $config,
        array $payload
    ): array {
        $query = (string) ($payload['query'] ?? '');
        $chatId = $payload['chat_id'] ?? null;
        $chatTitle = isset($payload['chat_title']) ? trim((string) $payload['chat_title']) : null;
        $promptFromPayload = isset($payload['prompt_engineering']) ? trim((string) $payload['prompt_engineering']) : '';
        $configPrompt = trim((string) ($config->prompt_engineering ?? ''));
        $promptEngineering = $configPrompt !== ''
            ? $configPrompt
            : ($promptFromPayload !== '' ? $promptFromPayload : 'You are a medical assistant. Provide concise answers based on the context.');

        $sources = [];
        $usage = [];
        $model = (string) ($config->chat_model_default ?: 'gpt-4.1-mini');

        $retrieved = $this->ragRetrievalService->retrieveChunks(
            businessId: $businessId,
            workspaceId: $workspaceId,
            query: $query,
            topK: max(7, (int) ($config->top_k ?? 7)),
            threshold: (float) ($config->similarity_threshold ?? 0.0),
        );

        $sources = collect($retrieved)->map(function (array $item): array {
            $chunk = $item['chunk'];

            return [
                'document_id' => (string) $chunk->document_id,
                'filename' => (string) $item['filename'],
                'page' => $chunk->page_number,
                'chunk_id' => (string) $chunk->id,
                'snippet' => Str::limit((string) $chunk->content, 280),
            ];
        })->values()->all();

        $context = collect($retrieved)->map(function (array $item): string {
            $chunk = $item['chunk'];
            $page = $chunk->page_number ? ' (page '.$chunk->page_number.')' : '';
            return '['.$item['filename'].$page.'] '.(string) $chunk->content;
        })->implode("\n\n");

        $effectivePromptEngineering = $promptEngineering;
        if (trim($context) !== '') {
            $effectivePromptEngineering = trim(
                $promptEngineering
                ."\n\nanswer in the light of the text/chunk provided"
                ."\nIf the question is directly answered in the provided context, answer strictly from that context."
                ."\nIf and only if it is not directly answered in the context, you may generate your own answer, but keep it consistent with the provided context."
            );
        }

        if ($this->isMedicalOrBiological($query) && $context === '') {
            $answer = self::NO_RAG_ANSWER;
            $model = 'N/A';
        } else {
            $override = $payload['chat_config_override'] ?? [];
            $resolvedModel = isset($override['model']) && is_string($override['model']) && trim($override['model']) !== ''
                ? trim($override['model'])
                : $model;
            $temperature = isset($override['temperature']) ? (float) $override['temperature'] : (float) $config->chat_temperature_default;
            $maxTokens = isset($override['max_tokens']) ? (int) $override['max_tokens'] : (int) $config->chat_max_tokens_default;

            $openAi = $this->callOpenAi(
                promptEngineering: $effectivePromptEngineering,
                query: $query,
                context: $context,
                model: $resolvedModel,
                temperature: $temperature,
                maxTokens: $maxTokens,
            );

            $answer = $openAi['answer'];
            $usage = $openAi['usage'];
            $model = $resolvedModel;
        }

        DB::transaction(function () use ($admin, $businessId, $workspaceId, $query, $chatId, $answer, $sources, $usage, $model, $chatTitle): void {
            $chatRequest = ChatRequest::query()->create([
                'business_id' => $businessId,
                'workspace_id' => $workspaceId,
                'user_id' => $admin->email,
                'user_uuid' => $admin->id,
                'chat_header' => $chatId,
                'query_text' => $query,
                'retrieved_chunk_ids' => json_encode([], JSON_UNESCAPED_UNICODE),
            ]);

            ChatResponse::query()->create([
                'request_id' => $chatRequest->id,
                'chat_header' => $chatId,
                'answer_text' => $answer,
                'sources_json' => json_encode($sources, JSON_UNESCAPED_UNICODE),
                'model_used' => $model,
                'tokens_json' => json_encode($usage, JSON_UNESCAPED_UNICODE),
            ]);

            if (!$chatId) {
                return;
            }

            $fallbackTitle = Str::limit(trim(strtok($query, "\n") ?: 'New chat'), 80, '');
            $finalTitle = $chatTitle !== null && $chatTitle !== '' ? $chatTitle : ($fallbackTitle !== '' ? $fallbackTitle : 'New chat');

            $header = ChatHeader::query()
                ->where('owner_user_uuid', $admin->id)
                ->where('business_id', $businessId)
                ->where('workspace_id', $workspaceId)
                ->where('chat_id', $chatId)
                ->first();

            if ($header) {
                $header->title = $finalTitle;
                $header->save();
            } else {
                ChatHeader::query()->create([
                    'owner_user_id' => $admin->email,
                    'owner_user_uuid' => $admin->id,
                    'business_id' => $businessId,
                    'workspace_id' => $workspaceId,
                    'chat_id' => $chatId,
                    'title' => $finalTitle,
                ]);
            }
        });

        return [
            'answer' => $answer,
            'sources' => $sources,
            'usage' => [
                'model' => $usage['model'] ?? $model,
                'prompt_tokens' => (int) ($usage['prompt_tokens'] ?? 0),
                'completion_tokens' => (int) ($usage['completion_tokens'] ?? 0),
                'total_tokens' => (int) ($usage['total_tokens'] ?? 0),
            ],
        ];
    }

    private function callOpenAi(
        string $promptEngineering,
        string $query,
        string $context,
        string $model,
        float $temperature,
        int $maxTokens
    ): array {
        $apiKey = trim((string) env('OPENAI_API_KEY', ''));
        if ($apiKey === '') {
            throw new RuntimeException('OPENAI_API_KEY not configured.');
        }

        $baseUrl = rtrim((string) config('services.openai.base_url', 'https://api.openai.com/v1'), '/');
        $userPrompt = $query;
        if (trim($context) !== '') {
            $userPrompt = "Use the context below to answer. If context is insufficient, say so briefly.\n\nContext:\n"
                .$context
                ."\n\nQuestion:\n"
                .$query;
        }

        $response = Http::baseUrl($baseUrl)
            ->withToken($apiKey)
            ->timeout(60)
            ->post('/chat/completions', [
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => $promptEngineering],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                'temperature' => $temperature,
                'max_tokens' => $maxTokens,
            ]);

        if (!$response->successful()) {
            throw new RuntimeException('OpenAI call failed with status '.$response->status());
        }

        $data = $response->json();
        $answer = (string) data_get($data, 'choices.0.message.content', '');

        return [
            'answer' => $answer,
            'usage' => [
                'model' => $model,
                'prompt_tokens' => (int) data_get($data, 'usage.prompt_tokens', 0),
                'completion_tokens' => (int) data_get($data, 'usage.completion_tokens', 0),
                'total_tokens' => (int) data_get($data, 'usage.total_tokens', 0),
            ],
        ];
    }

    private function isMedicalOrBiological(string $query): bool
    {
        $normalized = strtolower($query);
        foreach (self::MED_BIO_KEYWORDS as $keyword) {
            if (str_contains($normalized, $keyword)) {
                return true;
            }
        }

        return false;
    }
}