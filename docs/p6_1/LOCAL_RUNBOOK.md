# Ubuntu local operator runbook

Project directory: `/home/xojiakbar/PhpstormProjects/mening_fikrim`.
Campaign: `p6-1-completion-150-20261029`. It is already prepared paused; source traffic and the150-document collection have NOT started. P6 remains terminal. Run only the commands for the new campaign. No Supervisor/systemd or scheduler is required.

## Before starting

```bash
cd /home/xojiakbar/PhpstormProjects/mening_fikrim
php artisan documents:campaign status --id=p6-1-completion-150-20261029 --json
php artisan documents:campaign run --id=p6-1-completion-150-20261029 --dry-run
php artisan migrate:status
```

Expect prepared=true,paused,new_canonical0,http.used0,max3500,stages15/50/100/150. Corpus remains1079documents/2763contents unless unrelated imports occur. Source raw retention and scheduling must remain disabled. Do not reset/truncate data or clear application cache to reset budgets. If configuration was cached, run `php artisan config:clear` before starting processes so per-command live flags and the reporting token setting are read. This clears compiled configuration, not the PostgreSQL campaign registry.

## Terminal1 — campaign-aware Laravel queue worker

```bash
cd /home/xojiakbar/PhpstormProjects/mening_fikrim
SCRAPING_LIVE_ENABLED=true php artisan documents:campaign worker --id=p6-1-completion-150-20261029
```

This waits safely while prepared/paused, then consumes the existing database `scraping` queue. It wraps Laravel queue:work with tries3,timeout900,retry_after960,memory512MiB,max-jobs1 per ownership handover. It remains running between bounded jobs/batches. **Do not start a plain queue:work alongside it:** that worker lacks the campaign ownership handshake. Do not start another campaign worker; the global worker mutex rejects it.

## Terminal2 — one autonomous controller

```bash
cd /home/xojiakbar/PhpstormProjects/mening_fikrim
SCRAPING_LIVE_ENABLED=true php artisan documents:campaign run --id=p6-1-completion-150-20261029
```

First `run` starts the prepared campaign's execution window. Laravel now discovers, dispatches batches, waits, audits, advances stages and completes/stops deterministically. No Codex/manual next-batch command is needed. If only the controller is running, queued work remains pending until its worker is started. An empty queue is not itself completion.

## Monitor and read reports (third terminal if needed)

```bash
php artisan documents:campaign status --id=p6-1-completion-150-20261029 --json
php artisan documents:campaign report --id=p6-1-completion-150-20261029 --format=json
php artisan documents:campaign report --id=p6-1-completion-150-20261029 --format=markdown
php artisan documents:campaign report --id=p6-corpus-1000-20261029 --format=json
```

Current metrics derive from PostgreSQL. The final snapshot is also stored in the campaign's `final-report` checkpoint automatically. Current cohort content counts describe stored representations on canonicals created by the campaign; they are not historical update-event counts. Elapsed window includes pauses/waiting; unavailable active-time metrics are marked rather than fabricated.

## Pause, graceful process stop and recovery

```bash
php artisan documents:campaign pause --id=p6-1-completion-150-20261029
php artisan documents:campaign status --id=p6-1-completion-150-20261029 --json
```

Wait for paused (pausing can mean an active HTTP/parse job is settling). Ctrl+C/SIGTERM on the controller requests a safe-boundary pause; do not repeatedly kill it. Ctrl+C on the worker finishes its current job then exits. After pause, stopping both terminal processes is safe; pending receipts remain durable. For a campaign whose actual status is recoverably paused:

```bash
php artisan documents:campaign resume --id=p6-1-completion-150-20261029
# Restart the worker if it exited, then restart the same controller:
SCRAPING_LIVE_ENABLED=true php artisan documents:campaign run --id=p6-1-completion-150-20261029
```

After unexpected termination of a still-running campaign, restart the same worker/controller commands. Do not create a replacement campaign, retry historical failed jobs or reset budgets. Queue reservations wait out retry_after; an unobserved reserved HTTP token remains spent. The six-hour execution window is conservative: subsequent pauses/downtime count and resume never refreshes it.

For a terminal operator stop:

```bash
php artisan documents:campaign stop --id=p6-1-completion-150-20261029 --reason=operator_requested
```

Stopped/completed campaigns cannot ordinarily resume.403/429,repeated source failures,integrity/budget/scope failures require a decision outside ordinary resume. Do not reopen P6. Unused budget is not authority to expand scope.

## Optional tmux

`tmux` was not installed in the inspected environment; the examples below are documented, not claimed as executed. Install it separately if wanted (`sudo apt-get install tmux`). Two ordinary terminals are sufficient and the two-process flow was tested offline.

```bash
tmux new-session -d -s mf-p61 -n worker -c /home/xojiakbar/PhpstormProjects/mening_fikrim 'SCRAPING_LIVE_ENABLED=true php artisan documents:campaign worker --id=p6-1-completion-150-20261029'
tmux new-window -t mf-p61 -n controller -c /home/xojiakbar/PhpstormProjects/mening_fikrim 'SCRAPING_LIVE_ENABLED=true php artisan documents:campaign run --id=p6-1-completion-150-20261029'
tmux set-window-option -t mf-p61:worker remain-on-exit on
tmux set-window-option -t mf-p61:controller remain-on-exit on
tmux attach-session -t mf-p61
```

Detach: Ctrl+b,then d. Reattach: `tmux attach-session -t mf-p61`. Select worker/controller with Ctrl+b then0/1. Use the Artisan status/report commands above to inspect current progress. Pause via Artisan before stopping processes; Ctrl+C in the relevant window stops gracefully. After an exited controller and eligible resume, restart its command in a terminal or use `tmux respawn-window -t mf-p61:controller -c /home/xojiakbar/PhpstormProjects/mening_fikrim 'SCRAPING_LIVE_ENABLED=true php artisan documents:campaign run --id=p6-1-completion-150-20261029'`. Without `-k`, tmux refuses to replace a still-running window. Restart an exited worker similarly with the worker action. Do not kill an active tmux session as a substitute for pausing.

Tmux survives terminal detach, not machine shutdown or worker crashes. PostgreSQL progress survives; after restart the operator must restart eligible processes. There is no automatic process supervisor in this stage.

To read the immutable stored terminal snapshot separately from current computed metrics:

```bash
php artisan documents:campaign report --id=p6-1-completion-150-20261029 --snapshot=final --format=markdown
```

Before termination it explicitly reports `available=false`; it never fabricates a final report. Older P6 campaigns without this registry snapshot keep their frozen file reports and are not backfilled or modified. The execution window is checked before new requests/controller work; an already in-flight bounded job may need a safe settling period at a stop boundary.
