"""Offline source-fixture acceptance + localhost fault injection, not Lex load tests."""
import json
import re
from pathlib import Path
import tempfile
import threading
import time
import unittest
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from bs4 import BeautifulSoup
import http_probe
from sample_import import TMP, EVIDENCE, parse_html, parse_pdf, languages, status_of, card_parse


class FixtureTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.fixtures=json.loads((EVIDENCE/'source_fixtures.json').read_text())
        cls.manifest=json.loads((EVIDENCE/'sample_manifest.json').read_text())

    def test_pending_requires_date_not_source_Y(self):
        meta=self.fixtures['8557098']['metadata']
        self.assertEqual(meta['Статус акта'],'Действующий')
        self.assertEqual(status_of(meta),'pending')

    def test_N_is_old_edition_not_pending(self):
        meta=self.fixtures['-2509996']['metadata']
        self.assertEqual(status_of(meta),'inactive')

    def test_unknown_status_fails_closed(self):
        self.assertEqual(status_of({'Статус акта':'unexpected'}),'unresolved')

    def test_actual_language_links_have_distinct_ids(self):
        soup=BeautifulSoup(''.join(self.fixtures['8506802']['language_controls']),'lxml')
        variants=languages(soup,'https://lex.uz/uz/docs/8506802')
        self.assertEqual(set(variants),{'ru','en','uz-Latn','uz-Cyrl'})
        self.assertTrue(variants['ru'].endswith('/8507863'))
        self.assertTrue(variants['en'].endswith('/8506870'))

    def test_global_interface_menu_not_translations(self):
        soup=BeautifulSoup(self.fixtures['8557098'].get('global_interface_menu',''),'lxml')
        self.assertEqual(languages(soup,'https://lex.uz/uz/docs/8557098'),{})

    def test_large_code_keeps_593_articles_and_last_text(self):
        md=(EVIDENCE/'markdown/-6257288_uz-Latn.md').read_text()
        doc=next(d for d in self.manifest if d['id']=='-6257288')
        q=next(v['quality'] for v in doc['variants'] if v['language']=='uz-Latn')
        self.assertEqual(len(re.findall(r'^### ',md,re.M)),593)
        self.assertIn('### 581-modda.',md)
        self.assertIn('### 1-modda.',md)
        self.assertNotIn('Hujjatga taklif yuborish',md)
        self.assertNotIn('LexUZ sharhi',md)
        self.assertNotIn('Oldingi tahrirga',md)
        self.assertTrue(q['token_fidelity'])

    def test_metadata_only_translation_is_detected(self):
        md=(EVIDENCE/'markdown/8554309_en.md').read_text()
        _,q=parse_html('<div id="divCont">'+md+'</div>')
        self.assertTrue(q['metadata_only'])

    def test_viewer_is_not_a_pdf(self):
        with self.assertRaisesRegex(ValueError,'Not PDF magic'):
            parse_pdf(self.fixtures['8557098']['excerpt_html'].encode(),'invalid','uz-Latn')

    def test_real_source_excerpt_removes_editorial_ui(self):
        md,q=parse_html(self.fixtures['-6257288']['excerpt_html'])
        self.assertIn('### 1-modda.',md)
        self.assertNotIn('Hujjatga taklif yuborish',md)
        self.assertNotIn('Oldingi tahrirga',md)
        self.assertTrue(q['token_fidelity'])

    def test_inactive_label_from_real_card(self):
        self.assertEqual(status_of(self.fixtures['8181977']['metadata']),'inactive')

    def test_default_current_revision_is_recorded(self):
        self.assertIn('15.10.2026 01',self.fixtures['-6257288']['selected_revision'])

    def test_scanned_pdf_conflict_is_quarantined(self):
        doc=next(d for d in self.manifest if d['id']=='-8553692')
        self.assertEqual(doc['status'],'unresolved')
        self.assertFalse(doc['strict_quality_pass'])
        self.assertEqual(doc['variants'][0]['language'],'uz-Cyrl')
        self.assertEqual(len(doc['variants'][0]['quality']['ocr_pages']),19)

    def test_bounded_pagination_reconciles(self):
        data=json.loads((EVIDENCE/'discovery.json').read_text())
        for name in ['official_month','nat_october','nat_oct23','nat_left','nat_right']:
            self.assertTrue(data[name]['reconciled'],name)
        self.assertEqual(len(data['official_month']['pages']),5)
        self.assertEqual(data['official_month']['unique_count'],89)


class Handler(BaseHTTPRequestHandler):
    calls={}
    def log_message(self,*args):pass
    def do_GET(self):
        Handler.calls[self.path]=Handler.calls.get(self.path,0)+1
        count=Handler.calls[self.path]
        if self.path=='/slow':time.sleep(.15)
        code=403 if self.path=='/forbidden' else 429 if self.path=='/retry' and count==1 else 200
        self.send_response(code)
        if code==429:self.send_header('Retry-After','2')
        self.end_headers()
        try:self.wfile.write(b'controlled localhost fixture')
        except BrokenPipeError:pass


class FaultInjectionTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temp=tempfile.TemporaryDirectory(prefix='lex-p0-local-tests-')
        cls.old=(http_probe.TMP,http_probe.EVIDENCE)
        http_probe.TMP=http_probe.EVIDENCE=Path(cls.temp.name)
        cls.server=ThreadingHTTPServer(('127.0.0.1',0),Handler)
        cls.thread=threading.Thread(target=cls.server.serve_forever,daemon=True)
        cls.thread.start()
        cls.url=f'http://127.0.0.1:{cls.server.server_port}'

    @classmethod
    def tearDownClass(cls):
        cls.server.shutdown(); cls.server.server_close()
        http_probe.TMP,http_probe.EVIDENCE=cls.old
        cls.temp.cleanup()

    def test_403_stops_without_retry(self):
        with self.assertRaisesRegex(RuntimeError,'403: stop'):
            http_probe.fetch(self.url+'/forbidden','forbidden')
        self.assertEqual(Handler.calls['/forbidden'],1)

    def test_429_retry_after_then_success(self):
        response=http_probe.fetch(self.url+'/retry','retry')
        self.assertEqual(response.status_code,200)
        self.assertEqual(Handler.calls['/retry'],2)

    def test_read_timeout_exhausts_at_bound(self):
        with self.assertRaisesRegex(RuntimeError,'exhausted'):
            http_probe.fetch(self.url+'/slow','timeout',timeout=(1,.01),attempts=2)
        self.assertEqual(Handler.calls['/slow'],2)


if __name__=='__main__':unittest.main(verbosity=2)
