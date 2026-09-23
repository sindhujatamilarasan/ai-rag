<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ImageResource;
use App\Models\Image;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class AskController extends Controller
{
    public function __invoke(Request $request)
    {
        $data = $request->validate([
            'question' => ['required', 'string', 'max:500'],
        ]);

        $vector = Cache::remember(
            'embed:v1:768:' . md5($data['question']),
            now()->addDays(30),
            fn () => Http::timeout(30)
                ->post(config('services.ai.url') . '/embed', ['texts' => [$data['question']]])
                ->throw()
                ->json('embeddings.0'),
        );

        $literal = '[' . implode(',', $vector) . ']';

        // Top objects, not top images — the model reasons better with several
        // detections from the same photo than with one label per photo.
        $rows = DB::select(
            'SELECT o.label, o.confidence, d.image_id, i.title, i.captured_at
             FROM detected_objects o
             JOIN detections d ON d.id = o.detection_id
             JOIN images i ON i.id = d.image_id
             WHERE d.user_id = ?
               AND o.embedding IS NOT NULL
               AND i.deleted_at IS NULL
             ORDER BY o.embedding <=> ?::vector
             LIMIT 15',
            [$request->user()->id, $literal],
        );

        $answer = Http::timeout(180)
            ->post(config('services.ai.url') . '/ask', [
                'question' => $data['question'],
                'context'  => array_map(fn ($r) => [
                    'image_id'    => (int) $r->image_id,
                    'title'       => $r->title,
                    'captured_at' => $r->captured_at,
                    'label'       => $r->label,
                    'confidence'  => (float) $r->confidence,
                ], $rows),
            ])
            ->throw()
            ->json();

        // Load only the cited images — the answer must be checkable, so every
        // id it claims has to come back as something the user can actually open.
        $cited = Image::with('latestDetection.objects')
            ->where('user_id', $request->user()->id)
            ->whereIn('id', $answer['cited_image_ids'] ?? [])
            ->get();

        return response()->json([
            'question'            => $data['question'],
            'answered'            => $answer['answered'] ?? false,
            'answer'              => $answer['answer'] ?? '',
            'cited_images'        => ImageResource::collection($cited),
            // Exposed so evaluation can score retrieval separately from generation —
            // otherwise a bad answer can't be traced to "wrong photos fetched" vs
            // "right photos, wrong reasoning".
            'retrieved_image_ids' => array_values(array_unique(
                array_map(fn ($r) => (int) $r->image_id, $rows)
            )),
            'context_size'        => count($rows),
            'usage'               => $answer['usage'] ?? [],
        ]);
    }
}
