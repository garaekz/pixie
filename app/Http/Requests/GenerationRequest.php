<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;

class GenerationRequest extends EstimateGenerationRequest
{
    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return ['request_id' => ['required', 'uuid']] + parent::rules();
    }

    /** @return array{request_id: string, prompt: string, size: string, quality: string, reference_images: list<string>} */
    public function generationData(): array
    {
        return [
            'request_id' => $this->string('request_id')->toString(),
            'prompt' => $this->string('prompt')->toString(),
            'size' => $this->string('size')->toString(),
            'quality' => $this->string('quality')->toString(),
            'reference_images' => $this->validated('reference_images', []),
        ];
    }
}
