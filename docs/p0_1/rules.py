"""Experimental identity, validation and loss-aware formatting; no app/DB code."""
from collections import Counter
from datetime import date,datetime
import hashlib
import json
import re
from bs4 import BeautifulSoup,NavigableString
from support import BASE
from sample_import import parse_html as p0_html,OMIT

def compact(s):return re.sub(r'\s+',' ',s).strip()
def digest(s):return hashlib.sha256(s.encode()).hexdigest()

def fingerprint(meta):
    bodies=sorted(tuple(compact(str(x)).casefold() for x in row[1:])
                  for row in meta.get('accepting_bodies',[]) if len(row)>=6)
    if not bodies or not any(re.search(r'\d{2}\.\d{2}\.\d{4}',str(x)) for b in bodies for x in b):
        return None
    return [meta.get('Вид акта'),meta.get('Форма акта'),bodies,
            meta.get('Регистрационный № МЮ'),meta.get('№ издания официального источника')]

def identity_components(graph):
    """Only reciprocal source language edges with agreeing card requisites."""
    edges=[];rejected=[];neighbors={u:set() for u in graph}
    for u,node in graph.items():
        for v in node['links'].values():
            if u==v:continue
            reason=None
            if v not in graph:reason='target_not_fetched'
            elif u not in graph[v]['links'].values():reason='not_reciprocal'
            elif fingerprint(node['metadata']) is None:reason='missing_requisites'
            elif not requisites_agree(node['metadata'],graph[v]['metadata']):reason='requisites_conflict'
            if reason:rejected.append(dict(source=u,target=v,reason=reason));continue
            neighbors[u].add(v);neighbors[v].add(u);edges.append((u,v))
    components=[];seen=set()
    for root in sorted(graph):
        if root in seen:continue
        todo=[root];group=[]
        while todo:
            u=todo.pop()
            if u in seen:continue
            seen.add(u);group.append(graph[u]['id']);todo.extend(neighbors[u]-seen)
        components.append(sorted(group))
    return dict(components=components,verified_edges=edges,rejected_edges=rejected,admissible=not rejected,
                canonical_candidates=[min(group) for group in components])

def requisites_agree(left,right):
    a,b=fingerprint(left),fingerprint(right)
    if a is None or b is None or a[:3]!=b[:3]:return False
    # A missing publication field in translated metadata is not a contradiction.
    return all(not x or not y or x==y for x,y in zip(a[3:],b[3:]))

MONTHS={'yanvar':1,'январ':1,'fevral':2,'феврал':2,'mart':3,'март':3,'aprel':4,'апрел':4,
        'may':5,'май':5,'iyun':6,'июн':6,'iyul':7,'июл':7,'avgust':8,'август':8,
        'sentabr':9,'сентябр':9,'oktabr':10,'октябр':10,'noyabr':11,'ноябр':11,'dekabr':12,'декабр':12}

def status_validation(meta,text,as_of=date(2026,10,28),ocr=False,listing_status=None):
    errors=[];raw=meta.get('Статус акта','');effective=None
    try:effective=datetime.strptime(meta.get('Дата вступления в силу',''),'%d.%m.%Y').date()
    except ValueError:pass
    if len(re.findall(r'\d{2}\.\d{2}\.\d{4}',meta.get('Дата вступления в силу','')))>1:
        errors.append('multiple_source_effective_dates')
    candidate=None
    if raw=='Действующий' or (raw=='Amalda' and listing_status=='Y'):
        if effective is None:errors.append('missing_effective_date')
        else:candidate='pending' if effective>as_of else 'active'
    elif raw in ['Утративший силу','Утратил силу','Не действующий','Принят в новой редакции']:
        candidate='inactive'
        if raw=='Не действующий' and (effective is None or effective>as_of):
            errors.append('not_active_date_ambiguous')
    else:errors.append('unknown_source_status')
    if listing_status=='Y' and candidate=='inactive':errors.append('listing_card_status_conflict')
    if listing_status in ['R','N'] and candidate in ['active','pending']:errors.append('listing_card_status_conflict')
    statements=[]
    normalized=compact(text)
    pattern=r'(?:Ушбу|Мазкур|Ushbu|Mazkur)\s+(?:қарор|Қонун|qaror|Qonun)\b.{0,220}?(?:кучга\s+киради|kuchga\s+kiradi)'
    for match in re.finditer(pattern,normalized,re.I):
        sentence=match[0]
        if re.search(r'\b(?:band|qism|банд|қисм)',sentence,re.I):continue
        dm=re.search(r'(20\d{2})\s*[-–—]?\s*(?:yil|йил)\s*(\d{1,2})\s*[-–—]?\s*('+ '|'.join(MONTHS)+r')',sentence,re.I)
        if dm:
            try:d=date(int(dm[1]),MONTHS[dm[3].lower()],int(dm[2]))
            except ValueError:errors.append('invalid_text_date');continue
            statements.append(dict(date=d.isoformat(),excerpt=sentence))
            if effective and d!=effective:errors.append('effective_date_conflict')
    if ocr:errors.append('ocr_status_evidence_needs_review')
    return dict(business_status=None if errors else candidate,source_candidate=candidate,
                effective_at=effective.isoformat() if effective else None,
                validation='review' if errors else 'valid',issues=sorted(set(errors)),text_statements=statements)

SUP=str.maketrans('0123456789+-()','⁰¹²³⁴⁵⁶⁷⁸⁹⁺⁻⁽⁾')
def html_improved(body):
    soup=BeautifulSoup(body,'lxml');root=soup.select_one('#divCont')
    if not root:raise ValueError('missing content root')
    for element in root.select(OMIT):element.decompose()
    original_images=[x.get('src') for x in root.select('img[src]')]
    sup=[];unknown=[]
    for element in list(root.select('sup')):
        value=compact(element.get_text())
        if not value and not element.select('img'):
            element.decompose();continue
        if re.fullmatch(r'[0-9+()\-]+',value):
            replacement=value.translate(SUP);sup.append(dict(source=value,rendered=replacement))
            element.replace_with(NavigableString(replacement))
        else:
            unknown.append(value)
            element.replace_with(NavigableString('^{'+value+'}'))
    root.smooth()
    complex_tables=[];all_tables=len(root.select('table'))
    # Row/column coordinates retain merged-cell geometry in Markdown records.
    # Header semantics still require review; this is not permission to auto-save.
    for table_index,table in enumerate(list(root.select('table')),1):
        if table.find_parent('table'):continue
        if not table.select('[rowspan],[colspan]'):continue
        occupied={};cells=[];invalid=[]
        for row_index,row in enumerate(table.find_all('tr'),1):
            if row.find_parent('table') is not table:continue
            col=1
            for cell in row.find_all(['td','th'],recursive=False):
                while (row_index,col) in occupied:col+=1
                rs=int(cell.get('rowspan','1'));cs=int(cell.get('colspan','1'))
                value=compact(cell.get_text(' ',strip=True))
                if rs<1 or cs<1 or rs>1000 or cs>1000:invalid.append('span_out_of_bounds');continue
                for r in range(row_index,row_index+rs):
                    for c in range(col,col+cs):
                        if (r,c) in occupied:invalid.append('overlapping_span')
                        occupied[r,c]=len(cells)
                cells.append(dict(row=row_index,col=col,rowspan=rs,colspan=cs,text=value,
                                  nested_table=bool(cell.select('table'))))
                col+=cs
        lines=[f'\n\n**Jadval {table_index} — kataklar va birlashtirishlar**\n',
               '| Katak | Qator span | Ustun span | Matn |','| --- | --- | --- | --- |']
        for c in cells:
            lines.append(f'| R{c["row"]}C{c["col"]} | {c["rowspan"]} | {c["colspan"]} | '+c['text'].replace('|','\\|')+' |')
        table.replace_with(NavigableString('\n'.join(lines)+'\n\n'))
        complex_tables.append(dict(index=table_index,cells=cells,invalid_spans=invalid,
                                   nested_tables=any(x['nested_table'] for x in cells)))
    md,q=p0_html(str(soup))
    q['images']=original_images
    if original_images:q['issues'].append('unextracted_images')
    q['superscripts']=sup;q['unsupported_superscripts']=unknown
    q['complex_tables']=complex_tables;q['source_table_count']=all_tables
    # The source-derived cell inventory is checked separately from token parity.
    normalized_md=compact(md)
    q['cell_texts_retained']=all(c['text'].replace('|','\\|') in normalized_md for t in complex_tables for c in t['cells'])
    if complex_tables:q['issues'].append('complex_table_semantics_review')
    if unknown:q['issues'].append('unsupported_superscript')
    if not q['cell_texts_retained']:q['issues'].append('table_cell_loss')
    if any(t['invalid_spans'] or t['nested_tables'] for t in complex_tables):q['issues'].append('table_geometry_ambiguous')
    q['issues']=sorted(set(q['issues']))
    return md,q

def safe_update(old,candidate):
    """Admission contract used by the temporary harness: atomic whole candidate."""
    if candidate['validation']!='valid' or candidate.get('quality_issues'):
        return old,'rejected_preserved'
    if old and candidate.get('content') and len(candidate['content'])<len(old.get('content',''))*.7:
        return old,'rejected_shrinkage'
    return candidate,'unchanged' if old==candidate else 'accepted'

def checkpoint_accept(checkpoint,page):
    """A rejected/expired/cycling page never advances the durable checkpoint."""
    ids=[r['id'] for r in page.get('rows',[])]
    if page.get('total')!=checkpoint['source_total'] or (checkpoint['source_total'] and not ids):
        return checkpoint,'restart_segment'
    page_hash=digest(json.dumps(ids))
    if page_hash in checkpoint.get('page_hashes',[]):return checkpoint,'restart_segment'
    updated=dict(checkpoint,seen_ids=sorted(set(checkpoint['seen_ids'])|set(ids)),
                 page_committed=checkpoint['page_committed']+1,
                 page_hashes=checkpoint.get('page_hashes',[])+[page_hash])
    return updated,'committed'

def run_completion(discovery_complete,coverage_reconciled,pending_jobs,errors,all_languages):
    if pending_jobs:return 'running'
    if not discovery_complete or not coverage_reconciled:return 'failed'
    if errors or not all_languages:return 'completed_with_errors'
    return 'completed'
