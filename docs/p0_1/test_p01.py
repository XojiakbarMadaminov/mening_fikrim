"""Offline acceptance of real P0.1 evidence + explicitly controlled fault tests."""
import copy
import itertools
import json
from pathlib import Path
import tempfile
import unittest
from support import HERE,BASE
from rules import identity_components,status_validation,html_improved,safe_update,checkpoint_accept,run_completion
from pdf_checks import page_decision
from store_probe import Store

def load(name):return json.loads((HERE/name).read_text())

class IdentityTests(unittest.TestCase):
    def test_four_live_components_fifteen_ids(self):
        graphs=load('identity_graphs.json')
        self.assertEqual(sum(map(len,graphs.values())),15)
        for graph in graphs.values():
            result=identity_components(graph)
            self.assertTrue(result['admissible']);self.assertEqual(len(result['components']),1)

    def test_one_way_relation_requires_review(self):
        graph=copy.deepcopy(load('identity_graph.json'))
        graph['https://lex.uz/uz/docs/8507863']['links'].pop('uz-Latn')
        self.assertFalse(identity_components(graph)['admissible'])

    def test_contradicting_requisites_never_silently_merge(self):
        graph=copy.deepcopy(load('identity_graph.json'))
        graph['https://lex.uz/uz/docs/8507863']['metadata']['accepting_bodies'][0][4]='01.01.1999'
        result=identity_components(graph)
        self.assertFalse(result['admissible']);self.assertTrue(result['rejected_edges'])

    def test_equal_requisites_without_links_do_not_merge(self):
        graph=copy.deepcopy(load('identity_graph.json'))
        graph={k:v for k,v in list(graph.items())[:2]}
        for node in graph.values():node['links']={}
        self.assertEqual(len(identity_components(graph)['components']),2)

    def test_missing_optional_publication_is_not_conflict(self):
        for seed in ['8554309','-7655343']:
            graph=load('identity_graphs.json')[seed]
            self.assertTrue(identity_components(graph)['admissible'])
            self.assertTrue(any(not x['metadata'].get('№ издания официального источника') for x in graph.values()))

class StatusTests(unittest.TestCase):
    def test_future_Y_is_pending(self):
        for id in ['8557098','8554315']:
            self.assertEqual(status_validation(load(f'status_{id}.json')['metadata'],'')['business_status'],'pending')

    def test_R_and_N_are_inactive(self):
        for id in ['8181977','-2509996']:
            self.assertEqual(status_validation(load(f'status_{id}.json')['metadata'],'')['business_status'],'inactive')
        controlled={'Статус акта':'Не действующий','Дата вступления в силу':'01.01.2027'}
        self.assertIsNone(status_validation(controlled,'')['business_status'])

    def test_conflict_detected_from_text_without_id_special_case(self):
        doc=next(d for d in load('acquired.json') if d['id']=='-8553692')
        pdf=next(d for d in load('pdf_results.json') if d['id']==doc['id'])
        result=status_validation(doc['metadata'],(HERE/pdf['markdown']).read_text())
        self.assertIn('effective_date_conflict',result['issues']);self.assertIsNone(result['business_status'])

    def test_OCR_cannot_certify_status(self):
        doc=next(d for d in load('acquired.json') if d['id']=='-8553258')
        self.assertEqual(status_validation(doc['metadata'],'',ocr=True)['validation'],'review')

    def test_actual_unrecognized_label_fails_closed(self):
        doc=next(d for d in load('acquired.json') if d['id']=='8554326')
        self.assertEqual(doc['metadata']['Статус акта'],'Amalda')
        self.assertIsNone(status_validation(doc['metadata'],'')['business_status'])

    def test_localized_Amalda_needs_live_listing_corroboration(self):
        doc=next(d for d in load('acquired.json') if d['id']=='8554326')
        rows=load('discovery.json')['nat_oct']['pages'][0]['rows']
        row=next(r for r in rows if r['id']=='8554326')
        self.assertTrue(any('status_code_y' in classes for classes in row['status_classes']))
        self.assertEqual(status_validation(doc['metadata'],'',listing_status='Y')['business_status'],'active')
        self.assertIsNone(status_validation(doc['metadata'],'',listing_status='R')['business_status'])

    def test_missing_or_multiple_effective_dates_fail_closed(self):
        for effective in ['', '24.10.2026, 01.01.2027']:
            self.assertEqual(status_validation({'Статус акта':'Действующий','Дата вступления в силу':effective},'')['validation'],'review')

    def test_future_partial_clause_does_not_make_whole_act_pending(self):
        meta={'Статус акта':'Действующий','Дата вступления в силу':'24.10.2026'}
        controlled='Ushbu qarorning 4-bandi 2027-yil 1 yanvardan kuchga kiradi.'
        self.assertEqual(status_validation(meta,controlled)['business_status'],'active')

class QualityTests(unittest.TestCase):
    def test_gold_excerpts_do_not_certify_entire_OCR(self):
        gold=load('ocr_gold.json')
        self.assertEqual(gold[0]['normalized_character_errors'],0)
        self.assertEqual(gold[1]['normalized_character_errors'],2)
        self.assertTrue(all(not d['full_import_accepted'] for d in load('pdf_results.json') if d['ocr_pages']))
    def test_six_previous_numbering_reviews_resolved(self):
        rows=load('html_results.json')
        passed={d['id'] for d in rows if d['strict_pass']}
        self.assertEqual(passed,{'-6257288','8519448','8520357','-7303292','-7290944','-2509996'})

    def test_real_superscripts_keep_numeric_notation(self):
        fixtures=load('regression_fixtures.json')
        md,q=html_improved(fixtures['-6257288']['html'])
        self.assertTrue(q['superscripts'])
        for item in q['superscripts']:self.assertIn(item['rendered'],md)

    def test_scientific_superscripts_are_explicit_and_reviewed(self):
        doc=next(d for d in load('html_results.json') if d['id']=='-8540314')
        variant=next(v for v in doc['variants'] if v['language']=='uz-Latn')
        self.assertIn('max',variant['quality']['unsupported_superscripts'])
        self.assertIn('^{max}',(HERE/variant['markdown']).read_text())
        self.assertFalse(doc['strict_pass'])

    def test_complex_cells_and_spans_preserved_but_not_autoaccepted(self):
        doc=next(d for d in load('html_results.json') if d['id']=='8554309')
        variants=[v for v in doc['variants'] if not v['skipped']]
        for v in variants:
            q=v['quality'];self.assertTrue(q['cell_texts_retained'])
            self.assertTrue(q['complex_tables']);self.assertIn('complex_table_semantics_review',q['issues'])
        self.assertFalse(doc['strict_pass'])

    def test_images_never_silently_vanish_from_admission(self):
        doc=next(d for d in load('html_results.json') if d['id']=='8548754')
        self.assertFalse(doc['strict_pass'])
        self.assertTrue(any('unextracted_images' in v['quality']['issues'] for v in doc['variants']))

    def test_all_101_PDF_pages_accounted_for(self):
        docs=load('pdf_results.json')
        self.assertEqual(sum(d['pages'] for d in docs),101)
        self.assertEqual(sum(d['ocr_pages'] for d in docs),36)
        self.assertTrue(all(d['page_count_verified'] for d in docs))
        self.assertTrue(all(not d['full_import_accepted'] for d in docs))

    def test_pdf_table_heuristic_is_not_certification(self):
        trials=load('pdf_table_trials.json')
        shapes=[(t['rows'],t['columns']) for trial in trials for t in trial['tables']]
        self.assertIn((11,11),shapes);self.assertIn((9,5),shapes)
        self.assertFalse(load('pdf_results.json')[0]['full_import_accepted'])

    def test_partial_layer_above_old_character_threshold_still_triggers(self):
        # Controlled geometry regression, not an additional Lex PDF.
        self.assertTrue(page_decision(.97,450,.12)['needs_ocr'])
        self.assertTrue(page_decision(.97,600,.8)['image_backed_text_review'])
        self.assertFalse(page_decision(.02,1200,.8)['needs_ocr'])

class DiscoveryTests(unittest.TestCase):
    def test_Latin_filter_misses_real_court_subtypes(self):
        latin=load('court_subtypes.json');all_lang=load('all_language_discovery.json')
        for form in ['F','H','J']:
            self.assertEqual(latin[form]['total'],0)
            self.assertGreater(all_lang['court_'+form]['total'],0)
    def test_real_restart_reconciles_and_deduplicates(self):
        result=load('resume.json')
        self.assertEqual(result['unique_count'],80);self.assertEqual(result['duplicates_skipped'],20)
        self.assertTrue(result['reconciled'])
        self.assertFalse(result['actual_token_expiration_tested'])

    def test_historical_split_reconciles(self):
        result=load('historical_split.json')
        self.assertTrue(result['comparison']['equal']);self.assertEqual(result['comparison']['union_count'],22)

    def test_large_last_page_is_reachable_not_full_coverage(self):
        result=load('large_pagination.json')
        self.assertEqual(result['last']['row_numbers'][-1],'15787')
        self.assertFalse(result['last']['next']);self.assertFalse(result['middle_pages_traversed'])

    def test_all_language_last_page_beyond_1000_is_reachable(self):
        result=load('large_all_language_last.json')
        self.assertEqual(result['total'],55971)
        self.assertEqual(result['row_numbers'][-1],'55971')
        self.assertIn('2799',result['paging_text']);self.assertFalse(result['next'])

    def test_expired_or_cycling_page_cannot_advance_checkpoint(self):
        checkpoint=load('resume.json')['checkpoint']
        nextpage=load('resume.json')['old_viewstate_new_cookiejar']
        state,action=checkpoint_accept(checkpoint,{'rows':[],'total':None})
        self.assertEqual(action,'restart_segment');self.assertEqual(state,checkpoint)
        state,action=checkpoint_accept(checkpoint,nextpage)
        self.assertEqual(action,'committed')
        again,action=checkpoint_accept(state,nextpage)
        self.assertEqual(action,'restart_segment');self.assertEqual(again,state)

    def test_strict_completion_never_hides_incomplete_scope(self):
        self.assertEqual(run_completion(False,False,0,0,True),'failed')
        self.assertEqual(run_completion(True,True,1,0,True),'running')
        self.assertEqual(run_completion(True,True,0,1,True),'completed_with_errors')
        self.assertEqual(run_completion(True,True,0,0,False),'completed_with_errors')
        self.assertEqual(run_completion(True,True,0,0,True),'completed')

class StoreTests(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory(prefix='lex-p01-store-')
        self.store=Store(Path(self.tmp.name)/'test.sqlite')
        self.aliases=identity_components(load('identity_graph.json'))['components'][0]
        old=next(d for d in json.loads((BASE/'sample_manifest.json').read_text()) if d['id']=='8506802')
        self.mapping={v['language']:{'url':v['url'],'availability':'full_text' if v['parsed'] else 'metadata_only'} for v in old['variants']}
        self.contents={v['language']:(BASE/v['markdown']).read_text() for v in old['variants'] if v['parsed']}
        self.validation={'business_status':'active','validation':'valid','quality_issues':[]}

    def tearDown(self):self.store.db.close();self.tmp.cleanup()
    def put(self,entry=None,source=1,**overrides):
        args=dict(mapping=self.mapping,contents=self.contents,validation=self.validation)
        args.update(overrides)
        return self.store.import_verified(source,self.aliases,entry or self.aliases[0],**args)

    def test_all_24_arrival_orders_one_doc_three_actual_contents(self):
        ids=set()
        for order in itertools.permutations(self.aliases):
            for entry in order:ids.add(self.put(entry)[0])
        self.assertEqual(len(ids),1)
        self.assertEqual(self.store.db.execute('SELECT COUNT(*) FROM documents').fetchone()[0],1)
        self.assertEqual(self.store.db.execute('SELECT COUNT(*) FROM document_contents').fetchone()[0],3)
        self.assertEqual(len(self.store.snapshot()),4)

    def test_source_namespace_isolation(self):
        self.assertNotEqual(self.put(source=1)[0],self.put(source=2)[0])
        raw=self.store.db.execute('SELECT metadata FROM documents WHERE source_id=2').fetchone()[0]
        self.assertIn('other',json.loads(raw));self.assertNotIn('lex',json.loads(raw))

    def test_persisted_anchor_never_renumbered(self):
        id,_=self.put()
        self.store.db.execute('UPDATE documents SET external_id=? WHERE id=?',('8506802',id));self.store.db.commit()
        self.assertEqual(self.put('8507863')[0],id)
        self.assertEqual(self.store.db.execute('SELECT external_id FROM documents WHERE id=?',(id,)).fetchone()[0],'8506802')

    def test_unproven_entry_is_rejected(self):
        with self.assertRaisesRegex(ValueError,'not in verified'):self.put('123456')

    def test_actual_conflict_and_quality_review_preserve_every_field(self):
        self.put();before=self.store.snapshot()
        for rejected in [{'validation':'review','business_status':None,'quality_issues':['effective_date_conflict']},
                         {'validation':'valid','business_status':'inactive','quality_issues':['complex_table_semantics_review']}]:
            self.assertEqual(self.put(validation=rejected)[1],'rejected_preserved')
            self.assertEqual(self.store.snapshot(),before)

    def test_missing_required_language_preserves_every_field(self):
        self.put();before=self.store.snapshot()
        self.assertEqual(self.put(contents={})[1],'rejected_missing_language')
        self.assertEqual(self.store.snapshot(),before)

    def test_mid_transaction_shrinkage_rolls_back_all_languages(self):
        self.put();before=self.store.snapshot()
        candidate={k:v+' controlled additional paragraph' for k,v in self.contents.items()}
        candidate[list(candidate)[-1]]='truncated'
        with self.assertRaisesRegex(ValueError,'shrinkage'):self.put(contents=candidate)
        self.assertEqual(self.store.snapshot(),before)

    def test_unchanged_second_import(self):
        inactive=dict(self.validation,business_status='inactive')
        self.assertEqual(self.put(validation=inactive)[1],'skipped_new_inactive')
        self.assertEqual(self.store.db.execute('SELECT COUNT(*) FROM documents').fetchone()[0],0)
        self.put();before=self.store.snapshot()
        self.assertEqual(self.put()[1],'unchanged');self.assertEqual(self.store.snapshot(),before)

if __name__=='__main__':unittest.main(verbosity=2)
