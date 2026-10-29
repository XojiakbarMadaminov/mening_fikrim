"""Reproduce the bounded supplementary probes recorded during P0.1."""
import hashlib
import json
import re
from datetime import datetime,timezone
from urllib.parse import urlencode
from bs4 import BeautifulSoup
from support import HERE,TMP,get,save,card_url,card_parse,languages,listing
from live_checks import walk,next_body

graphs={}
for seed in ['8506802','8554309','-7655343','-8540314']:
    graph={};pending=['https://lex.uz/uz/docs/'+seed]
    while pending:
        url=pending.pop(0)
        if url in graph:continue
        if len(graph)>=8:raise RuntimeError('identity graph budget')
        body=get(url);links=languages(BeautifulSoup(body,'lxml'),url)
        graph[url]=dict(id=url.rsplit('/',1)[-1],links=links,metadata=card_parse(get(card_url(body,url))))
        pending.extend(x for x in links.values() if x not in graph)
    graphs[seed]=graph
save('identity_graphs.json',graphs)

split={}
for name,start,end in [('left','01.01.2010','15.01.2010'),('right','16.01.2010','31.01.2010')]:
    url='https://lex.uz/uz/search/nat?'+urlencode({'lang':4,'status':'Y','from':start,'to':end})
    split[name]=walk(url,'historical_split_'+name)
a,b=[set(split[name]['unique_ids']) for name in ['left','right']]
whole=set(json.loads((HERE/'discovery.json').read_text())['nat_2010']['unique_ids'])
split['comparison']=dict(intersection=sorted(a&b),union_count=len(a|b),whole_count=len(whole),equal=a|b==whole)
save('historical_split.json',split)

forms={}
for f in ['F','H','J']:
    url='https://lex.uz/uz/search/court?'+urlencode({'lang':4,'status':'Y','court_form':f})
    forms[f]=walk(url,'court_form_'+f,limit=1)
save('court_subtypes.json',forms)
all_lang={}
for section in ['nat','loc','int','tech','court','oliy']:
    all_lang[section]=walk('https://lex.uz/uz/search/'+section+'?status=Y','all_languages_'+section,limit=1)
for f in ['F','H','J']:
    url='https://lex.uz/uz/search/court?'+urlencode({'status':'Y','court_form':f})
    all_lang['court_'+f]=walk(url,'all_languages_court_'+f,limit=1)
save('all_language_discovery.json',all_lang)
save('court_without_status.json',walk('https://lex.uz/uz/search/court?court_form=F','court_F_without_status',limit=1))
row=all_lang['court_F']['pages'][0]['rows'][0]
body=get(row['url'])
save('court_language_gap_example.json',dict(listing=row,metadata=card_parse(get(card_url(body,row['url']))),
     offered_languages=languages(BeautifulSoup(body,'lxml'),row['url'])))

url='https://lex.uz/uz/search/nat?status=Y'
body=(TMP/'all_languages_nat.body').read_bytes()
last_path=TMP/'big_all_language_last.body'
last=last_path.read_bytes() if last_path.exists() else next_body(body,url,'big_all_language_last','#ucFoundActsControl_lblpage[href]')
soup=BeautifulSoup(last,'lxml');result=listing(last)
result['row_numbers']=[a.find_parent('tr').find('td').get_text(' ',strip=True) for a in soup.select('a.lx_link[href]')
                       if re.fullmatch(r'/uz/docs/-?\d+',a['href'])]
result['paging_text']=soup.select_one('.pgn').get_text(' ',strip=True)
result['actual_expiration_tested']=False
source=next(json.loads(line) for line in (HERE/'requests.jsonl').read_text().splitlines() if json.loads(line)['name']=='all_languages_nat')
result['source_state_age_seconds']=round((datetime.now(timezone.utc)-datetime.fromisoformat(source['started_at'])).total_seconds(),3)
save('large_all_language_last.json',result)

fixtures={}
for id in ['8554309','-7655343','-8540314','-6257288']:
    url='https://lex.uz/uz/docs/'+id
    soup=BeautifulSoup(get(url),'lxml');root=soup.select_one('#divCont');fragments=[]
    for sup in root.select('sup')[:2]:
        parent=next((p for p in sup.parents if 'lx_elem' in p.get('class',[])),sup)
        fragments.append(str(parent))
    table=root.select_one('table')
    if table:fragments.append('<table>'+''.join(map(str,table.find_all('tr')[:2]))+'</table>')
    fixtures[id]=dict(url=url,html='<div id="divCont">'+''.join(fragments)+'</div>')
save('regression_fixtures.json',fixtures)
