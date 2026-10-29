"""Post-run audit and reduced source fixtures. Reads only temporary source files."""
from collections import Counter
from datetime import datetime, timezone
import hashlib
import json
from pathlib import Path
import re
import sqlite3
from bs4 import BeautifulSoup
from sample_import import EVIDENCE, TMP, status_of, parse_html


def write(name,value):
    (EVIDENCE/name).write_text(json.dumps(value,ensure_ascii=False,indent=2))


manifest=json.loads((EVIDENCE/'sample_manifest.json').read_text())
fixtures={}
for doc in manifest:
    body=(TMP/f'sample_{doc["id"]}.body').read_bytes()
    soup=BeautifulSoup(body,'lxml')
    doc['status']=status_of(doc['metadata'])
    doc['errors']=[x for x in doc['errors'] if x!='unresolved_status']
    doc['document_quality_issues']=[]
    if doc['status']=='unresolved':doc['document_quality_issues'].append('unresolved_status')
    source_excerpt=[]
    for selector in ['.ACT_TITLE','.TEXT_HEADER_DEFAULT','.CLAUSE_DEFAULT','.ACT_TEXT','.COMMENT']:
        found=soup.select_one('#divCont '+selector)
        if found:source_excerpt.append(str(found))
    metadata=doc['metadata']
    required={'title':metadata.get('Наименование акта'), 'type':metadata.get('Форма акта'),
              'status':metadata.get('Статус акта'),'accepting_bodies':metadata.get('accepting_bodies')}
    missing=[k for k,v in required.items() if not v]
    if missing:doc['document_quality_issues'].append('missing_metadata:'+','.join(missing))
    fixtures[doc['id']]=dict(source_url=doc['url'],source_sha256=hashlib.sha256(body).hexdigest(),
        metadata_url=doc['metadata_url'],metadata=metadata,
        language_controls=[str(x) for x in soup.select('.docContentHeader__item-link[title]')[:16]],
        global_interface_menu=str(soup.select_one('.languageSelector') or ''),
        selected_revision=[x.get_text(' ',strip=True) for x in soup.select('.lx_date_selected')],
        excerpt_html='<div id="divCont">'+''.join(source_excerpt)+'</div>',
        pdf_embed=re.findall(r'PDFObject\.embed\("([^"]+)"',body.decode('utf8',errors='replace')))
    for variant in doc['variants']:
        q=variant['quality']
        if variant['format']=='HTML':
            name=f'sample_{doc["id"]}' if variant['url']==doc['url'] else f'variant_{doc["id"]}_{variant["language"]}'
            vbody=(TMP/f'{name}.body').read_bytes()
            vsoup=BeautifulSoup(vbody,'lxml')
            root=vsoup.select_one('#divCont')
            q['superscript_elements']=len(root.select('sup'))
            # Record a format limitation rather than certify legal numbering.
            if q['superscript_elements'] and not variant.get('skipped'):
                q['issues'].append('superscript_numbering_needs_review')
        else:
            q['issues']=sorted(set(q['issues']+['pdf_structure_unverified']))
            if not q.get('detected_script'):
                md=(EVIDENCE/variant['markdown']).read_text()
                cyr=len(re.findall('[А-Яа-яЎўҚқҒғҲҳ]',md))
                lat=len(re.findall('[A-Za-z]',md))
                q['detected_script']='Cyrl' if cyr>lat else 'Latn'
            if variant['language'].startswith('uz-') and not variant['language'].endswith(q['detected_script']):
                variant['declared_language']=variant['language']
                variant['language']='uz-'+q['detected_script']
                q['issues']=sorted(set(q['issues']+['pdf_script_differs_from_metadata']))
            doc['document_quality_issues'].append('pdf_language_coverage_unconfirmed')
        variant['sha256']=hashlib.sha256((EVIDENCE/variant['markdown']).read_bytes()).hexdigest()
    if doc['id']=='-8553692':
        # Manually verified against rendered page 1, clause 4, not a generic legal rule.
        doc['source_card_status']='active'
        doc['status']='unresolved'
        doc['document_quality_issues'].append('effective_date_conflict_card_2026-10-23_vs_pdf_2027-01-01')
    doc['document_quality_issues']=sorted(set(doc['document_quality_issues']))
    doc['full_text_languages']=sorted(set(v['language'] for v in doc['variants'] if v['parsed']))
    doc['strict_quality_pass']=bool(doc['parse_success'] and not doc['errors'] and
                                  not doc['document_quality_issues'] and
                                  all(not v['quality']['issues'] for v in doc['variants'] if v['parsed']))
write('source_fixtures.json',fixtures)
write('sample_manifest.json',manifest)

requests=[json.loads(x) for x in (EVIDENCE/'requests.jsonl').read_text().splitlines()]
variants=[v for d in manifest for v in d['variants'] if v['parsed']]
starts=[datetime.fromisoformat(x['started_at']) for x in requests]
gaps=[(b-a).total_seconds() for a,b in zip(starts,starts[1:])]
stats=json.loads((EVIDENCE/'import_stats.json').read_text())
start=min(datetime.fromisoformat(r['started_at']) for r in requests if r['name']=='sample_8557098')
finish=datetime.fromisoformat(stats['finished_at'])
stats.update(import_wall_seconds=round((finish-start).total_seconds(),3),
    import_first_request=start.isoformat(),import_finished=finish.isoformat(),
    parse_success=sum(d['parse_success'] for d in manifest),
    strict_quality_pass=sum(d['strict_quality_pass'] for d in manifest),
    review_documents=sum(not d['strict_quality_pass'] for d in manifest),
    extraction_errors=sum(not d['parse_success'] for d in manifest),
    status_counts=dict(Counter(d['status'] for d in manifest)),
    html_documents=sum(any(v['format']=='HTML' and v['parsed'] for v in d['variants']) for d in manifest),
    pdf_documents=sum(any(v['format']=='PDF' for v in d['variants']) for d in manifest),
    text_pdf_documents=sum(any(v['format']=='PDF' and not v['quality'].get('ocr_pages') for v in d['variants']) for d in manifest),
    ocr_documents=sum(any(v['quality'].get('ocr_pages') for v in d['variants']) for d in manifest),
    ocr_pages=sum(len(v['quality'].get('ocr_pages',[])) for v in variants),
    pdf_pages=sum(v['quality'].get('pages',0) for v in variants),
    full_text_variants=len(variants),
    metadata_only_variants=sum(v.get('skipped',False) for d in manifest for v in d['variants']),
    languages=dict(Counter(v['language'] for v in variants)),
    html_token_fidelity_pass=sum(v['quality'].get('token_fidelity',False) for v in variants if v['format']=='HTML'),
    html_variants=sum(v['format']=='HTML' for v in variants),
    table_variants=sum(v['quality'].get('tables',0)>0 for v in variants),
    image_variants=sum(bool(v['quality'].get('images')) for v in variants if v['format']=='HTML'),
    requests=len(requests),http_statuses=dict(Counter(str(r.get('status','client_error')) for r in requests)),
    response_bytes=sum(r.get('bytes',0) for r in requests),
    observed_min_request_start_gap_seconds=round(min(gaps),6),
    first_request=starts[0].isoformat(),last_request=starts[-1].isoformat(),
    source_probe_span_seconds=round((starts[-1]-starts[0]).total_seconds(),3))
latencies=sorted(r['elapsed_seconds'] for r in requests if r.get('status')==200)
stats['http_200_latency_seconds']={'median':latencies[len(latencies)//2], 'max':max(latencies)}
write('import_stats.json',stats)
db=sqlite3.connect(TMP/'test_import.sqlite')
for d in manifest:
    db.execute('INSERT OR REPLACE INTO results VALUES (?,?)',(d['id'],json.dumps(d,ensure_ascii=False)))
db.commit()
audit={'database':str(TMP/'test_import.sqlite'),'rows':db.execute('SELECT COUNT(*) FROM results').fetchone()[0],
       'distinct_ids':db.execute('SELECT COUNT(DISTINCT id) FROM results').fetchone()[0],
       'production_database_accessed':False,'schema':'single experimental results table; not application schema'}
assert audit['rows']==audit['distinct_ids']==30
write('temp_db_audit.json',audit)
db.close()
print(json.dumps(stats,ensure_ascii=False,indent=2))
