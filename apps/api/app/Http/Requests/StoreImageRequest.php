<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route is already behind auth:sanctum
    }

    public function rules(): array
    {
        return [
            // `image` validates the actual file contents, not just the extension —
            // renaming virus.exe to photo.jpg does not get past this.
            'image'       => ['required', 'image', 'mimes:jpeg,png,webp', 'max:10240'],
            'title'       => ['nullable', 'string', 'max:255'],
            'captured_at' => ['nullable', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'image.max' => 'The image may not be larger than 10 MB.',
        ];
    }
}
