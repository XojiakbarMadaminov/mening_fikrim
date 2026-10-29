"""Bounded live discovery experiments; never a full import."""
import json
import re
from urllib.parse import urlencode, urljoin
from bs4 import BeautifulSoup
from http_probe import fetch, TMP, EVIDENCE


def listing(body):
    soup = BeautifulSoup(body, 'lxml')
    text = soup.get_text(' ', strip=True)
    total = re.search(r'([\d\s\xa0]+)\s+hujjat topildi', text)
    rows = []
    for a in soup.select('a.lx_link[href]'):
        if not re.fullmatch(r'/uz/docs/-?\d+', a['href']):
            continue
        row = a.find_parent('tr')
        rows.append(dict(id=a['href'].split('/')[-1], url=urljoin('https://lex.uz', a['href']),
                         title=a.get_text(' ', strip=True),
                         status_classes=[x.get('class') for x in row.select('[class*=status_code]')],
                         description=row.get_text(' ',strip=True)))
    next_link = soup.select_one('#ucFoundActsControl_LinkButton1[href]')
    return dict(total=int(re.sub(r'\s','',total[1])) if total else None,
                rows=rows, next=next_link['href'] if next_link else None)


def walk(url, name, body=None, max_pages=8):
    if body is None:
        body = fetch(url, name).content
    pages = []
    for page in range(1, max_pages + 1):
        parsed = listing(body)
        pages.append(parsed)
        if not parsed['next']:
            break
        soup = BeautifulSoup(body, 'lxml')
        data = {x['name']: x.get('value', '') for x in soup.select('#Form1 input[name]')}
        target = re.search(r"__doPostBack\('([^']+)'", parsed['next'])[1]
        data.update(__EVENTTARGET=target, __EVENTARGUMENT='')
        body = fetch(url, f'{name}_page{page+1}', method='POST', data=data).content
    ids = {r['id'] for p in pages for r in p['rows']}
    return dict(url=url, pages=pages, unique_count=len(ids),
                reconciled=bool(pages[0]['total'] == len(ids) and not pages[-1]['next']))


if __name__ == '__main__':
    result = {}
    result['official_month'] = walk('https://lex.uz/uz/search/official?lang=4&pub_date=month',
                                    'official_month', (TMP/'official_month.body').read_bytes())
    cases = [
        ('nat_active', 'nat', {'lang':4,'status':'Y'}),
        ('nat_not_active', 'nat', {'lang':4,'status':'N'}),
        ('nat_inactive', 'nat', {'lang':4,'status':'R'}),
        ('nat_all_languages', 'nat', {'status':'Y'}),
        ('nat_oct23', 'nat', {'lang':4,'from':'23.10.2026','to':'23.10.2026'}),
        ('nat_left', 'nat', {'lang':4,'from':'01.10.2026','to':'15.10.2026'}),
        ('nat_right', 'nat', {'lang':4,'from':'16.10.2026','to':'28.10.2026'}),
        ('nat_october', 'nat', {'lang':4,'from':'01.10.2026','to':'28.10.2026'}),
        ('nat_codes', 'nat', {'lang':4,'status':'Y','form_id':3964}),
        ('loc', 'loc', {'lang':4,'status':'Y'}),
        ('int', 'int', {'lang':4,'status':'Y'}),
        ('tech', 'tech', {'lang':4,'status':'Y'}),
        ('court', 'court', {'lang':4,'status':'Y'}),
        ('oliy', 'oliy', {'lang':4,'status':'Y'}),
        ('unique', 'unique', {'lang':4,'status':'Y'}),
    ]
    for name, section, params in cases:
        url = f'https://lex.uz/uz/search/{section}?{urlencode(params)}'
        if name in ['nat_october','nat_left','nat_right','nat_oct23']:
            result[name] = walk(url, name)
        else:
            result[name] = dict(url=url, **listing(fetch(url,name).content))
        (EVIDENCE/'discovery.json').write_text(json.dumps(result,ensure_ascii=False,indent=2))
    for path,name in [('/agreement','agreement'),('/uz/user_guide','user_guide'),
                      ('/uz/offline','offline'),('/uz/statistic','statistics')]:
        fetch('https://lex.uz'+path,name)
    print('DISCOVERY', {k:{f:v[f] for f in ['total','unique_count','reconciled'] if f in v} for k,v in result.items()})
