<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ImageResource;
use App\Models\Image;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

class SearchController extends Controller
{
    public function __invoke(Request $request)
    {
        $data = $request->validate([
            'q'     => ['required', 'string', 'max:200'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $limit = $data['limit'] ?? 20;

        // The query goes through the same embedding model as the labels —
        // vectors from different models are not comparable.
       // The same query always produces the same vector, so an embedding call per
        // keystroke-settled search is pure waste. The key carries the model and
        // dimensions — changing either must invalidate every cached vector.
        $vector = Cache::remember(
            'embed:v1:768:' . md5($data['q']),
            now()->addDays(30),
            fn () => Http::timeout(30)
                ->post(config('services.ai.url') . '/embed', ['texts' => [$data['q']]])
                ->throw()
                ->json('embeddings.0'),
        );

        $literal = '[' . implode(',', $vector) . ']';

        // DISTINCT ON keeps the single best-matching object per image, then the
        // outer query ranks the images by that score. Without the subquery,
        // DISTINCT ON would force ordering by image_id and lose the ranking.
        $rows = DB::select(
            'SELECT image_id, label, similarity FROM (
                SELECT DISTINCT ON (d.image_id)
                       d.image_id,
                       o.label,
                       1 - (o.embedding <=> ?::vector) AS similarity
                FROM detected_objects o
                JOIN detections d ON d.id = o.detection_id
                WHERE d.user_id = ? AND o.embedding IS NOT NULL
                ORDER BY d.image_id, o.embedding <=> ?::vector
            ) t
            ORDER BY similarity DESC
            LIMIT ?',
            [$literal, $request->user()->id, $literal, $limit],
        );

        $images = Image::with('latestDetection.objects')
            ->whereIn('id', array_column($rows, 'image_id'))
            ->get()
            ->keyBy('id');

        return response()->json([
            'query' => $data['q'],
            'data'  => collect($rows)
                ->filter(fn ($r) => $images->has($r->image_id))
                ->map(fn ($r) => [
                    'similarity'   => round($r->similarity, 4),
                    'matched_label' => $r->label,
                    'image'        => ImageResource::make($images[$r->image_id]),
                ])
                ->values(),
        ]);
    }
}
