"""Derived audit only; no network or database access."""
from collections import Counter
from datetime import datetime,timezone
import json
import re
from bs4 import BeautifulSoup
from support import HERE,BASE,TMP,save,listing
from rules import compact,identity_components,status_validation

def load(name):return json.loads((HERE/name).read_text())

discovery=load('discovery.json')
for name,result in discovery.items():
    if result['total'] is None:
        observed=listing((TMP/(name+'.body')).read_bytes())
        if observed.get('empty_confirmed'):
            result['total']=0;result['pages'][0]=observed;result['reconciled']=True
            result['source_empty_message']='Sizning so‘rovingiz bo‘yicha hujjat topilmadi!'
save('discovery.json',discovery)
pairs=[]
for a,b in [('nat_oct','official_oct'),('nat_oct','unique_oct'),('nat_2000','oliy_2000'),('nat_2010','loc_2010')]:
    overlap=sorted(set(discovery[a]['unique_ids'])&set(discovery[b]['unique_ids']))
    pairs.append(dict(left=a,right=b,intersection_count=len(overlap),ids=overlap,
                      partial_lists=not(discovery[a]['reconciled'] and discovery[b]['reconciled'])))
save('overlaps.json',pairs)

pdfs=load('pdf_results.json')
acquired=load('acquired.json')
for doc in pdfs:
    if any(p.get('mean_word_confidence',100)<90 or p.get('below_70_fraction',0)>.05 for p in doc['page_stats']):
        doc['quality_issues']=sorted(set(doc['quality_issues']+['ocr_confidence_threshold_failed']))
    flags={cls.rsplit('_',1)[-1].upper() for segment in discovery.values() for page in segment['pages']
           for row in page['rows'] if row['id']==doc['id'] for classes in row['status_classes']
           for cls in classes if cls.startswith('status_code_')}
    listing_flag=next(iter(flags)) if len(flags)==1 else None
    meta=next(d['metadata'] for d in acquired if d['id']==doc['id'])
    doc['status']=status_validation(meta,(HERE/doc['markdown']).read_text(),ocr=bool(doc['ocr_pages']),listing_status=listing_flag)
    doc['corroborating_listing_status']=listing_flag
save('pdf_results.json',pdfs)

def normalize(text):return compact(re.sub(r'[^\w\s]',' ',text.casefold()))
def edit_distance(a,b):
    row=list(range(len(b)+1))
    for i,x in enumerate(a,1):
        new=[i]
        for j,y in enumerate(b,1):new.append(min(new[-1]+1,row[j]+1,row[j-1]+(x!=y)))
        row=new
    return row[-1]

gold_specs=[('-8553692',1,'4. Ушбу қарор 2027 йил 1 январдан бошлаб кучга киради.',r'4[.,]?\s+Ушбу қарор 2027.*?кучга киради[.]?'),
            ('-8553258',17,'39. Ушбу Низом талабларининг бузилишида айбдор бўлган шахслар қонунчилик ҳужжатларига мувофиқ жавоб беради.',r'39[.,]?\s+Ушбу Низом.*?жавоб беради[.]?')]
gold=[]
for id,page,reference,pattern in gold_specs:
    doc=next(d for d in pdfs if d['id']==id)
    md=(HERE/doc['markdown']).read_text()
    text=compact(md.split(f'<!-- page {page} -->')[1].split('<!-- page ')[0])
    match=re.search(pattern,text)
    actual=match[0] if match else text
    a,b=normalize(reference),normalize(actual)
    distance=edit_distance(a,b)
    gold.append(dict(id=id,url=doc['url'],page=page,reference=reference,ocr_excerpt=actual,
                     matched=bool(match),normalized_character_errors=distance,
                     normalized_reference_chars=len(a),normalized_CER=round(distance/len(a),6),
                     scope='One manually transcribed clause; not a document-wide accuracy certificate'))
save('ocr_gold.json',gold)

html=load('html_results.json');prior=json.loads((BASE/'sample_manifest.json').read_text())
requests=[json.loads(x) for x in (HERE/'requests.jsonl').read_text().splitlines()]
timing=[json.loads(x) for x in (HERE/'timing.jsonl').read_text().splitlines()]
gaps=[b['monotonic']-a['monotonic'] for a,b in zip(timing,timing[1:])]
identity={k:identity_components(v) for k,v in load('identity_graphs.json').items()};save('identity_results.json',identity)
new_pass=sum(x['strict_pass'] for x in html)
comparison=[]
for doc in prior:
    h=next((x for x in html if x['id']==doc['id']),None)
    p=next((x for x in pdfs if x['id']==doc['id']),None)
    comparison.append(dict(id=doc['id'],url=doc['url'],p0_pass=doc['strict_quality_pass'],
                           p01_retested=bool(h or p),p01_pass=h['strict_pass'] if h else False if p else None,
                           status_validation=h['status'] if h else p['status'] if p else None))
save('comparison.json',comparison)
stats=dict(recorded_at=datetime.now(timezone.utc).isoformat(),requests=len(requests),
           http_statuses=dict(Counter(str(r.get('status','error')) for r in requests)),
           first_request=requests[0]['started_at'],last_request=requests[-1]['started_at'],
           min_monotonic_start_gap_seconds=round(min(gaps),6),
           source_response_bytes=sum(r.get('bytes',0) for r in requests),
           identity_groups=len(identity),identity_nodes=sum(len(g) for g in load('identity_graphs.json').values()),
           identity_directed_edges=sum(len(g['verified_edges']) for g in identity.values()),
           review_seeds_retested=len(html)+len(pdfs),html_seeds=len(html),pdf_seeds=len(pdfs),
           html_full_variants=sum(not v['skipped'] for d in html for v in d['variants']),
           html_metadata_only=sum(v['skipped'] for d in html for v in d['variants']),
           new_pass_in_previous_19=new_pass,remaining_review_in_previous_19=19-new_pass,
           p0_pass_carried_not_retested=sum(d['strict_quality_pass'] for d in prior),
           combined_30_with_carry_forward_pass=11+new_pass,
           pdf_pages=sum(d['pages'] for d in pdfs),ocr_pages=sum(d['ocr_pages'] for d in pdfs),
           ocr_seconds=sum(p.get('ocr_seconds',0) for d in pdfs for p in d['page_stats']),
           ocr_page_confidence_range=[min(p['mean_word_confidence'] for d in pdfs for p in d['page_stats'] if p['needs_ocr']),
                                      max(p['mean_word_confidence'] for d in pdfs for p in d['page_stats'] if p['needs_ocr'])],
           ocr_confidence_review_pages=sum(p.get('mean_word_confidence',100)<90 or p.get('below_70_fraction',0)>.05 for d in pdfs for p in d['page_stats']),
           discovery_cases=len(discovery),fully_reconciled_cases=sum(d['reconciled'] for d in discovery.values()),
           global_coverage_confirmed=False,production_database_accessed=False,laravel_created=False)
save('stats.json',stats)
print(json.dumps(stats,ensure_ascii=False,indent=2));print('GOLD',gold)
