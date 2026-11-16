<?php

namespace App\Http\Requests;

use App\Enums\GenerationStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class GenerationIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return ['status' => ['nullable', new Enum(GenerationStatus::class)]];
    }

    public function status(): ?GenerationStatus
    {
        $status = $this->validated('status');

        return $status === null ? null : GenerationStatus::from($status);
    }
}
