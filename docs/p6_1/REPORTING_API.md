# Authenticated read-only corpus reporting

Implemented through Laravel request authentication (`corpus-reports`) and Gate `corpus.read`. No users table/login existed, so this is a narrowly scoped machine identity. Empty `CORPUS_REPORT_TOKEN_SHA256` denies every request; no default token is shipped. Use a high-entropy bearer token of at least32characters; configure only its lowercase SHA256 digest. Never put the raw token in Git, prompts, application logs or frontend code. Routes are GET/HEAD only and rate limited60/minute per identity. There are no campaign-control, SQL, shell or arbitrary-file endpoints. Unexpected report errors return a generic503 even in debug mode.

## Local setup

Generate a private curl configuration outside the repository; only the digest is printed:

```bash
umask 077
install -d -m 700 "$HOME/.config/mening-fikrim"
php -r '$path=getenv("HOME")."/.config/mening-fikrim/report.curl";$token=bin2hex(random_bytes(32));file_put_contents($path,"header = \"Authorization: Bearer ".$token."\"\n");chmod($path,0600);echo hash("sha256",$token),PHP_EOL;'
```

Put the printed digest in local ignored `.env` as `CORPUS_REPORT_TOKEN_SHA256=...`, then `php artisan config:clear` before starting/restarting the local HTTP server. Keep this server on localhost unless a separate secure deployment is authorized:

```bash
php artisan serve --host=127.0.0.1 --port=8000
curl --config "$HOME/.config/mening-fikrim/report.curl" http://127.0.0.1:8000/api/admin/corpus/statistics
```

This configuration was not automatically written to the operator's environment; API feature tests exercise valid/invalid credentials. No supervisor, deployment, public exposure or frontend was configured.

## Endpoints

| GET path (prefix `/api/admin/corpus`) | Result |
|---|---|
| `/campaigns` | Durable campaign registry; cached progress lag explicitly noted |
| `/campaigns/{id}` | Actual progress, budgets, source/canonical/content distinctions |
| `/campaigns/{id}/report?format=json` | Curated consistent JSON snapshot |
| `/campaigns/{id}/report?format=markdown` | Human-readable Markdown |
| `/statistics` | Corpus/language/availability/quality/legal counts,today,last24h,archive |
| `/quality` | Per-language quality and retained issue frequencies |
| `/discovery?campaign_id=...` | Fixed scope and reconciled/incomplete partition evidence |
| `/tools` | Approved read-only tool catalog |
| `/tools/{tool}` | Bounded declared arguments only |
| `/assistant?question=...&campaign_id=...` | Uzbek deterministic answer plus actual tool evidence |

Every snapshot includes observation time; campaign results include ID. PostgreSQL transactions use REPEATABLE READ / READ ONLY; even an accidental writer is rejected. Comparisons retain individual snapshot timestamps rather than pretending separate reports are atomic. Raw exception/SQL, queue payloads, credentials, owner tokens, arbitrary paths and source bodies are not returned. Global failed jobs and campaign-associated failed jobs/items are separate. Metadata-only no-body outcomes do not imply an available translation failed. Current stored cohorts do not invent historical content-update events. Missing active-processing timing and legacy scope configuration are explicitly unavailable; elapsed time is not active time. Archive enabled/permission/blob/byte values are read from current config/database; offline fixture certification is not a runtime metric and is linked to test evidence instead of hardcoded.

## Assistant tools

- `get_campaign_status(campaign_id?)`
- `get_campaign_statistics(campaign_id?)`
- `get_quality_summary()`
- `get_discovery_coverage(campaign_id?)`
- `get_failed_import_summary(campaign_id?,limit=20)`;limit1..30
- `compare_campaigns(campaign_ids[])`;2..5unique valid IDs
- `get_corpus_summary()`

Unknown tools/arguments, SQL, shell/file access and campaign mutation functions are rejected. No generated SQL is executed. The adapter exposes no database credentials or write capability. Existing report queries are fixed application code with bound parameters. The same allowlist can be consumed by an external AI tool-calling client using this read-only credential.

No supported LLM/Ollama integration exists in composer/app configuration. P6.1 therefore installs no model/provider. `/assistant` supports bounded Uzbek reporting intents (bugun,24soat,jami,needs_review,sifat,PDF,xatolar,campaign,budget,taqqosla), explicitly returns `mode=deterministic_read_only_adapter; no LLM invoked`, and rejects unsupported questions with422. It is not a general chat model or AI legal adviser. An unavailable model cannot affect scraping or deterministic JSON/Markdown reports.

```bash
curl --config "$HOME/.config/mening-fikrim/report.curl" 'http://127.0.0.1:8000/api/admin/corpus/tools/get_campaign_status?campaign_id=p6-1-completion-150-20261029'
curl --config "$HOME/.config/mening-fikrim/report.curl" --get --data-urlencode 'question=Bugun nechta hujjat import qilindi?' http://127.0.0.1:8000/api/admin/corpus/assistant
curl --config "$HOME/.config/mening-fikrim/report.curl" --get --data-urlencode 'campaign_ids[]=p6-corpus-1000-20261029' --data-urlencode 'campaign_ids[]=p6-1-completion-150-20261029' http://127.0.0.1:8000/api/admin/corpus/tools/compare_campaigns
```

Global discovery completeness, unknown language availability, human legal accuracy and OCR certification must not be inferred from these counts. `verified` means existing automated rules passed. Legacy P5/P5.1 cache-only campaigns are not fabricated as permanent registry records; their frozen reports remain historical evidence.

Stored terminal snapshots are separately available through `/campaigns/{id}/report?snapshot=final` (and optional `format=markdown`), CLI `--snapshot=final`, or `get_campaign_statistics(campaign_id,snapshot=final)`. The default remains current metrics. The response distinguishes read observation time from the immutable report's original observation time. A prepared/running or legacy campaign without a stored snapshot returns `available=false,report=null`; it is never manufactured from a guessed success. Repeated finalization preserves the first terminal snapshot.
