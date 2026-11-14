"""On the operator's machine, plain gathering must reproduce every banked answer byte for byte."""
import contextlib
import io
import pathlib
import runpy
import unittest
from unittest.mock import patch


ROOT = pathlib.Path(__file__).resolve().parents[2]
FIXTURE = ROOT / 'tests/Fixtures/Rulings/edge-rulings.json'
SOURCE = ROOT / 'storage/scratch/end-listen-20261114/versions.json'


class GatherReproducibilityTest(unittest.TestCase):
    @unittest.skipUnless(SOURCE.is_file(), 'Requires the operator’s saved listening pages')
    def test_plain_gather_reproduces_the_banked_fixture_without_writing_it(self):
        expected = FIXTURE.read_text()
        output = io.StringIO()
        real_open = open

        class RetainedOutput(io.StringIO):
            def close(self):
                pass

        fixture_output = RetainedOutput()

        def capture_fixture(file, mode='r', *args, **kwargs):
            if pathlib.Path(file).resolve() == FIXTURE and mode == 'w':
                return fixture_output
            return real_open(file, mode, *args, **kwargs)

        with patch('builtins.open', side_effect=capture_fixture), contextlib.redirect_stdout(output):
            runpy.run_path(str(ROOT / 'scripts/rulings/gather.py'), run_name='__main__')

        self.assertEqual(expected, fixture_output.getvalue())


if __name__ == '__main__':
    unittest.main()
