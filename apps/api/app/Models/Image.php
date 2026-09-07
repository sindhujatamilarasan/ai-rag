<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Image extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id', 'title', 'path', 'original_filename',
        'mime_type', 'size_bytes', 'width', 'height',
        'checksum', 'captured_at',
    ];

    protected function casts(): array
    {
        return [
            'captured_at' => 'datetime',
            'size_bytes'  => 'integer',
            'width'       => 'integer',
            'height'      => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function detections(): HasMany
    {
        return $this->hasMany(Detection::class);
    }

    /**
     * The most recent run. This is the answer to the first question I asked you:
     * latestOfMany() lets Eloquent fetch "the newest child" as a hasOne, so the
     * Result screen loads without pulling every historical run.
     */
    public function latestDetection(): HasOne
    {
        return $this->hasOne(Detection::class)->latestOfMany();
    }

    /**
     * And the second question: hasManyThrough hops Image -> Detection -> DetectedObject
     * in one query, so you can ask for an image's objects without a manual join.
     */
    public function detectedObjects(): HasManyThrough
    {
        return $this->hasManyThrough(DetectedObject::class, Detection::class);
    }
}
