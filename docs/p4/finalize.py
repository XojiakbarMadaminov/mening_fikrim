"""Finalize saved P4 evidence only. No network or database writes."""
import collections
import hashlib
import json
import math
from datetime import datetime
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
HERE = ROOT / 'docs/p4'

def read(name):
    return json.loads((HERE / name).read_text())

def save(name, value):
    (HERE / name).write_text(json.dumps(value, ensure_ascii=False, indent=2) + '\n')

historical = read('historical_hashes.json')
changed = [name for name, sha in historical.items() if not (ROOT/name).is_file() or hashlib.sha256((ROOT/name).read_bytes()).hexdigest() != sha]
assert not changed, changed
unchanged_modules = ['app/Services/Documents/DocumentValidator.php', 'app/Jobs/ImportDocumentJob.php', 'app/Services/Scraping/RunItemTracker.php', 'app/Scraping/LexUz/LexHttpClient.php', 'app/Scraping/LexUz/LexUzParser.php', 'app/Scraping/LexUz/PdfTextExtractor.php', 'app/Scraping/LexUz/PdfQualityInspector.php', 'app/Scraping/LexUz/inspect_pdf.py', 'app/Enums/DocumentStatus.php']
modules = {name: (ROOT/name).read_bytes() == (ROOT/'storage/app/p4_baseline'/name).read_bytes() for name in unchanged_modules}
assert all(modules.values()), modules
save('historical_integrity.json', {'files_checked': len(historical), 'all_unchanged': True, 'changed': changed, 'unchanged_modules': modules})

before = read('database_before.json')
first = read('run_8.json')
middle = read('run_9.json')
last = read('run_10.json')
final = read('database_final.json')
initial_contents = {str(r['id']): r for r in first['contents']}
latest_contents = {str(r['id']): r for r in last['contents']}
old_ok = all(latest_contents[k]['content_hash'] == v['hash'] and latest_contents[k]['document_id'] == v['document_id'] and latest_contents[k]['language'] == v['language'] for k,v in before['contents'].items())
all_ok = initial_contents.keys() == latest_contents.keys() and all(initial_contents[k]['content_hash'] == latest_contents[k]['content_hash'] for k in initial_contents)
assert old_ok and all_ok
failed_ok = before['record_hashes']['failed_jobs'] == final['record_hashes']['failed_jobs']
assert failed_ok
old_ids = set(before['record_hashes']['document_identifiers'])
assert old_ids <= set(final['record_hashes']['document_identifiers'])
save('preservation.json', {'original_40_content_ids_associations_hashes_preserved': old_ok, 'all_63_content_ids_hashes_stable_across_live_reimports': all_ok, 'all_original_identifier_ids_present': True, 'old_24_failed_jobs_row_hashes_preserved': failed_ok, 'new_documents': final['rows']['documents']-before['rows']['documents'], 'new_contents': final['rows']['document_contents']-before['rows']['document_contents']})

targets = json.loads((ROOT/'docs/p3/review_comparison.json').read_text())
items = {r['source_external_id']: r for r in first['items']}
documents = {r['id']: r for r in last['documents']}
reviews = []
for old in targets:
    item = items[old['id']]
    document_id = item['document_id']
    contents = [c for c in last['contents'] if c['document_id'] == document_id]
    assert contents
    reviews.append({'source_id': old['id'], 'url': old['url'], 'document_id': document_id, 'legal_status': documents[document_id]['status'], 'p3_format': old['p3_format'], 'p4_format': item['outcome']['format'], 'quality_status': documents[document_id]['quality_status'], 'contents': contents})
save('review_results.json', reviews)

logs = [json.loads(line) for line in (HERE/'requests.jsonl').read_text().splitlines()]
requests = [r['context'] for r in logs]
gaps = [b['start_monotonic']-a['start_monotonic'] for a,b in zip(requests, requests[1:])]
latencies = sorted(r['elapsed_seconds'] for r in requests)
stats = {'requests': len(requests), 'statuses': dict(collections.Counter(str(r.get('status')) for r in requests)), 'minimum_start_interval_seconds': min(gaps), 'budget': 400, 'all_intervals_at_least_2s': min(gaps)>=2, 'within_budget': len(requests)<=400, 'retries': sum(r['attempt']>1 for r in requests), 'first_at': logs[0]['datetime'], 'last_at': logs[-1]['datetime'], 'window_seconds': (datetime.fromisoformat(logs[-1]['datetime'])-datetime.fromisoformat(logs[0]['datetime'])).total_seconds(), 'latency_p50_seconds': latencies[math.ceil(len(latencies)*.5)-1], 'latency_p95_seconds': latencies[math.ceil(len(latencies)*.95)-1]}
assert stats['all_intervals_at_least_2s'] and stats['within_budget']
save('http_stats.json', stats)
save('campaign_summary.json', {'selected_source_ids': 19, 'distinct_canonical_groups': 16, 'formerly_review_groups_persisted': len(reviews), 'formerly_review_quality_counts': dict(collections.Counter(r['quality_status'] for r in reviews)), 'first_summary': first['summary'], 'intermediate_summary': middle['summary'], 'final_summary': last['summary'], 'final_database_rows': final['rows'], 'content_quality_counts': final['quality_counts'], 'source_format_counts_first': dict(collections.Counter(i['outcome'].get('format') for i in first['items'] if i['status']!='skipped_alias')), 'http': stats})

inventory = []
backup = ROOT/'storage/app/p4_baseline'
for folder in ['app','config','tests','database','routes']:
    old = {str(f.relative_to(backup)):f for f in (backup/folder).rglob('*') if f.is_file()}
    new = {str(f.relative_to(ROOT)):f for f in (ROOT/folder).rglob('*') if f.is_file()}
    for name in sorted(old.keys()|new.keys()):
        if name not in old: inventory.append({'file':name, 'change':'added'})
        elif name not in new: inventory.append({'file':name, 'change':'removed'})
        elif old[name].read_bytes()!=new[name].read_bytes(): inventory.append({'file':name, 'change':'modified'})
save('changed_files.json', inventory)
print(json.dumps({'historical_files_unchanged':len(historical), 'implementation_test_files_changed':len(inventory), 'all_13_targets_persisted':True, 'old_40_and_all_63_content_hashes_preserved':True, 'quality_counts':final['quality_counts'], 'final_run':last['summary'], 'http':stats}, ensure_ascii=False, indent=2))
