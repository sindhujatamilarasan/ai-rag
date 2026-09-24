# Architecture

## Services

| Service | Stack | Owns |
|---|---|---|
| `web` | Next.js 16, App Router | UI, polling for detection status, bbox overlay |
| `api` | Laravel 13, PHP 8.5 | Users, images, detections, ownership, retrieval |
| `queue` | same image as `api` | Runs detection jobs off the request cycle |
| `ai` | FastAPI, Python 3.14 | Prompts, response schemas, embeddings, retries |
| `postgres` | Postgres 16 + pgvector | Relational data *and* vectors |

`web` talks to `api` only. `api` and `queue` talk to `ai` and `postgres`. `ai`
talks to Gemini and to nothing else — it holds no database credentials and no
notion of users, which keeps the AI layer swappable.

## Data model

```
users
  └── images                  (path, checksum, width, height, captured_at)
        └── detections        (status, model, prompt_version, params, usage, error_message)
              └── detected_objects
                    label, confidence,
                    bbox_x, bbox_y, bbox_width, bbox_height,   -- 0..1, top-left origin
                    embedding vector(768)
```

`detections` is a separate table from `images` on purpose: one photo can be
detected several times — a prompt revision, a model change, a retry after a
failure. Each run keeps its own status, token usage and error, so the history of
what the system understood and when is not overwritten.

`user_id` is denormalised onto `detections` so retrieval can filter tenants
without joining back through `images` on every vector search.

Indexes:

```sql
CREATE INDEX ON detections (image_id);
CREATE INDEX ON detected_objects (detection_id);
CREATE INDEX ON detected_objects (label);
CREATE INDEX detected_objects_embedding_idx
  ON detected_objects USING hnsw (embedding vector_cosine_ops);
```

Postgres does not index foreign keys automatically — unlike MySQL. Those first
three are easy to forget and the cost only shows up under load.

## Upload → searchable

```
POST /api/images
  │  hash the file (sha256) → already uploaded? return it
  │  store the file, create the image row
  │  create detection (status = pending)
  └─ dispatch RunDetection ──────────────────▶ 202, UI starts polling

RunDetection (queue worker)
  │  already completed? return          ← idempotent: the job can be retried safely
  │  status = processing
  │  POST ai:8001/detect                → labels + confidence + boxes
  │  POST ai:8001/embed                 → one 768-dim vector per label
  │  ── transaction ──────────────────────────────────────────
  │    delete previous objects for this detection
  │    insert objects, then UPDATE ... SET embedding = ?
  │    status = completed, save token usage
  │  ────────────────────────────────────────────────────────
  └─ on failure: failed() records status, error_message, completed_at
```

The two HTTP calls happen **outside** the transaction. A network call inside a
transaction holds a database connection open for the length of a remote
request — under any real traffic that exhausts the pool.

A non-retryable 4xx from the AI service calls `$this->fail()` instead of
throwing: a malformed request will be malformed on all three attempts, so
retrying only delays the error. A 429 or 5xx is thrown, and the job's own
backoff handles it.

## Search

```
GET /api/search?q=flying
  │  embed the query (cached: embed:v1:768:<md5>)
  └─ one photo per match, best object per photo:

SELECT DISTINCT ON (d.image_id) d.image_id, o.label,
       1 - (o.embedding <=> :q) AS similarity
FROM detected_objects o
JOIN detections d ON d.id = o.detection_id
WHERE d.user_id = :user AND o.embedding IS NOT NULL
ORDER BY d.image_id, o.embedding <=> :q
```

`DISTINCT ON` keeps a photo of six books from filling the results with six rows
of the same photo. The UI shows which label matched, so a result that looks
wrong is explainable rather than mysterious.

Absolute similarity scores mean very little on their own — two unrelated labels
measured 0.607. Only the ranking is meaningful, which is why there is no
hard-coded score threshold; a cut-off would have to be calibrated against the
eval set, not guessed.

## Ask (RAG)

```
POST /api/ask  { question }
  │  embed the question
  │  top 15 objects for this user, joined to images, embedding not null
  └─ POST ai:8001/ask  { question, context: [{image_id, label, confidence, ...}] }
        │  grounding rules + response schema (answered, answer, cited_image_ids)
        │  thinkingBudget 1024
        └─ drop cited ids that were not in the context

     ← { answered, answer, cited_images, retrieved_image_ids, context_size, usage }
```

`retrieved_image_ids` is returned alongside the citations so the eval can tell
two different failures apart: the right photo never reached the model
(retrieval), or it reached the model and wasn't used (generation). Without that
field a low score is unactionable.

## Timeouts

Each timeout must be longer than the one inside it, or the outer layer kills
work that was about to succeed:

```
Gemini call        45s  × up to 5 attempts, waiting the retryDelay the API returns
AI service        ~240s total budget
Laravel HTTP       300s
Job timeout        360s
Queue retry_after  420s
```

`DB_QUEUE_RETRY_AFTER` is the subtle one: if it is shorter than the job timeout,
the queue hands the same job to a second worker while the first is still
running it.

## Rate limits

Gemini's free tier allows 20 requests per minute across everything — detection,
embeddings, answers. A 429 response carries the exact wait in
`error.details[].retryDelay`, and the client reads it rather than guessing:

```python
if res.status_code == 429:
    await asyncio.sleep(retry_after(res.text))
    continue
```

Guessing too short wastes the retry; guessing too long (the first version waited
15s, 30s, 45s for a 2.4s limit) burns the whole timeout budget waiting for a
window that already reopened.

## What is deliberately not here

- **No auth provider.** Sanctum tokens are enough for a single-tenant demo, and
  swapping in OAuth later touches one controller.
- **No background embedding queue.** Embeddings are generated inside the
  detection job. If a photo ever produced hundreds of objects, they'd need their
  own job — 5–30 objects does not.
- **No score threshold on search.** It needs eval data to calibrate; a guessed
  threshold silently hides correct results.
- **No deploy pipeline.** CI builds and lints. Deployment would add a registry
  push and a host, which proves nothing new about the application.