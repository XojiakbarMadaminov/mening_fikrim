"""Isolated 30-document spike, derived Markdown + temporary SQLite only.

This is an experimental adapter, not code for the future Laravel application.
"""
import hashlib
import json
import os
from pathlib import Path
import re
import sqlite3
import subprocess
import time
from datetime import date, datetime, timezone
from urllib.parse import urljoin
from bs4 import BeautifulSoup
from pypdf import PdfReader
from http_probe import TMP, EVIDENCE, fetch

AS_OF = date(2026, 10, 28)
LANG = {'Рус':'ru','Eng':'en','Ўзб':'uz-Cyrl','O’zb':'uz-Latn'}
OMIT = '.lx_elem2,.COMMENT,.COMMENTLEXUZ,.CHANGES_ORIGINS,.INDEXES_ON_REF,script,style'


def compact(text):
    return re.sub(r'\s+', ' ', text).strip()


def get(url, name):
    path = TMP / f'{name}.body'
    return path.read_bytes() if path.exists() else fetch(url, name, timeout=(10,90)).content


def card_parse(body):
    soup = BeautifulSoup(body, 'lxml')
    fields = {}
    for label in soup.select('td.lbl'):
        sibling = label.find_next_sibling('td')
        if sibling:
            fields[label.get_text(' ', strip=True)] = sibling.get_text(' ', strip=True)
    fields['accepting_bodies'] = [[x.get_text(' ',strip=True) for x in row.select('td')]
                                 for row in soup.select('#bodyTBody tr')]
    return fields


def status_of(meta):
    raw = meta.get('Статус акта','')
    effective = meta.get('Дата вступления в силу','')
    match = re.search(r'\d{2}\.\d{2}\.\d{4}',effective)
    future = bool(match and datetime.strptime(match[0],'%d.%m.%Y').date() > AS_OF)
    if raw == 'Действующий':
        return 'pending' if future else 'active'
    if raw in ['Утратил силу','Утративший силу','Принят в новой редакции','Не действующий']:
        return 'inactive'
    return 'unresolved'


def languages(soup, current_url):
    variants = {}
    for element in soup.select('.docContentHeader__item-link[title]'):
        label = element.get_text(' ',strip=True)
        if label not in LANG:
            continue
        match = re.search(r"openUrl\('([^']+)'",element.get('onclick',''))
        if match:
            variants[LANG[label]] = urljoin('https://lex.uz',match[1])
        elif 'active' in element.get('class',[]):
            variants[LANG[label]] = current_url
    return variants


def parse_html(body):
    soup = BeautifulSoup(body,'lxml')
    root = soup.select_one('#divCont')
    if root is None:
        raise ValueError('Missing #divCont')
    comment_count = len(root.select('.COMMENT,.COMMENTLEXUZ,.CHANGES_ORIGINS'))
    for x in root.select(OMIT):
        x.decompose()
    # Hidden source/index/revision fields use labels s527/s1089/s1104.
    for label in list(root.select('label[id]')):
        if label.get('id') in ['s527','s1089','s1104','s5987','s5988']:
            parent = label.parent
            if parent is not root:
                parent.decompose()
    expected = compact(root.get_text(' ',strip=True))
    images = [x.get('src') for x in root.select('img[src]')]
    complex_tables = len(root.select('td[rowspan],th[rowspan],td[colspan],th[colspan]'))
    tables = root.select('table')
    # Table cells retain reading order; complex spans stay explicit review issues.
    for table in reversed(tables):
        lines = []
        for row in table.select('tr'):
            cells = row.find_all(['td','th'],recursive=False)
            if cells:
                lines.append([compact(c.get_text(' ',strip=True)).replace('|','\\|') for c in cells])
        if lines:
            width=max(map(len,lines))
            rendered = ['| ' + ' | '.join(row+['']*(width-len(row)))+' |' for row in lines]
            rendered.insert(1,'| '+' | '.join(['---']*width)+' |')
            table.replace_with('\n\n'+'\n'.join(rendered)+'\n\n')
    blocks=[]
    headings={'h1':0,'h2':0,'h3':0}
    for child in root.children:
        if isinstance(child,str):
            if child.strip():blocks.append(str(child).strip())
            continue
        text=compact(child.get_text(' ',strip=True))
        if not text:continue
        classes=child.get('class',[])
        prefix=''
        if 'ACT_TITLE' in classes:
            prefix='# '; headings['h1']+=1
        elif 'TEXT_HEADER_DEFAULT' in classes or any('APPENDIX' in c for c in classes):
            prefix='## '; headings['h2']+=1
        elif 'CLAUSE_DEFAULT' in classes:
            prefix='### '; headings['h3']+=1
        # Tables may be nested in an outer block: preserve their newlines.
        if '| ---' in child.get_text():
            text=child.get_text('\n',strip=True)
        blocks.append(prefix+text)
    markdown='\n\n'.join(blocks)+'\n'
    # Compare ordered alphanumeric tokens, independent of Markdown punctuation.
    tokens=lambda x: re.findall(r'[^\W_]+',x,flags=re.UNICODE)
    source_tokens=tokens(expected)
    out_tokens=tokens(markdown)
    fidelity=source_tokens==out_tokens
    issues=[]
    placeholder_patterns = ['Текст акта приводится на', 'The text of the act is given in',
                            'Hujjat matni', 'Матн акта']
    metadata_only = any(p in expected for p in placeholder_patterns[:2])
    if metadata_only:issues.append('source_metadata_only_language')
    if not fidelity:issues.append('html_token_order_or_loss')
    if images:issues.append('unextracted_images')
    if complex_tables:issues.append('complex_table_spans_need_review')
    return markdown,dict(chars=len(markdown),source_chars=len(expected),headings=headings,
                         tables=len(tables),complex_span_cells=complex_tables,images=images,
                         comments_removed=comment_count,token_fidelity=fidelity,
                         source_token_count=len(source_tokens),output_token_count=len(out_tokens),
                         metadata_only=metadata_only,issues=issues)


def parse_pdf(body, name, lang):
    if not body.startswith(b'%PDF-'):
        raise ValueError('Not PDF magic bytes')
    path=TMP/f'{name}.pdf'
    path.write_bytes(body)
    reader=PdfReader(path)
    proc=subprocess.run(['pdftotext','-layout',str(path),'-'],capture_output=True,timeout=90,check=True)
    pages=proc.stdout.decode('utf8').split('\f')
    if pages and not pages[-1].strip():pages.pop()
    if len(pages)!=len(reader.pages):raise ValueError('PDF page count mismatch')
    stats=[]; issues=[]; ocr_pages=[]
    for index,page in enumerate(reader.pages):
        chars=len(compact(pages[index]))
        image_count=len(page.images)
        info=dict(page=index+1,text_chars=chars,images=image_count,ocr=False)
        if chars<100 and image_count:
            if len(ocr_pages)>=20:
                issues.append('ocr_page_budget_exceeded')
            else:
                prefix=TMP/f'{name}_p{index+1}'
                subprocess.run(['pdftoppm','-f',str(index+1),'-l',str(index+1),'-scale-to','2400',
                                '-singlefile','-png',str(path),str(prefix)],capture_output=True,timeout=90,check=True)
                env=os.environ.copy()
                env['LD_LIBRARY_PATH']=str(TMP/'ocr/usr/lib/x86_64-linux-gnu')
                env['TESSDATA_PREFIX']=str(TMP/'ocr/usr/share/tesseract-ocr/5/tessdata')
                env['OMP_THREAD_LIMIT']='1'
                # The source's Latin title can wrap a physically Cyrillic scan.
                ocrlang={'uz-Latn':'uzb_cyrl+uzb','uz-Cyrl':'uzb_cyrl+uzb','ru':'rus','en':'eng'}.get(lang,'eng')
                try:
                    ocr=subprocess.run([str(TMP/'ocr/usr/bin/tesseract'),str(prefix)+'.png','stdout','-l',ocrlang],
                                       capture_output=True,text=True,env=env,timeout=120,check=True)
                    pages[index]=ocr.stdout
                    info.update(ocr=True,ocr_chars=len(compact(ocr.stdout)),ocr_models=ocrlang)
                    ocr_pages.append(index+1)
                finally:
                    Path(str(prefix)+'.png').unlink(missing_ok=True)
                issues.append('ocr_text_needs_manual_review')
        if chars<100 and not info['ocr']:issues.append('sparse_page_needs_review')
        stats.append(info)
    # Keep every page boundary; plain PDF extraction does not establish hierarchy.
    markdown='\n\n'.join(f'<!-- page {i+1} -->\n\n'+p.strip() for i,p in enumerate(pages))+'\n'
    cyrillic=len(re.findall('[А-Яа-яЎўҚқҒғҲҳ]',markdown))
    latin=len(re.findall('[A-Za-z]',markdown))
    detected_script='Cyrl' if cyrillic>latin else 'Latn'
    if lang.startswith('uz-') and not lang.endswith(detected_script):
        issues.append('pdf_script_differs_from_metadata')
    if any(x['images'] for x in stats):issues.append('pdf_images_or_diagrams_need_review')
    return markdown,dict(chars=len(markdown),pages=len(pages),page_stats=stats,ocr_pages=ocr_pages,
                         issues=sorted(set(issues)),hierarchy_verified=False,detected_script=detected_script)


def main():
    started=time.time()
    discovery=json.loads((EVIDENCE/'discovery.json').read_text())
    seeds=[]
    selections=[('official_month',10),('nat_codes',2),('loc',3),('int',3),
                ('tech',3),('court',3),('oliy',3),('nat_inactive',2),('nat_not_active',1)]
    for section,n in selections:
        item=discovery[section]
        rows=item.get('rows', item.get('pages',[{}])[0].get('rows',[]))
        for row in rows[:n]:seeds.append(dict(section=section,**row))
    assert len(seeds)==30 and len({x['id'] for x in seeds})==30
    db=sqlite3.connect(TMP/'test_import.sqlite')
    db.execute('CREATE TABLE IF NOT EXISTS results (id TEXT PRIMARY KEY, payload TEXT NOT NULL)')
    existing=json.loads((EVIDENCE/'sample_manifest.json').read_text()) if (EVIDENCE/'sample_manifest.json').exists() else []
    manifest=[]
    (EVIDENCE/'markdown').mkdir(exist_ok=True)
    for index,seed in enumerate(seeds,1):
        old=next((x for x in existing if x['id']==seed['id']),None)
        if old:
            manifest.append(old)
            continue
        result=dict(seed,variants=[],errors=[])
        try:
            body=get(seed['url'],f'sample_{seed["id"]}')
            soup=BeautifulSoup(body,'lxml')
            scripts='\n'.join(x.get_text() for x in soup.select('script'))
            cardmatch=re.search(r"showIframeCard\('([^']*card1/[^']+)'",scripts)
            if not cardmatch:raise ValueError('No source-discovered card1 URL')
            cardurl=urljoin(seed['url'],cardmatch[1])
            result['metadata_url']=cardurl
            result['metadata']=card_parse(get(cardurl,f'sample_card_{seed["id"]}'))
            result['status']=status_of(result['metadata'])
            if result['status']=='unresolved':result['errors'].append('unresolved_status')
            variants=languages(soup,seed['url'])
            if not variants:
                # PDF viewer's global interface language menu is not a source language list.
                source_lang=result['metadata'].get('Язык акта','')
                lang={'Узбекский (Л)':'uz-Latn','Узбекский':'uz-Cyrl','Русский':'ru','Английский':'en'}.get(source_lang)
                if not lang:raise ValueError('Unknown PDF source language: '+source_lang)
                variants={lang:seed['url']}
            result['offered_languages']=variants
            for lang,url in variants.items():
                variant=dict(language=lang,url=url)
                try:
                    vbody=body if url==seed['url'] else get(url,f'variant_{seed["id"]}_{lang}')
                    vsoup=BeautifulSoup(vbody,'lxml')
                    pdfmatch=re.search(r'PDFObject\.embed\("([^"]+)"',vbody.decode('utf8',errors='replace'))
                    if pdfmatch:
                        pdfurl=urljoin(url,pdfmatch[1])
                        pdfbody=get(pdfurl,f'bytes_{seed["id"]}_{lang}')
                        markdown,quality=parse_pdf(pdfbody,f'parse_{seed["id"]}_{lang}',lang)
                        variant.update(format='PDF',resource_url=pdfurl)
                    else:
                        markdown,quality=parse_html(vbody)
                        variant['format']='HTML'
                    out=EVIDENCE/'markdown'/f'{seed["id"]}_{lang}.md'
                    out.write_text(markdown)
                    metadata_only=quality.get('metadata_only',False)
                    variant.update(quality=quality,markdown=str(out.relative_to(EVIDENCE)),
                                   sha256=hashlib.sha256(markdown.encode()).hexdigest(),parsed=not metadata_only,
                                   skipped=metadata_only)
                except Exception as exc:
                    variant.update(parsed=False,error=f'{type(exc).__name__}: {exc}')
                    result['errors'].append(f'{lang}: '+variant['error'])
                result['variants'].append(variant)
            result['parse_success']=bool(result['variants'] and all(v['parsed'] or v.get('skipped') for v in result['variants']))
            result['strict_quality_pass']=result['parse_success'] and not result['errors'] and all(not v['quality']['issues'] for v in result['variants'] if v['parsed'])
        except Exception as exc:
            result.update(parse_success=False,strict_quality_pass=False)
            result['errors'].append(f'{type(exc).__name__}: {exc}')
        db.execute('INSERT OR REPLACE INTO results VALUES (?,?)',(seed['id'],json.dumps(result,ensure_ascii=False)))
        db.commit()
        manifest.append(result)
        (EVIDENCE/'sample_manifest.json').write_text(json.dumps(manifest,ensure_ascii=False,indent=2))
        print('IMPORT',index,seed['id'],result.get('status'),'parsed',result['parse_success'],
              'quality',result['strict_quality_pass'],'languages',list(result.get('offered_languages',{})),result['errors'],flush=True)
    stats=dict(started_at=datetime.fromtimestamp(started,timezone.utc).isoformat(),
               finished_at=datetime.now(timezone.utc).isoformat(),elapsed_seconds=round(time.time()-started,3),
               sample_count=len(manifest),parse_success=sum(x['parse_success'] for x in manifest),
               strict_quality_pass=sum(x['strict_quality_pass'] for x in manifest),
               sqlite_rows=db.execute('SELECT COUNT(*) FROM results').fetchone()[0])
    (EVIDENCE/'import_stats.json').write_text(json.dumps(stats,indent=2))
    print('STATS',stats,flush=True)
    db.close()


if __name__=='__main__':main()
