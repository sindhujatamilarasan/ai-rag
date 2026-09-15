import asyncio
from pydantic import BaseModel
import base64
import os

import httpx
from dotenv import load_dotenv
from fastapi import FastAPI, File, HTTPException, UploadFile

load_dotenv()

API_KEY = os.getenv("GEMINI_API_KEY")
MODEL = os.getenv("GEMINI_MODEL", "gemini-3.5-flash")
BASE = "https://generativelanguage.googleapis.com/v1beta/models"

PROMPT = (
    "Detect every distinct visible item in this image, including background "
    "items, surfaces, furniture and small objects. Do not only return the main "
    "subject — list everything you can see. Label each item with a lowercase "
    "singular noun phrase and no article ('pen', not 'a pen'). Give your "
    "confidence from 0 to 1, and a bounding box where x and y are the top-left "
    "corner."
)

SCHEMA = {
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

app = FastAPI(title="VisionDesk AI Service")

EMBED_MODEL = "gemini-embedding-001"
EMBED_DIMS = 768


def normalize(v: list[float]) -> list[float]:
    """Gemini only returns unit-length vectors at the full 3072 dims.
    Truncated output must be re-normalized or cosine distance is wrong."""
    mag = sum(x * x for x in v) ** 0.5
    return [x / mag for x in v] if mag else v


async def embed_text(text: str) -> list[float]:
    async with httpx.AsyncClient(timeout=30) as client:
        res = await client.post(
            f"{BASE}/{EMBED_MODEL}:embedContent",
            headers={"x-goog-api-key": API_KEY},
            json={
                "content": {"parts": [{"text": text}]},
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

    vectors = await asyncio.gather(*(embed_text(t) for t in req.texts))

    return {"embeddings": vectors, "dimensions": EMBED_DIMS}


@app.get("/health")
def health():
    return {"status": "ok", "service": "ai"}


def clamp(value: float) -> float:
    """Gemini works on a 0-1000 grid regardless of what the prompt asks for."""
    return min(1.0, max(0.0, value / 1000))


@app.post("/detect")
async def detect(image: UploadFile = File(...)):
    if not API_KEY:
        raise HTTPException(500, "GEMINI_API_KEY is not set")

    raw = await image.read()

    payload = {
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
            "thinkingConfig": {"thinkingBudget": 0},
            "responseMimeType": "application/json",
            "responseSchema": SCHEMA,
        },
    }

    async with httpx.AsyncClient(timeout=60) as client:
        res = await client.post(
            f"{BASE}/{MODEL}:generateContent",
            headers={"x-goog-api-key": API_KEY},
            json=payload,
        )

    if res.status_code != 200:
        raise HTTPException(res.status_code, f"Gemini failed: {res.text}")

    body = res.json()

    try:
        text = body["candidates"][0]["content"]["parts"][0]["text"]
    except (KeyError, IndexError):
        raise HTTPException(502, "Gemini returned no text part")

    import json

    objects = json.loads(text)

    if not objects:
        raise HTTPException(502, "Detection returned no objects")

    usage = body.get("usageMetadata", {})

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
        "usage": {
            "prompt_tokens": usage.get("promptTokenCount", 0),
            "output_tokens": usage.get("candidatesTokenCount", 0),
            "thought_tokens": usage.get("thoughtsTokenCount", 0),
            "total_tokens": usage.get("totalTokenCount", 0),
        },
    }