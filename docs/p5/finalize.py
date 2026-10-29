"""Offline evidence aggregation only; never sends requests or mutates PostgreSQL."""
from pathlib import Path
from collections import Counter, defaultdict
from datetime import datetime
import hashlib, json, math, statistics
ROOT = Path(__file__).resolve().parents[2]
P = ROOT / 'docs/p5'
def read(name, default=None):
    path=P/(name+'.json')
    return json.loads(path.read_text()) if path.exists() else default
def save(name, value):
    (P/(name+'.json')).write_text(json.dumps(value, ensure_ascii=False, indent=2)+'\n')
def lines(name):
    path=P/name
    return [json.loads(x) for x in path.read_text().splitlines() if x.strip()] if path.exists() else []
def pct(values, q):
    return sorted(values)[max(0, math.ceil(len(values)*q)-1)] if values else None
baseline=read('database_baseline')
final=read('integrity_audit')
execution=read('execution', {})
progress=read('progress', {'runs':[], 'attempted_ids':[]})
coverage=read('discovery_coverage', {})
runs=[read('run_'+str(i)) for i in progress['runs']]
runs=[r for r in runs if r]
items=[i for r in runs for i in r['items']]
raw=lines('requests.jsonl')
requests=[r['context'] for r in raw if r.get('message')=='lex.request']
profiles=read('worker_profiles', [])
waits=lines('pacer_waits.jsonl')
old_ids=set(map(int,baseline['contents']))
new_content={int(k):v for k,v in final['contents'].items() if int(k) not in old_ids}
old_docs={d['id'] for d in baseline['documents']}
campaign_sources={r['run']['source_id'] for r in runs}
new_docs={d['id'] for d in final['documents'] if d['source_id'] in campaign_sources} - old_docs
stored_ids={i['document_id'] for i in items if i['document_id'] is not None}
proven_keys={i['outcome'].get('identity_key') for i in items if i['document_id'] is None and i['outcome'].get('identity_verified')}
quality=Counter(v['quality_status'] for v in new_content.values())
issue_counts=Counter(code for v in new_content.values() for code in v['quality_issues'])
records=[]
for r in runs:
    for i in r['items']:
        outcome=i.get('outcome') or {}
        if i['status']=='skipped_alias':
            continue
        quality_evidence=outcome.get('quality', {})
        for lang, result in (i.get('language_results') or {}).items():
            if result.get('state')=='metadata_only':
                continue
            q=quality_evidence.get(lang, {})
            content=next((v for v in final['contents'].values() if v['document_id']==i['document_id'] and v['language']==lang),{})
            records.append({'run_id':r['run']['id'],'source_id':i['source_external_id'], 'url':'https://lex.uz/uz/docs/'+i['source_external_id'],
                'document_id':i['document_id'],'language':lang,'format':q.get('format',outcome.get('format')),
                'characters':content.get('characters',q.get('chars')), 'quality_status':result.get('quality_status'),
                'quality_issues':result.get('quality_issues', []), 'persistence':result.get('persistence'),
                'ocr_pages':q.get('ocr_pages',[]),'pages':q.get('pages_expected'),'timings':q.get('timings',{}),
                'error':q.get('error'), 'extracted_hash':result.get('extracted_hash')})
# Latest unique persisted representation: repeat/control attempts are reported separately.
unique={}
for record in records:
    if record['document_id'] and record['persistence'] in ['stored','candidate']:
        unique[(record['document_id'],record['language'])]=record
formats=Counter(r['format'] for r in unique.values())
languages=Counter(v['language'] for v in new_content.values())
html_times=[r['timings']['html_parse_seconds'] for r in records if 'html_parse_seconds' in r['timings']]
pdf_times=[r['timings']['pdf_parse_seconds'] for r in records if 'pdf_parse_seconds' in r['timings']]
ocr_times=[r['timings']['ocr_seconds'] for r in records if r['timings'].get('ocr_seconds',0)>0]
ocr_unique={k:v for k,v in unique.items() if v['ocr_pages']}
extraction={'unique_persisted_representations':len(unique),'formats':dict(formats),'new_content_languages':dict(languages),
    'ocr_representations':len(ocr_unique),'ocr_pages':sum(len(v['ocr_pages']) if isinstance(v['ocr_pages'],list) else v['ocr_pages'] for v in ocr_unique.values()),
    'physical_pdf_representations':sum(r['format'] in ['pdf','ocr'] for r in unique.values()),
    'text_pdf_representations':sum(r['format']=='pdf' and not r['ocr_pages'] for r in unique.values()),
    'representation_attempts':records, 'unassociated_review_items':[{'url':'https://lex.uz/uz/docs/'+i['source_external_id'],'reason':i['last_error'],'status':i['status']} for i in items if i['document_id'] is None and i['status'] in ['review','failed','retry_wait']]}
save('extraction_statistics',extraction)
quality_stats={'total':final['quality_counts'],'new_content_quality':dict(quality),'new_content_issue_counts':dict(issue_counts),
    'new_verified_rate':quality.get('verified',0)/len(new_content) if new_content else None,
    'new_review_content_persisted':quality.get('needs_review',0),
    'extraction_failure_items':sum(i.get('outcome',{}).get('import_outcome')=='extraction_failed' for i in items),
    'failed_representations':sum(r.get('quality_status')=='extraction_failed' for r in records),
    'persisted_representation_attempts':sum(r['persistence'] in ['stored','candidate'] for r in records),
    'representation_persistence_success_rate':sum(r['persistence'] in ['stored','candidate'] for r in records)/len(records) if records else None,
    'explanation':'Persistence rate uses evaluated full-text language attempts, including repeats; metadata/identity rejections reported separately. Verified is automated validation, not legal certification.'}
save('quality_statistics',quality_stats)
http_intervals=[b['start_monotonic']-a['start_monotonic'] for a,b in zip(requests,requests[1:])]
http={'requests':len(requests),'budget':execution.get('campaign',final.get('campaign',{})).get('request_limit'),
    'reserved':execution.get('campaign',final.get('campaign',{})).get('used'), 'statuses':dict(Counter(str(r.get('status',0)) for r in requests)),
    'retries':sum(r.get('attempt',1)>1 for r in requests),'min_interval_seconds':min(http_intervals) if http_intervals else None,
    'all_intervals_at_least_2s':all(x>=2 for x in http_intervals),'response_bytes':sum(r.get('bytes',0) for r in requests),
    'http_seconds':sum(r['elapsed_seconds'] for r in requests), 'latency_p50_seconds':pct([r['elapsed_seconds'] for r in requests],.5),
    'latency_p95_seconds':pct([r['elapsed_seconds'] for r in requests],.95), 'first_at':raw[0]['datetime'] if raw else None,'last_at':raw[-1]['datetime'] if raw else None}
save('http_statistics',http)
worker_seconds=sum(p['wall_seconds'] for p in profiles)
wall_seconds=execution.get('controller_wall_seconds')
metrics=[i['outcome']['metrics'] for i in items if i.get('outcome',{}).get('metrics')]
job_seconds=sum(m.get('fetch_parse_import_seconds',0) for m in metrics)
queue_waits=[m['queue_wait_seconds'] for m in metrics if 'queue_wait_seconds' in m]
performance={'controller_elapsed_seconds':wall_seconds,'active_worker_seconds':worker_seconds,
    'operator_pause_seconds':0,'explanation':'One uninterrupted controller invocation; worker time includes pacing/network/parser/DB/retries. Controller minus worker includes discovery, audits, queue orchestration and file I/O, not operator pauses. Queue waiting percentiles are not additive to elapsed time.',
    'network_pacing_and_backoff_wait_seconds':sum(r['actual_seconds'] for r in waits),
    'job_fetch_parse_import_seconds':job_seconds,'transaction_and_reconciliation_seconds_estimate':sum(max(0,m.get('fetch_parse_import_seconds',0)-m.get('fetch_parse_seconds',0)) for m in metrics),
    'all_worker_database_query_seconds':sum(p.get('database_query_ms',0) for p in profiles)/1000,
    'peak_php_bytes':max((p['php_peak_bytes'] for p in profiles),default=0),
    'max_self_rss_kib':max((p['self_usage'].get('ru_maxrss',0) for p in profiles),default=0),
    'max_child_rss_kib':max((p['child_usage'].get('ru_maxrss',0) for p in profiles),default=0),
    'html_parse_seconds':{'observations':len(html_times),'sum':sum(html_times),'p50':pct(html_times,.5),'p95':pct(html_times,.95)},
    'pdf_parse_seconds':{'observations':len(pdf_times),'sum':sum(pdf_times),'p50':pct(pdf_times,.5),'p95':pct(pdf_times,.95)},
    'ocr_seconds':{'observations':len(ocr_times),'sum':sum(ocr_times),'p50':pct(ocr_times,.5)},
    'queue_wait_seconds':{'p50':pct(queue_waits,.5),'p95':pct(queue_waits,.95)},
    'new_documents_per_hour_worker':len(new_docs)*3600/worker_seconds if worker_seconds else None,
    'new_documents_per_hour_end_to_end':len(new_docs)*3600/wall_seconds if wall_seconds else None,
    'new_contents_per_hour_worker':len(new_content)*3600/worker_seconds if worker_seconds else None,
    'new_contents_per_hour_end_to_end':len(new_content)*3600/wall_seconds if wall_seconds else None,
    'requests_per_new_document_all_overhead':len(requests)/len(new_docs) if new_docs else None,
    'queue_retry_attempts':sum(max(0,i['attempts']-1) for i in items),
    'technical_failure_rate_items':sum(i['status']=='failed' for i in items)/len(items) if items else None}
projections={}
for target in [1000,10000,100000]:
    if new_docs and wall_seconds:
        base=wall_seconds/len(new_docs)*target/3600
        projections[str(target)]={'same_mix_hours':base,'same_mix_requests':math.ceil(len(requests)/len(new_docs)*target),
            'planning_range_hours_1x_to_3x': [base,base*3],
            'ocr_sensitivity_extra_hours_per_10_percent_at_60s_per_doc':target*.1*60/3600}
performance['projections']=projections
performance['projection_limitations']='Linear planning estimates only; this sample mix, observed discovery/alias/retry overhead included. 1x–3x is a planning scenario, not a confidence interval. Unseen scanned PDFs, long tables, drift, access stops and multi-day worker/cache reliability may dominate; 60s OCR sensitivity is an assumption, not measured OCR throughput.'
save('performance',performance)
# Stage snapshots bound cumulative DB counts. Run IDs are mapped by ordered persisted run counts in this isolated single-controller pilot.
stages=[]
previous=baseline
position=0
for label,target,name in [('B',30,'stage_b_results'),('C',100,'stage_c_results'),('D',200,'stage_d_results')]:
    snap=read(name)
    if not snap: continue
    run_count=snap['rows']['scrape_runs']-baseline['rows']['scrape_runs']
    selected=runs[position:run_count]
    its=[i for r in selected for i in r['items']]
    run_ids={r['run']['id'] for r in selected}
    stage_requests=[r for r in requests if r.get('context','') in {'run-'+str(i) for i in run_ids}]
    stage={'stage':label,'target_cumulative_new':target,'actual_cumulative_new':snap['new_canonical_documents'],'new_documents':snap['rows']['documents']-previous['rows']['documents'],
        'new_contents':snap['rows']['document_contents']-previous['rows']['document_contents'],'verified_contents_delta':snap['quality_counts'].get('verified',0)-previous['quality_counts'].get('verified',0),
        'needs_review_contents_delta':snap['quality_counts'].get('needs_review',0)-previous['quality_counts'].get('needs_review',0),
        'selected_source_ids':len(its),'skipped_aliases':sum(i['status']=='skipped_alias' for i in its),'technical_failures':sum(i['status']=='failed' for i in its),
        'http_requests_import_only':len(stage_requests),'run_ids':sorted(run_ids),'completed':snap['new_canonical_documents']>=target,
        'duration_seconds_run_start_to_finish_sum':sum((datetime.fromisoformat(r['run']['finished_at'])-datetime.fromisoformat(r['run']['started_at'])).total_seconds() for r in selected if r['run']['finished_at'])}
    stages.append(stage); previous=snap; position=run_count
save('stage_statistics',stages)
protected=read('historical_hashes')
changed=[p for p,h in protected.items() if not (ROOT/p).is_file() or hashlib.sha256((ROOT/p).read_bytes()).hexdigest()!=h]
save('historical_integrity',{'protected_files':len(protected),'changed':changed})
all_listed={id for entry in coverage.values() for id in entry['unique_ids']}
final_ident={str(i['external_id']):i['document_id'] for i in final['identifiers']}
partition_statistics=[]
for key,entry in coverage.items():
    if entry['pass']!=1:
        continue
    ids=set(entry['unique_ids'])
    mapped={id for id in ids if id in final_ident}
    partition_statistics.append({'query':entry['query'],'source_total':entry['result']['total'],
        'enumerated_unique_source_ids':len(ids),'complete':entry['result']['complete'],
        'replay_stable':coverage.get(key.split('_')[0]+'_2',{}).get('stable_order_and_total'),
        'mapped_to_stored_canonical_source_ids':len(mapped),'unmapped_enumerated_ids':sorted(ids-mapped),
        'stored_canonical_groups_in_enumerated_ids':len({final_ident[id] for id in mapped}),
        'not_enumerated_source_records':entry['result']['total']-len(ids)})
save('discovery_partition_statistics',partition_statistics)
old_identifier_ids={i['external_id'] for i in baseline['identifiers']}
for problem in extraction['unassociated_review_items']:
    problem['final_canonical_document_id']=final_ident.get(problem['url'].split('/')[-1])
    problem['interpretation']='Later associated through another proven alias' if problem['final_canonical_document_id'] else 'Source ID remains unassociated; do not infer canonical identity'
save('extraction_statistics',extraction)
attempts=set(progress['attempted_ids'])
known_excluded={id for id in all_listed-attempts if id in final_ident}
changed_representations=set()
unchanged_attempts=0
run_statistics=[]
previous_doc_ids=set(old_docs)
prior=baseline['contents']
for run in runs:
    snap=read('after_run_'+str(run['run']['id']))
    if not snap: continue
    current=snap['contents']
    touched={i['document_id'] for i in run['items'] if i['document_id'] is not None}
    run_statistics.append({'run_id':run['run']['id'], 'kind':run['run']['checkpoint'].get('pilot_kind'),
        'selected_source_ids':len(run['items']), 'canonical_groups':len(touched),
        'new_canonical_documents':len(touched-previous_doc_ids),'already_existing_canonical_documents':len(touched & previous_doc_ids),
        'skipped_aliases':sum(i['status']=='skipped_alias' for i in run['items']),
        'new_contents':len(set(current)-set(prior)),
        'http_requests':sum(r.get('context')=='run-'+str(run['run']['id']) for r in requests),
        'technical_failures':sum(i['status']=='failed' for i in run['items']),
        'verified':run['summary'].get('verified'), 'imported_with_needs_review':run['summary'].get('imported_with_needs_review'),
        'extraction_failed':run['summary'].get('extraction_failed'), 'created':run['summary'].get('created'),
        'updated':run['summary'].get('updated'),'persisted_unchanged':run['summary'].get('persisted_unchanged')})
    previous_doc_ids.update(touched)
    for id,old in prior.items():
        if id in current and current[id]['hash']!=old['hash']:
            changed_representations.add(id)
    for item in run['items']:
        if item['status']=='skipped_alias':
            continue
        for lang,result in (item.get('language_results') or {}).items():
            previous_content=next((c for c in prior.values() if c['document_id']==item['document_id'] and c['language']==lang),{})
            if previous_content and result.get('extracted_hash')==previous_content['hash'] and result.get('persistence') in ['stored','candidate']:
                unchanged_attempts+=1
    prior=current
summary={'new_canonical_documents':len(new_docs),'total_documents':final['rows']['documents'],'total_contents':final['rows']['document_contents'],
    'quality_counts':final['quality_counts'],'new_content_representations':len(new_content),'updated_content_representations':len(changed_representations),
    'unchanged_representation_attempts':unchanged_attempts,'unchanged_basis':'Fresh full-text evaluations matching the preceding run snapshot; alias skips and metadata-only excluded.','discovered_unique_source_ids':len(all_listed),'selected_source_id_attempts':len(items),
    'distinct_selected_source_ids':len(attempts),'canonical_groups_stored':len(stored_ids),'proven_unpersisted_groups':len(proven_keys),
    'baseline_existing_canonical_documents_discovered':len({final_ident[id] for id in all_listed & old_identifier_ids}),
    'baseline_existing_listing_source_ids':len(all_listed & old_identifier_ids),
    'baseline_existing_canonical_documents_touched':len(stored_ids & old_docs),
    'skipped_alias_items':sum(i['status']=='skipped_alias' for i in items),'known_identifier_ids_excluded_before_queue':len(known_excluded),
    'new_identifiers':final['rows']['document_identifiers']-baseline['rows']['document_identifiers'],
    'technical_failed_items':sum(i['status']=='failed' for i in items),'new_failed_jobs':final['rows']['failed_jobs']-baseline['rows']['failed_jobs'],
    'final_unmapped_attempted_source_ids':sorted(attempts-set(final_ident)),
    'unselected_unmapped_listing_ids':sorted(all_listed-set(final_ident)-attempts),
    'review_items_later_resolved_by_alias':sum(p['final_canonical_document_id'] is not None for p in extraction['unassociated_review_items']),
    'unassociated_review_items':sum(i['status']=='review' and i['document_id'] is None for i in items),
    'pending_items':sum(i['status'] in ['queued','processing','retry_wait','discovered'] for i in items),'pending_jobs':final['rows']['jobs'],
    'unattributed_new_document_ids':sorted(new_docs-stored_ids),
    'canonical_count_matches_controller':len(new_docs)==execution.get('new_canonical_documents'),
    'http_requests':len(requests),'controller_seconds':wall_seconds,'integrity_errors':final['errors'],'historical_files_changed':changed,
    'stop':execution.get('campaign',final.get('campaign',{})).get('stopped'),
    'recommendation':'CONDITIONAL GO' if len(new_docs)==200 and not final['errors'] and not changed and not any(i['status']=='failed' for i in items) else 'NO-GO',
    'recommendation_scope':'Expansion to a 1000-document pilot; controlled P5 collection remained safe but exact200 gate was not achieved.',
    'next_step':'Review five unassociated attempted source IDs, capture/test localized language labels, split incomplete date partitions, and authorize a bounded supplementary42-new-document run with recalibrated source-ID budget before deciding on1000. Do not automatically resume.'}
save('run_statistics',run_statistics)
save('final_summary',summary)
print(json.dumps(summary,ensure_ascii=False,indent=2))
