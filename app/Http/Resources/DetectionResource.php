<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DetectionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'status'           => $this->status->value,
            'model'            => $this->model,
            'prompt_version'   => $this->prompt_version,
            'duration_seconds' => $this->durationSeconds(),
            'error_message'    => $this->error_message,
            'total_tokens'     => $this->total_tokens,
            'objects'          => DetectedObjectResource::collection($this->whenLoaded('objects')),
            'created_at'       => $this->created_at->toIso8601String(),
        ];
    }
}
