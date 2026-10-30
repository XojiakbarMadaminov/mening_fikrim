# Actual database schema

Generated from local PostgreSQL introspection and applied Laravel migrations at 2026-10-30T10:03:37+05:00. Database: mening_fikrim_active. Authorized 30,000 collection adds migration000013 changing campaign CHECK constraints; no new table or column. No users/auth table exists; reporting uses a read-only Laravel request guard. No section/chunk/vector tables exist.

## cache

| Column | PostgreSQL type | Nullable | Default |
|---|---|---|---|
| key | character varying (varchar) | NO | — |
| value | text (text) | NO | — |
| expiration | bigint (int8) | NO | — |

Constraints and indexes (actual definitions):

```sql
cache_pkey: PRIMARY KEY (key)
CREATE INDEX cache_expiration_index ON public.cache USING btree (expiration);
CREATE UNIQUE INDEX cache_pkey ON public.cache USING btree (key);
```

## cache_locks

| Column | PostgreSQL type | Nullable | Default |
|---|---|---|---|
| key | character varying (varchar) | NO | — |
| owner | character varying (varchar) | NO | — |
| expiration | bigint (int8) | NO | — |

Constraints and indexes (actual definitions):

```sql
cache_locks_pkey: PRIMARY KEY (key)
CREATE INDEX cache_locks_expiration_index ON public.cache_locks USING btree (expiration);
CREATE UNIQUE INDEX cache_locks_pkey ON public.cache_locks USING btree (key);
```

## document_contents

| Column | PostgreSQL type | Nullable | Default |
|---|---|---|---|
| id | bigint (int8) | NO | nextval('document_contents_id_seq'::regclass) |
| document_id | bigint (int8) | NO | — |
| language | character varying (varchar) | NO | — |
| title | text (text) | NO | — |
| content | text (text) | NO | — |
| content_hash | character (bpchar) | NO | — |
| last_fetched_at | timestamp with time zone (timestamptz) | YES | — |
| created_at | timestamp with time zone (timestamptz) | YES | — |
| updated_at | timestamp with time zone (timestamptz) | YES | — |
| quality_status | character varying (varchar) | NO | 'needs_review'::character varying |
| quality_issues | jsonb (jsonb) | NO | '[]'::jsonb |
| quality_checked_at | timestamp with time zone (timestamptz) | YES | — |
| extraction_metadata | jsonb (jsonb) | NO | '{}'::jsonb |
| pending_candidate | jsonb (jsonb) | YES | — |

Constraints and indexes (actual definitions):

```sql
contents_candidate_check: CHECK (((pending_candidate IS NULL) OR COALESCE(((jsonb_typeof(pending_candidate) = 'object'::text) AND jsonb_exists_all(pending_candidate, ARRAY['quality_status'::text, 'content'::text, 'content_hash'::text, 'quality_issues'::text, 'extraction_metadata'::text]) AND ((pending_candidate ->> 'quality_status'::text) = 'needs_review'::text) AND (jsonb_typeof((pending_candidate -> 'content'::text)) = 'string'::text) AND (jsonb_typeof((pending_candidate -> 'quality_issues'::text)) = 'array'::text) AND (jsonb_typeof((pending_candidate -> 'extraction_metadata'::text)) = 'object'::text) AND (length(btrim((pending_candidate ->> 'content'::text))) > 0) AND ((pending_candidate ->> 'content_hash'::text) ~ '^[a-f0-9]{64}$'::text)), false)))
contents_hash_check: CHECK ((content_hash ~ '^[a-f0-9]{64}$'::text))
contents_nonempty_check: CHECK ((length(btrim(content)) > 0))
contents_quality_json_check: CHECK (((jsonb_typeof(quality_issues) = 'array'::text) AND (jsonb_typeof(extraction_metadata) = 'object'::text) AND (((quality_status)::text <> 'verified'::text) OR (quality_issues = '[]'::jsonb))))
contents_quality_status_check: CHECK (((quality_status)::text = ANY ((ARRAY['verified'::character varying, 'needs_review'::character varying])::text[])))
document_contents_document_id_foreign: FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE
document_contents_document_id_language_unique: UNIQUE (document_id, language)
document_contents_pkey: PRIMARY KEY (id)
CREATE UNIQUE INDEX document_contents_document_id_language_unique ON public.document_contents USING btree (document_id, language);
CREATE UNIQUE INDEX document_contents_pkey ON public.document_contents USING btree (id);
CREATE INDEX document_contents_quality_status_id_index ON public.document_contents USING btree (quality_status, id);
```

## document_identifiers

| Column | PostgreSQL type | Nullable | Default |
|---|---|---|---|
| id | bigint (int8) | NO | nextval('document_identifiers_id_seq'::regclass) |
| document_id | bigint (int8) | NO | — |
| source_id | bigint (int8) | NO | — |
| external_id | character varying (varchar) | NO | — |
| language | character varying (varchar) | YES | — |
| source_url | text (text) | YES | — |
| metadata | jsonb (jsonb) | YES | — |
| created_at | timestamp with time zone (timestamptz) | YES | — |
| updated_at | timestamp with time zone (timestamptz) | YES | — |

Constraints and indexes (actual definitions):

```sql
document_identifiers_document_id_source_id_foreign: FOREIGN KEY (document_id, source_id) REFERENCES documents(id, source_id) ON DELETE CASCADE
document_identifiers_pkey: PRIMARY KEY (id)
document_identifiers_source_id_external_id_unique: UNIQUE (source_id, external_id)
document_identifiers_source_id_foreign: FOREIGN KEY (source_id) REFERENCES document_sources(id) ON DELETE RESTRICT
CREATE INDEX document_identifiers_document_id_index ON public.document_identifiers USING btree (document_id);
CREATE UNIQUE INDEX document_identifiers_pkey ON public.document_identifiers USING btree (id);
CREATE UNIQUE INDEX document_identifiers_source_id_external_id_unique ON public.document_identifiers USING btree (source_id, external_id);
```

## document_sources

| Column | PostgreSQL type | Nullable | Default |
|---|---|---|---|
| id | bigint (int8) | NO | nextval('document_sources_id_seq'::regclass) |
| name | character varying (varchar) | NO | — |
| slug | character varying (varchar) | NO | — |
| type | character varying (varchar) | NO | — |
| base_url | text (text) | YES | — |
| is_active | boolean (bool) | NO | true |
| created_at | timestamp with time zone (timestamptz) | YES | — |
| updated_at | timestamp with time zone (timestamptz) | YES | — |

Constraints and indexes (actual definitions):

```sql
document_sources_pkey: PRIMARY KEY (id)
document_sources_slug_unique: UNIQUE (slug)
sources_type_check: CHECK (((type)::text = ANY ((ARRAY['scraper'::character varying, 'manual'::character varying])::text[])))
CREATE UNIQUE INDEX document_sources_pkey ON public.document_sources USING btree (id);
CREATE UNIQUE INDEX document_sources_slug_unique ON public.document_sources USING btree (slug);
```

## documents

| Column | PostgreSQL type | Nullable | Default |
|---|---|---|---|
| id | bigint (int8) | NO | nextval('documents_id_seq'::regclass) |
| source_id | bigint (int8) | NO | — |
| external_id | character varying (varchar) | YES | — |
| title | text (text) | NO | — |
| document_type | character varying (varchar) | YES | — |
| document_number | character varying (varchar) | YES | — |
| status | character varying (varchar) | NO | — |
| issued_at | date (date) | YES | — |
| published_at | date (date) | YES | — |
| effective_at | date (date) | YES | — |
| source_url | text (text) | YES | — |
| metadata | jsonb (jsonb) | NO | '{}'::jsonb |
| last_checked_at | timestamp with time zone (timestamptz) | YES | — |
| created_at | timestamp with time zone (timestamptz) | YES | — |
| updated_at | timestamp with time zone (timestamptz) | YES | — |

Constraints and indexes (actual definitions):

```sql
documents_id_source_id_unique: UNIQUE (id, source_id)
documents_pkey: PRIMARY KEY (id)
documents_source_id_external_id_unique: UNIQUE (source_id, external_id)
documents_source_id_foreign: FOREIGN KEY (source_id) REFERENCES document_sources(id) ON DELETE RESTRICT
documents_status_check: CHECK (((status)::text = ANY ((ARRAY['active'::character varying, 'pending'::character varying, 'inactive'::character varying])::text[])))
CREATE UNIQUE INDEX documents_id_source_id_unique ON public.documents USING btree (id, source_id);
CREATE UNIQUE INDEX documents_pkey ON public.documents USING btree (id);
CREATE UNIQUE INDEX documents_source_id_external_id_unique ON public.documents USING btree (source_id, external_id);
CREATE INDEX documents_source_id_status_last_checked_at_index ON public.documents USING btree (source_id, status, last_checked_at);
CREATE INDEX documents_status_effective_at_index ON public.documents USING btree (status, effective_at);
```

## failed_jobs

| Column | PostgreSQL type | Nullable | Default |
|---|---|---|---|
| id | bigint (int8) | NO | nextval('failed_jobs_id_seq'::regclass) |
| uuid | character varying (varchar) | NO | — |
| connection | character varying (varchar) | NO | — |
| queue | character varying (varchar) | NO | — |
| payload | text (text) | NO | — |
| exception | text (text) | NO | — |
| failed_at | timestamp without time zone (timestamp) | NO | CURRENT_TIMESTAMP |

Constraints and indexes (actual definitions):

```sql
failed_jobs_pkey: PRIMARY KEY (id)
failed_jobs_uuid_unique: UNIQUE (uuid)
CREATE INDEX failed_jobs_connection_queue_failed_at_index ON public.failed_jobs USING btree (connection, queue, failed_at);
CREATE UNIQUE INDEX failed_jobs_pkey ON public.failed_jobs USING btree (id);
CREATE UNIQUE INDEX failed_jobs_uuid_unique ON public.failed_jobs USING btree (uuid);
```

## job_batches

| Column | PostgreSQL type | Nullable | Default |
|---|---|---|---|
| id | character varying (varchar) | NO | — |
| name | character varying (varchar) | NO | — |
| total_jobs | integer (int4) | NO | — |
| pending_jobs | integer (int4) | NO | — |
| failed_jobs | integer (int4) | NO | — |
| failed_job_ids | text (text) | NO | — |
| options | text (text) | YES | — |
| cancelled_at | integer (int4) | YES | — |
| created_at | integer (int4) | NO | — |
| finished_at | integer (int4) | YES | — |

Constraints and indexes (actual definitions):

```sql
job_batches_pkey: PRIMARY KEY (id)
CREATE UNIQUE INDEX job_batches_pkey ON public.job_batches USING btree (id);
```

## jobs

| Column | PostgreSQL type | Nullable | Default |
|---|---|---|---|
| id | bigint (int8) | NO | nextval('jobs_id_seq'::regclass) |
| queue | character varying (varchar) | NO | — |
| payload | text (text) | NO | — |
| attempts | smallint (int2) | NO | — |
| reserved_at | integer (int4) | YES | — |
| available_at | integer (int4) | NO | — |
| created_at | integer (int4) | NO | — |

Constraints and indexes (actual definitions):

```sql
jobs_pkey: PRIMARY KEY (id)
CREATE UNIQUE INDEX jobs_pkey ON public.jobs USING btree (id);
CREATE INDEX jobs_queue_index ON public.jobs USING btree (queue);
```

## migrations

| Column | PostgreSQL type | Nullable | Default |
|---|---|---|---|
| id | integer (int4) | NO | nextval('migrations_id_seq'::regclass) |
| migration | character varying (varchar) | NO | — |
| batch | integer (int4) | NO | — |

Constraints and indexes (actual definitions):

```sql
migrations_pkey: PRIMARY KEY (id)
CREATE UNIQUE INDEX migrations_pkey ON public.migrations USING btree (id);
```

## scrape_campaign_checkpoints

| Column | PostgreSQL type | Nullable | Default |
|---|---|---|---|
| id | bigint (int8) | NO | nextval('scrape_campaign_checkpoints_id_seq'::regclass) |
| scrape_campaign_id | bigint (int8) | NO | — |
| name | character varying (varchar) | NO | — |
| bounds | jsonb (jsonb) | NO | — |
| state | jsonb (jsonb) | NO | — |
| version | integer (int4) | NO | 1 |
| owner_generation | integer (int4) | NO | — |
| created_at | timestamp with time zone (timestamptz) | YES | — |
| updated_at | timestamp with time zone (timestamptz) | YES | — |

Constraints and indexes (actual definitions):

```sql
scrape_campaign_checkpoints_pkey: PRIMARY KEY (id)
scrape_campaign_checkpoints_scrape_campaign_id_foreign: FOREIGN KEY (scrape_campaign_id) REFERENCES scrape_campaigns(id) ON DELETE RESTRICT
scrape_campaign_checkpoints_scrape_campaign_id_name_unique: UNIQUE (scrape_campaign_id, name)
CREATE UNIQUE INDEX scrape_campaign_checkpoints_pkey ON public.scrape_campaign_checkpoints USING btree (id);
CREATE UNIQUE INDEX scrape_campaign_checkpoints_scrape_campaign_id_name_unique ON public.scrape_campaign_checkpoints USING btree (scrape_campaign_id, name);
```

## scrape_campaigns

| Column | PostgreSQL type | Nullable | Default |
|---|---|---|---|
| id | bigint (int8) | NO | nextval('scrape_campaigns_id_seq'::regclass) |
| campaign_id | character varying (varchar) | NO | — |
| source_id | bigint (int8) | NO | — |
| campaign_type | character varying (varchar) | NO | 'corpus_pilot'::character varying |
| status | character varying (varchar) | NO | 'running'::character varying |
| target_new_canonical | integer (int4) | NO | — |
| baseline_max_id | bigint (int8) | NO | — |
| baseline_canonical_count | integer (int4) | NO | — |
| current_new_canonical_count | integer (int4) | NO | 0 |
| max_source_selections | integer (int4) | NO | — |
| selected_source_count | integer (int4) | NO | 0 |
| max_http_requests | integer (int4) | NO | — |
| used_http_requests | integer (int4) | NO | 0 |
| stage_target | integer (int4) | NO | — |
| owner_generation | integer (int4) | NO | 0 |
| owner_token | uuid (uuid) | YES | — |
| owner_backend_pid | integer (int4) | YES | — |
| owner_claimed_at | timestamp with time zone (timestamptz) | YES | — |
| last_request_started_at | timestamp with time zone (timestamptz) | YES | — |
| started_at | timestamp with time zone (timestamptz) | NO | — |
| paused_at | timestamp with time zone (timestamptz) | YES | — |
| completed_at | timestamp with time zone (timestamptz) | YES | — |
| stopped_at | timestamp with time zone (timestamptz) | YES | — |
| stop_reason | text (text) | YES | — |
| configuration_snapshot | jsonb (jsonb) | NO | — |
| state | jsonb (jsonb) | NO | '{}'::jsonb |
| created_at | timestamp with time zone (timestamptz) | YES | — |
| updated_at | timestamp with time zone (timestamptz) | YES | — |

Constraints and indexes (actual definitions):

```sql
campaigns_budget_check: CHECK ((((used_http_requests >= 0) AND (used_http_requests <= max_http_requests)) AND ((selected_source_count >= 0) AND (selected_source_count <= max_source_selections)) AND ((((campaign_type)::text = 'corpus_collection'::text) AND ((campaign_id)::text = 'corpus-30000-20261030'::text) AND ((max_http_requests >= 1) AND (max_http_requests <= 360000)) AND ((max_source_selections >= 1) AND (max_source_selections <= 75000))) OR (((campaign_type)::text = 'corpus_collection'::text) AND ((campaign_id)::text = 'corpus-10000-20261029'::text) AND ((max_http_requests >= 1) AND (max_http_requests <= 120000)) AND ((max_source_selections >= 1) AND (max_source_selections <= 25000))) OR (((campaign_type)::text <> 'corpus_collection'::text) AND ((max_http_requests >= 1) AND (max_http_requests <= 15000)) AND ((max_source_selections >= 1) AND (max_source_selections <= 3500))))))
campaigns_status_check: CHECK (((status)::text = ANY ((ARRAY['running'::character varying, 'pausing'::character varying, 'paused'::character varying, 'completed'::character varying, 'stopped'::character varying, 'failed'::character varying])::text[])))
campaigns_target_check: CHECK ((((current_new_canonical_count >= 0) AND (current_new_canonical_count <= target_new_canonical)) AND ((((campaign_type)::text = 'validation'::text) AND (target_new_canonical = 0) AND (stage_target = 0)) OR (((campaign_type)::text = 'corpus_collection'::text) AND ((campaign_id)::text = 'corpus-30000-20261030'::text) AND (target_new_canonical = 30000) AND ((stage_target >= 1) AND (stage_target <= target_new_canonical))) OR (((campaign_type)::text = 'corpus_collection'::text) AND ((campaign_id)::text = 'corpus-10000-20261029'::text) AND (target_new_canonical = 10000) AND ((stage_target >= 1) AND (stage_target <= target_new_canonical))) OR (((campaign_type)::text <> ALL ((ARRAY['validation'::character varying, 'corpus_collection'::character varying])::text[])) AND ((target_new_canonical >= 1) AND (target_new_canonical <= 1000)) AND ((stage_target >= 1) AND (stage_target <= target_new_canonical))))))
scrape_campaigns_campaign_id_unique: UNIQUE (campaign_id)
scrape_campaigns_pkey: PRIMARY KEY (id)
scrape_campaigns_source_id_foreign: FOREIGN KEY (source_id) REFERENCES document_sources(id) ON DELETE RESTRICT
CREATE UNIQUE INDEX campaigns_one_open_source ON public.scrape_campaigns USING btree (source_id) WHERE ((status)::text = ANY ((ARRAY['running'::character varying, 'pausing'::character varying, 'paused'::character varying])::text[]));
CREATE UNIQUE INDEX scrape_campaigns_campaign_id_unique ON public.scrape_campaigns USING btree (campaign_id);
CREATE UNIQUE INDEX scrape_campaigns_pkey ON public.scrape_campaigns USING btree (id);
CREATE INDEX scrape_campaigns_source_id_id_index ON public.scrape_campaigns USING btree (source_id, id);
```

## scrape_run_items

| Column | PostgreSQL type | Nullable | Default |
|---|---|---|---|
| id | bigint (int8) | NO | nextval('scrape_run_items_id_seq'::regclass) |
| scrape_run_id | bigint (int8) | NO | — |
| source_external_id | character varying (varchar) | NO | — |
| document_id | bigint (int8) | YES | — |
| status | character varying (varchar) | NO | 'discovered'::character varying |
| attempts | integer (int4) | NO | 0 |
| listing_status | character varying (varchar) | YES | — |
| action | character varying (varchar) | YES | — |
| review_reasons | jsonb (jsonb) | YES | — |
| language_results | jsonb (jsonb) | YES | — |
| outcome | jsonb (jsonb) | YES | — |
| discovery_signatures | jsonb (jsonb) | NO | '[]'::jsonb |
| queue_job_id | bigint (int8) | YES | — |
| dispatch_token | uuid (uuid) | YES | — |
| available_at | timestamp with time zone (timestamptz) | YES | — |
| lease_expires_at | timestamp with time zone (timestamptz) | YES | — |
| last_error | text (text) | YES | — |
| started_at | timestamp with time zone (timestamptz) | YES | — |
| finished_at | timestamp with time zone (timestamptz) | YES | — |
| created_at | timestamp with time zone (timestamptz) | YES | — |
| updated_at | timestamp with time zone (timestamptz) | YES | — |

Constraints and indexes (actual definitions):

```sql
items_attempts_check: CHECK ((attempts >= 0))
items_document_check: CHECK ((((status)::text <> ALL ((ARRAY['imported'::character varying, 'unchanged'::character varying, 'skipped_alias'::character varying])::text[])) OR (document_id IS NOT NULL)))
items_ledger_completion: TRIGGER DEFERRABLE INITIALLY DEFERRED
items_status_check: CHECK (((status)::text = ANY ((ARRAY['discovered'::character varying, 'queued'::character varying, 'processing'::character varying, 'retry_wait'::character varying, 'imported'::character varying, 'unchanged'::character varying, 'review'::character varying, 'failed'::character varying, 'skipped_alias'::character varying])::text[])))
items_terminal_check: CHECK ((((status)::text = ANY ((ARRAY['imported'::character varying, 'unchanged'::character varying, 'review'::character varying, 'failed'::character varying, 'skipped_alias'::character varying])::text[])) = (finished_at IS NOT NULL)))
scrape_run_items_document_id_foreign: FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE RESTRICT
scrape_run_items_pkey: PRIMARY KEY (id)
scrape_run_items_scrape_run_id_foreign: FOREIGN KEY (scrape_run_id) REFERENCES scrape_runs(id) ON DELETE CASCADE
scrape_run_items_scrape_run_id_source_external_id_unique: UNIQUE (scrape_run_id, source_external_id)
CREATE INDEX items_stale_processing ON public.scrape_run_items USING btree (lease_expires_at) WHERE ((status)::text = 'processing'::text);
CREATE UNIQUE INDEX scrape_run_items_pkey ON public.scrape_run_items USING btree (id);
CREATE INDEX scrape_run_items_scrape_run_id_document_id_index ON public.scrape_run_items USING btree (scrape_run_id, document_id);
CREATE UNIQUE INDEX scrape_run_items_scrape_run_id_source_external_id_unique ON public.scrape_run_items USING btree (scrape_run_id, source_external_id);
CREATE INDEX scrape_run_items_scrape_run_id_status_index ON public.scrape_run_items USING btree (scrape_run_id, status);
```

## scrape_runs

| Column | PostgreSQL type | Nullable | Default |
|---|---|---|---|
| id | bigint (int8) | NO | nextval('scrape_runs_id_seq'::regclass) |
| source_id | bigint (int8) | NO | — |
| mode | character varying (varchar) | NO | — |
| status | character varying (varchar) | NO | 'running'::character varying |
| processed_count | integer (int4) | NO | 0 |
| created_count | integer (int4) | NO | 0 |
| updated_count | integer (int4) | NO | 0 |
| failed_count | integer (int4) | NO | 0 |
| checkpoint | jsonb (jsonb) | YES | — |
| last_error | text (text) | YES | — |
| started_at | timestamp with time zone (timestamptz) | NO | — |
| finished_at | timestamp with time zone (timestamptz) | YES | — |

Constraints and indexes (actual definitions):

```sql
runs_completion_check: CHECK ((((status)::text <> 'completed'::text) OR ((failed_count = 0) AND ((checkpoint @> '{"quality_passed": true, "jobs_reconciled": true, "coverage_verified": true, "discovery_finished": true, "languages_complete": true}'::jsonb) IS TRUE))))
runs_counts_check: CHECK (((processed_count >= 0) AND (created_count >= 0) AND (updated_count >= 0) AND (failed_count >= 0) AND ((created_count + updated_count) <= processed_count) AND (failed_count <= processed_count)))
runs_ledger_completion: TRIGGER DEFERRABLE INITIALLY DEFERRED
runs_mode_check: CHECK (((mode)::text = ANY ((ARRAY['full'::character varying, 'daily'::character varying, 'recheck'::character varying])::text[])))
runs_status_check: CHECK (((status)::text = ANY ((ARRAY['running'::character varying, 'completed'::character varying, 'completed_with_errors'::character varying, 'failed'::character varying])::text[])))
scrape_runs_pkey: PRIMARY KEY (id)
scrape_runs_source_id_foreign: FOREIGN KEY (source_id) REFERENCES document_sources(id) ON DELETE RESTRICT
CREATE UNIQUE INDEX scrape_runs_pkey ON public.scrape_runs USING btree (id);
CREATE INDEX scrape_runs_source_id_started_at_index ON public.scrape_runs USING btree (source_id, started_at);
CREATE INDEX scrape_runs_source_id_status_index ON public.scrape_runs USING btree (source_id, status);
```

## source_archive_blobs

| Column | PostgreSQL type | Nullable | Default |
|---|---|---|---|
| id | bigint (int8) | NO | nextval('source_archive_blobs_id_seq'::regclass) |
| sha256 | character (bpchar) | NO | — |
| storage_disk | character varying (varchar) | NO | — |
| storage_path | character varying (varchar) | NO | — |
| format | character varying (varchar) | NO | — |
| content_type | character varying (varchar) | NO | — |
| file_size | bigint (int8) | NO | — |
| archived_at | timestamp with time zone (timestamptz) | NO | — |
| created_at | timestamp with time zone (timestamptz) | YES | — |
| updated_at | timestamp with time zone (timestamptz) | YES | — |

Constraints and indexes (actual definitions):

```sql
source_archive_blob_shape: CHECK (((sha256 ~ '^[a-f0-9]{64}$'::text) AND ((format)::text = ANY ((ARRAY['html'::character varying, 'pdf'::character varying])::text[])) AND (file_size > 0)))
source_archive_blobs_pkey: PRIMARY KEY (id)
source_archive_blobs_sha256_unique: UNIQUE (sha256)
source_archive_blobs_storage_disk_storage_path_unique: UNIQUE (storage_disk, storage_path)
CREATE UNIQUE INDEX source_archive_blobs_pkey ON public.source_archive_blobs USING btree (id);
CREATE UNIQUE INDEX source_archive_blobs_sha256_unique ON public.source_archive_blobs USING btree (sha256);
CREATE UNIQUE INDEX source_archive_blobs_storage_disk_storage_path_unique ON public.source_archive_blobs USING btree (storage_disk, storage_path);
```

## source_archive_observations

| Column | PostgreSQL type | Nullable | Default |
|---|---|---|---|
| id | bigint (int8) | NO | nextval('source_archive_observations_id_seq'::regclass) |
| observation_key | character (bpchar) | NO | — |
| capture_key | uuid (uuid) | NO | — |
| blob_id | bigint (int8) | YES | — |
| document_source_id | bigint (int8) | NO | — |
| document_id | bigint (int8) | YES | — |
| source_external_id | character varying (varchar) | YES | — |
| language | character varying (varchar) | YES | — |
| source_url | text (text) | NO | — |
| observed_sha256 | character (bpchar) | NO | — |
| file_size | bigint (int8) | NO | — |
| format | character varying (varchar) | NO | — |
| content_type | character varying (varchar) | NO | — |
| archive_status | character varying (varchar) | NO | — |
| failure_reason | character varying (varchar) | YES | — |
| fetched_at | timestamp with time zone (timestamptz) | NO | — |
| context | jsonb (jsonb) | NO | '{}'::jsonb |
| created_at | timestamp with time zone (timestamptz) | YES | — |
| updated_at | timestamp with time zone (timestamptz) | YES | — |

Constraints and indexes (actual definitions):

```sql
source_archive_observation_shape: CHECK (((observed_sha256 ~ '^[a-f0-9]{64}$'::text) AND ((format)::text = ANY ((ARRAY['html'::character varying, 'pdf'::character varying])::text[])) AND ((archive_status)::text = ANY ((ARRAY['archived'::character varying, 'disabled'::character varying, 'privacy_blocked'::character varying, 'failed'::character varying])::text[])) AND (jsonb_typeof(context) = 'object'::text) AND (((archive_status)::text <> 'archived'::text) OR (blob_id IS NOT NULL))))
source_archive_observations_blob_id_foreign: FOREIGN KEY (blob_id) REFERENCES source_archive_blobs(id) ON DELETE RESTRICT
source_archive_observations_document_id_foreign: FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE RESTRICT
source_archive_observations_document_source_id_foreign: FOREIGN KEY (document_source_id) REFERENCES document_sources(id) ON DELETE RESTRICT
source_archive_observations_observation_key_unique: UNIQUE (observation_key)
source_archive_observations_pkey: PRIMARY KEY (id)
CREATE INDEX archive_observations_source_document_status ON public.source_archive_observations USING btree (document_source_id, document_id, archive_status);
CREATE INDEX source_archive_observations_capture_key_index ON public.source_archive_observations USING btree (capture_key);
CREATE UNIQUE INDEX source_archive_observations_observation_key_unique ON public.source_archive_observations USING btree (observation_key);
CREATE UNIQUE INDEX source_archive_observations_pkey ON public.source_archive_observations USING btree (id);
```

## Applied migrations

- `0001_01_01_000001_create_cache_table` (batch 1)
- `0001_01_01_000002_create_jobs_table` (batch 1)
- `2026_10_28_000001_create_document_sources_table` (batch 1)
- `2026_10_28_000002_create_documents_table` (batch 1)
- `2026_10_28_000003_create_document_identifiers_table` (batch 1)
- `2026_10_28_000004_create_document_contents_table` (batch 1)
- `2026_10_28_000005_create_scrape_runs_table` (batch 1)
- `2026_10_28_000006_create_scrape_run_items_table` (batch 2)
- `2026_10_28_000007_add_content_quality_tracking` (batch 3)
- `2026_10_29_000008_create_scrape_campaigns` (batch 4)
- `2026_10_29_000010_create_source_archives` (batch 4)
- `2026_10_29_000012_allow_bounded_corpus_collection` (batch 5)
- `2026_10_30_000013_allow_bounded_30000_collection` (batch 6)
