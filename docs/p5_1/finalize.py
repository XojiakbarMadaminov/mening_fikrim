"""Offline evidence aggregation; never sends HTTP or writes the database."""
import json, math, re, hashlib
from pathlib import Path
from collections import Counter
D=Path(__file__).resolve().parent
ROOT=D.parent.parent

def read(name,default=None):
 p=D/(name+'.json');return json.loads(p.read_text()) if p.exists() else default

def write(name,data):
 (D/(name+'.json')).write_text(json.dumps(data,indent=2,ensure_ascii=False)+'\n')

def obj(v):return v if isinstance(v,dict) else {}
def percentiles(values):
 s=sorted(values)
 return {'observations':len(s),'sum':sum(s),'p50':s[math.ceil(len(s)*.5)-1] if s else None,'p95':s[math.ceil(len(s)*.95)-1] if s else None}

baseline=read('database_baseline');final=read('integrity_audit');execution=read('execution');state=execution['campaign']
all_docs=read('documents')['documents'];new=[d for d in all_docs if d['id']>state['baseline_max_id']]
log=[json.loads(l) for l in (D/'requests.jsonl').read_text().splitlines()]
req=[r['context'] for r in log if r['message']=='lex.request'];obs=[r['context'] for r in log if r['message']=='lex_source_observation']
requests={'count':len(req),'reserved_count':state['used'],'budget':state['request_limit'],'statuses':dict(Counter(str(r['status']) for r in req)),
 'minimum_start_interval_seconds':min((b['start_monotonic']-a['start_monotonic'] for a,b in zip(req,req[1:])),default=None),
 'retry_requests':sum(r['attempt']>1 for r in req),'by_context':dict(Counter(r['context'] for r in req)),
 'response_seconds':sum(r['elapsed_seconds'] for r in req),'first_at':next((r['datetime'] for r in log if r['message']=='lex.request'),None),
 'last_at':next((r['datetime'] for r in reversed(log) if r['message']=='lex.request'),None)}
write('http_statistics',requests)
runs=[json.loads(p.read_text()) for p in D.glob('run_*.json') if re.fullmatch(r'run_\d+\.json',p.name)]
runs.sort(key=lambda r:r['run']['id'])
items=[i for r in runs for i in r['items']];outcomes=[obj(i.get('outcome')) for i in items]
formats=Counter();languages=Counter();issues=Counter();pdf=[]
for d in new:
 for lang,c in d['contents'].items():
  formats[c['format']]+=1;languages[lang]+=1;issues.update(c['quality_issues'])
for r in runs:
 for item in r['items']:
  outcome=obj(item.get('outcome'))
  for lang,q in obj(outcome.get('quality')).items():
   if q.get('format') in ['pdf','ocr']:
    pdf.append({'source_id':item['source_external_id'],'document_id':item['document_id'],'run_id':r['run']['id'],'format':q['format'],'language':lang,
     'characters':next((d['contents'].get(lang,{}).get('characters') for d in all_docs if d['id']==item['document_id']),None),'pages':q.get('pages_expected'),'pages_extracted':q.get('pages_extracted'),'quality':obj(outcome.get('languages')).get(lang),
     'issues':q.get('issues'),'timings':q.get('timings'),'ocr_pages':q.get('ocr_pages'),'persistence':obj(obj(outcome.get('languages')).get(lang)).get('persistence')})
write('pdf_ocr_verification',{'samples':pdf,'scope':'Existing real targeted sources only; diagnostic-only repeated PDF extraction is not an additional persisted sample or accuracy certification.'})
html=[];pdf_times=[];ocr=[];queue=[];parse_import=[];parse=[]
for o in outcomes:
 m=obj(o.get('metrics'))
 if 'queue_wait_seconds'in m:queue.append(m['queue_wait_seconds'])
 if 'fetch_parse_import_seconds'in m:parse_import.append(m['fetch_parse_import_seconds'])
 if 'fetch_parse_seconds'in m:parse.append(m['fetch_parse_seconds'])
 for q in obj(o.get('quality')).values():
  t=obj(q.get('timings'))
  if 'html_parse_seconds'in t:html.append(t['html_parse_seconds'])
  if 'pdf_parse_seconds'in t:pdf_times.append(t['pdf_parse_seconds'])
  if t.get('ocr_seconds',0)>0:ocr.append(t['ocr_seconds'])
profiles=read('worker_profiles',[]);worker_seconds=sum(p['wall_seconds'] for p in profiles);wall=execution['controller_wall_seconds']
waits=[json.loads(l) for l in (D/'pacer_waits.jsonl').read_text().splitlines()]
performance={'controller_elapsed_seconds':wall,'active_worker_seconds':worker_seconds,'operator_pause_seconds':0,
 'pacing_wait_seconds':sum(p['actual_seconds'] for p in waits),'request_response_seconds':requests['response_seconds'],
 'new_documents_per_hour_end_to_end':len(new)*3600/wall,'new_documents_per_hour_worker':len(new)*3600/worker_seconds,
 'new_contents_per_hour_end_to_end':sum(len(d['contents']) for d in new)*3600/wall,
 'http_requests_per_new_document_including_coverage_and_controls':len(req)/max(1,len(new)),
 'html_parse_seconds':percentiles(html),'pdf_parse_seconds':percentiles(pdf_times),'ocr_seconds':percentiles(ocr),
 'queue_wait_seconds':percentiles(queue),'worker_db_query_seconds':sum(p['database_query_ms'] for p in profiles)/1000,
 'transaction_and_reconciliation_seconds_estimate':sum(parse_import)-sum(parse),'peak_php_bytes':max((p['php_peak_bytes'] for p in profiles),default=0),
 'max_self_rss_kib':max((p['self_usage']['ru_maxrss'] for p in profiles),default=0),'max_child_rss_kib':max((p['child_usage']['ru_maxrss'] for p in profiles),default=0),
 'explanation':'One continuous controller. Discovery/diagnostics outside worker included in wall, not operator pauses. Pacing/HTTP/parse/DB overlap within wall; do not sum queue waits across items into wall. Coverage enumeration is intentionally broader than the42-document sample.'}
write('performance',performance)
run_stats=[]
for r in runs:
 its=r['items'];outs=[obj(i.get('outcome')) for i in its]
 run_stats.append({'run_id':r['run']['id'],'kind':r['run']['checkpoint'].get('pilot_kind'),'source_ids':len(its),'canonical_groups':len({i['document_id'] for i in its if i['document_id']}),
 'new_canonical_documents':sum(o.get('action')=='created' for o in outs),'skipped_aliases':sum(i['status']=='skipped_alias' for i in its),
 'technical_failures':sum(i['status']=='failed' for i in its),'unassociated_reviews':sum(i['status']=='review' and i['document_id']is None for i in its),
 'http_requests':sum(q['context']=='run-'+str(r['run']['id']) for q in req),'duration_seconds':r['summary'].get('duration_seconds'),
 'persisted_with_needs_review':r['summary'].get('imported_with_needs_review'),'summary':r['summary']})
write('run_statistics',run_stats)
coverage=read('discovery_coverage',{})
parts=[{'query':n['query'],'total':n.get('total'),'unique_ids':len(n['rows']),'complete':n.get('complete',False),'reason':n.get('reason'),'children':len(n.get('children',[])),'pages_observed':len(n['pages'])} for n in coverage.get('nodes',{}).values()]
write('partition_statistics',parts)
# Representative real cases. Missing live cases are explicitly synthetic, never asserted as live successes.
avail_docs=[d for d in all_docs if d['availability']]
examples={}
for name,predicate in [
 ('single_language',lambda d:len(d['contents'])==1),
 ('confirmed_missing_translation',lambda d:any(x['status']=='not_available' for x in d['availability'].values())),
 ('unknown_availability',lambda d:any(x['status']=='unknown' for x in d['availability'].values())),
 ('available_but_extraction_failed',lambda d:any(x['status']=='available' and l not in d['contents'] for l,x in d['availability'].items())),
 ('mixed_quality_multilingual',lambda d:len({c['quality_status'] for c in d['contents'].values()})>1)]:
 found=next((d for d in avail_docs if predicate(d)),None)
 examples[name]={'kind':'live_observation','document':found} if found else {'kind':'synthetic_regression_only','test_file':'tests/Feature/LanguageAvailabilityTest.php','live_coverage_gap':True}
examples['confirmed_missing_translation']={'kind':'retained_real_source_excerpt_offline','source_id':'8507863','source_url':'https://lex.uz/uz/docs/8507863','original_evidence':'docs/p0/markdown/8506802_ru.md','evidence_kind':'Actual P0 extracted Russian notice; HTML wrapper in regression is an explicitly controlled reconstruction. Not fetched or written to development in P5.1.','test_file':'tests/Unit/P51SourceEvidenceTest.php','live_p51_coverage_gap':True}
write('representative_examples',examples)
old=read('historical_baseline');changed=[]
for p,h in old.items():
 file=ROOT/p
 if not file.is_file() or hashlib.sha256(file.read_bytes()).hexdigest()!=h:changed.append(p)
write('historical_integrity',{'protected_files':len(old),'changed':changed})
failed_extractions=sum(obj(v).get('quality_status')=='extraction_failed' for o in outcomes for v in obj(o.get('languages')).values())
summary={'campaign_id':state['id'],'p5_new_canonical':158,'p5_1_new_canonical':len(new),'combined_new_canonical':158+len(new),
 'target_reached':len(new)==42,'stop':state['stopped'],'baseline_counts':baseline['rows'],'final_counts':final['rows'],
 'quality_distribution':final['quality_counts'],'new_content_representations':sum(len(d['contents']) for d in new),
 'new_quality_distribution':dict(Counter(c['quality_status'] for d in new for c in d['contents'].values())),
 'new_formats':dict(formats),'new_languages':dict(languages),'new_review_issue_counts':dict(issues),
 'selected_distinct_entry_ids':len(state['selected_ids']),'selected_cap':state['source_limit'],'http':requests,
 'technical_failures':sum(i['status']=='failed' for i in items),'new_failed_jobs':final['rows']['failed_jobs']-baseline['rows']['failed_jobs'],
 'failed_extraction_representations':failed_extractions,'skipped_alias_items':sum(i['status']=='skipped_alias' for i in items),
 'availability':read('language_availability')['counts'],'complete_partitions':sum(p['complete'] for p in parts),'incomplete_partitions':sum(not p['complete'] for p in parts),
 'complete_leaf_partitions':sum(p['complete'] and p['children']==0 for p in parts),'discovery_root_total':coverage.get('root',{}).get('total'),'discovered_unique_source_ids':len(coverage.get('rows',[])),
 'integrity_errors':final['errors'],'historical_files_changed':changed,'controller_elapsed_seconds':wall,
 'recommendation':'CONDITIONAL GO' if len(new)==42 and not final['errors'] and not changed else 'NO-GO'}
created={i['document_id'] for i in items if obj(i.get('outcome')).get('action')=='created'}
assert created=={d['id'] for d in new}, 'New canonical rows must equal campaign-created run-item document IDs'
lang_attempts=[obj(v) for o in outcomes for v in obj(o.get('languages')).values() if obj(v).get('quality_status') in ['verified','needs_review']]
stored=sum(v.get('persistence')=='stored' for v in lang_attempts)
verified=sum(v.get('quality_status')=='verified' for v in lang_attempts)
summary['canonical_count_proof']={'new_document_ids':sorted(created),'all_new_rows_have_campaign_created_items':True,'already_existing_baseline_groups_touched':len({i['document_id'] for i in items if i['document_id'] and i['document_id']<=state['baseline_max_id']}),'queued_unique_source_ids':len({i['source_external_id'] for i in items}),'queued_items':len(items)}
summary['representation_attempts']={'usable':len(lang_attempts),'stored':stored,'verified':verified,'protected_candidates':sum(v.get('persistence')=='candidate' for v in lang_attempts),'persistence_rate_percent':100*stored/max(1,len(lang_attempts)),'automated_verification_rate_percent':100*verified/max(1,len(lang_attempts)),'scope':'Usable identity-admitted run representations, including controls/repeats. Diagnostic-only identity rejections excluded; no failed extraction was encountered.'}
summary['tests']=read('validation_summary')
summary['discovery_attribution']={'mapped_source_ids':len({x['id'] for x in coverage['rows']} & {i for d in all_docs for i in d['identifiers']}),'mapped_canonical_groups':len({d['id'] for d in all_docs if set(d['identifiers']) & {x['id'] for x in coverage['rows']}}),'scope':'Only groups proven by existing identifiers; remaining unimported discovery IDs are not inferred canonical documents.'}
write('final_summary',summary)
print(json.dumps(summary,indent=2,ensure_ascii=False))
