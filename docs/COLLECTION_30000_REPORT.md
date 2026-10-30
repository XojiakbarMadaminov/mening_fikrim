# 30,000-document collection — implementation and launch report

User authorized a new30,000NEW target on2026-10-30. Existing corpus:3,667documents/11,399identifiers/8,922contents (7,145verified/1,777needs_review);24historicalfailedjobs. Previous10,000campaign remains stopped at2,438NEW due to single-day discovery overflow.

New campaign corpus-30000-20261030:30,000additional NEW;360,000HTTP/75,000selections/21days. One worker/>=2,000ms,run<=30/context<=400,finite397queries. Single-day64-page pagination and durable non-blocking coverage gaps; all access/budget/identity/integrity stops retained. Existing10,000campaign is preserved, not resumed. Migration000013 is non-destructive and exact-profile bounded.

See [implementation, fixed scope, limits, test evidence, operational commands and limitations](collection_30000/README.md). Launch and actual persistence verification are appended after tests/audit. No claim of30,000completion is made.

## Verified preflight and launch

Full241tests/1,123assertions PASS (100.321s); Pint PASS. Migration000013applied in5.26ms,13total migrations/16tables. Main preservation audit errors[] before launch; historical campaign rows/content hashes retained. Started at2026-10-30 10:03:59Asia/Tashkent using detached nohup sessions: workerPID2961657,controllerPID2961658. Both remained alive across subsequent commands. First live scope is loc30.12.2021. Actual source enumeration/persistence is appended separately; this startup is not a30,000completion claim.

## Actual source and persistence verification

At2026-10-30T10:05:30+05:00, real loc30.12.2021 enumerated all397/397distinct IDs in20pages,zero restarts,complete=true. Existing source URL and committed page-prefix fingerprints are retained in problem_day_live.json. This is an actual live result using the shared budget/pacer, not a synthetic-fixture claim.

At2026-10-30T10:05:03+05:00: statusrunning,2NEW/30000,total3669documents,4new stored representations,HTTP29/360000,technicalfaileditems0. Historical data/campaign preservation errors[];coverage gaps[]. Both detached actors remained alive. Counts continue changing; handoff_summary.json/launch_status.json are timestamped snapshots, not a final collection report.
