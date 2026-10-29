"""P0-only HTTP recorder. No database, Laravel code, or full crawl.

Raw responses live only under LEX_P0_TMP; derived evidence is kept in docs/p0.
"""
import hashlib
import json
import os
from pathlib import Path
import time
from datetime import datetime, timezone
import requests

TMP = Path(os.environ.get('LEX_P0_TMP', '/tmp/lex-p0-20261028-fLI3Zm'))
EVIDENCE = Path(__file__).resolve().parent
TMP.mkdir(parents=True, exist_ok=True)
SESSION = requests.Session()
SESSION.headers['User-Agent'] = 'LexP0TechnicalSpike/0.1 (limited research; single worker)'


def fetch(url, name, *, method='GET', data=None, timeout=(10, 30), attempts=3):
    """Serialize all remote requests, including retries, at >=2s start spacing."""
    for attempt in range(1, attempts + 1):
        clock = TMP / 'last_request'
        last = float(clock.read_text()) if clock.exists() else 0
        time.sleep(max(0, 2 - (time.monotonic() - last)))
        clock.write_text(str(time.monotonic()))
        started = time.time()
        logged_data = ({k: ('sha256:' + hashlib.sha256(v.encode()).hexdigest()
                           if k.startswith('__VIEWSTATE') else v)
                       for k, v in data.items()} if data else None)
        record = dict(name=name, url=url, method=method, data=logged_data,
                      attempt=attempt, started_at=datetime.now(timezone.utc).isoformat())
        response = None
        try:
            response = SESSION.request(method, url, data=data, timeout=timeout)
            record.update(status=response.status_code, final_url=response.url,
                          content_type=response.headers.get('Content-Type'),
                          bytes=len(response.content), sha256=hashlib.sha256(response.content).hexdigest(),
                          headers={k: v for k, v in response.headers.items()
                                   if k.lower() in ['content-type', 'retry-after', 'server', 'last-modified', 'etag', 'content-length']},
                          redirects=[dict(status=h.status_code, url=h.url) for h in response.history])
            (TMP / f'{name}.body').write_bytes(response.content)
        except requests.RequestException as exc:
            record.update(error=type(exc).__name__, message=str(exc))
        record['elapsed_seconds'] = round(time.time() - started, 3)
        with (EVIDENCE / 'requests.jsonl').open('a') as out:
            out.write(json.dumps(record, ensure_ascii=False) + '\n')
        print(json.dumps({k: record[k] for k in ['name', 'status', 'bytes', 'error', 'elapsed_seconds'] if k in record}), flush=True)
        if response is not None:
            if response.status_code == 403:
                raise RuntimeError(f'403: stop, no bypass: {url}')
            if response.status_code not in [429, 500, 502, 503, 504]:
                return response
        if attempt < attempts:
            delay = max(2 ** attempt, 2)
            if response is not None and response.headers.get('Retry-After', '').isdigit():
                delay = max(delay, int(response.headers['Retry-After']))
            if delay > 60:
                raise RuntimeError('Retry-After >60s: defer; do not retry early')
            time.sleep(delay)
    raise RuntimeError(f'Request exhausted: {url}')


if __name__ == '__main__':
    for url, name in [
        ('https://lex.uz/robots.txt', 'robots'),
        ('https://lex.uz/uz/axborot', 'terms'),
        ('https://lex.uz/uz/search/official?lang=4&pub_date=month', 'official_month'),
        ('https://lex.uz/uz/docs/8557098', 'doc_8557098'),
    ]:
        fetch(url, name)
