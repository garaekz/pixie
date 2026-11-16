<?php

namespace App\Models;

use App\Enums\GenerationQuality;
use App\Enums\GenerationSize;
use App\Enums\GenerationStatus;
use App\Enums\SubmissionState;
use Carbon\CarbonInterface;
use Database\Factories\GenerationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property int $user_id
 * @property string|null $provider_id
 * @property string $api_key_hash
 * @property string $prompt
 * @property GenerationSize $size
 * @property GenerationQuality $quality
 * @property list<string> $reference_images
 * @property GenerationStatus $status
 * @property SubmissionState $submission_state
 * @property int $submission_attempts
 * @property bool $submission_uncertain
 * @property CarbonInterface|null $next_attempt_at
 * @property int $estimate_pico
 * @property int $reserved_pico
 * @property int|null $provider_cost_pico
 * @property int|null $user_charge_pico
 * @property array<string, string>|null $rates
 * @property array<string, int>|null $usage
 * @property string|null $image_url
 * @property CarbonInterface|null $image_url_expires_at
 * @property string|null $error
 * @property CarbonInterface|null $provider_created_at
 * @property CarbonInterface|null $started_at
 * @property CarbonInterface|null $completed_at
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
#[Fillable(['id', 'user_id', 'api_key_hash', 'prompt', 'size', 'quality', 'reference_images', 'estimate_pico', 'reserved_pico', 'next_attempt_at', 'status', 'submission_state'])]
class Generation extends Model
{
    /** @use HasFactory<GenerationFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => GenerationStatus::class,
            'submission_state' => SubmissionState::class,
            'quality' => GenerationQuality::class,
            'size' => GenerationSize::class,
            'reference_images' => 'array', 'rates' => 'array', 'usage' => 'array',
            'estimate_pico' => 'integer', 'reserved_pico' => 'integer',
            'provider_cost_pico' => 'integer', 'user_charge_pico' => 'integer',
            'submission_attempts' => 'integer', 'next_attempt_at' => 'datetime',
            'submission_uncertain' => 'boolean',
            'provider_created_at' => 'datetime', 'started_at' => 'datetime',
            'completed_at' => 'datetime', 'image_url_expires_at' => 'datetime',
        ];
    }
}
