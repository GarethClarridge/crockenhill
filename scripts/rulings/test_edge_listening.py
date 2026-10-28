import importlib.util
import pathlib
import unittest


spec = importlib.util.spec_from_file_location('edge_listening', pathlib.Path(__file__).with_name('edge_listening.py'))
edge_listening = importlib.util.module_from_spec(spec)
spec.loader.exec_module(edge_listening)


class EdgeListeningRulingsTest(unittest.TestCase):
    def question(self, section, edge, a, b):
        return dict(id=f'{section}-{edge}', key=dict(kind='changed', section=section, edge=edge, times=dict(a=a, b=b)))

    def answer(self, question, choice, note=''):
        return dict(question_id=question, choice=choice, note=note, saved_at='2026-10-25T21:10:00.000Z', run=1267, title='Song')

    def test_a_chosen_version_is_kept(self):
        records = edge_listening.collect('s', [self.question(4735, 'start', 1856.36, 1855.52), self.question(1730, 'end', 1970.14, 1964.8)],
                                         [self.answer('4735-start', 'a'), self.answer('1730-end', 'b')])
        self.assertEqual([('keep', 1856.36), ('keep', 1964.8)], [(r['expect'], r['heard']) for r in records])

    def test_either_makes_each_cut_right_and_prefers_neither(self):
        records = edge_listening.collect('s', [self.question(1259, 'start', 2419.18, 2417.38), self.question(1102, 'end', 675.141, 675.534)],
                                         [self.answer('1259-start', 'both'), self.answer('1102-end', 'both')])
        self.assertEqual(['either', 'either'], [r['expect'] for r in records])
        self.assertEqual([2417.38, 675.534], [r['heard'] for r in records])
        self.assertEqual([2417.38, 2419.18], records[0]['points'])
        self.assertNotIn('range', records[0])

    def test_neither_is_truth_only_with_an_exact_time(self):
        questions = [self.question(3779, 'start', 215.48, 215.0), self.question(4322, 'start', 1567.44, 1564.88)]
        answers = [self.answer('3779-start', 'neither', 'Should really be 3:36. Both these versions cut the introductory sentence partway through.'),
                   self.answer('4322-start', 'neither', 'Should be something like 26:09. But B better than A as it keeps a whole sentence')]
        records = edge_listening.collect('s', questions, answers)
        self.assertEqual([(3779, 'at', 216.0)], [(r['section_id'], r['expect'], r['ruled_time']) for r in records])

    def test_answer_source_is_preserved_separately_from_the_listening_page(self):
        questions = [self.question(1, 'end', 10, 11), self.question(2, 'end', 20, 21)]
        answers = [{**self.answer('1-end', 'a'), 'source': 'operator'},
                   {**self.answer('2-end', 'b'), 'source': 'claude-review'}]
        records = edge_listening.collect('listening-page', questions, answers)
        self.assertEqual(['listening-page', 'listening-page'], [r['source'] for r in records])
        self.assertEqual(['operator', 'claude-review'], [r['answer_source'] for r in records])

    def test_unsure_and_unknown_questions_give_no_ruling(self):
        records = edge_listening.collect('s', [self.question(959, 'start', 524.27, 523.0)],
                                         [self.answer('959-start', 'unsure'), self.answer('2867-start', 'neither', 'not a song')])
        self.assertEqual([], records)


if __name__ == '__main__':
    unittest.main()
