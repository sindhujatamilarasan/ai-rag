<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreImageRequest;
use App\Http\Resources\ImageResource;
use App\Models\Image;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;

class ImageController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $images = $request->user()
            ->images()                            // scoped to the owner automatically
            ->with('latestDetection')
            ->withCount('detectedObjects')
            ->latest('captured_at')
            ->paginate(20);

        return ImageResource::collection($images);
    }

    public function store(StoreImageRequest $request): JsonResponse
    {
        $file     = $request->file('image');
        $checksum = hash_file('sha256', $file->getRealPath());

        // Same photo, same user, already uploaded? Return the existing record
        // instead of paying ~1,100 tokens to detect it again.
        $existing = $request->user()->images()->where('checksum', $checksum)->first();

        if ($existing) {
            return response()->json([
                'message'   => 'This image was already uploaded.',
                'duplicate' => true,
                'data'      => ImageResource::make($existing->load('latestDetection')),
            ], 200);
        }

        [$width, $height] = getimagesize($file->getRealPath());

        $path = $file->store('images/' . now()->format('Y/m'), 'public');

        $image = $request->user()->images()->create([
            'title'             => $request->input('title'),
            'path'              => $path,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type'         => $file->getMimeType(),
            'size_bytes'        => $file->getSize(),
            'width'             => $width,
            'height'            => $height,
            'checksum'          => $checksum,
            'captured_at'       => $request->input('captured_at') ?? now(),
        ]);

        // Milestone 6: dispatch(new RunDetection($image));

        return response()->json([
            'duplicate' => false,
            'data'      => ImageResource::make($image),
        ], 201);
    }

    public function show(Request $request, Image $image): ImageResource
    {
        // Ownership check. Without this, image ID 5 is readable by anyone —
        // the single most common API vulnerability (IDOR).
        abort_unless($image->user_id === $request->user()->id, 403);

        return ImageResource::make(
            $image->load(['latestDetection.objects'])->loadCount('detectedObjects')
        );
    }

    public function destroy(Request $request, Image $image): JsonResponse
    {
        abort_unless($image->user_id === $request->user()->id, 403);

        Storage::disk('public')->delete($image->path);
        $image->delete();

        return response()->json(['message' => 'Deleted']);
    }
}
