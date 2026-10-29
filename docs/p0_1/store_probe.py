"""Temporary SQLite contract model with four business tables; not PostgreSQL proof."""
import json
import sqlite3
from rules import digest,safe_update

class Store:
    def __init__(self,path):
        self.db=sqlite3.connect(path)
        self.db.executescript('''
        CREATE TABLE document_sources(id INTEGER PRIMARY KEY,slug TEXT UNIQUE);
        CREATE TABLE documents(id INTEGER PRIMARY KEY,source_id INTEGER,external_id TEXT,status TEXT,metadata TEXT,
                               UNIQUE(source_id,external_id));
        CREATE TABLE document_contents(id INTEGER PRIMARY KEY,document_id INTEGER,language TEXT,content TEXT,content_hash TEXT,
                                       UNIQUE(document_id,language));
        CREATE TABLE scrape_runs(id INTEGER PRIMARY KEY,checkpoint TEXT,status TEXT);
        ''')
        self.db.executemany('INSERT INTO document_sources VALUES (?,?)',[(1,'lex'),(2,'other')]);self.db.commit()

    def import_verified(self,source,aliases,entry,mapping,contents,validation):
        if entry not in aliases:raise ValueError('entry not in verified component')
        if validation['validation']!='valid' or validation.get('quality_issues'):
            return None,'rejected_preserved'
        expected={lang for lang,info in mapping.items() if info['availability']=='full_text'}
        if set(contents)!=expected:return None,'rejected_missing_language'
        if validation['business_status'] not in ['active','pending','inactive']:
            raise ValueError('unknown business status')
        namespace=self.db.execute('SELECT slug FROM document_sources WHERE id=?',(source,)).fetchone()[0]
        self.db.execute('BEGIN IMMEDIATE')
        try:
            matches=[]
            for row in self.db.execute('SELECT id,external_id,metadata FROM documents WHERE source_id=?',(source,)):
                stored=json.loads(row[2])[namespace]['identity']['aliases']
                if set(stored)&set(aliases):matches.append(row)
            if len(matches)>1:raise ValueError('identity collision; no automatic row merge')
            if matches:
                doc,anchor,raw=matches[0]
                oldmeta=json.loads(raw)
                aliases=sorted(set(aliases)|set(oldmeta[namespace]['identity']['aliases']))
                action='unchanged'
            else:
                if validation['business_status']=='inactive':
                    self.db.rollback();return None,'skipped_new_inactive'
                anchor=min(aliases) # opaque string ordering, never abs(id)
                cur=self.db.execute('INSERT INTO documents(source_id,external_id,status,metadata) VALUES (?,?,?,?)',
                                    (source,anchor,validation['business_status'],'{}'))
                doc=cur.lastrowid;action='created'
            metadata={namespace:{'identity':{'anchor':anchor,'aliases':aliases,'language_mapping':mapping}}}
            if matches and (metadata!=oldmeta or self.db.execute('SELECT status FROM documents WHERE id=?',(doc,)).fetchone()[0]!=validation['business_status']):
                action='updated'
            self.db.execute('UPDATE documents SET status=?,metadata=? WHERE id=?',
                            (validation['business_status'],json.dumps(metadata,ensure_ascii=False),doc))
            # Entire candidate is validated before writes, so no partial language overwrite.
            for language,content in contents.items():
                old=self.db.execute('SELECT content FROM document_contents WHERE document_id=? AND language=?',(doc,language)).fetchone()
                if old:
                    accepted,outcome=safe_update({'content':old[0],'validation':'valid'},
                                                {'content':content,'validation':'valid','quality_issues':[]})
                    if outcome.startswith('rejected'):raise ValueError(outcome)
                    if old[0]!=content and action=='unchanged':action='updated'
                elif action=='unchanged':action='updated'
                self.db.execute('INSERT INTO document_contents(document_id,language,content,content_hash) VALUES (?,?,?,?) '
                                'ON CONFLICT(document_id,language) DO UPDATE SET content=excluded.content,content_hash=excluded.content_hash',
                                (doc,language,content,digest(content)))
            self.db.commit();return doc,action
        except Exception:
            self.db.rollback();raise

    def snapshot(self):
        return {table:list(self.db.execute('SELECT * FROM '+table)) for table in
                ['document_sources','documents','document_contents','scrape_runs']}
