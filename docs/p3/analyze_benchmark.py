"""Read recorded offline measurements; no HTTP or DB operations."""
from pathlib import Path
import json,math
p=Path(__file__).resolve().parent

def percentile(values,q):
    if not values:return None
    values=sorted(values)
    return values[max(0,math.ceil(q*len(values))-1)]

rows=[]
for run in json.loads((p/'benchmark_results.json').read_text()):
    profiles=run['workers_profile']; items=run['items']; elapsed=run['wall_seconds']
    cpu=sum(r.get('ru_utime.tv_sec',0)+r.get('ru_utime.tv_usec',0)/1e6+r.get('ru_stime.tv_sec',0)+r.get('ru_stime.tv_usec',0)/1e6 for profile in profiles for r in [profile['self_usage'],profile['child_usage']])
    latency=[r['elapsed_seconds'] for r in run['requests']]
    wait=[item['outcome'].get('metrics',{}).get('queue_wait_seconds',0) for item in items]
    source_locks=[q['seconds'] for profile in profiles for q in profile['lock_queries'] if q['source_lock']]
    transactions=[n for profile in profiles for n in profile['transactions_seconds']]
    html=[];ocr=[];chars=0
    for item in items:
        for lang,quality in item['outcome'].get('quality',{}).items():
            if 'html_parse_seconds' in quality.get('timings',{}):html.append(quality['timings']['html_parse_seconds'])
            if 'ocr_seconds' in quality.get('timings',{}):ocr.append(quality['timings']['ocr_seconds'])
            if item['language_results'].get(lang,{}).get('state')=='valid':chars+=quality.get('chars',0)
    rows.append({k:run[k] for k in ['workers','interval_ms','wall_seconds','request_count','minimum_start_interval_seconds','requests_per_second','valid_documents_per_hour','review_documents_per_hour','lock_wait_samples_at_100ms']} | {
        'http_latency_p50_seconds':percentile(latency,.5),'http_latency_p95_seconds':percentile(latency,.95),
        'http_retry_rate':sum(r['attempt']>1 for r in run['requests'])/len(run['requests']),
        'queue_wait_p50_seconds':percentile(wait,.5),'queue_wait_p95_seconds':percentile(wait,.95),
        'html_parse_p50_seconds':percentile(html,.5),'html_parse_p95_seconds':percentile(html,.95),
        'ocr_seconds_sum':sum(ocr),'pdf_parse_seconds':None,
        'cpu_self_plus_children_seconds':cpu,'cpu_utilization_percent':100*cpu/elapsed,
        'max_worker_rss_kib':max(profile['self_usage']['ru_maxrss'] for profile in profiles),
        'max_child_rss_kib':max(profile['child_usage']['ru_maxrss'] for profile in profiles),
        'source_lock_query_p95_seconds':percentile(source_locks,.95),'source_lock_query_max_seconds':max(source_locks,default=0),
        'transaction_p95_seconds':percentile(transactions,.95),'accepted_markdown_chars_per_second':chars/elapsed,
        'created':run['summary']['created'],'review':run['summary']['review_required'],'technical_failed':run['summary']['technical_failed'],
        'worker_activity_transaction_counts':[len(profile['transactions_seconds']) for profile in profiles]})
(p/'benchmark_summary.json').write_text(json.dumps(rows,indent=2)+'\n')
for row in rows:print(json.dumps(row))
