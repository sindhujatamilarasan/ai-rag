import asyncio
import base64
import json
import os

import httpx
from dotenv import load_dotenv
from fastapi import FastAPI, File, HTTPException, UploadFile
from pydantic import BaseModel

load_dotenv()

API_KEY = os.getenv("GEMINI_API_KEY")
MODEL = os.getenv("GEMINI_MODEL", "gemini-3.5-flash")
BASE = "https://generativelanguage.googleapis.com/v1beta/models"

EMBED_MODEL = "gemini-embedding-001"
EMBED_DIMS = 768

app = FastAPI(title="VisionDesk AI Service")


# ---------------------------------------------------------------------------
# Shared Gemini client
# ---------------------------------------------------------------------------

async def gemini_post(payload: dict) -> dict:
    """Call Gemini with bounded retries.

    Free-tier latency is spiky: a request that times out once often succeeds
    immediately on retry. Retries timeouts, 5xx and 429 — never a plain 4xx,
    because a malformed request stays malformed no matter how often you send it.
    """
    last = "unknown"

    for attempt in range(3):
        try:
            async with httpx.AsyncClient(timeout=120) as client:
                res = await client.post(
                    f"{BASE}/{MODEL}:generateContent",
                    headers={"x-goog-api-key": API_KEY},
                    json=payload,
                )

            if res.status_code == 200:
                return res.json()

            if 400 <= res.status_code < 500 and res.status_code != 429:
                raise HTTPException(res.status_code, f"Gemini failed: {res.text}")

            last = f"{res.status_code} {res.text[:200]}"

        except httpx.TimeoutException as exc:
            last = f"timeout after 120s ({type(exc).__name__})"

        await asyncio.sleep(2 ** attempt)      # 1s, 2s, 4s

    raise HTTPException(504, f"Gemini unavailable after 3 attempts: {last}")


def first_text(body: dict) -> str:
    """Pull the text part out of a Gemini response, or fail loudly."""
    try:
        return body["candidates"][0]["content"]["parts"][0]["text"]
    except (KeyError, IndexError):
        raise HTTPException(502, "Gemini returned no text part")


def usage_of(body: dict) -> dict:
    u = body.get("usageMetadata", {})
    return {
        "prompt_tokens": u.get("promptTokenCount", 0),
        "output_tokens": u.get("candidatesTokenCount", 0),
        "thought_tokens": u.get("thoughtsTokenCount", 0),
        "total_tokens": u.get("totalTokenCount", 0),
    }


@app.get("/health")
def health():
    return {"status": "ok", "service": "ai"}


# ---------------------------------------------------------------------------
# Detection
# ---------------------------------------------------------------------------

PROMPT = (
    "Detect every distinct visible item in this image, including background "
    "items, surfaces, furniture and small objects. Do not only return the main "
    "subject — list everything you can see. Label each item with a lowercase "
    "singular noun phrase and no article ('pen', not 'a pen'). Give your "
    "confidence from 0 to 1, and a bounding box where x and y are the top-left "
    "corner."
)

DETECT_SCHEMA = {
    "type": "ARRAY",
    "items": {
        "type": "OBJECT",
        "properties": {
            "label": {"type": "STRING"},
            "confidence": {"type": "NUMBER"},
            "x": {"type": "NUMBER"},
            "y": {"type": "NUMBER"},
            "width": {"type": "NUMBER"},
            "height": {"type": "NUMBER"},
        },
        "required": ["label", "confidence", "x", "y", "width", "height"],
    },
}


def clamp(value: float) -> float:
    """Gemini works on a 0-1000 grid regardless of what the prompt asks for."""
    return min(1.0, max(0.0, value / 1000))


@app.post("/detect")
async def detect(image: UploadFile = File(...)):
    if not API_KEY:
        raise HTTPException(500, "GEMINI_API_KEY is not set")

    raw = await image.read()

    body = await gemini_post({
        "contents": [
            {
                "parts": [
                    {"text": PROMPT},
                    {
                        "inline_data": {
                            "mime_type": image.content_type or "image/jpeg",
                            "data": base64.b64encode(raw).decode(),
                        }
                    },
                ]
            }
        ],
        "generationConfig": {
            # Detection is extraction, not reasoning — thinking costs tokens
            # here without improving recall. Measured: 1 -> 5 objects came from
            # the prompt, not from the thinking budget.
            "thinkingConfig": {"thinkingBudget": 0},
            "responseMimeType": "application/json",
            "responseSchema": DETECT_SCHEMA,
        },
    })

    objects = json.loads(first_text(body))

    if not objects:
        raise HTTPException(502, "Detection returned no objects")

    return {
        "objects": [
            {
                "label": o["label"].strip().lower(),
                "confidence": float(o["confidence"]),
                "x": clamp(o["x"]),
                "y": clamp(o["y"]),
                "width": clamp(o["width"]),
                "height": clamp(o["height"]),
            }
            for o in objects
        ],
        "usage": usage_of(body),
    }


# ---------------------------------------------------------------------------
# Embeddings
# ---------------------------------------------------------------------------

def normalize(v: list[float]) -> list[float]:
    """Gemini only returns unit-length vectors at the full 3072 dims.
    Truncated output must be re-normalized or cosine distance is subtly wrong —
    no error, just worse ranking."""
    mag = sum(x * x for x in v) ** 0.5
    return [x / mag for x in v] if mag else v


async def embed_text(text: str) -> list[float]:
    async with httpx.AsyncClient(timeout=60) as client:
        res = await client.post(
            f"{BASE}/{EMBED_MODEL}:embedContent",
            headers={"x-goog-api-key": API_KEY},
            json={
                "content": {"parts": [{"text": text}]},
                # 768 rather than the default 3072: pgvector's HNSW index caps
                # at 2000 dimensions, so 3072 could be stored but never indexed.
                "outputDimensionality": EMBED_DIMS,
            },
        )

    if res.status_code != 200:
        raise HTTPException(res.status_code, f"Embedding failed: {res.text}")

    return normalize(res.json()["embedding"]["values"])


class EmbedRequest(BaseModel):
    texts: list[str]


@app.post("/embed")
async def embed(req: EmbedRequest):
    if not req.texts:
        raise HTTPException(422, "texts must not be empty")

    # gather() fires every embedding call at once instead of waiting for each in
    # turn — 50 labels take about as long as one, not fifty times as long.
    vectors = await asyncio.gather(*(embed_text(t) for t in req.texts))

    return {"embeddings": vectors, "dimensions": EMBED_DIMS}


# ---------------------------------------------------------------------------
# Grounded question answering
# ---------------------------------------------------------------------------

class ContextItem(BaseModel):
    image_id: int
    title: str | None = None
    captured_at: str | None = None
    label: str
    confidence: float


class AskRequest(BaseModel):
    question: str
    context: list[ContextItem]


ANSWER_SCHEMA = {
    "type": "OBJECT",
    "properties": {
        "answered": {"type": "BOOLEAN"},
        "answer": {"type": "STRING"},
        "cited_image_ids": {"type": "ARRAY", "items": {"type": "INTEGER"}},
    },
    "required": ["answered", "answer", "cited_image_ids"],
}

ASK_SYSTEM = """You answer questions about a field engineer's photo library.

You are given a list of objects that an AI vision model detected in their
photos. That list is the ONLY thing you know. You have no other knowledge of
their site, their work, or their photos.

Rules, in order of importance:

1. Answer ONLY from the provided detections. Never use general knowledge to
   fill a gap, and never infer something that is not in the list.
2. If the detections cannot answer the question, set "answered" to false and
   say plainly what is missing. A wrong answer is far worse than "I don't
   know" — the user cannot tell that you guessed.
3. Cite the image_id of every photo your answer relies on, in cited_image_ids.
   If you cite nothing, you have not answered from the data.
4. Detections carry a confidence. Say so when you lean on a low-confidence one.
5. Be brief. Two or three sentences is usually enough."""


@app.post("/ask")
async def ask(req: AskRequest):
    if not req.context:
        return {
            "answered": False,
            "answer": "There are no detections to search yet. Capture some photos first.",
            "cited_image_ids": [],
            "usage": {},
        }

    lines = [
        f"- image_id={c.image_id} | {c.label} "
        f"(confidence {c.confidence:.2f}) | title={c.title or 'untitled'} "
        f"| captured {c.captured_at or 'unknown'}"
        for c in req.context
    ]

    prompt = (
        f"{ASK_SYSTEM}\n\n"
        "DETECTIONS:\n" + "\n".join(lines) + f"\n\nQUESTION: {req.question}"
    )

    body = await gemini_post({
        "contents": [{"parts": [{"text": prompt}]}],
        "generationConfig": {
            # Thinking helps grounding — the model has to actually check the
            # list rather than pattern-match a plausible answer. Bounded,
            # because an unbounded budget is an unbounded latency.
            "thinkingConfig": {"thinkingBudget": 1024},
            "responseMimeType": "application/json",
            "responseSchema": ANSWER_SCHEMA,
        },
    })

    parsed = json.loads(first_text(body))

    # The model can cite an id that was never in the context. Dropping those is
    # the difference between a citation and a decoration.
    allowed = {c.image_id for c in req.context}
    parsed["cited_image_ids"] = [i for i in parsed["cited_image_ids"] if i in allowed]

    parsed["usage"] = usage_of(body)

    return parsed