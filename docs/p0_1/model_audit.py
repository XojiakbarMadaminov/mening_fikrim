"""Repeat the isolated four-table identity/upsert demonstration."""
from collections import Counter
import itertools
import json
from pathlib import Path
import tempfile
from support import BASE,HERE,save
from rules import identity_components,status_validation
from store_probe import Store

with tempfile.TemporaryDirectory(prefix='lex-p01-model-') as tmp:
    store=Store(Path(tmp)/'test.sqlite')
    graph=json.loads((HERE/'identity_graph.json').read_text())
    aliases=identity_components(graph)['components'][0]
    doc=next(d for d in json.loads((BASE/'sample_manifest.json').read_text()) if d['id']=='8506802')
    mapping={v['language']:dict(external_id=v['url'].rsplit('/',1)[-1],url=v['url'],
                               availability='full_text' if v['parsed'] else 'metadata_only') for v in doc['variants']}
    contents={v['language']:(BASE/v['markdown']).read_text() for v in doc['variants'] if v['parsed']}
    validation=status_validation(doc['metadata'],'');validation['quality_issues']=[]
    actions=Counter();ids=set()
    for order in itertools.permutations(aliases):
        for entry in order:
            id,action=store.import_verified(1,aliases,entry,mapping,contents,validation)
            ids.add(id);actions[action]+=1
    before=store.snapshot()
    _,result=store.import_verified(1,aliases,aliases[0],mapping,contents,
                                  dict(validation='review',business_status=None,quality_issues=['effective_date_conflict']))
    save('store_audit.json',dict(engine='temporary SQLite, JSON TEXT; not PostgreSQL locking/JSONB performance validation',
        business_tables=len(before),arrival_permutations=24,import_calls=96,actions=dict(actions),document_ids=sorted(ids),
        document_rows=len(before['documents']),content_rows=len(before['document_contents']),rejected_update_result=result,
        old_snapshot_preserved=store.snapshot()==before,metadata_example=json.loads(before['documents'][0][-1])))
    store.db.close()
