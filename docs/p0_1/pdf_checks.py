"""Page geometry, Poppler text, Tesseract TSV confidence and review admission."""
import csv
from collections import defaultdict
import io
import json
import os
from pathlib import Path
import re
import subprocess
import time
import pdfplumber
from support import HERE,TMP,save
from rules import compact,status_validation

OCR_RUNTIME=Path(os.environ.get('LEX_P01_OCR_ROOT','/tmp/lex-p0-20261028-fLI3Zm/ocr'))

def page_decision(image_coverage,body_chars,text_height):
    needs=image_coverage>.6 and (body_chars<100 or text_height<.35)
    return dict(needs_ocr=needs,image_backed_text_review=image_coverage>.6 and not needs)

def analyze(doc,variant):
    raw=(TMP/(variant['pdf_name']+'.body')).read_bytes()
    if not raw.startswith(b'%PDF-'):raise ValueError('PDF signature missing')
    path=TMP/(doc['id']+'.pdf');path.write_bytes(raw)
    txt=subprocess.run(['pdftotext','-layout',str(path),'-'],capture_output=True,check=True,timeout=90).stdout.decode()
    pages=txt.split('\f')
    if not pages[-1].strip():pages.pop()
    stats=[];ocr_count=0
    with pdfplumber.open(path) as pdf:
        if len(pdf.pages)!=len(pages):raise ValueError('page coverage mismatch')
        for i,page in enumerate(pdf.pages):
            area=page.width*page.height
            coverage=min(1,sum(max(0,img['x1']-img['x0'])*max(0,img['bottom']-img['top']) for img in page.images)/area)
            body_chars=[c for c in page.chars if page.height*.08<c['top']<page.height*.92]
            height=(max(c['bottom'] for c in body_chars)-min(c['top'] for c in body_chars))/page.height if body_chars else 0
            decision=page_decision(coverage,len(body_chars),height)
            record=dict(page=i+1,layer_chars=len(compact(pages[i])),body_chars=len(body_chars),
                        image_rectangle_coverage=round(coverage,4),text_vertical_coverage=round(height,4),
                        images=len(page.images),**decision)
            if decision['needs_ocr']:
                prefix=TMP/f'ocr_{doc["id"]}_{i+1}'
                image=Path(str(prefix)+'.png')
                started=time.monotonic()
                try:
                    subprocess.run(['pdftoppm','-f',str(i+1),'-l',str(i+1),'-scale-to','2400','-singlefile',
                                    '-png',str(path),str(prefix)],capture_output=True,check=True,timeout=90)
                    env=os.environ.copy();env['LD_LIBRARY_PATH']=str(OCR_RUNTIME/'usr/lib/x86_64-linux-gnu')
                    env['TESSDATA_PREFIX']=str(OCR_RUNTIME/'usr/share/tesseract-ocr/5/tessdata');env['OMP_THREAD_LIMIT']='1'
                    run=subprocess.run([str(OCR_RUNTIME/'usr/bin/tesseract'),str(image),'stdout','-l','uzb_cyrl+uzb','tsv'],
                                       env=env,capture_output=True,text=True,check=True,timeout=120)
                    words=[x for x in csv.DictReader(io.StringIO(run.stdout),delimiter='\t') if x.get('text','').strip() and float(x.get('conf','-1'))>=0]
                    lines=defaultdict(list)
                    for w in words:lines[(w['block_num'],w['par_num'],w['line_num'])].append(w['text'])
                    pages[i]='\n'.join(' '.join(ws) for ws in lines.values())
                    confidence=[float(w['conf']) for w in words]
                    record.update(ocr_words=len(words),ocr_chars=len(compact(pages[i])),
                                  mean_word_confidence=round(sum(confidence)/len(confidence),2) if confidence else 0,
                                  below_70_fraction=round(sum(x<70 for x in confidence)/len(confidence),4) if confidence else 1,
                                  ocr_seconds=round(time.monotonic()-started,3))
                    ocr_count+=1
                finally:image.unlink(missing_ok=True)
            tables=[]
            if not decision['needs_ocr']:
                for table in page.find_tables():
                    cells=table.extract()
                    tables.append(dict(bbox=table.bbox,rows=len(cells),columns=max(map(len,cells)) if cells else 0,
                                       cells=cells))
            record['tables']=tables
            stats.append(record)
            page.close()
    cyr=len(re.findall('[А-Яа-яЎўҚқҒғҲҳ]', '\n'.join(pages)))
    lat=len(re.findall('[A-Za-z]', '\n'.join(pages)))
    script='Cyrl' if cyr>lat else 'Latn'
    headings=[];rendered=[]
    for i,page in enumerate(pages,1):
        lines=[]
        for line in page.splitlines():
            trimmed=line.strip()
            if re.match(r'^\d+[-. ]+(?:bob|боб)\.',trimmed,re.I):
                lines.append('## '+trimmed);headings.append(dict(page=i,kind='chapter',text=trimmed))
            elif re.match(r'^\d+[-. ]+(?:modda|модда)\.',trimmed,re.I):
                lines.append('### '+trimmed);headings.append(dict(page=i,kind='article',text=trimmed))
            elif re.fullmatch(r'\d+\s*[-–]?\s*(?:ilova|илова)',trimmed,re.I):
                lines.append('## '+trimmed);headings.append(dict(page=i,kind='appendix',text=trimmed))
            else:lines.append(line)
        rendered.append(f'<!-- page {i} -->\n\n'+'\n'.join(lines))
    md='\n\n'.join(rendered)
    issues=['pdf_structure_unverified']
    if any(p['images'] for p in stats):issues.append('image_semantics_unverified')
    if ocr_count:issues.append('ocr_accuracy_not_document_certified')
    if any(p.get('mean_word_confidence',100)<90 or p.get('below_70_fraction',0)>.05 for p in stats):
        issues.append('ocr_confidence_threshold_failed')
    if any(p['image_backed_text_review'] for p in stats):issues.append('image_backed_text_review')
    if any(p['tables'] for p in stats):issues.append('pdf_table_reading_order_review')
    out=HERE/'markdown'/f'{doc["id"]}_uz-{script}.md';out.parent.mkdir(exist_ok=True);out.write_text(md)
    return dict(id=doc['id'],url=doc['url'],format='PDF',pages=len(pages),ocr_pages=ocr_count,
                page_stats=stats,detected_language='uz-'+script,headings=headings,quality_issues=issues,
                markdown=str(out.relative_to(HERE)),status=status_validation(doc['metadata'],md,ocr=bool(ocr_count)),
                page_count_verified=True,full_import_accepted=False)

def table_trials():
    trials=[]
    with pdfplumber.open(TMP/'8557098.pdf') as pdf:
        page=pdf.pages[8]
        for strict in [False,True]:
            for tolerance in [3,5,8]:
                settings=dict(vertical_strategy='lines_strict' if strict else 'lines',
                              horizontal_strategy='lines_strict' if strict else 'lines',
                              snap_tolerance=tolerance,join_tolerance=tolerance)
                tables=page.find_tables(settings)
                trials.append(dict(settings=settings,tables=[dict(rows=len(t.extract()),columns=max(map(len,t.extract())),cells=t.extract()) for t in tables]))
    save('pdf_table_trials.json',trials)

if __name__=='__main__':
    results=[]
    for doc in json.loads((HERE/'acquired.json').read_text()):
        for v in doc['variants']:
            if 'pdf_url' in v:
                results.append(analyze(doc,v));save('pdf_results.json',results)
                print('PDF',doc['id'],'pages',results[-1]['pages'],'ocr',results[-1]['ocr_pages'],flush=True)
    table_trials()
