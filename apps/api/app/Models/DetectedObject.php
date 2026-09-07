<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DetectedObject extends Model
{
    use HasFactory;

    /** Detected objects are facts from a run — never edited, so no updated_at. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'detection_id', 'label', 'confidence',
        'bbox_x', 'bbox_y', 'bbox_width', 'bbox_height',
    ];

    protected function casts(): array
    {
        return [
            'confidence'  => 'float',
            'bbox_x'      => 'float',
            'bbox_y'      => 'float',
            'bbox_width'  => 'float',
            'bbox_height' => 'float',
        ];
    }

    public function detection(): BelongsTo
    {
        return $this->belongsTo(Detection::class);
    }

    /** HIGH / GOOD / LOW — computed, never stored. Change the thresholds freely. */
    public function confidenceTier(): string
    {
        return match (true) {
            $this->confidence >= 0.90 => 'HIGH',
            $this->confidence >= 0.75 => 'GOOD',
            default                   => 'LOW',
        };
    }
}
