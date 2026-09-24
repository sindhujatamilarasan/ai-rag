# VisionDesk

Photograph anything, and it becomes searchable by meaning — not by filename.

A field engineer photographs equipment on site. A multimodal model detects what's
in each photo, those detections become vector embeddings, and the whole library
is then searchable semantically ("flying", "stationery") and answerable through
grounded RAG chat with citations back to the source photos.

```
┌──────────┐    ┌──────────┐    ┌───────────┐    ┌──────────────────┐
│ Next.js  │───▶│ Laravel  │───▶│  FastAPI  │───▶│  Gemini API      │
│ (web UI) │    │  (API)   │    │ (AI svc)  │    │  3.5-flash       │
└──────────┘    └────┬─────┘    └───────────┘    └──────────────────┘
                     │
                ┌────▼─────────────────┐
                │ Postgres + pgvector  │
                └──────────────────────┘
```

---

## Why it's built this way

**Three services, not one.** Laravel owns the domain — users, images, detections,
ownership rules. The Python service owns everything AI: prompts, schemas,
embeddings, retries. The boundary is an HTTP contract, so the model or the
provider can change without touching a single line of business logic.

**Detection runs in a queue, not in the request.** A Gemini call takes 15–30
seconds and can fail. Inside an HTTP request that means a spinning browser tab
and an error the user can't retry. As a job it gets `$tries = 3`, backoff, a
timeout, and a recorded failure state the UI can show.

**Vectors live in Postgres, not a separate vector database.** With pgvector, one
query does tenant filtering, a join to images, and similarity search together:

```sql
WHERE d.user_id = ? AND o.embedding IS NOT NULL
ORDER BY o.embedding <=> ?
```

A dedicated vector DB would mean a second store to keep in sync, and the
ownership filter would have to happen in application code — which is exactly
where cross-tenant leaks come from.

**Embeddings are 768 dimensions, not the default 3072.** pgvector's HNSW index
caps at 2000 dimensions, so 3072-dim vectors could be stored but never indexed —
every search would scan every row. Truncated vectors are re-normalised, because
Gemini only returns unit-length vectors at full width and cosine distance is
subtly wrong otherwise. No error, just worse ranking.

**Answers are grounded in three layers.** The prompt says to answer only from the
supplied detections. The response schema has an `answered: false` branch so the
model has a way to say "not in your photos". And the service drops any cited
image id that wasn't in the context — the model can hallucinate a citation, so
code validates it rather than trusting it.

---

## Running it

Requires Docker. Nothing else.

```bash
git clone git@github.com:sindhujatamilarasan/ai-rag.git
cd ai-rag

cp apps/api/.env.example apps/api/.env      # set APP_KEY, GEMINI_API_KEY
cp apps/ai/.env.example  apps/ai/.env       # set GEMINI_API_KEY

docker compose up -d
```

| Service | URL |
|---|---|
| Web UI | http://localhost:3000 |
| API | http://localhost:8000/api |
| AI service | http://localhost:8001/docs |
| pgAdmin | http://localhost:5051 |

Ports are deliberately off-default (Postgres on 5433, pgAdmin on 5051) so the
stack can run beside other local Postgres containers without a conflict.

```bash
docker compose ps                      # status
docker compose logs queue --tail 20    # worker output
docker compose up -d --build api queue # after a code change
docker compose down                    # stop
```

---

## Evaluation

"It works" is not a measurement. A golden set of 20 questions —
direct lookups, paraphrases ("writing tool" → pen), category questions, counts,
and five questions the photos genuinely cannot answer — runs against the live
API and scores the pipeline.

```bash
docker compose exec ai python eval/run_eval.py
```

| Metric | Result | What it means |
|---|---|---|
| Decision accuracy | 100% | Answered when it could, refused when it couldn't |
| Retrieval recall | 100% | The right photos reached the model |
| Citation precision | 100% | Every cited photo was actually relevant |
| Citation recall | 100% | Every relevant photo was cited |
| Citation leaks | 0 | Never cited a source for an unanswerable question |

Refusal cases matter as much as the answers. A RAG system that answers
everything is worse than one that answers less, because the user can't tell
which answers were invented.

What the eval found that manual testing had not:

- **Retrieval recall of 33%** — traced to the eval token belonging to a
  different account than the photos. The `user_id` filter was working correctly;
  tenant isolation was doing its job. Confirmation, not a bug.
- **Silent rate-limit failures** — 14 of 20 cases returned 504. Gemini's free
  tier allows 20 requests per minute, and the retry logic was guessing its own
  backoff. It now reads `retryDelay` from the 429 response body and waits
  exactly that long.
- **A broken timeout chain** — patient retries inside the AI service meant
  nothing while the Laravel HTTP client gave up at 150s. Timeouts now increase
  outward: Python 240s → Laravel 300s → job 360s → queue retry_after 420s.

---

## Notes

**Secrets.** `.env` files are in both `.gitignore` and `.dockerignore`. An image
carrying an API key leaks it to anyone who can pull the image.

**Ownership.** Every read checks `user_id` — `abort_unless($image->user_id ===
$request->user()->id, 403)` on single records, a `WHERE` clause on collections.
Never a trusted id from the client.

**Deduplication.** Uploads are hashed (SHA-256); the same photo doesn't pay for
detection twice. But a *failed* detection understood nothing, so re-uploading
dispatches a fresh attempt — that's the user's only retry path.

**Thinking budget is set per call.** Detection is extraction, so
`thinkingBudget: 0` — measured, it cut thinking tokens from 319 to 18 with no
loss in recall (the missing objects came from the prompt, not the budget).
Question answering gets 1024, because grounding needs the model to actually
check the list instead of pattern-matching a plausible answer.

---

## Stack

Next.js 16 · Laravel 13 (PHP 8.5) · FastAPI (Python 3.14) · Postgres 16 +
pgvector · Gemini 3.5-flash · Docker Compose · GitHub Actions