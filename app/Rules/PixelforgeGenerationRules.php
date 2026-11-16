<?php

namespace App\Rules;

use App\Enums\GenerationStatus;
use Illuminate\Validation\Rule;

final class PixelforgeGenerationRules
{
    /** @return array<string, array<mixed>> */
    public static function rules(string $prefix = ''): array
    {
        $field = $prefix === '' ? '' : $prefix.'.';
        $succeeded = 'required_if:'.$field.'status,'.GenerationStatus::Succeeded->value;
        $rules = [
            $field.'id' => ['required', 'string', 'max:255'],
            $field.'status' => ['required', Rule::enum(GenerationStatus::class)],
            $field.'metadata.local_id' => ['sometimes', 'uuid'],
            $field.'created_at' => ['required', 'date'],
            $field.'started_at' => ['nullable', 'date'],
            $field.'completed_at' => ['required_if:'.$field.'status,'.GenerationStatus::Succeeded->value.','.GenerationStatus::Failed->value, 'nullable', 'date'],
            $field.'image_url' => ['nullable', 'url:http,https'],
            $field.'image_url_expires_at' => ['nullable', 'date'],
            $field.'rates' => ['required', 'array'],
            $field.'usage' => [$succeeded, 'nullable', 'array'],
            $field.'usage.total_tokens' => ['sometimes', 'integer', 'min:0'],
            $field.'error' => ['nullable', 'array'],
        ];
        foreach (['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'] as $type) {
            $rules[$field.'rates.'.$type] = ['required', 'string', 'numeric', 'min:0'];
            $rules[$field.'usage.'.$type] = [$succeeded, 'integer', 'min:0'];
        }

        return $rules;
    }
}
