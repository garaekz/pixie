<?php

namespace App\Http\Requests;

use App\Enums\PixelforgeEventType;
use App\Http\Middleware\VerifyPixelforgeSignature;
use App\Rules\PixelforgeGenerationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PixelforgeWebhookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function providerKeyHash(): string
    {
        return (string) $this->attributes->get(VerifyPixelforgeSignature::KEY_HASH_ATTRIBUTE);
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        $envelope = [
            'id' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(PixelforgeEventType::class)],
            'created_at' => ['sometimes', 'date'],
        ];

        return $this->input('type') === PixelforgeEventType::Ping->value
            ? $envelope
            : [...$envelope, 'data.object' => ['required', 'array'], ...PixelforgeGenerationRules::rules('data.object')];
    }
}
