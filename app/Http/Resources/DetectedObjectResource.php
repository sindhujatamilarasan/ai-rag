<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DetectedObjectResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'label'      => $this->label,
            'confidence' => (float) $this->confidence,
            'tier'       => $this->confidenceTier(),
            'bbox'       => $this->bbox_x === null ? null : [
                'x'      => (float) $this->bbox_x,
                'y'      => (float) $this->bbox_y,
                'width'  => (float) $this->bbox_width,
                'height' => (float) $this->bbox_height,
            ],
        ];
    }
}
