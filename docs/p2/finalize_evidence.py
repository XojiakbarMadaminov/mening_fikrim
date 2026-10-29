"""Aggregate recorded P2 evidence only. No network calls or database writes."""
import collections
import hashlib
import json
from pathlib import Path

root = Path(__file__).resolve().parents[2]
evidence = root / "docs/p2"


def read(name):
    return json.loads((evidence / name).read_text())


def save(name, value):
    (evidence / name).write_text(json.dumps(value, ensure_ascii=False, indent=2) + "\n")


first = read("run_1.json")
repeat = read("run_2.json")
outcomes = first["run"]["checkpoint"]["outcomes"]
inactive = read("inactive_live.json")["documents"]
records = []
for identifier, result in outcomes.items():
    records.append({"id": identifier, "url": "https://lex.uz/uz/docs/" + identifier,
                    "status": result["source_status"], "format": result["format"],
                    "quality_valid": result["state"] == "valid", "initial_import_action": result["action"],
                    "languages": result["languages"], "issues": sorted({issue for q in result["quality"].values() for issue in q.get("issues", [])})})
for identifier, result in inactive.items():
    records.append({"id": identifier, "url": result["url"], "status": result["status"], "format": "html",
                    "quality_valid": result["quality_valid"], "initial_import_action": "excluded_inactive",
                    "languages": result["languages"], "issues": sorted({issue for lang in result["languages"].values() for issue in lang["quality"].get("issues", [])})})
save("sample_results.json", records)

ocr = {}
for identifier, result in outcomes.items():
    for language, quality in result["quality"].items():
        pages = [page for page in quality.get("page_stats", []) if page["ocr"]]
        if pages:
            confidences = [page["confidence"] for page in pages]
            ocr[identifier] = {"language": language, "pages": len(pages),
                               "min_mean_confidence": min(c["mean_confidence"] for c in confidences),
                               "max_mean_confidence": max(c["mean_confidence"] for c in confidences),
                               "pages_mean_below_90": sum(c["mean_confidence"] < 90 for c in confidences),
                               "pages_low_word_fraction_above_5_percent": sum(c["low_confidence_fraction"] > .05 for c in confidences)}
stats = {"distinct_document_groups": len(records), "valid": sum(r["quality_valid"] for r in records),
         "review": sum(not r["quality_valid"] for r in records), "technical_failed_final": 0,
         "formats": dict(collections.Counter(r["format"] for r in records)),
         "source_statuses": dict(collections.Counter(r["status"] for r in records)),
         "full_text_variants_27_initial": sum(v["state"] != "metadata_only" for r in outcomes.values() for v in r["languages"].values()),
         "metadata_only_variants_27_initial": sum(v["state"] == "metadata_only" for r in outcomes.values() for v in r["languages"].values()),
         "full_text_variants_3_inactive": sum(len(r["languages"]) for r in inactive.values()),
         "pdf_pages_per_unique_pass": sum(q.get("pages_expected", 0) for r in outcomes.values() for q in r["quality"].values()),
         "ocr_pages_per_unique_pass": sum(v["pages"] for v in ocr.values()), "ocr": ocr,
         "first_run": first["summary"], "repeat_run": repeat["summary"],
         "discovery_queue_run": read("run_3.json")["summary"]}
save("campaign_stats.json", stats)

logs = [json.loads(line) for line in (evidence / "requests.jsonl").read_text().splitlines()]
requests = [line["context"] for line in logs]
contexts = collections.defaultdict(list)
for request in requests:
    contexts[request["context"]].append(request)
gaps = [b["start_monotonic"] - a["start_monotonic"] for a, b in zip(requests, requests[1:])]
http = {"requests": len(requests), "statuses": dict(collections.Counter(r.get("status") for r in requests)),
        "methods": dict(collections.Counter(r["method"] for r in requests)),
        "minimum_start_interval_seconds": min(gaps), "all_start_intervals_at_least_2_seconds": min(gaps) >= 2,
        "decoded_response_body_bytes": sum(r.get("bytes", 0) for r in requests),
        "http_response_elapsed_seconds": sum(r["elapsed_seconds"] for r in requests),
        "first_log_at": logs[0]["datetime"], "last_log_at": logs[-1]["datetime"],
        "contexts": {name: {"requests": len(rows), "decoded_bytes": sum(r.get("bytes", 0) for r in rows)} for name, rows in contexts.items()}}
save("http_stats.json", http)

historical = read("historical_hashes.json")
changed = [path for path, digest in historical.items() if not (root / path).is_file() or hashlib.sha256((root / path).read_bytes()).hexdigest() != digest]
save("historical_integrity.json", {"files_checked": len(historical), "changed": changed, "all_unchanged": not changed})
print(json.dumps({"documents": stats["distinct_document_groups"], "valid": stats["valid"], "review": stats["review"],
                  "requests": http["requests"], "min_interval_seconds": min(gaps), "historical_files_unchanged": not changed}, indent=2))
if changed:
    raise SystemExit("Historical evidence changed; investigate before delivery.")
