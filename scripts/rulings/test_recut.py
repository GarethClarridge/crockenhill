import importlib.util
import pathlib
import unittest


spec = importlib.util.spec_from_file_location('recut', pathlib.Path(__file__).with_name('recut.py'))
recut = importlib.util.module_from_spec(spec)
spec.loader.exec_module(recut)


class RecutRulingsTest(unittest.TestCase):
    def test_direction_depends_on_which_edge_was_heard(self):
        self.assertEqual('outward', recut.expectation('early', 'end'))
        self.assertEqual('inward', recut.expectation('early', 'start'))
        self.assertEqual('inward', recut.expectation('late', 'end'))
        self.assertEqual('outward', recut.expectation('late', 'start'))
        self.assertIsNone(recut.expectation('unsure', 'end'))

    def test_committed_rulings_use_corrected_ids_and_superseding_answers(self):
        path = pathlib.Path(__file__).parents[2] / 'tests/Fixtures/Rulings/recut-20261019.json'
        records = recut.read(path)['rulings']
        self.assertEqual(len(records), len({r['id'] for r in records}))
        edge = next(r for r in records if r.get('corrected_id') == '1073-s1716-end')
        self.assertTrue(edge['relistened'])
        self.assertEqual('outward', edge['expect'])
        self.assertTrue(any(r.get('page_section_id') != r['section_id']
                            for r in records if r['output'] == 'section'))
        self.assertEqual('inward', next(r for r in records
                                      if r['id'].endswith(':1291-start'))['expect'])
        self.assertFalse(any('1345-gap' in r['id'] for r in records))


if __name__ == '__main__':
    unittest.main()
