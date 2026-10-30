import importlib.util
import pathlib
import unittest


spec = importlib.util.spec_from_file_location('section_binding', pathlib.Path(__file__).with_name('section_binding.py'))
section_binding = importlib.util.module_from_spec(spec)
spec.loader.exec_module(section_binding)


def ruling(section_id, edge, heard, *, section_type=None, title=None, source='canary13', run=949):
    return {'id': f'{source}:{section_id}-{edge}', 'source': source, 'run': run, 'output': 'section',
            'section_id': section_id, 'section_type': section_type, 'title': title, 'edge': edge, 'heard': heard}


def section(section_id, section_type, title, start, end):
    return {'id': section_id, 'type': section_type, 'title': title, 'start': start, 'end': end}


# 949 after re-detection inserted a section: the song the page heard as §4857 now sits in §4858.
SECTIONS_949 = {'949': [section(4856, 'prayer', 'Crockenhill sharing and prayer', 2100.0, 2226.4),
                        section(4857, 'bible_reading', "Jesus' crucifixion", 2226.48, 2447.26),
                        section(4858, 'song', 'I Will Sing Of The Lamb', 2459.8, 2690.0),
                        section(4859, 'sermon', 'God Provides a Lamb', 2690.1, 4620.0)]}


class SectionBindingTest(unittest.TestCase):
    def test_an_id_now_holding_another_type_takes_the_section_heard(self):
        records = [ruling(4857, 'start', 2459.93, section_type='song', title='I Will Sing Of The Lamb opens (2021-09-05 evening)')]
        self.assertEqual([], section_binding.rebind(records, SECTIONS_949))
        self.assertEqual(4858, records[0]['section_id'])
        self.assertEqual(4857, records[0]['page_section_id'])

    def test_a_gone_id_takes_the_nearest_section_of_its_type(self):
        sections = {'1036': [section(1474, 'prayer', 'Prayer', 484.5, 743.2),
                             section(1475, 'short_talk', 'The perfect gift', 747.9, 1491.3)]}
        records = [ruling(1476, 'end', 1491.36, section_type='talk', source='endings', run=1036)]
        section_binding.rebind(records, sections)
        self.assertEqual(1475, records[0]['section_id'])

    def test_a_title_the_page_named_moves_a_ruling_off_another_song(self):
        sections = {'1012': [section(1288, 'song', 'God Of Glory', 0.0, 85.0),
                             section(1289, 'song', 'O God Beyond All Praising', 88.94, 200.0)]}
        records = [ruling(1288, 'end', 198.096, section_type='song', title='O God Beyond All Praising ends (2026-03-22 morning)', run=1012)]
        section_binding.rebind(records, sections)
        self.assertEqual(1289, records[0]['section_id'])

    def test_an_edge_at_the_cut_heard_wins_over_the_title(self):
        sections = {'1012': [section(1288, 'song', 'God Of Glory', 0.0, 85.0),
                             section(1289, 'song', 'O God Beyond All Praising', 88.94, 200.0)]}
        records = [ruling(1288, 'start', 4.66, section_type='song', title='O God Beyond All Praising opens (2026-03-22 morning)', run=1012)]
        section_binding.rebind(records, sections)
        self.assertEqual(1288, records[0]['section_id'])
        self.assertNotIn('page_section_id', records[0])

    def test_a_ruling_that_still_holds_its_item_is_untouched(self):
        records = [ruling(4858, 'end', 2690.02, section_type='song', title='I Will Sing Of The Lamb ends (2021-09-05 evening)')]
        section_binding.rebind(records, SECTIONS_949)
        self.assertEqual(4858, records[0]['section_id'])
        self.assertNotIn('page_section_id', records[0])

    def test_relabelled_page_titles_are_not_evidence(self):
        records = [ruling(4859, 'end', 4620.0, title='End: song “I Will Sing Of The Lamb” (§4858)', source='listening-edges3-20261018')]
        section_binding.rebind(records, SECTIONS_949)
        self.assertEqual(4859, records[0]['section_id'])

    def test_no_section_of_the_type_near_the_cut_is_reported_and_left(self):
        records = [ruling(4857, 'start', 3500.0, section_type='song')]
        self.assertEqual(['canary13:4857-start'], section_binding.rebind(records, SECTIONS_949))
        self.assertEqual(4857, records[0]['section_id'])

    def test_sermon_rulings_and_id_less_rulings_are_left_alone(self):
        sermon = {**ruling(None, 'end', 4620.0), 'output': 'sermon'}
        id_less = ruling(None, 'start', 2459.9, section_type='song')
        section_binding.rebind([sermon, id_less], SECTIONS_949)
        self.assertIsNone(sermon['section_id'])
        self.assertIsNone(id_less['section_id'])


if __name__ == '__main__':
    unittest.main()
