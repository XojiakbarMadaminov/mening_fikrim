# P4 evidence

[P4_REPORT](../P4_REPORT.md) contains the implementation, policies, commands, real results and limitations. Raw originals are not retained. Default PHPUnit never contacts Lex.uz; `live.php` is an explicit bounded stage driver, not a schedule or unlimited importer.

| Evidence | Meaning |
|---|---|
| [INITIAL_FLOW](INITIAL_FLOW.md) | Before-code flow and exact quality-persistence block |
| [baseline tests](baseline_tests.txt), [final tests](final_tests.txt), [JUnit](final_junit.xml) |110/443 baseline;130/581 final real PostgreSQL suite |
| [P0 regression](p0_regression.txt), [P0.1 regression](p01_regression.txt) |16+37 offline legacy tests, localhost faults only |
| [migration](development_migration.txt), [status](development_migration_status.txt), [test status](testing_migration_status.txt) | Non-destructive content extension,9migrations Ran |
| [migration preservation](migration_preservation.json), [final preservation](preservation.json) | Original row fields intact at migration; original40/all63content hashes stable; old24failedjobs retained |
| [review targets](review_ids.json), [review results](review_results.json) |13old review groups all persisted;12needs_review+1source-changed verified |
| [first run8](run_8.json), [intermediate run9](run_9.json), [final run10](run_10.json) | Real run/item/content-hash/quality manifests; source warnings not hidden |
| [primary summary](primary_summary.txt), [final summary](final_summary.txt) |16canonical groups, successful collection versus quality counters |
| [numeric failure](numeric_diagnostic_failure.txt), [final tests](final_tests.txt) | Failing reproduction and fixed JSON numeric metadata idempotence |
| [control card observations](control_card_observations.jsonl) | Final fresh source metadata; does not prove intermediate source conflict cause |
| [HTTP](http_stats.json), [requests](requests.jsonl), [campaign](campaign_summary.json) |286real request, minimum2.000029156s,400budget,1worker |
| [recheck dry-run](recheck_dry_run.txt), [apply](recheck_apply.txt), [repeat](recheck_repeat.txt), [integrity](recheck_integrity.json) | No network,21review retained;63hash/fetch-time unchanged |
| [historical integrity](historical_integrity.json), [changed files](changed_files.json) |433old artifacts unchanged;11implementation/test files changed |
| [final checks](final_checks.json), [cleanup](cleanup.json), [links](documentation_links.json) | Fixed DBs/table count/flags/queue/permissions/temp/link QA |
| [Pint](pint_final.txt), [Composer](composer_validate.txt), [platform](platform_requirements.txt) | Final checks; Composer internal PHP8.5 deprecation may appear with exit0 |
| [terminal](final_terminal.txt) | Consolidated final statistics and all13target rows |

Intermediate failing logs remain available; only `final_tests.txt/final_junit.xml` is the final Laravel result. Existing tests were adapted only for explicitly changed persistence/inactive behavior; validator and extraction engines remain unchanged.

Reproduce offline with `vendor/bin/phpunit` and `vendor/bin/pint --test`. Test lifecycle is destructive only in the actual guarded `mening_fikrim_active_test`; never run suites concurrently on that database. Development data must not be reset. P0/P0.1 replay used the preserved rootless virtualenv; their historical README lists portable dependency setup. New source captures need a newly authorized bounded run; do not overwrite old stage evidence.

Current read-only report: `php artisan documents:sync lex --report=10`. Network-free bounded recheck: `php artisan documents:quality-recheck lex --status=needs_review --limit=20 --dry-run`; remove dry-run to apply. Exact scraper/worker commands are in P4_REPORT and project README.1000/full/cron/newOCR/AI/frontend remain out of scope.
