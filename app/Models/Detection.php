<?php

namespace App\Models;

use App\Enums\DetectionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Detection extends Model
{
    use HasFactory;

    protected $fillable = [
        'image_id', 'user_id', 'status', 'model', 'prompt_version', 'params',
        'started_at', 'completed_at', 'error_message',
        'prompt_tokens', 'output_tokens', 'thought_tokens', 'total_tokens', 'cost_usd',
    ];

    protected function casts(): array
    {
        return [
            'status'       => DetectionStatus::class,   // string in DB, enum in code
            'params'       => 'array',                  // jsonb <-> PHP array
            'started_at'   => 'datetime',
            'completed_at' => 'datetime',
            'cost_usd'     => 'decimal:6',
        ];
    }

    public function image(): BelongsTo
    {
        return $this->belongsTo(Image::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function objects(): HasMany
    {
        return $this->hasMany(DetectedObject::class)->orderByDesc('confidence');
    }

    /** Seconds the run took, or null if it hasn't finished. */
    public function durationSeconds(): ?float
    {
        if (! $this->started_at || ! $this->completed_at) {
            return null;
        }

        return round($this->completed_at->getPreciseTimestamp(3) / 1000
            - $this->started_at->getPreciseTimestamp(3) / 1000, 2);
    }
}
