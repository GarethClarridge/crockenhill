import importlib.util
import pathlib
import unittest


spec = importlib.util.spec_from_file_location('queue_sample', pathlib.Path(__file__).with_name('queue_sample.py'))
queue_sample = importlib.util.module_from_spec(spec)
spec.loader.exec_module(queue_sample)


class QueueSampleRulingsTest(unittest.TestCase):
    def member(self, section, run=1216):
        return dict(section_id=section, run=run, start=100.0, end=200.0, title='Song')

    def answer(self, question, choice, note=''):
        return dict(question_id=question, choice=choice, note=note, saved_at='2026-10-24T23:54:59.512Z', run=1216)

    def test_trailing_content_keeps_direction_without_inventing_precise_times(self):
        sample = {'strata': [{'stratum': 'song_trailing_content', 'members': [self.member(4336), self.member(3918), self.member(1730)]}]}
        answers = [self.answer('4336-song_trailing_content', 'short', 'Should finish about 67:08'),
                   self.answer('3918-song_trailing_content', 'other'), self.answer('1730-song_trailing_content', 'song')]
        records = queue_sample.collect(sample, answers)
        self.assertEqual(['outward', 'inward', 'keep'], [r['expect'] for r in records])
        self.assertTrue(all(r['ruled_time'] is None for r in records))
        self.assertTrue(all(r['heard'] == 200 for r in records))

    def test_low_confidence_answers_only_record_edges_the_operator_settled(self):
        sample = {'strata': [{'stratum': 'song_low_confidence', 'members': [self.member(752), self.member(163668), self.member(3769)]}]}
        answers = [self.answer('752-song_low_confidence', 'cut', 'Right song, should be 21:07-22:14'),
                   self.answer('163668-song_low_confidence', 'cut', 'End slightly clipped: should be about 70:22'),
                   self.answer('3769-song_low_confidence', 'cut', 'It had started before this clip. Ends at 5:52')]
        records = queue_sample.collect(sample, answers)
        self.assertEqual([1267, 1334, None, None, 352], [r['ruled_time'] for r in records])
        self.assertEqual(['start', 'end', 'end', 'start', 'end'], [r['edge'] for r in records])

    def test_unknown_and_unheard_answers_do_not_become_edge_truth(self):
        sample = {'strata': [{'stratum': 'song_low_confidence', 'members': [self.member(752)]}]}
        self.assertEqual([], queue_sample.collect(sample, [self.answer('752-song_low_confidence', 'unsure')]))

    def test_followup_uses_the_saved_time_without_applying_it_to_a_section(self):
        answer = self.answer('2659-sermon_start', 'before', '32:19')
        records = queue_sample.collect({'strata': []}, [], answer)
        self.assertEqual(1939, records[0]['ruled_time'])
        self.assertEqual('section', records[0]['output'])
        self.assertEqual(2659, records[0]['section_id'])
        self.assertEqual('sermon', records[0]['section_type'])
        self.assertEqual('2026-10-24T23:54:59.512Z', records[0]['ruled_at'])

    def test_sermon_start_answer_is_about_the_section_after_the_readings(self):
        sample = {'strata': [{'stratum': 'sermon_adjacent_speech_unowned', 'members': [self.member(3242, 1259)]}]}
        result = queue_sample.collect(sample, [self.answer('3242-sermon_adjacent_speech_unowned', 'mixed', 'The sermon starts at 35:24.')])[0]
        self.assertEqual(('section', 3242, 2124), (result['output'], result['section_id'], result['ruled_time']))


if __name__ == '__main__':
    unittest.main()
