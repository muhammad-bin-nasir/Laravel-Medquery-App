<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\WorkspaceConfig;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser;

class DocumentIngestService
{
    public function __construct(private readonly RagRetrievalService $ragRetrievalService)
    {
    }

    public function ingestDocument(Document $document, ?WorkspaceConfig $config = null, ?int $chunkWords = null, ?int $overlapWords = null): void
    {
        $effectiveChunkWords = max(1, (int) ($chunkWords ?? $config?->chunk_words ?? 300));
        $effectiveOverlapWords = max(0, (int) ($overlapWords ?? $config?->overlap_words ?? 50));

        $effectiveOverlapWords = min($effectiveOverlapWords, $effectiveChunkWords - 1);

        if (!Storage::exists($document->storage_path)) {
            $this->markFailed($document, 'Source file not found for ingestion');
            return;
        }

        try {
            if ($document->file_type === 'txt') {
                $text = trim((string) Storage::get($document->storage_path));
                $this->persistChunks($document, $this->chunkText($text, $effectiveChunkWords, $effectiveOverlapWords));
                return;
            }

            if ($document->file_type === 'pdf') {
                $chunks = $this->extractPdfChunks($document, $effectiveChunkWords, $effectiveOverlapWords);
                $this->persistChunks($document, $chunks);
                return;
            }

            $this->markFailed($document, 'Unsupported file type for ingestion');
        } catch (\Throwable $e) {
            $this->markFailed($document, 'Ingestion failed: '.$e->getMessage());
        }
    }

    private function extractPdfChunks(Document $document, int $chunkWords, int $overlapWords): array
    {
        $absolutePath = Storage::path($document->storage_path);
        $parser = new Parser();
        $pdf = $parser->parseFile($absolutePath);

        $allChunks = [];
        $chunkIndex = 0;
        $pages = $pdf->getPages();

        foreach ($pages as $pageNumber => $page) {
            $pageText = trim((string) $page->getText());
            $pageChunks = $this->chunkText($pageText, $chunkWords, $overlapWords);

            foreach ($pageChunks as $chunk) {
                $allChunks[] = [
                    'chunk_index' => $chunkIndex,
                    'page_number' => $pageNumber + 1,
                    'content' => $chunk['content'],
                ];
                $chunkIndex++;
            }
        }

        return $allChunks;
    }

    private function chunkText(string $text, int $chunkWords, int $overlapWords): array
    {
        if ($text === '') {
            return [];
        }

        $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (empty($words)) {
            return [];
        }

        $step = max(1, $chunkWords - $overlapWords);
        $chunks = [];
        $chunkIndex = 0;

        for ($i = 0; $i < count($words); $i += $step) {
            $slice = array_slice($words, $i, $chunkWords);
            if (empty($slice)) {
                continue;
            }

            $chunks[] = [
                'chunk_index' => $chunkIndex,
                'page_number' => null,
                'content' => implode(' ', $slice),
            ];

            $chunkIndex++;
            if ($i + $chunkWords >= count($words)) {
                break;
            }
        }

        return $chunks;
    }

    private function persistChunks(Document $document, array $chunks): void
    {
        DB::transaction(function () use ($document, $chunks): void {
            DocumentChunk::query()->where('document_id', $document->id)->delete();

            foreach ($chunks as $chunk) {
                DocumentChunk::query()->create([
                    'business_id' => $document->business_id,
                    'workspace_id' => $document->workspace_id,
                    'document_id' => $document->id,
                    'chunk_index' => $chunk['chunk_index'],
                    'page_number' => $chunk['page_number'],
                    'content' => $chunk['content'],
                    'embedding' => $this->ragRetrievalService->serializeEmbedding(
                        $this->ragRetrievalService->buildEmbedding((string) $chunk['content'])
                    ),
                ]);
            }

            $document->status = 'indexed';
            $document->indexed_at = now();
            $document->meta_json = empty($chunks)
                ? 'Indexed successfully (no extractable text found)'
                : 'Indexed successfully';
            $document->save();
        });
    }

    private function markFailed(Document $document, string $reason): void
    {
        $document->status = 'failed';
        $document->meta_json = $reason;
        $document->save();
    }
}
