"""Finalize saved P3 evidence only. No HTTP or database writes."""
import collections,hashlib,json,math
from pathlib import Path
root=Path(__file__).resolve().parents[2];p=root/'docs/p3'

def read(name):return json.loads((p/name).read_text())
def save(name,value):(p/name).write_text(json.dumps(value,ensure_ascii=False,indent=2)+'\n')
def percentile(values,q):
 values=sorted(values)
 return values[max(0,math.ceil(len(values)*q)-1)] if values else None

historical=read('historical_hashes.json')
changed=[name for name,sha in historical.items() if not (root/name).is_file() or hashlib.sha256((root/name).read_bytes()).hexdigest()!=sha]
core=['app/Services/Documents/DocumentImporter.php','app/Services/Documents/DocumentValidator.php','app/Models/Document.php','app/Models/DocumentIdentifier.php','app/Models/DocumentContent.php','app/Models/DocumentSource.php','app/Enums/DocumentStatus.php']
foundation={name:(root/name).read_bytes()==(root/'storage/app/p3_baseline'/name).read_bytes() for name in core}
save('historical_integrity.json',{'files_checked':len(historical),'changed':changed,'all_unchanged':not changed,'foundation_unchanged':foundation})
if changed or not all(foundation.values()):raise SystemExit('Historical/foundation integrity check failed')

old={r['id']:r for r in json.loads((root/'docs/p2/sample_results.json').read_text())}
latest=read('run_7.json');items={r['source_external_id']:r for r in latest['items']}
ids=['8548754','8554309','-7655343','-8540314','-8102878','8557098','8554315','8554432','8554353','8554326','8554321','-8553258','-8553692']
comparison=[]
for identifier in ids:
 item=items[identifier];outcome=item['outcome'];quality=outcome.get('quality',{})
 comparison.append({'id':identifier,'url':old[identifier]['url'],'p2_format':old[identifier]['format'],'p2_issues':old[identifier]['issues'],
  'p3_format':outcome.get('format'),'p3_status':item['status'],'source_status':outcome.get('source_status'),
  'p3_issues':sorted({issue for q in quality.values() for issue in q.get('issues',[])}),'admission_error':item.get('last_error'),
  'full_text_language_results':item.get('language_results'),'physical_source_change':identifier in ['-8553258','-8553692']})
save('review_comparison.json',comparison)

log=p/'requests.jsonl'
if not log.exists():log=p/'live_http-2026-10-28.log'
records=[json.loads(line) for line in log.read_text().splitlines()];requests=[line['context'] for line in records]
gaps=[b['start_monotonic']-a['start_monotonic'] for a,b in zip(requests,requests[1:])]
save('http_stats.json',{'requests':len(requests),'statuses':dict(collections.Counter(r.get('status') for r in requests)),
 'minimum_start_interval_seconds':min(gaps),'all_intervals_at_least_2s':min(gaps)>=2,'campaign_budget':400,'within_budget':len(requests)<=400,
 'decoded_response_bytes':sum(r.get('bytes',0) for r in requests),'latency_p50_seconds':percentile([r['elapsed_seconds'] for r in requests],.5),'latency_p95_seconds':percentile([r['elapsed_seconds'] for r in requests],.95),
 'first_at':records[0]['datetime'],'last_at':records[-1]['datetime'],'http_retries':sum(r['attempt']>1 for r in requests)})

reference=json.loads((root/'docs/p0_1/pdf_table_trials.json').read_text())
reference=next(t for t in reference if t['settings']['vertical_strategy']=='lines' and t['settings']['snap_tolerance']==8)['tables'][0]
first=read('run_4.json');pdf_item=next(item for item in first['items'] if item['source_external_id']=='8557098')
quality=pdf_item['outcome']['quality']['uz-Latn'];page=quality['layout_diagnostics']['pages'][8]
trial=next(t for t in page['trials'] if t['tolerance']==8)['tables'][0]
save('pdf_table_regression.json',{'id':'8557098','page':9,'source_sha256':quality['provenance']['source_sha256'],'expected_rows':9,'expected_columns':5,
 'actual_rows':trial['rows'],'actual_columns':trial['columns'],'all_45_cell_values_match_p01_reference':trial['cells']==reference['cells'],
 'settings_disagree':page['settings_disagree'],'table_relationships_verified':False,'visual_review_performed':True})
if trial['cells']!=reference['cells']:raise SystemExit('Real table regression mismatch')

regions=read('ocr_regions_real.json');ocr={}
for identifier,record in regions.items():
 pages=record['quality']['page_stats'];conf=[page['confidence'] for page in pages if page['ocr']]
 ocr[identifier]={'pages':len(conf),'region_count':sum(c['review_regions']['region_count'] for c in conf),'samples_truncated_pages':sum(c['review_regions']['sample_truncated'] for c in conf),
  'mean_confidence_min':min(c['mean_confidence'] for c in conf),'mean_confidence_max':max(c['mean_confidence'] for c in conf),
  'pages_mean_below_90':sum(c['mean_confidence']<90 for c in conf),'pages_low_fraction_above_5_percent':sum(c['low_confidence_fraction']>.05 for c in conf),
  'document_certified':False,'markdown_hash_unchanged':record['markdown_hash_unchanged'],'gold':record['gold']}
save('ocr_summary.json',ocr)

formats=collections.Counter(item['outcome'].get('format') for item in latest['items'] if item['status']!='skipped_alias')
page_count=sum(q.get('pages_expected',0) for item in latest['items'] for q in item['outcome'].get('quality',{}).values())
latencies=[];pdf_times=[];html_times=[]
for item in latest['items']:
 for q in item['outcome'].get('quality',{}).values():
  t=q.get('timings',{})
  if 'html_parse_seconds' in t:html_times.append(t['html_parse_seconds'])
  if 'pdf_parse_seconds' in t:pdf_times.append(t['pdf_parse_seconds'])
summary={'selected_source_entrypoints':19,'selected_canonical_groups':16,'review_groups':13,'accepted_groups':3,
 'main_formats':dict(formats),'main_pdf_pages':page_count,'alternate_ocr_pages':sum(r['pages'] for r in ocr.values()),
 'run_summaries':{str(i):read('run_'+str(i)+'.json')['summary'] for i in [4,5,6,7]},
 'final_rows':latest['rows'],'html_parse_p50_seconds':percentile(html_times,.5),'html_parse_p95_seconds':percentile(html_times,.95),
 'pdf_parse_p50_seconds':percentile(pdf_times,.5),'pdf_parse_p95_seconds':percentile(pdf_times,.95),'pdf_parse_seconds_sum':sum(pdf_times),
 'html_timing_variants':len(html_times),'pdf_timing_variants':len(pdf_times),'technical_failed_final':0}
save('campaign_summary.json',summary)

old_contents=read('run_5.json')['contents'];new_contents=latest['contents']
save('final_idempotency.json',{'all_40_content_ids_and_hashes_unchanged':old_contents==new_contents,'created':latest['summary']['created'],'updated':latest['summary']['updated'],
 'unchanged_canonical':latest['summary']['unchanged'],'skipped_alias_source_ids':latest['summary']['skipped_alias'],'review':latest['summary']['review_required'],'failed_jobs_preserved_at_24':latest['rows']['failed_jobs']==24})
if old_contents!=new_contents or latest['summary']['created'] or latest['summary']['updated']:raise SystemExit('Final idempotency failed')
print(json.dumps({'historical_files':len(historical),'all_unchanged':not changed,'source_requests':len(requests),'groups':16,'accepted':3,'review':13,'final_rows':latest['rows']},indent=2))
