"""Gather the operator's edge rulings into one committed, data-only set (plan §4.6).

Every listening page since 2026-10-03 saved its answers as one JSON file per question in a
gitignored storage/scratch directory, with the edge and the time heard held separately in the
page's own items file. This joins each answer to its edge and writes tests/Fixtures/Rulings/edge-rulings.json:
for each ruling, the run, the output (the sermon video or a section), the edge, the cut the operator
heard (recording time), and what the ruling expects of any later cut.

    python3 scripts/rulings/gather.py            # from the repository root, on this machine

Expectations:
  keep     the cut heard was right: a later cut should stay within tolerance of it;
  outward  the cut heard clipped the item: a later cut should open earlier or end later;
  inward   the cut heard took in too much of the neighbouring item: open later or end earlier;
  at       the note names the right time (`ruled_time`).
Answers with no edge truth (can't tell, noise and loudness questions, joins) are left out and counted.
"""

import collections
import glob
import json
import os
import re
import sys

SCRATCH = 'storage/scratch'
OUT = 'tests/Fixtures/Rulings/edge-rulings.json'

EXPECT = {
    'right': 'keep', 'fine': 'keep', 'other_speaker': 'keep',
    'clips_word': 'outward', 'cut_off': 'outward', 'clipped': 'outward', 'wrong_place': 'outward',
    'too_much': 'inward',
    'cut_at': 'at',
}

NOTE_TIME = re.compile(r'\b(?:at|to)\s+((?:\d+:)?\d{1,2}:\d{2}(?:\.\d+)?)')


def load(path):
    with open(os.path.join(SCRATCH, path)) as handle:
        return json.load(handle)


def saved(directory):
    rulings = {}
    for path in sorted(glob.glob(os.path.join(SCRATCH, directory, '*.json'))):
        with open(path) as handle:
            ruling = json.load(handle)
        rulings[ruling['question_id']] = ruling
    return rulings


def seconds(clock):
    total = 0.0
    for part in clock.split(':'):
        total = total * 60 + float(part)
    return round(total, 3)


def ruled_time(ruling):
    match = NOTE_TIME.search(ruling.get('note') or '')
    return seconds(match.group(1)) if match else None


def is_sermon(*texts):
    return any('sermon' in (text or '').lower() for text in texts)


records = []
skipped = collections.Counter()


def add(source, ruling, *, run, edge, heard, output, section_id=None, section_type=None):
    choice = ruling['choice']
    expect = EXPECT.get(choice)
    at = ruled_time(ruling)
    if at is not None and expect in ('outward', 'inward', 'at'):
        expect = 'at'
    if expect is None or edge not in ('start', 'end') or heard is None:
        skipped[f'{source}:{choice}'] += 1
        return
    if expect == 'at' and at is None:
        skipped[f'{source}:{choice} (no time in note)'] += 1
        return
    records.append({
        'id': f"{source}:{ruling['question_id']}",
        'source': source,
        'ruled_on': ruling['saved_at'][:10],
        'run': int(run),
        'output': output,
        'section_id': section_id,
        'section_type': section_type,
        'title': ruling.get('title'),
        'edge': edge,
        'heard': round(float(heard), 3),
        'answer': choice,
        'expect': expect,
        'ruled_time': at,
        'note': ruling.get('note') or '',
    })


def from_items(source, rulings_dir, items_file, section_edges=None):
    """Pages whose items carry `edge` and `time` (the full-pass main page and canaries 11-15)."""
    items = {item['id']: item for item in load(items_file)}
    for question_id, ruling in saved(rulings_dir).items():
        item = items.get(question_id)
        if item is not None and item.get('edge') == 'pair':
            add_pair(source, ruling, item)
            continue
        if item is None or item.get('edge') not in ('start', 'end') or 'time' not in item:
            skipped[f'{source}:not an edge question'] += 1
            continue
        parts = question_id.split('-')
        section_id = None
        sermon = is_sermon(question_id, item.get('title'))
        # Full-pass ids are <run>-<section id|sermon>-<edge>.
        if not sermon and len(parts) == 3 and parts[0] == str(item['run']) and parts[1].isdigit():
            section_id = int(parts[1])
        section_type = None
        if not sermon and section_id is None and section_edges is not None:
            match = section_edges.get((item['run'], item['edge'], round(float(item['time']), 1)))
            if match:
                section_id, section_type = match
        add(source, ruling, run=item['run'], edge=item['edge'], heard=item['time'],
            output='sermon' if sermon else 'section', section_id=section_id, section_type=section_type)


PAIR_KEEPS = {'right': ('start', 'end'), 'start_wrong': ('end',), 'end_wrong': ('start',)}


def add_pair(source, ruling, item):
    """Canary 15 asked about a song's or talk's two ends at once; a ruling keeps the ends it calls right."""
    kept = PAIR_KEEPS.get(ruling['choice'])
    if kept is None:
        skipped[f"{source}:pair {ruling['choice']}"] += 1
        return
    clips = {clip['src'].rsplit('-', 2)[-2]: clip for clip in item['clips'] if clip['src'].endswith('-as.mp3')}
    section_type = item['id'].split('-', 2)[2].removesuffix('-pair')
    for edge in kept:
        heard = clips[edge]['offset'] if edge == 'start' else clips[edge]['end']
        add(source, {**ruling, 'choice': 'right', 'question_id': f"{ruling['question_id']}-{edge}"},
            run=item['run'], edge=edge, heard=heard, output='section', section_type=section_type)


def canary13_section_edges():
    edges = {}
    for edge in load('canary13-listening/edges.json'):
        if edge.get('section_id'):
            edges[(edge['run'], edge['edge'], round(float(edge['time']), 1))] = (edge['section_id'], edge.get('type'))
    return edges


# The 2026-10 endings listening: the answer key already joins run and time; edges are all ends.
key = load('endings-listening/answer-key.json')
types = {row['id']: row.get('type') for row in load('endings-rule/joined.json')}
for question_id, answer in key.items():
    _, run, which = question_id.split('-', 2)
    ruling = {'question_id': question_id, 'choice': answer['answer'], 'note': answer.get('note', ''),
              'saved_at': '2026-10-12', 'title': answer.get('group')}
    add('endings', ruling, run=run, edge='end', heard=answer['time'],
        output='sermon' if which == 'sermon' else 'section',
        section_id=None if which == 'sermon' else int(which),
        section_type=None if which == 'sermon' else types.get(question_id))

from_items('full-pass', 'endings-rule/main-rulings/rulings', 'full-pass-listening/items.json')
from_items('full-pass-v2', 'full-pass-listening/rulings-v2/rulings', 'full-pass-listening/items.json')
from_items('canary11', 'canary11-listening-20261005/saved/rulings', 'canary11-listening-20261005/items.json')
from_items('canary12', 'canary12-listening-20261006/saved/rulings', 'canary12-listening-20261006/items.json')
from_items('canary13', 'canary13-listening/answers/rulings', 'canary13-listening/items.json', canary13_section_edges())
from_items('canary14', 'canary14-listening/answers/rulings', 'canary14-listening/items.json')
from_items('canary15', 'canary15-listening/rulings/rulings', 'canary15-listening/items.json')

# 10-03 widening page: ids are <run>-<edge>-<whole seconds of the edge before widening>.
widened = {(edge['run'], edge['edge'], int(edge['original_time'])): edge for edge in load('widening-listening-20261003/edges.json')}
for question_id, ruling in saved('widening-listening-20261003/saved/rulings').items():
    run, edge_name, original = question_id.split('-')
    edge = widened.get((int(run), edge_name, int(original)))
    if edge is None:
        skipped['widening:no edge'] += 1
        continue
    add('widening', ruling, run=run, edge=edge_name, heard=edge['time'],
        output='sermon' if is_sermon(edge.get('output')) else 'section')

# 10-03 word-edge page: ids are <index>-<run>-<edge>, the index into edges.json.
word_edges = load('word-edge-listening-20261003/edges.json')
for question_id, ruling in saved('word-edge-listening-20261003/saved/rulings').items():
    index, run, edge_name = question_id.split('-')
    edge = word_edges[int(index)]
    add('word-edge', ruling, run=run, edge=edge_name, heard=edge['time'],
        output='sermon' if is_sermon(edge.get('output')) else 'section')

# 10-03 reading-end page: one reading end per run.
reading_ends = {edge['run']: edge for edge in load('edge-listening-20261003/edges.json')}
for question_id, ruling in saved('edge-listening-20261003/saved/rulings').items():
    edge = reading_ends[int(question_id.split('-')[0])]
    add('reading-end', ruling, run=edge['run'], edge='end', heard=edge['cut'], output='section',
        section_id=edge['section_id'], section_type=edge['section_type'])

# Canary 13 proposed cuts: ids are <run>-<sermon|song<section id>>-<edge>-<centiseconds>.
for question_id, ruling in saved('canary13-edges/saved/rulings').items():
    run, which, edge_name, centiseconds = question_id.split('-')
    section_id = None if which == 'sermon' else int(which.removeprefix('song'))
    add('canary13-edges', ruling, run=run, edge=edge_name, heard=int(centiseconds) / 100,
        output='sermon' if which == 'sermon' else 'section', section_id=section_id,
        section_type=None if which == 'sermon' else 'song')

records.sort(key=lambda record: (record['run'], record['heard'], record['id']))
summary = {
    'rulings': len(records),
    'by_source': dict(sorted(collections.Counter(r['source'] for r in records).items())),
    'by_expect': dict(sorted(collections.Counter(r['expect'] for r in records).items())),
    'runs': len({r['run'] for r in records}),
    'left_out': dict(sorted(skipped.items())),
}
os.makedirs(os.path.dirname(OUT), exist_ok=True)
with open(OUT, 'w') as handle:
    json.dump({
        '_comment': 'The operator\'s edge rulings, joined to the edge and the cut heard (plan §4.6). '
                    'Generated by scripts/rulings/gather.py from this machine\'s storage/scratch listening pages; '
                    'do not edit by hand. Times are recording seconds.',
        'summary': summary,
        'rulings': records,
    }, handle, indent=1, ensure_ascii=False)
    handle.write('\n')
json.dump(summary, sys.stdout, indent=1)
print()
