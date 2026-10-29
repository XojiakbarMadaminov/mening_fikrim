"""Recheck all previously reviewed HTML variants; carry no old pass as a new test."""
import json
import time
from support import HERE,TMP,save
from rules import html_improved,status_validation

results=[]
for doc in json.loads((HERE/'acquired.json').read_text()):
    if any('pdf_url' in v for v in doc['variants']):continue
    variants=[];primary=''
    for v in doc['variants']:
        started=time.monotonic()
        body=(TMP/(v['body_name']+'.body')).read_bytes()
        md,q=html_improved(body)
        target=HERE/'markdown'/f'{doc["id"]}_{v["language"]}.md';target.parent.mkdir(exist_ok=True)
        target.write_text(md)
        variants.append(dict(language=v['language'],url=v['url'],quality=q,skipped=q['metadata_only'],
                             markdown=str(target.relative_to(HERE)),parse_seconds=round(time.monotonic()-started,3)))
        if v['url']==doc['url']:primary=md
    status=status_validation(doc['metadata'],primary)
    result=dict(id=doc['id'],url=doc['url'],variants=variants,status=status,
                strict_pass=status['validation']=='valid' and all(v['skipped'] or not v['quality']['issues'] for v in variants))
    results.append(result);save('html_results.json',results)
    print('HTML',doc['id'],'pass',result['strict_pass'],'issues',[(v['language'],v['quality']['issues']) for v in variants],flush=True)
