<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class AiChatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'business_client_id' => ['required', 'string', 'max:100'],
            'workspace_id' => ['required', 'string', 'max:100'],
            'user_id' => ['required', 'string', 'max:255'],
            'query' => ['required', 'string', 'min:1'],
            'chat_id' => ['nullable', 'string', 'max:255'],
            'chat_title' => ['nullable', 'string', 'max:255'],
            'prompt_engineering' => ['nullable', 'string'],
            'image_data_url' => ['nullable', 'string', $this->imageDataUrlRule()],
            'chat_config_override' => ['nullable', 'array'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $payload = $this->all();

        foreach (['business_client_id', 'workspace_id', 'query', 'chat_id', 'chat_title', 'prompt_engineering'] as $field) {
            if (array_key_exists($field, $payload) && is_string($payload[$field])) {
                $payload[$field] = trim($payload[$field]);
            }
        }

        if (array_key_exists('user_id', $payload) && is_string($payload['user_id'])) {
            $payload['user_id'] = strtolower(trim($payload['user_id']));
        }

        $this->replace($payload);
    }

    protected function failedValidation(Validator $validator): void
    {
        $correlationId = (string) $this->attributes->get('correlation_id', '');

        throw new HttpResponseException(response()->json([
            'code' => 'validation_error',
            'message' => 'The given data was invalid.',
            'details' => [
                'errors' => $validator->errors()->toArray(),
            ],
            'correlation_id' => $correlationId,
        ], 422));
    }

    private function imageDataUrlRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if ($value === null || $value === '') {
                return;
            }

            if (!is_string($value)) {
                $fail('The '.$attribute.' must be a valid image data URL.');
                return;
            }

            $normalized = str_replace(["\r", "\n"], '', trim($value));
            if (!preg_match('/^data:image\/[a-zA-Z0-9.+-]+;base64,[A-Za-z0-9+\/=]+$/', $normalized)) {
                $fail('The '.$attribute.' must be a valid base64-encoded image data URL.');
                return;
            }

            $parts = explode(',', $normalized, 2);
            if (count($parts) !== 2) {
                $fail('The '.$attribute.' must include base64 image data.');
                return;
            }

            $decoded = base64_decode($parts[1], true);
            if ($decoded === false) {
                $fail('The '.$attribute.' contains invalid base64 data.');
                return;
            }

            $maxBytes = max((int) config('services.fastapi.max_image_bytes', 5 * 1024 * 1024), 1);
            if (strlen($decoded) > $maxBytes) {
                $fail('The '.$attribute.' exceeds the maximum allowed size of '.$maxBytes.' bytes.');
            }
        };
    }
}
