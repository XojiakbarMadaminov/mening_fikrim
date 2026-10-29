"""Additional bounded source checks, run after sample_import.py has stopped."""
import json
import re
from urllib.parse import urljoin
from bs4 import BeautifulSoup
from http_probe import fetch,TMP,EVIDENCE
from discovery_probe import listing

out={}
for name,url in [
    ('unique_R','https://lex.uz/uz/search/unique?lang=4&status=R'),
    ('unique_N','https://lex.uz/uz/search/unique?lang=4&status=N'),
    ('future_effective','https://lex.uz/uz/search/nat?lang=4&status=Y&from_eff=29.10.2026&to_eff=31.12.2030'),
    ('pdf_language_lookup','https://lex.uz/uz/search/nat?actnum=3962&regnummj=1'),
]:
    response=fetch(url,name)
    out[name]=dict(url=url,**listing(response.content))
lookup=out['pdf_language_lookup']['rows']
out['pdf_language_resources']=[]
for row in lookup:
    # IDs come from a real title/registration-number result, never +/- guessing.
    if '8557098' not in row['id']:continue
    response=fetch(row['url'],'lookup_doc_'+row['id'])
    match=re.search(r'PDFObject\.embed\("([^"]+)"',response.text)
    if match:
        url=urljoin(response.url,match[1])
        pdf=fetch(url,'lookup_pdf_'+row['id'],timeout=(10,90))
        out['pdf_language_resources'].append(dict(id=row['id'],url=url,status=pdf.status_code,
             content_type=pdf.headers.get('Content-Type'),pdf_magic=pdf.content.startswith(b'%PDF-'),bytes=len(pdf.content)))
body=(TMP/'sample_8181977.body').read_text()
match=re.search(r"showIframeCard\('([^']*revhis/[^']+)'",body)
if match:
    url=urljoin('https://lex.uz',match[1])
    r=fetch(url,'inactive_revision_history')
    out['inactive_history']=dict(url=url,text=BeautifulSoup(r.content,'lxml').get_text(' ',strip=True))
# A deliberately tiny timeout is a client fault test against a verified URL.
try:
    fetch('https://lex.uz/uz/docs/8557098','live_tiny_timeout',timeout=(.000001,.000001),attempts=1)
    out['live_timeout']='No timeout observed; not proven by this trial'
except RuntimeError as exc:
    out['live_timeout']=str(exc)
r=fetch('https://lex.uz/uz/docs/8557098','live_timeout_recovery')
out['live_recovery_status']=r.status_code
(EVIDENCE/'final_probes.json').write_text(json.dumps(out,ensure_ascii=False,indent=2))
print('FINAL PROBES',json.dumps({k:v for k,v in out.items() if k in ['live_timeout','live_recovery_status','pdf_language_resources']},ensure_ascii=False))
