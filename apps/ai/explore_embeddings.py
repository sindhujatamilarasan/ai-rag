import os

import httpx
from dotenv import load_dotenv

load_dotenv()

API_KEY = os.getenv("GEMINI_API_KEY")
URL = ("https://generativelanguage.googleapis.com/v1beta/models/"
       "gemini-embedding-001:embedContent")


def embed(text: str) -> list[float]:
    res = httpx.post(
        URL,
        headers={"x-goog-api-key": API_KEY},
        json={"content": {"parts": [{"text": text}]}},
        timeout=30,
    )
    res.raise_for_status()
    return res.json()["embedding"]["values"]


def similarity(a: list[float], b: list[float]) -> float:
    """Cosine similarity — how close two directions are, ignoring length."""
    dot = sum(x * y for x, y in zip(a, b))
    mag_a = sum(x * x for x in a) ** 0.5
    mag_b = sum(x * x for x in b) ** 0.5
    return dot / (mag_a * mag_b)


sentences = [
    "a broken water pipe leaking badly",
    "damaged plumbing with a leak",        # same meaning, no shared words
    "a pen lying on a wooden desk",
    "broken pipe",                         # shares words with #1
]

vectors = [embed(s) for s in sentences]

print(f"Dimensions: {len(vectors[0])}")
print(f"First 5 numbers: {vectors[0][:5]}\n")

for i in range(len(sentences)):
    for j in range(i + 1, len(sentences)):
        score = similarity(vectors[i], vectors[j])
        print(f"{score:.3f}   {sentences[i][:32]:<34} | {sentences[j][:32]}")