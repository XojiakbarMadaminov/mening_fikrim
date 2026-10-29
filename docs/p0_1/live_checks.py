"""Bounded live acquisition and discovery. No database connection/import."""
import json
import re
from urllib.parse import urlencode,urljoin
from bs4 import BeautifulSoup
from support import HERE,BASE,TMP,http,get,request,save,listing,card_url,card_parse,languages

def next_body(body,url,name,selector='#ucFoundActsControl_LinkButton1[href]'):
    soup=BeautifulSoup(body,'lxml')
    link=soup.select_one(selector)
    if not link:return None
    target=re.search(r"__doPostBack\('([^']+)'",link['href'])[1]
    fields={x['name']:x.get('value','') for x in soup.select('#Form1 input[name]')}
    fields.update(__EVENTTARGET=target,__EVENTARGUMENT='')
    return request(url,name,method='POST',data=fields).content

def walk(url,name,limit=3):
    body=get(url,name)
    pages=[]
    for page in range(limit):
        parsed=listing(body)
        soup=BeautifulSoup(body,'lxml')
        parsed['row_numbers']=[x.find_parent('tr').find('td').get_text(' ',strip=True)
                               for x in soup.select('a.lx_link[href]') if re.fullmatch(r'/uz/docs/-?\d+',x['href'])]
        pages.append(parsed)
        if not parsed['next'] or page+1==limit:break
        body=next_body(body,url,f'{name}_p{page+2}')
    ids=[r['id'] for p in pages for r in p['rows']]
    return dict(url=url,total=pages[0]['total'],pages=pages,unique_ids=sorted(set(ids)),
                duplicate_records=len(ids)-len(set(ids)),
                reconciled=not pages[-1]['next'] and len(set(ids))==pages[0]['total'],
                stopped_at_budget=bool(pages[-1]['next']))

def discovery():
    out={}
    for section in ['nat','loc','int','tech','court','oliy','official','unique']:
        params={'lang':4,'status':'Y','from':'01.10.2026','to':'28.10.2026'}
        url='https://lex.uz/uz/search/'+section+'?'+urlencode(params)
        out[section+'_oct']=walk(url,section+'_oct',limit=1)
        save('discovery.json',out)
    historical=[('nat_1992','nat','01.12.1992','31.12.1992'),
                ('nat_2000','nat','01.05.2000','31.05.2000'),
                ('nat_2010','nat','01.01.2010','31.01.2010'),
                ('nat_2020','nat','01.01.2020','31.01.2020'),
                ('loc_2010','loc','01.01.2010','31.01.2010'),
                ('int_2000','int','01.01.2000','31.12.2000'),
                ('tech_2020','tech','01.01.2020','31.12.2020'),
                ('court_2010','court','01.01.2010','31.12.2010'),
                ('oliy_2000','oliy','01.05.2000','31.05.2000')]
    for name,section,start,end in historical:
        url='https://lex.uz/uz/search/'+section+'?'+urlencode(dict(lang=4,status='Y',**{'from':start,'to':end}))
        out[name]=walk(url,name,limit=3)
        save('discovery.json',out)
    # Real interrupt/resume on a fixed small month: persist seen IDs and replay
    # from a new session; viewstate is transient, not the durable cursor.
    url='https://lex.uz/uz/search/nat?lang=4&from=01.10.2026&to=28.10.2026'
    first=get(url,'resume_initial')
    seen={x['id'] for x in listing(first)['rows']}
    checkpoint={'url':url,'seen_ids':sorted(seen),'page_committed':1,'source_total':listing(first)['total']}
    (TMP/'checkpoint.json').write_text(json.dumps(checkpoint))
    http.SESSION.cookies.clear()
    stale=next_body(first,url,'resume_old_viewstate_new_cookiejar')
    old_state_result=listing(stale)
    # A saved token in a new cookie jar is not proof of actual token expiry.
    replay=request(url,'resume_restart_get').content
    replay_pages=[listing(replay)]
    duplicate=0
    for page in range(4):
        parsed=listing(replay)
        for row in parsed['rows']:
            if row['id'] in seen:duplicate+=1
            seen.add(row['id'])
        if not parsed['next']:break
        replay=next_body(replay,url,f'resume_replay_p{page+2}')
        replay_pages.append(listing(replay))
    save('resume.json',dict(checkpoint=checkpoint,replay_pages=replay_pages,
        old_viewstate_new_cookiejar=old_state_result,unique_count=len(seen),duplicates_skipped=duplicate,
        reconciled=not replay_pages[-1]['next'] and len(seen)==checkpoint['source_total'],
        actual_token_expiration_tested=False))
    # Probe the real last-page link on a large result, without traversing archive.
    bigurl='https://lex.uz/uz/search/nat?lang=4&status=Y'
    big=get(bigurl,'big_first')
    nxt=next_body(big,bigurl,'big_second')
    last=next_body(big,bigurl,'big_last','#ucFoundActsControl_lblpage[href]')
    def info(body):
        soup=BeautifulSoup(body,'lxml')
        return dict(**listing(body),row_numbers=[a.find_parent('tr').find('td').get_text(' ',strip=True)
            for a in soup.select('a.lx_link[href]') if re.fullmatch(r'/uz/docs/-?\d+',a['href'])],
            paging_text=soup.select_one('.pgn').get_text(' ',strip=True) if soup.select_one('.pgn') else '')
    save('large_pagination.json',dict(url=bigurl,first=info(big),second=info(nxt),last=info(last) if last else None,
                                     middle_pages_traversed=False))

def acquire():
    previous=json.loads((BASE/'sample_manifest.json').read_text())
    out=[]
    for doc in previous:
        if doc['strict_quality_pass']:continue
        body=get(doc['url'])
        soup=BeautifulSoup(body,'lxml')
        cu=card_url(body,doc['url'])
        meta=card_parse(get(cu))
        variants=languages(soup,doc['url']) or {doc['variants'][0].get('declared_language',doc['variants'][0]['language']):doc['url']}
        record=dict(id=doc['id'],url=doc['url'],metadata_url=cu,metadata=meta,variants=[])
        for lang,url in variants.items():
            vb=body if url==doc['url'] else get(url)
            match=re.search(r'PDFObject\.embed\("([^"]+)"',vb.decode('utf8',errors='replace'))
            v=dict(language=lang,url=url,body_name='url_'+__import__('hashlib').sha256(url.encode()).hexdigest()[:20])
            if match:
                pu=urljoin(url,match[1]);pdf=get(pu,pdf=True)
                v.update(pdf_url=pu,pdf_name='url_'+__import__('hashlib').sha256(pu.encode()).hexdigest()[:20],pdf_magic=pdf.startswith(b'%PDF-'))
            record['variants'].append(v)
        out.append(record);save('acquired.json',out)
        print('ACQUIRED REVIEW',len(out),doc['id'],flush=True)
    # Every link in this component is verified live, including metadata-only RU.
    component={}; pending=['https://lex.uz/uz/docs/8506802']
    while pending:
        url=pending.pop(0)
        if url in component:continue
        if len(component)>=8:raise RuntimeError('Identity bounded graph exceeded')
        body=get(url);soup=BeautifulSoup(body,'lxml')
        cu=card_url(body,url);meta=card_parse(get(cu))
        links=languages(soup,url)
        component[url]=dict(id=url.rsplit('/',1)[-1],metadata=meta,links=links)
        pending.extend(x for x in links.values() if x not in component)
    save('identity_graph.json',component)
    for id in ['8557098','8554315','8181977','-2509996']:
        old=next(d for d in previous if d['id']==id)
        body=get(old['url']);cu=card_url(body,old['url'])
        save(f'status_{id}.json',dict(url=old['url'],metadata=card_parse(get(cu))))

if __name__=='__main__':
    discovery()
    acquire()
