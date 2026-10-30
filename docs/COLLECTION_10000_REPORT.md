# 10,000-document collection — launch report

The user explicitly authorized and requested starting a separate10,000NEW collection campaign on2026-10-29. See [scope, implementation, evidence and runbook](collection_10000/README.md). Completion is pending; live state is authoritative.

Baseline:1,229documents/3,557identifiers/3,104contents;2,705verified/399needs_review;24historicalfailedjobs. P6.1 actually completed150NEW/1,122HTTP,0technicalfailures; its historical report remains unchanged.

Campaign `corpus-10000-20261029`:10,000NEW target,120,000HTTP,25,000source selections,7days;1worker/>=2,000ms,run<=30/context<=400. Fixed144monthlyqueries(nat/loc/oliy,2021–2024,statusY), bounded discovery, automatic integrity-gated stages. No unrestricted crawl, scheduling, raw retention or new extraction technology.

Migration000012 only replaces two campaign CHECK constraints, preserving existing rows and per-pilot limits. Extended service limits require an exact approved profile. Generic unapproved10,000creation is rejected. Full results and process startup evidence follow in collection_10000/.

## Actual launch

Full231tests/1,077assertions PASS;Pint PASS. Migration applied non-destructively; preservation errors[]. At18:32:50Asia/Tashkent, detached workerPID2168363/controllerPID2168364started and remained alive across subsequent commands. Actual source HTTP200requests observed,statusrunning. Laravel owns subsequent discovery/import/audits/stages; Codex supervision is unnecessary. This is launch verification, not final10,000completion. See collection_10000/launch_status.json and processes.json; inspect current Artisan status for newer counts.

Latest launch verification at2026-10-29T18:34:31+05:00: statusrunning,5NEW/10000,total1234documents,11newcontent representations,HTTP50/120000,technicalfaileditems0,preservation errors[]. Running collection, not final completion.
