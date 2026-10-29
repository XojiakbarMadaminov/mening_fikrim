"""P0.1-only recorder, reusing the verified P0 HTTP and listing mechanisms."""
import hashlib
import json
import os
from pathlib import Path
import sys
import time

CODE=Path(__file__).resolve().parent
HERE=Path(os.environ.get('LEX_P01_OUTPUT',str(CODE)))
HERE.mkdir(parents=True,exist_ok=True)
BASE=CODE.parent/'p0'
TMP=Path(os.environ.get('LEX_P01_TMP','/tmp/lex-p01-20261028-NVkDdJ'))
TMP.mkdir(parents=True,exist_ok=True)
sys.path.insert(0,str(BASE))
import http_probe as http
http.TMP=TMP
http.EVIDENCE=HERE
from discovery_probe import listing as p0_listing
from sample_import import card_parse,languages

def listing(body):
    from bs4 import BeautifulSoup
    result=p0_listing(body)
    if result['total'] is None and not result['rows']:
        text=BeautifulSoup(body,'lxml').get_text(' ',strip=True)
        if 'Sizning so‘rovingiz bo‘yicha hujjat topilmadi!' in text:
            result.update(total=0,empty_confirmed=True)
    return result

def save(name,data):
    (HERE/name).write_text(json.dumps(data,ensure_ascii=False,indent=2))

def request(url,name,**kwargs):
    # Single worker. Additional monotonic margin avoids the prior wall-clock jitter.
    clock=TMP/'monotonic_gate'
    previous=float(clock.read_text()) if clock.exists() else 0
    time.sleep(max(0,2.05-(time.monotonic()-previous)))
    started=time.monotonic()
    clock.write_text(str(started))
    with (HERE/'timing.jsonl').open('a') as out:
        out.write(json.dumps(dict(name=name,monotonic=started,url=url))+'\n')
    if len((HERE/'timing.jsonl').read_text().splitlines())>180:
        raise RuntimeError('P0.1 hard request budget reached; no full crawl')
    return http.fetch(url,name,**kwargs)

def get(url,name=None,pdf=False):
    name=name or 'url_'+hashlib.sha256(url.encode()).hexdigest()[:20]
    path=TMP/(name+'.body')
    if not path.exists():request(url,name,timeout=(10,90 if pdf else 30))
    return path.read_bytes()

def card_url(body,url):
    import re
    from urllib.parse import urljoin
    match=re.search(r"showIframeCard\('([^']*card1/[^']+)'",body.decode('utf8',errors='replace'))
    if not match:raise ValueError('No source-discovered card1 URL')
    return urljoin(url,match[1])
