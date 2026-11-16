"""Fold capture.php's one-fixture-per-ruling output into one fixture per run (plan §4.6).

Fixtures on the same run hold the same sections, transcript, decoded edge words and run row; only
the sound levels differ, each a window around that ruling's edges. A run fixture stores the shared
data once, the union of the level windows, and a `rulings` list of what each ruling adds (its ruling,
the live cut and any recorded answers). RuledEndingsRegressionTest replays each entry on its own.

    python3 scripts/ruled-endings/merge.py <capture dir> <out dir> [<ruling ids to leave out file>]

Writes <out dir>/run-<run id>-<captured_on>.json.gz.
"""

import collections
import glob
import gzip
import io
import json
import os
import sys

SHARED = ('config', 'run', 'files', 'sections', 'captured_on', 'service_songs')

capture_dir, out_dir = sys.argv[1], sys.argv[2]
left_out = set()
if len(sys.argv) > 3:
    with open(sys.argv[3]) as handle:
        left_out = {line.strip() for line in handle if line.strip()}

by_run = collections.defaultdict(list)
for path in sorted(glob.glob(os.path.join(capture_dir, '*.json.gz'))):
    with gzip.open(path, 'rt') as handle:
        fixture = json.load(handle)
    if fixture['id'] in left_out:
        continue
    by_run[fixture['run']['id']].append(fixture)

os.makedirs(out_dir, exist_ok=True)
for run_id, fixtures in sorted(by_run.items()):
    first = fixtures[0]
    for fixture in fixtures[1:]:
        for key in SHARED:
            if fixture.get(key) != first.get(key):
                sys.exit(f"run {run_id}: {fixture['id']} differs from {first['id']} in {key}")
        if fixture['rms']['threshold'] != first['rms']['threshold'] or fixture['rms']['disk'] != first['rms']['disk']:
            sys.exit(f"run {run_id}: {fixture['id']} has another level threshold or disk")

    samples = sorted({tuple(sample) for fixture in fixtures for sample in fixture['rms']['samples']})
    merged = {
        'id': f"run-{run_id}-{first['captured_on']}",
        **{key: first[key] for key in SHARED if key in first},
        'rms': {**first['rms'], 'samples': [list(sample) for sample in samples]},
        'rulings': [{key: value for key, value in fixture.items() if key not in (*SHARED, 'rms')} for fixture in fixtures],
    }
    with open(os.path.join(out_dir, merged['id'] + '.json.gz'), 'wb') as output:
        with gzip.GzipFile(filename='', mode='wb', compresslevel=9, fileobj=output, mtime=0) as compressed:
            with io.TextIOWrapper(compressed) as handle:
                json.dump(merged, handle, separators=(',', ':'))
    print(f"{merged['id']}: {len(fixtures)} rulings")
