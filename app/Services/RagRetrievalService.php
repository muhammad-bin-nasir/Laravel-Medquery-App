<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentChunk;

class RagRetrievalService
{
    private const VECTOR_DIMENSION = 384;

    public function buildEmbedding(string $text): array
    {
        $vector = array_fill(0, self::VECTOR_DIMENSION, 0.0);
        $tokens = $this->tokenize($text);

        if (empty($tokens)) {
            return $vector;
        }

        foreach ($tokens as $token) {
            $hash = abs((int) crc32($token));
            $index = $hash % self::VECTOR_DIMENSION;
            $vector[$index] += 1.0;
        }

        return $this->normalize($vector);
    }

    public function serializeEmbedding(array $vector): string
    {
        $normalized = $this->normalizeDimension($vector, self::VECTOR_DIMENSION);
        $driver = config('database.default');

        if ($driver === 'pgsql') {
            return '['.implode(',', array_map(static fn (float $v): string => (string) $v, $normalized)).']';
        }

        return json_encode($normalized, JSON_UNESCAPED_UNICODE);
    }

    public function deserializeEmbedding(mixed $stored, ?string $fallbackText = null): array
    {
        if (is_array($stored)) {
            $vector = $this->normalizeDimension(array_map('floatval', $stored), self::VECTOR_DIMENSION);
            if ($this->isZeroVector($vector) && $fallbackText !== null) {
                return $this->buildEmbedding($fallbackText);
            }

            return $vector;
        }

        if (is_string($stored) && $stored !== '') {
            $value = trim($stored);

            if (str_starts_with($value, '[') && str_ends_with($value, ']')) {
                $inner = substr($value, 1, -1);
                if ($inner !== '') {
                    $parts = explode(',', $inner);
                    $floats = array_map(static fn (string $p): float => (float) trim($p), $parts);
                    return $this->normalizeDimension($floats, self::VECTOR_DIMENSION);
                }
            }

            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $floats = array_map(static fn ($v): float => (float) $v, $decoded);
                $vector = $this->normalizeDimension($floats, self::VECTOR_DIMENSION);
                if ($this->isZeroVector($vector) && $fallbackText !== null) {
                    return $this->buildEmbedding($fallbackText);
                }

                return $vector;
            }
        }

        if ($fallbackText !== null) {
            return $this->buildEmbedding($fallbackText);
        }

        return array_fill(0, self::VECTOR_DIMENSION, 0.0);
    }

    public function cosineSimilarity(array $a, array $b): float
    {
        $left = $this->normalizeDimension($a, self::VECTOR_DIMENSION);
        $right = $this->normalizeDimension($b, self::VECTOR_DIMENSION);

        $dot = 0.0;
        $leftNorm = 0.0;
        $rightNorm = 0.0;

        for ($i = 0; $i < self::VECTOR_DIMENSION; $i++) {
            $dot += $left[$i] * $right[$i];
            $leftNorm += $left[$i] * $left[$i];
            $rightNorm += $right[$i] * $right[$i];
        }

        if ($leftNorm <= 0.0 || $rightNorm <= 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($leftNorm) * sqrt($rightNorm));
    }

    public function retrieveChunks(string $businessId, string $workspaceId, string $query, int $topK, float $threshold): array
    {
        $queryVector = $this->buildEmbedding($query);
        $queryTerms = $this->tokenize($query);

        $chunks = DocumentChunk::query()
            ->where('business_id', $businessId)
            ->where('workspace_id', $workspaceId)
            ->get();

        if ($chunks->isEmpty()) {
            return [];
        }

        $docMap = Document::query()
            ->whereIn('id', $chunks->pluck('document_id')->unique()->values())
            ->get()
            ->keyBy('id');

        $scored = [];
        foreach ($chunks as $chunk) {
            $chunkVector = $this->deserializeEmbedding($chunk->embedding, (string) $chunk->content);
            $score = $this->cosineSimilarity($queryVector, $chunkVector);

            $document = $docMap->get($chunk->document_id);

            $scored[] = [
                'chunk' => $chunk,
                'filename' => $document?->filename ?? 'unknown',
                'score' => round($score, 6),
            ];
        }

        usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        $filtered = array_values(array_filter(
            $scored,
            static fn (array $item): bool => $item['score'] >= $threshold
        ));

        if (!empty($filtered)) {
            return array_slice($filtered, 0, max(1, $topK));
        }

        // Fallback for strict thresholds: still return top scored chunks when scores are positive.
        $positive = array_values(array_filter(
            $scored,
            static fn (array $item): bool => $item['score'] > 0
        ));

        if (!empty($positive)) {
            return array_slice($positive, 0, max(1, $topK));
        }

        // Final fallback for short/sparse queries: lexical term matching over chunk text.
        if (!empty($queryTerms)) {
            $lexical = [];
            foreach ($chunks as $chunk) {
                $content = strtolower((string) $chunk->content);
                $hitCount = 0;

                foreach ($queryTerms as $term) {
                    if ($term === '') {
                        continue;
                    }

                    if (str_contains($content, $term)) {
                        $hitCount++;
                        continue;
                    }

                    // Prefix fallback helps cases like cluster vs clustering.
                    $prefix = strlen($term) >= 5 ? substr($term, 0, 5) : $term;
                    if ($prefix !== '' && str_contains($content, $prefix)) {
                        $hitCount++;
                    }
                }

                if ($hitCount <= 0) {
                    continue;
                }

                $document = $docMap->get($chunk->document_id);
                $lexical[] = [
                    'chunk' => $chunk,
                    'filename' => $document?->filename ?? 'unknown',
                    'score' => round($hitCount / count($queryTerms), 6),
                ];
            }

            usort($lexical, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

            if (!empty($lexical)) {
                return array_slice($lexical, 0, max(1, $topK));
            }
        }

        return [];
    }

    private function tokenize(string $text): array
    {
        $parts = preg_split('/[^a-z0-9]+/i', strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return array_values(array_filter($parts, static fn (string $token): bool => strlen($token) >= 2));
    }

    private function normalize(array $vector): array
    {
        $sumSquares = 0.0;
        foreach ($vector as $value) {
            $sumSquares += $value * $value;
        }

        if ($sumSquares <= 0.0) {
            return $vector;
        }

        $norm = sqrt($sumSquares);
        foreach ($vector as $idx => $value) {
            $vector[$idx] = $value / $norm;
        }

        return $vector;
    }

    private function normalizeDimension(array $vector, int $dimension): array
    {
        $normalized = array_fill(0, $dimension, 0.0);
        $limit = min($dimension, count($vector));

        for ($i = 0; $i < $limit; $i++) {
            $normalized[$i] = (float) $vector[$i];
        }

        return $normalized;
    }

    private function isZeroVector(array $vector): bool
    {
        foreach ($vector as $value) {
            if (abs((float) $value) > 1e-12) {
                return false;
            }
        }

        return true;
    }
}
