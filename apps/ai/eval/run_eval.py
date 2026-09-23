"""Score the golden set against the live /api/ask endpoint.

Measures retrieval and generation separately, so a failure can be traced to
"wrong photos fetched" versus "right photos, wrong answer".
"""

import json
import os
import sys
import time
from datetime import datetime
from pathlib import Path

import httpx
from dotenv import load_dotenv

HERE = Path(__file__).parent
load_dotenv(HERE.parent / ".env")

API = os.getenv("EVAL_API_URL", "http://localhost:8000/api")
TOKEN = os.getenv("EVAL_TOKEN")


def ratio(num: int, den: int):
    return num / den if den else None


def mean(values):
    vals = [v for v in values if v is not None]
    return sum(vals) / len(vals) if vals else None


def pct(v):
    return "  —  " if v is None else f"{v * 100:5.1f}%"


def score(case: dict, res: dict, secs: float) -> dict:
    expected = set(case["expected_images"])
    retrieved = set(res.get("retrieved_image_ids", []))
    cited = {img["id"] for img in res.get("cited_images", [])}
    answer = res.get("answer", "")

    row = {
        "id": case["id"],
        "question": case["question"],
        "should_answer": case["should_answer"],
        "answered": res.get("answered"),
        "decision_ok": res.get("answered") == case["should_answer"],
        "retrieved": sorted(retrieved),
        "cited": sorted(cited),
        "expected": sorted(expected),
        "answer": answer,
        "seconds": round(secs, 2),
        "tokens": res.get("usage", {}).get("total_tokens", 0),
    }

    if case["should_answer"]:
        row["retrieval_recall"] = ratio(len(expected & retrieved), len(expected))
        row["citation_recall"] = ratio(len(expected & cited), len(expected))
        row["citation_precision"] = ratio(len(expected & cited), len(cited)) if cited else 0.0
    else:
        # For a question with no answer, citing anything is a fabricated source.
        row["citation_leak"] = bool(cited)

    if "expected_answer_contains" in case:
        row["contains_ok"] = case["expected_answer_contains"].lower() in answer.lower()

    return row


def main():
    if not TOKEN:
        sys.exit("Set EVAL_TOKEN in apps/ai/.env — a Sanctum token from POST /api/login")

    cases = json.loads((HERE / "golden.json").read_text())
    rows = []

    headers = {"Accept": "application/json", "Authorization": f"Bearer {TOKEN}"}

    with httpx.Client(headers=headers, timeout=300) as client:
        for i, case in enumerate(cases, 1):
            t0 = time.perf_counter()
            try:
                r = client.post(f"{API}/ask", json={"question": case["question"]})
                r.raise_for_status()
                row = score(case, r.json(), time.perf_counter() - t0)
            except Exception as exc:
                row = {"id": case["id"], "question": case["question"], "error": str(exc)}

            rows.append(row)

            if "error" in row:
                mark = "ERR "
            else:
                ok = row["decision_ok"] and not row.get("citation_leak") and row.get("contains_ok", True)
                mark = "PASS" if ok else "FAIL"

            print(f"[{i:2}/{len(cases)}] {mark}  {case['id']:<20} {row.get('seconds', '')}s")

            # Sequential with a pause — the free tier rate-limits bursts, and a
            # 429 mid-run would be scored as a model failure when it isn't one.
            time.sleep(20)

    ok_rows = [r for r in rows if "error" not in r]
    answerable = [r for r in ok_rows if r["should_answer"]]
    refusals = [r for r in ok_rows if not r["should_answer"]]
    counting = [r for r in ok_rows if "contains_ok" in r]

    summary = {
        "cases": len(rows),
        "errors": len(rows) - len(ok_rows),
        "decision_accuracy": mean([1.0 if r["decision_ok"] else 0.0 for r in ok_rows]),
        "retrieval_recall": mean([r["retrieval_recall"] for r in answerable]),
        "citation_precision": mean([r["citation_precision"] for r in answerable]),
        "citation_recall": mean([r["citation_recall"] for r in answerable]),
        "refusal_accuracy": mean([1.0 if not r["answered"] else 0.0 for r in refusals]),
        "citation_leaks": sum(1 for r in refusals if r["citation_leak"]),
        "count_accuracy": mean([1.0 if r["contains_ok"] else 0.0 for r in counting]),
        "avg_seconds": mean([r["seconds"] for r in ok_rows]),
        "total_tokens": sum(r["tokens"] for r in ok_rows),
    }

    print("\n" + "=" * 52)
    print(f"  Decision accuracy   {pct(summary['decision_accuracy'])}   answered vs refused correctly")
    print(f"  Retrieval recall    {pct(summary['retrieval_recall'])}   right photos reached the model")
    print(f"  Citation precision  {pct(summary['citation_precision'])}   cited photos were correct")
    print(f"  Citation recall     {pct(summary['citation_recall'])}   correct photos were cited")
    print(f"  Refusal accuracy    {pct(summary['refusal_accuracy'])}   said 'not in your photos'")
    print(f"  Citation leaks      {summary['citation_leaks']:>5}    sources cited for unanswerable Qs")
    print(f"  Count accuracy      {pct(summary['count_accuracy'])}")
    print(f"  Avg latency         {summary['avg_seconds'] or 0:5.1f}s")
    print(f"  Total tokens        {summary['total_tokens']:>6}")
    print("=" * 52)

    failures = [r for r in ok_rows
                if not r["decision_ok"] or r.get("citation_leak") or r.get("contains_ok") is False
                or (r["should_answer"] and (r["citation_recall"] or 0) < 1)]
    if failures:
        print("\nWorth a look:")
        for r in failures:
            print(f"  • {r['id']}: expected {r['expected']}  retrieved {r['retrieved']}  cited {r['cited']}")
            print(f"      “{r['answer'][:140]}”")

    out = HERE / "results"
    out.mkdir(exist_ok=True)
    path = out / f"{datetime.now():%Y%m%d-%H%M%S}.json"
    path.write_text(json.dumps({"summary": summary, "rows": rows}, indent=2))
    print(f"\nSaved {path.relative_to(HERE.parent)}")


if __name__ == "__main__":
    main()