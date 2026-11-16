<?php

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class AdminReportRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'start_date' => $this->input('start_date', now('UTC')->subDays(29)->toDateString()),
            'end_date' => $this->input('end_date', now('UTC')->toDateString()),
        ]);
    }

    public function authorize(): bool
    {
        return $this->user()?->can('view-admin-report') ?? false;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->hasAny(['start_date', 'end_date'])) {
                return;
            }

            $start = CarbonImmutable::createFromFormat('!Y-m-d', $this->string('start_date')->toString(), 'UTC');
            $end = CarbonImmutable::createFromFormat('!Y-m-d', $this->string('end_date')->toString(), 'UTC');

            if ($end->lessThan($start) || $start->diffInDays($end) > 91) {
                $validator->errors()->add('end_date', 'Choose a date range of 1 to 92 UTC days.');
            }
        }];
    }
}
