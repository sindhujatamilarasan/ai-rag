<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class ImageResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'title'             => $this->title,
            'original_filename' => $this->original_filename,
            'url'               => Storage::url($this->path),
            'width'             => $this->width,
            'height'            => $this->height,
            'size_bytes'        => $this->size_bytes,
            'captured_at'       => $this->captured_at?->toIso8601String(),
            'created_at'        => $this->created_at->toIso8601String(),
            'latest_detection'  => DetectionResource::make($this->whenLoaded('latestDetection')),
            'object_count'      => $this->whenCounted('detectedObjects'),
        ];
    }
}
