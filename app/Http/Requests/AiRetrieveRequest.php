<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class AiRetrieveRequest extends FormRequest
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
            'top_k' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $payload = $this->all();

        foreach (['business_client_id', 'workspace_id', 'query'] as $field) {
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
}
