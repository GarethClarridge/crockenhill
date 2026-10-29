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
A sermon start ruling means the video's opening; one with `part` ({section_id}) means the start of the later part of
the video that opens on that section (plan §4.1a).
Answers with no edge truth (can't tell, noise and loudness questions, joins) are left out and counted.
"""

import collections
import json
import os
import re
import sys

from edge_listening import collect as edge_listening_rulings
from queue_sample import collect as queue_sample_rulings
from saved_answers import load as load_saved_answer, saved

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
    return load_saved_answer(os.path.join(SCRATCH, path))


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


def add(source, ruling, *, run, edge, heard, output, section_id=None, section_type=None, expect=None, heard_range=None):
    choice = ruling['choice']
    expect = 'either' if heard_range else expect or EXPECT.get(choice)
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
        'ruled_at': ruling['saved_at'],
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
        **({'points': sorted(round(float(time), 3) for time in heard_range)} if heard_range else {}),
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

# Pages from 2026-10-18 on. Their single-cut questions answer `early` or `late`, which clip or
# overrun depending on the edge; their A/B questions name the version heard right.
TOWARDS = {('end', 'early'): 'outward', ('start', 'late'): 'outward',
           ('end', 'late'): 'inward', ('start', 'early'): 'inward'}

CLOCK = r'((?:\d+:)?\d{1,2}:\d{2}(?:\.\d+)?)'
CONTEXT_TIME = [re.compile(pattern + CLOCK) for pattern in (
    r'from \S+ to ', r'beginning (?:exactly )?at ', r'ending (?:exactly )?at ', r'now ends at ')]


def single(source, ruling, *, run, edge, heard, output, section_id=None, section_type=None):
    if ruling['choice'] in ('early', 'late'):
        add(source, ruling, run=run, edge=edge, heard=heard, output=output, section_id=section_id,
            section_type=section_type, expect=TOWARDS[(edge, ruling['choice'])])
    else:
        add(source, ruling, run=run, edge=edge, heard=heard, output=output, section_id=section_id,
            section_type=section_type)


def versions(source, ruling, key, *, run, edge, output, section_id=None, section_type=None):
    """An A/B answer keeps the version chosen. "Both fine" makes each of the two cuts right, and only those
    (operator, 10-26: either is fine, not any time between). `heard` names one of them; neither is preferred."""
    times = key.get('times') or {name: key[f'{name}_end'] for name in (key['1'], key['2'])}
    choice = ruling['choice']
    if choice in ('1', '2'):
        heard = times[key[choice]]
    elif choice == 'both':
        heard = max(times.values()) if edge == 'end' else min(times.values())
    else:
        skipped[f'{source}:{choice}'] += 1
        return
    version = next(name for name, time in times.items() if time == heard)
    add(source, {**ruling, 'choice': 'right', 'question_id': f"{ruling['question_id']}-{version}"}, run=run, edge=edge,
        heard=heard, output=output, section_id=section_id, section_type=section_type,
        heard_range=list(times.values()) if choice == 'both' else None)


def from_context(source, directory, rulings_dir='answers/rulings'):
    """Pages whose question text gives the cut heard: `<run>-<edge>` sermons, `<run>-s<section>-<edge>` sections."""
    questions = {question['id']: question for question in load(f'{directory}/questions.json')}
    for question_id, ruling in saved(f'{directory}/{rulings_dir}').items():
        match = re.fullmatch(r'(\d+)-(?:s(\d+)-)?(start|end)', question_id)
        context = questions[question_id]['context']
        heard = next((seconds(m.group(1)) for p in CONTEXT_TIME if (m := p.search(context))), None)
        if match is None or heard is None:
            skipped[f'{source}:not an edge question'] += 1
            continue
        run, section_id, edge = match.groups()
        # The context's clock is to a tenth; the clip cut from the edge has it exactly.
        length = re.search(r'(?:first|last) (\d+(?:\.\d+)?) s', context)
        if length:
            exact = questions[question_id]['clips'][0]['offset'] + (float(length.group(1)) if edge == 'end' else 0.0)
            heard = exact if abs(exact - heard) < 0.1 else heard
        single(source, ruling, run=run, edge=edge, heard=round(heard, 3),
               output='section' if section_id else 'sermon', section_id=int(section_id) if section_id else None)


def from_keys(source, directory, keys):
    """Pages that kept each question's edge in a key: one cut (`at`) or two versions (`times`)."""
    for question_id, ruling in saved(f'{directory}/answers/rulings').items():
        key = keys.get(question_id) or {}
        # Held-word ids were candidate-<run>-<section>-<edge>; ids elsewhere name the section s<id>.
        ruling = {**ruling, 'question_id': re.sub(r'^candidate-(\d+)-(\d+)-', r'\1-s\2-', question_id)}
        edge = key.get('edge') or question_id.rsplit('-', 1)[-1]
        if edge not in ('start', 'end') or not ('at' in key or 'times' in key or '1' in key):
            skipped[f'{source}:not an edge question'] += 1
            continue
        owner = dict(output='sermon') if key.get('sermon') or key.get('owner') == 'sermon' else dict(
            output='section', section_id=key.get('section'), section_type=key.get('type'))
        if 'at' in key:
            single(source, ruling, run=ruling['run'], edge=edge, heard=key['at'], **owner)
        else:
            versions(source, ruling, key, run=ruling['run'], edge=edge, **owner)


from_context('listening-edges-20261018', 'listening-edges-20261018')
from_context('listening-edges2-20261018', 'listening-edges2-20261018')
# Some 10-18 section questions named the wrong section; relabelled.json joins each to the true one.
cut_rulings = saved('listening-edges3-20261018/answers/rulings-cut')
for edge in load('listening-edges3-20261018/answers/relabelled.json'):
    single('listening-edges3-20261018', cut_rulings[edge['id']], run=edge['run'], edge=edge['edge'], heard=edge['at'],
           output='section', section_id=edge['true_sid'], section_type=edge['type'])
from_context('listening-last-20261019', 'listening-last-20261019')
from_context('listening-relisten-20261019', 'listening-relisten-20261019', 'answers/rulings-relisten')
from_context('listening-recut-20261020', 'listening-recut-20261020')
from_context('listening-1251-k16-20261020', 'listening-1251-k16-20261020')
for source, directory in (('listening-songs-20261020', 'listening-songs-20261020'),
                          ('listening-song-changes-20261020', 'listening-song-changes-20261020')):
    keys = {question['id']: {**question['key'], 'section': question['key']['section_id'], 'type': 'song'}
            for question in load(f'{directory}/questions.json')}
    from_keys(source, directory, keys)
for source, name in (('listening-held-word-20261021', 'held-word-endings'), ('listening-song-questions-20261021', 'song-questions')):
    keys = {question_id: {**entry.get('key', {}), 'type': 'song'} for question_id, entry in load(f'listening-final-20261021/{name}/private.json').items()}
    from_keys(source, f'listening-final-20261021/{name}', keys)
for source, directory in (('listening-final-round-20261022', 'listening-final-round-20261022'),
                          ('listening-recut-20261022', 'listening-recut-20261022'),
                          ('listening-1310-20261022', 'listening-1310-20261022'),
                          ('listening-open-edges-20261022', 'listening-open-edges-20261022')):
    from_keys(source, directory, {question['id']: question['key'] for question in load(f'{directory}/questions.json')})

records.extend(queue_sample_rulings(
    load('queue-sample-20261024/sample.json'),
    list(saved('queue-listening-20261024/answers/rulings').values()),
    load('queue-followup-20261025/answers/rulings/2659-sermon_start.json'),
))
records.extend(edge_listening_rulings(
    'edge-listening-20261025', load('edge-listening-20261025/questions.json'),
    list(saved('edge-listening-20261025/answers/final/rulings').values()),
))
# 10-26: song starts the sentence rule moves; A is the cut before it, B after. Same question shape.
records.extend(edge_listening_rulings(
    'sentence-listening-20261026', load('sentence-listening-20261026/questions.json'),
    list(saved('sentence-listening-20261026/answers/rulings').values()),
))
# 10-26: ruled edges the clipped-opening rule (E22) moves, heard again; A is the ruled cut, B the rule's.
records.extend(edge_listening_rulings(
    'margin-relisten-20261026', load('margin-relisten-20261026/questions.json'),
    list(saved('margin-relisten-20261026/answers/rulings').values()),
))
# 10-26 night: run 1216 re-cut after the E22 redetect; A heard before, B the new cut. The sermon-song
# join (two edges in one question) has no single-edge key and stays in the page record.
records.extend(edge_listening_rulings(
    'run1216-recut-20261026', [q for q in load('run1216-recut-20261026/questions.json') if q['key']['kind'] == 'cut'],
    list(saved('run1216-recut-20261026/answers/rulings').values()),
))
# 10-27: song starts the wider edge decode (Step 2) moves; A is the cut before it, B after. The key names
# the section in `output`.
records.extend(edge_listening_rulings(
    'wider-decode-20261027',
    [{**q, 'key': {**q['key'], 'output': 'section', 'section': q['key']['output'], 'type': 'song'}}
     for q in load('wide-edge-step2-listening-20261027/questions.json')],
    list(saved('wide-edge-step2-listening-20261027/answers/rulings').values()),
))
# 10-27 night: song starts the first-quiet margin moves; B ("now") the cut before it, C ("new") after.
# 1023 §1361 also played A, the cut ruled right, which was not chosen. In A/B terms: now = a, new = b.
MARGIN = {'now': 'a', 'new': 'b', 'either': 'both'}
records.extend(edge_listening_rulings(
    'start-margin-20261027',
    [{**q, 'key': {**q['key'], 'times': {'a': q['key']['times']['now'], 'b': q['key']['times']['new']}}}
     for q in load('start-margin-listening-20261027/questions.json')],
    [{**answer, 'choice': MARGIN.get(answer['choice'], answer['choice'])}
     for answer in saved('start-margin-listening-20261027/answers/rulings').values()],
))
# 10-28: song ends the silence fix moves (A now, B at the silence), and two sermon ends. 1053 played three
# cuts; the note ("C is right. B is acceptable.") makes B and C the two acceptable ones, C heard.
SONG_END_SILENCE = {'1053-sermon-end': ({'a': 1576.9, 'b': 1573.4}, 'both')}
song_end_answers = saved('test-fixes-listening-20261027/answers/rulings')
records.extend(edge_listening_rulings(
    'song-end-silence-20261028',
    [{**q, 'key': {**q['key'], 'times': SONG_END_SILENCE.get(q['id'], (q['key']['times'],))[0]}}
     for q in load('test-fixes-listening-20261027/questions.json')],
    [{**answer, 'choice': SONG_END_SILENCE[question_id][1]} if question_id in SONG_END_SILENCE else answer
     for question_id, answer in song_end_answers.items()],
))

# 10-28: recording-end joins, the sermon-own-last-line review, then the moved song-join ends the Amen step-back and its listening page. Each answer retains its actor.
for source in ('sermon-end-no-song-20261028', 'sermon-own-last-line-20261028', 'sermon-own-line-songs-20261028', 'sermon-amen-step-back-20261028', 'amen-k16-listening-20261028'):
    records.extend(edge_listening_rulings(
        source, load(f'{source}/questions.json'), list(saved(f'{source}/answers').values()),
    ))

# 10-29: song starts the dependent-opening rule moves (A "now", B "new"), 1311's ruled start against the start
# now, and starts the rule leaves alone, heard alone ("right" keeps the start heard). 1096's "later" opens later
# than heard; its note names two places and stays in the note.
leaning_questions = load('dependent-opening-listening-20261029/questions.json')
leaning_answers = saved('dependent-opening-listening-20261029/answers/rulings')
# A page version is A or B by its order on the page; a lone version is B, the cut heard.
leaning_letters = {q['id']: dict(zip(q['key']['times'], ('a', 'b') if len(q['key']['times']) == 2 else ('b',)))
                   for q in leaning_questions}
records.extend(edge_listening_rulings(
    'leaning-openings-20261029',
    [{**q, 'key': {**q['key'], 'times': {leaning_letters[q['id']][name]: time for name, time in q['key']['times'].items()}}}
     for q in leaning_questions],
    [{**answer, 'choice': {**leaning_letters[question_id], 'either': 'both', 'right': 'b'}.get(answer['choice'], answer['choice'])}
     for question_id, answer in leaning_answers.items()],
))
for question in leaning_questions:
    answer = leaning_answers.get(question['id'])
    if answer is not None and answer['choice'] in ('later', 'earlier'):
        add('leaning-openings-20261029', answer, run=question['run'], edge='start', heard=question['key']['times']['now'],
            output='section', section_id=question['key']['section'], section_type='song',
            expect='inward' if answer['choice'] == 'later' else 'outward')

# 10-29 evening: 968's and 1184's sermon ends and 1267 §3355's song end. Choices are the version letters;
# 1267's "several" note ("A or B. C is wrong - it includes speech.") makes A and B the two acceptable ends.
THREE_ENDS = {'1267-s3355-end': ('a', 'b')}
three_ends_answers = saved('three-ends-listening-20261029/answers/rulings')
records.extend(edge_listening_rulings(
    'three-ends-20261029',
    [{**q, 'key': {**q['key'], 'times': {name: time for name, time in q['key']['times'].items()
                                         if name in THREE_ENDS.get(q['id'], q['key']['times'])}}}
     for q in load('three-ends-listening-20261029/questions.json')],
    [{**answer, 'choice': 'both' if question_id in THREE_ENDS else answer['choice'].lower()}
     for question_id, answer in three_ends_answers.items()],
))

# 10-29: sermon ends the K16 reword moves (A now, B reworded; 1183 also C, its 10-29 ruled end). 1183's "All are
# fine" makes all three acceptable; 962's "Either fine, but B better" is two acceptable ends, the preference noted.
records.extend(edge_listening_rulings(
    'k16-reword-20261029', load('k16-reword-listening-20261029/questions.json'),
    [{**answer, 'choice': 'both' if answer['choice'] == 'several' else answer['choice']}
     for answer in saved('k16-reword-listening-20261029/answers/rulings').values()],
))

# 10-29 late: Song Tail Lines (the song-order lyric extension). 1291's song end was heard alone ("right" keeps
# it); 1291's reading start "about 18:45" is later than both cuts, an approximate time that stays in the note.
lyric_tail_questions = load('lyric-tail-listening-20261029/questions.json')
lyric_tail_answers = saved('lyric-tail-listening-20261029/answers/rulings')
records.extend(edge_listening_rulings(
    'lyric-tail-20261029', lyric_tail_questions,
    [{**answer, 'choice': {'right': 'a'}.get(answer['choice'], answer['choice'].lower())}
     for answer in lyric_tail_answers.values()],
))


def with_parts(source, questions, inner_parts, answers):
    """Rulings from a page whose inner_parts questions heard the start of a later part of the sermon video, not its
    opening: each carries `part`, the section that part opens on, so it never stands for the opening (plan §4.1a)."""
    first_sections = {question['id']: question['key']['first_section'] for question in questions if question['id'] in inner_parts}
    collected = edge_listening_rulings(source, questions, answers)
    for record in collected:
        question_id = record['id'].split(':', 1)[1]
        if question_id in first_sections:
            record['part'] = {'section_id': int(first_sections[question_id])}
    return collected


# 10-29 late: After The Song (a spoken start follows a song that fades out). Choices are the version letters.
# 1208's and 1250's moved starts open a later part of the sermon video (1250's "more like 28:59", later than both
# cuts, is approximate and stays in the note; SongEndEdgesTest holds it).
FADE_INNER_PARTS = {'1208-sermon-start', '1250-sermon-start'}
fade_questions = load('after-song-fade-listening-20261029/questions.json')
fade_answers = saved('after-song-fade-listening-20261029/answers/rulings')
records.extend(with_parts(
    'after-song-fade-20261029', fade_questions, FADE_INNER_PARTS,
    [{**answer, 'choice': answer['choice'].lower()} for answer in fade_answers.values()],
))
for source, questions, answers in (('lyric-tail-20261029', lyric_tail_questions, lyric_tail_answers),
                                   ('after-song-fade-20261029', [q for q in fade_questions if q['id'] not in FADE_INNER_PARTS], fade_answers)):
    for question in questions:
        answer = answers.get(question['id'])
        if answer is not None and answer['choice'] == 'neither' and question['key']['edge'] == 'start':
            key = question['key']
            add(source, answer, run=question['run'], edge='start', heard=max(key['times'].values()),
                output=key['output'], section_id=key['section'], section_type=key['type'], expect='inward')

# 10-29 late: 1042's song start heard longer (Leaning Song Openings could not tell); "right" keeps the start heard.
records.extend(edge_listening_rulings(
    'longer-1042-20261029',
    [{**q, 'key': {**q['key'], 'times': {'b': q['key']['times']['now']}}} for q in load('longer-1042-listening-20261029/questions.json')],
    [{**answer, 'choice': {'right': 'b'}.get(answer['choice'], answer['choice'])}
     for answer in saved('longer-1042-listening-20261029/answers/rulings').values()],
))

# 10-29 night: Smeared First Words (a speech start back-estimated from its first word heard at speaking pace).
# Choices are the version letters; all three were "either". 1235's moved start opens the sermon video's second part.
records.extend(with_parts(
    'smeared-opening-20261029', load('smeared-opening-listening-20261029/questions.json'), {'1235-sermon-start'},
    [{**answer, 'choice': answer['choice'].lower()} for answer in saved('smeared-opening-listening-20261029/answers/rulings').values()],
))
# 10-29 night: Good Morning Start, 1208's second part heard again at the smeared-opening back-estimate ("either").
records.extend(with_parts(
    'smeared-1208-20261029', load('smeared-1208-listening-20261029/questions.json'), {'1208-sermon-start'},
    [{**answer, 'choice': answer['choice'].lower()} for answer in saved('smeared-1208-listening-20261029/answers/rulings').values()],
))

# Answers the plan later closed with a reading of its own: the answer heard something true that no
# cut can fix, so the cut heard stands.
CLOSED = {
    # §4.0, closed 10-21: the reading's first verse is in a dead feed, so this is the first reading recorded.
    'listening-edges-20261018:1328-start': 'keep',
    'listening-recut-20261020:1328-start': 'keep',
}
for record in records:
    if record['id'] in CLOSED:
        record['expect'] = CLOSED[record['id']]

# Rulings made outside a listening page: the operator's word on a heard edge, or a register entry that
# settles an edge heard before it. Each allows either of the two times.
RULED = [
    ('operator-20261022:1363-s4533-start', '2026-10-22T11:44:56Z', 1363, 4533, 'start', (201.74, 201.74),
     'Plan §5, operator 10-22: left as cut, a recorded soft miss.'),
    ('register-e9:1262-s3284-start', '2026-10-22T11:38:43Z', 1262, 3284, 'start', (1520.86, 1531),
     'Plan §5, 10-22 step 5: song introduction asked in error; recorded as a preference. E9 permits either.'),
    ('operator-20261022:1347-sermon-start', '2026-10-22T11:44:56Z', 1347, None, 'start', (1576.7, 1576.7),
     'Plan §5, 10-22: the operator kept the mid-verse sermon start as cut.'),
    ('operator-20261022:1268-s4736-end', '2026-10-22T11:22:42.109Z', 1268, 4736, 'end', (2338.9, 2338.9),
     'Plan §5, 10-22: sermon join3 at 2338.9 was heard right, superseding the earlier section-end ruling.'),
    # The decisions were recorded at 11:44 UTC (0cb0fa404), after the 11:11 answer.
    ('operator-20261022:1274-s3433-end', '2026-10-22T11:44:56Z', 1274, 3433, 'end', (1704.74, 1713.95),
     'Step 5 chose the old end; the operator left the current one as cut (soft miss, plan §5 10-22).'),
    ('register-e9:1286-s3587-start', '2026-10-10', 1286, 3587, 'start', (1665.22, 1670.96),
     'E9: a song clip keeps its spoken introduction ("Now we\'re going to sing a new song."); 10-10: '
     'an introduction may be in or out.'),
]
# 10-24: song starts set by the operator's Intro Rule, Song Intros and Song Openings answers
# (listening-intro-rule-20261024/record-starts.php; register D6 amended 10-24). They were stored as operator
# edges on the runs, so they supersede any earlier ruling on the same start.
for run, section_id, time, status in load('listening-intro-rule-20261024/recorded-starts.json'):
    RULED.append((f'operator-20261024:{run}-s{section_id}-start', '2026-10-24T11:29:29Z', run, section_id, 'start', (time, time),
                  'Plan §5, operator 10-24: start set from the Intro Rule / Song Intros / Song Openings listening.'))
for ruling_id, ruled_at, run, section_id, edge, heard_range, note in RULED:
    records.append({'id': ruling_id, 'source': ruling_id.split(':')[0], 'ruled_on': ruled_at[:10], 'ruled_at': ruled_at, 'run': run,
                    'output': 'sermon' if section_id is None else 'section', 'section_id': section_id, 'section_type': None, 'title': None, 'edge': edge,
                    'heard': heard_range[1] if edge == 'end' else heard_range[0], 'answer': None, 'expect': 'either',
                    'ruled_time': None, 'note': note, 'points': list(heard_range)})

# A later ruling on the same edge was made on a later cut, so it replaces the earlier ones. A later part of a sermon
# video is its own edge: its start never replaces the video's opening, nor the reverse.
def edge_of(record):
    return (record['run'], record['output'], record['section_id'], record['edge'], (record.get('part') or {}).get('section_id'))


latest = {}
for record in sorted(records, key=lambda record: record['ruled_at']):
    latest[edge_of(record)] = record['ruled_at']
for record in records:
    record['superseded'] = record['ruled_at'] < latest[edge_of(record)]

records.sort(key=lambda record: (record['run'], record['heard'], record['id']))
summary = {
    'rulings': len(records),
    'by_source': dict(sorted(collections.Counter(r['source'] for r in records).items())),
    'by_expect': dict(sorted(collections.Counter(r['expect'] for r in records).items())),
    'by_answer_source': dict(sorted(collections.Counter(r['answer_source'] for r in records if 'answer_source' in r).items())),
    'runs': len({r['run'] for r in records}),
    'superseded': sum(r['superseded'] for r in records),
    'left_out': dict(sorted(skipped.items())),
}
os.makedirs(os.path.dirname(OUT), exist_ok=True)
with open(OUT, 'w') as handle:
    json.dump({
        '_comment': 'Edge rulings and recorded reviews, joined to the edge and cut heard (plan §4.6). '
                    'source names the listening page; answer_source preserves operator or claude-review where recorded. '
                    'Generated by scripts/rulings/gather.py from this machine\'s storage/scratch listening pages; '
                    'do not edit by hand. Times are recording seconds.',
        'summary': summary,
        'rulings': records,
    }, handle, indent=1, ensure_ascii=False)
    handle.write('\n')
json.dump(summary, sys.stdout, indent=1)
print()
