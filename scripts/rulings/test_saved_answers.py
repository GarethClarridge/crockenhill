import json
import pathlib
import tempfile
import unittest
from unittest.mock import patch

import saved_answers


class GatherSavedAnswersTest(unittest.TestCase):
    def save(self, root, saved_at):
        path = pathlib.Path(root, 'answers')
        path.mkdir(exist_ok=True)
        (path / 'question.json').write_text(json.dumps(dict(question_id='question', saved_at=saved_at)))

    def test_date_only_saved_at_is_rejected_with_its_filename(self):
        with tempfile.TemporaryDirectory() as root, patch.object(saved_answers, 'SCRATCH', root):
            self.save(root, '2026-10-28')
            with self.assertRaisesRegex(ValueError, r'question.json.*saved_at.*time of day'):
                saved_answers.saved('answers')

    def test_a_directly_loaded_answer_also_requires_a_time(self):
        with tempfile.TemporaryDirectory() as root:
            self.save(root, '2026-10-28')
            with self.assertRaisesRegex(ValueError, r'question.json.*saved_at.*time of day'):
                saved_answers.load(pathlib.Path(root, 'answers', 'question.json'))

    def test_question_lists_and_legacy_keys_without_saved_at_are_unchanged(self):
        with tempfile.TemporaryDirectory() as root:
            path = pathlib.Path(root, 'questions.json')
            for value in ([dict(id='question')], dict(question=dict(answer='right'))):
                with self.subTest(value=value):
                    path.write_text(json.dumps(value))
                    self.assertEqual(value, saved_answers.load(path))

    def test_invalid_saved_times_are_rejected(self):
        with tempfile.TemporaryDirectory() as root, patch.object(saved_answers, 'SCRATCH', root):
            for saved_at in ('2026-10-28T25:10:00Z', '2026-02-30T12:00:00Z', '', None):
                with self.subTest(saved_at=saved_at):
                    self.save(root, saved_at)
                    with self.assertRaisesRegex(ValueError, r'question.json.*saved_at.*time of day'):
                        saved_answers.saved('answers')

    def test_full_saved_time_is_preserved(self):
        with tempfile.TemporaryDirectory() as root, patch.object(saved_answers, 'SCRATCH', root):
            for saved_at in ('2026-10-28T18:24:30Z', '2026-10-28T18:24:30.123Z', '2026-10-28T18:24:30+01:00'):
                with self.subTest(saved_at=saved_at):
                    self.save(root, saved_at)
                    self.assertEqual(saved_at, saved_answers.saved('answers')['question']['saved_at'])


if __name__ == '__main__':
    unittest.main()
