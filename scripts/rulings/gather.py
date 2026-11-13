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
from section_binding import rebind as rebind_drifted_sections

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


def from_items(source, rulings_dir, items_file, section_edges=None, pair_sections=None):
    """Pages whose items carry `edge` and `time` (the full-pass main page and canaries 11-15)."""
    items = {item['id']: item for item in load(items_file)}
    for question_id, ruling in saved(rulings_dir).items():
        item = items.get(question_id)
        if item is not None and item.get('edge') == 'pair':
            add_pair(source, ruling, item, (pair_sections or {}).get((item['run'], round(float(item['time']), 2))))
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


def add_pair(source, ruling, item, section_id=None):
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
            run=item['run'], edge=edge, heard=heard, output='section', section_id=section_id, section_type=section_type)


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
# A pair's time is its output's first segment start in the page's source, which names the section, so later
# rulings on the same edge supersede it.
from_items('canary15', 'canary15-listening/rulings/rulings', 'canary15-listening/items.json', pair_sections={
    (int(run), round(float(output['segments'][0]['start_time']), 2)): output['section_id']
    for run, service in load('canary15-listening/fresh.json').items() for output in service['outputs']
    if output.get('section_id') and output.get('segments')})

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

# 10-29 night: Amen Heard Again, 1288's sermon end re-asked once K16 let the answering Amen remain (plan §4.1a step 2).
# B again: "It's not the second Amen that's the problem." (A carries the onset of "So let's sing…").
records.extend(edge_listening_rulings(
    'amen-1288-relisten-20261029', load('amen-1288-relisten-20261029/questions.json'),
    list(saved('amen-1288-relisten-20261029/answers/rulings').values()),
))

# 10-29 night: Before The Next Word, four ends moved off the next word's decoded onset into the quiet before its sound
# (plan §4.1a step 2). All B; operator: "'Right' might be a bit of an overstatement, but they're significantly better.
# I don't really see you can get much better than this as there isn't really a pause between words." 928's end is the
# reading's, before the sermon video jumps to the sermon: an earlier part's end (§512), never the video's close.
word_boundary = edge_listening_rulings(
    'word-boundary-20261029', load('word-boundary-listening-20261029/questions.json'),
    [{**answer, 'choice': answer['choice'].lower()} for answer in saved('word-boundary-listening-20261029/answers/rulings').values()],
)
for record in word_boundary:
    if record['run'] == 928:
        record['part'] = {'section_id': 512}
records.extend(word_boundary)

# 10-29: K16-tied sermon ends (A the end before the song talk, B through to the song). 1001's A ended mid-sentence
# ("And celebrate his mercy to you,"); B was chosen over that, not over "…Make it today." (1924.76, ruled 10-28), and
# both are whole-sentence ends, so its answer allows either (plan §4.1a step 4.0).
TIED_1001 = {'a': 1924.76, 'b': 1939.2}
records.extend(edge_listening_rulings(
    'k16-tied-20261029',
    [{**q, 'key': {**q['key'], 'times': TIED_1001}} if q['run'] == 1001 else q
     for q in load('k16-tied-listening-20261029/questions.json')],
    [{**answer, 'choice': 'both'} if answer['run'] == 1001 else answer
     for answer in saved('k16-tied-listening-20261029/answers/rulings').values()],
))

# 10-30: K16 reword ends read from the transcript (plan §4.1a step 4.0). 1140's current end drops the last point
# ("how can you not glorify God?"), so a later end should end later; the reword's 1853.29 carries a dangling "Let's"
# and is not kept. 1375 and 1324 keep the current end: the reword takes in quoted lyrics and the next hymn's title.
for run, edge_time, expect, note in (
    (1140, 1808.62, 'outward', 'Claude review 10-30: the end drops the last point, "how can you not glorify God?".'),
    (1375, 1318.0, 'keep', 'Claude review 10-30: right; the K16 reword takes in "Just because he lives." (quoted lyrics).'),
    (1324, 3307.0, 'keep', 'Claude review 10-30: right; the K16 reword takes in the next hymn\'s title.'),
):
    records.append({'id': f'claude-review-20261030:{run}-sermon-end', 'source': 'claude-review-20261030', 'ruled_on': '2026-10-30',
                    'ruled_at': '2026-10-30T01:53:01Z', 'run': run, 'output': 'sermon', 'section_id': None, 'section_type': 'sermon',
                    'title': None, 'edge': 'end', 'heard': edge_time, 'answer': None, 'expect': expect, 'ruled_time': None,
                    'note': note, 'answer_source': 'claude-review'})

# 10-30: moved edges settled from the transcript, decoded words and levels (plan §4.1a step 3 (a); triage in
# storage/scratch/page1-triage-20261030/). Sermon ends heard before the 10-28 Amen ruling keep the end heard and add the
# round's end, which stops after the preacher's last word or Amen; "either" leaves the cut heard acceptable.
AMEN = 'the 10-28 Amen ruling: the preacher\'s Amen in, the congregation\'s Amen and the song introduction out'
SETTLED = [
    # (run, section_id, edge, points, note); one point is a keep.
    (961, None, 'end', (3884.74, 3924.83), f'Ends "…and discouragement."; drops the hymn announcement ({AMEN}).'),
    (1007, None, 'end', (4506.82, 4561.58), 'Ends "This is the reason. He came to save sinners."; drops the closing prayer, an end that still reads as one (K16, 10-29).'),
    (1038, None, 'end', (3823.62, 3825.64), f'Ends after the Amen, before "All right." ({AMEN}).'),
    (1076, None, 'end', (1616.72, 1618.6), f'Ends after the preacher\'s Amen, before the congregation\'s ({AMEN}).'),
    (1190, None, 'end', (1493.46, 1494.74, 1498.202), f'Ends after "In Jesus\' name. Amen." ({AMEN}).'),
    (1194, None, 'end', (3490.44, 3521.14), f'Ends "…in Jesus\' name. Amen."; drops "let\'s stand… our concluding hymn" and its first verse ({AMEN}).'),
    (1201, None, 'end', (1092.25, 1093.87), f'Ends after the preacher\'s Amen, before the congregation\'s ({AMEN}).'),
    (1218, None, 'end', (3845.94, 3875.25), f'Ends "…in his name. Amen."; drops the hymn introduction ({AMEN}).'),
    (1255, None, 'end', (3604.05, 3625.0), f'Ends after the preacher\'s Amen; drops "let\'s stand… concluding hymn" ({AMEN}).'),
    (1296, None, 'end', (3763.21, 3764.76), f'Ends after the preacher\'s Amen, before the congregation\'s ({AMEN}).'),
    (1299, None, 'end', (4117.97, 4118.8), 'Both after the Amens, before "Let\'s stand"; 0.8 s of the same pause apart.'),
    (1303, None, 'end', (3399.38, 3411.71), f'Ends after the preacher\'s Amen, before the congregation\'s; drops "let\'s remind ourselves of the cross… as we conclude this part of our service" ({AMEN}).'),
    (1326, None, 'end', (3599.73, 3611.11), f'Ends "What a good God it is that we serve."; drops the hymn announcement ({AMEN}).'),
    # A 10-18 "cut off mid sentence" heard the cut inside the hymn announcement; the round ends on the Amen before it.
    (1115, None, 'end', (1461.28,), f'Ends "…in Jesus\' name. Amen."; the whole hymn 828 introduction is out ({AMEN}).'),
    (1127, None, 'end', (4184.14,), f'Ends "…the peace of God will be with you. Amen."; drops "as we sing our last song… let\'s sing" ({AMEN}).'),
    (1167, None, 'end', (4148.62,), f'Ends after the preacher\'s Amen, before the congregation\'s ({AMEN}).'),
    (1283, None, 'end', (3382.6,), f'Ends after "in Jesus\' name. Amen." (decoded 3382.21); drops the communion handover and the hymn\'s last two verses read aloud ({AMEN}).'),
    # Song edges.
    (1073, 1716, 'start', (2700.0, 2710.0), 'The played-through introduction sounds from 2699.5 ("after Jill\'s played it through"); a musical introduction is always in (D6, 10-24).'),
    (1120, 2012, 'start', (2352.06, 2353.05), 'Both in the quiet after "As With Gladness." and before the music.'),
    (1295, 3769, 'end', (350.695, 352.0), 'The round ends before the decoded "Let\'s join together in prayer" (350.77); "Ends at 5:52" was approximate.'),
]
for run, section_id, edge, points, note in SETTLED:
    owner = f's{section_id}' if section_id else 'sermon'
    records.append({'id': f'claude-review-20261030:{run}-{owner}-{edge}', 'source': 'claude-review-20261030', 'ruled_on': '2026-10-30',
                    'ruled_at': '2026-10-30T03:00:00Z', 'run': run, 'output': 'section' if section_id else 'sermon',
                    'section_id': section_id, 'section_type': 'song' if section_id else 'sermon', 'title': None, 'edge': edge,
                    'heard': points[0] if len(points) == 1 or edge == 'start' else max(points), 'answer': None,
                    'expect': 'keep' if len(points) == 1 else 'either', 'ruled_time': None, 'note': 'Claude review 10-30: '+note,
                    'answer_source': 'claude-review', **({'points': list(points)} if len(points) > 1 else {})})

# 10-30 (later): the 110 unruled ≥ 1 s moves settled from cues, decoded words, levels and classifier shares (plan §4.1a
# step 3 (a); triage in storage/scratch/unruled-triage-20261030/). K16 ends that stop after the preacher's last word or
# Amen and drop only a song introduction, quoted lyrics or the congregation's Amen keep the round's end; the other
# settled moves are either, the executed cut and the round's both acceptable. Defects, soft misses, holds and the one
# end that needs the ear are in the plan, not here.
K16_KEPT = {
    929: (4564.40, 'Ends on the Amen; drops the introduction of "What a friend we have in Jesus".'),
    946: (3636.74, 'Ends on the Amen; drops "Somewhat predictably, we\'re going to sing…" and the hymn\'s words.'),
    953: (1691.22, 'Ends "…in your son\'s name. Amen."; drops the congregation\'s Amen.'),
    957: (1573.90, 'Ends "…he is worthy of all our trust."; drops the hymn\'s words read aloud and "Let\'s stand".'),
    969: (4143.14, 'Ends "…in Jesus\' name. Amen."; drops the congregation\'s Amen and the hymn introduction.'),
    971: (3817.94, 'Ends "…in Jesus name amen"; drops the hymn introduction and its first verse.'),
    986: (4468.78, 'Ends on the Amen; drops the congregation\'s Amen and the song introduction.'),
    1017: (1411.40, 'Ends on the Amen; drops "Let\'s stand, shall we, and sing together."'),
    1026: (1661.90, 'Ends "…the weakness of God is stronger than human strength."; drops "let\'s pray together before we sing again".'),
    1032: (4124.06, 'Ends on the Amen; drops the hymn 907 introduction.'),
    1057: (3936.60, 'Ends on the preacher\'s Amen (decoded 3936.03); drops the congregation\'s.'),
    1064: (3880.94, 'Ends on the Amen; drops "let\'s stand… and sing" and the hymn\'s words.'),
    1073: (3593.70, 'Ends on the preacher\'s Amen; drops the congregation\'s and "Well we\'ll stand and sing".'),
    1075: (4031.58, 'Ends on the Amen; drops the congregation\'s Amen and the hymn 396 introduction.'),
    1100: (3776.82, 'Ends on the preacher\'s Amen (decoded 3776.06); drops the congregation\'s.'),
    1110: (981.27, 'Ends "…the wonderful grace of God to us in these verses."; drops "Amazing grace" read aloud.'),
    1141: (1866.04, 'Ends on the preacher\'s Amen; drops the congregation\'s and "Let\'s sing again".'),
    1142: (1522.06, 'Ends on the preacher\'s Amen (decoded 1521.29); drops the congregation\'s.'),
    1173: (1009.18, 'Ends "…have not seen and yet have believed."; drops "I think we\'re going to sing again."'),
    1208: (4112.54, 'Ends "In Jesus\' name, amen."; drops the hymn introduction.'),
    1215: (4008.10, 'Ends on the Amens; drops "we\'re going to sing about doing that…".'),
    1230: (2845.46, 'Ends on the preacher\'s Amen (decoded 2844.18); drops the congregation\'s.'),
    1239: (3943.34, 'Ends on the Amen; drops the song introduction.'),
    1262: (3613.40, 'Ends on the Amen; drops the hymn summary and its words read aloud.'),
    1289: (1443.42, 'Ends "…encourage us"; drops "we\'re going to sing another song…".'),
    1297: (3787.96, 'Ends on the preacher\'s Amen; drops the congregation\'s and "Let\'s sing our final song".'),
    1309: (3687.86, 'Ends "…in Jesus\' name. Amen."; drops the final hymn introduction.'),
    1319: (3332.14, 'Ends on the preacher\'s Amen (decoded 3330.66); drops the congregation\'s.'),
    1331: (3583.89, 'Ends "If only you will receive it."; drops "Let\'s sing our final hymn…".'),
    1336: (3327.86, 'Ends on the Amens; drops the "Be Still My Soul" introduction.'),
    1341: (3713.80, 'Ends on the Amen; drops the "Amazing Grace" introduction.'),
    1354: (3304.68, 'Ends on the Amen; drops the song introduction and its first lines.'),
    1372: (1405.00, 'Ends "…for Jesus\'s sake. Amen."; drops the song introduction.'),
    1381: (1689.18, 'Ends after the preacher\'s Amen sound (to 1689.15); drops the congregation\'s.'),
}
for run, (edge_time, note) in K16_KEPT.items():
    records.append({'id': f'claude-review-20261030:{run}-sermon-end', 'source': 'claude-review-20261030', 'ruled_on': '2026-10-30',
                    'ruled_at': '2026-10-30T10:00:00Z', 'run': run, 'output': 'sermon', 'section_id': None, 'section_type': 'sermon',
                    'title': None, 'edge': 'end', 'heard': edge_time, 'answer': None, 'expect': 'keep', 'ruled_time': None,
                    'note': f'Claude review 10-30: {note} ({AMEN}).', 'answer_source': 'claude-review'})
SPOKEN_INTRO = 'a spoken introduction is optional, a musical one always in (10-24)'
EITHER_MOVES = [
    # (run, section_id or None for the sermon, section_type, edge, executed, round, note)
    (932, 163060, 'song', 'end', 2532.48, 2511.415, 'The round ends before the closing prayer spoken over the music.'),
    (948, 706, 'song', 'start', 849.96, 851.46, f'Drops "…God\'s people. So let\'s stand"; {SPOKEN_INTRO}.'),
    (976, 959, 'song', 'start', 524.27, 523.0, 'Adds 1.3 s of the introduction\'s music.'),
    (1011, 1285, 'song', 'start', 519.73, 513.85, 'Adds 5.9 s of quiet before the song.'),
    (1011, None, 'sermon', 'start', 288.46, 283.645, 'Adds "Will you turn with me now in your Bibles to 2 Peter?"'),
    (1020, 1340, 'song', 'start', 414.16, 417.58, f'Drops the spoken "…the people close to his heart."; {SPOKEN_INTRO}.'),
    (1032, 1437, 'song', 'start', 4166.58, 4168.54, f'Drops the title spoken; {SPOKEN_INTRO}.'),
    (1033, 1440, 'song', 'start', 85.96, 87.16, f'Drops "…for them. Let\'s stand and sing"; {SPOKEN_INTRO}.'),
    (1033, 1445, 'song', 'start', 1129.18, 1131.98, f'Drops the title spoken; {SPOKEN_INTRO}.'),
    (1042, 164158, 'song', 'start', 4143.48, 4144.49, f'Drops "shall we?" spoken over the introduction; {SPOKEN_INTRO}.'),
    (1052, 1608, 'song', 'end', 3853.92, 3855.42, 'Adds 1.5 s of the song\'s last sound, no words.'),
    (1064, 1677, 'song', 'start', 1332.67, 1335.40, f'Drops "let\'s stand and sing it through together."; {SPOKEN_INTRO}.'),
    (1064, 1677, 'song', 'end', 1531.06, 1534.93, 'Adds 3.9 s of music, no words.'),
    (1104, None, 'sermon', 'start', 2003.82, 1999.82, 'Adds "Well, Mark\'s reading is Luke 18."'),
    (1106, 1879, 'song', 'start', 390.0, 375.0, 'Adds 15 s of the introduction\'s music.'),
    (1118, 174658, 'song', 'start', 4133.94, 4138.60, f'Drops "…because he promises to be faithful to us."; {SPOKEN_INTRO}.'),
    (1120, 2004, 'song', 'start', 1365.0, 1355.0, 'Adds 10 s of the introduction\'s music.'),
    (1124, 2031, 'song', 'start', 3255.64, 3258.49, f'Drops "…sing this lovely hymn through together. Amen."; {SPOKEN_INTRO}.'),
    (1129, 174809, 'song', 'start', 2623.15, 2625.69, f'Drops "Let\'s stand and sing before we look at God\'s Word"; {SPOKEN_INTRO}.'),
    (1166, 2285, 'song', 'end', 1580.10, 1581.1715, 'Adds 1.1 s of music.'),
    (1167, 2298, 'song', 'start', 2015.61, 2016.92, f'Drops "stand and sing." over the introduction; {SPOKEN_INTRO}.'),
    (1177, 2352, 'song', 'start', 676.43, 678.38, f'Drops the title spoken; {SPOKEN_INTRO}.'),
    (1194, 2434, 'short_talk', 'end', 939.645, 937.885, 'Drops "Let\'s pray, shall we," after the talk.'),
    (1195, 2444, 'song', 'end', 506.52, 508.722, 'Adds 2.2 s of music, before "Sit down".'),
    (1198, 2486, 'short_talk', 'start', 569.64, 571.22, 'Drops the handover "Anne is going to introduce the next carol."'),
    (1198, 2487, 'song', 'start', 866.31, 868.51, f'Drops the title spoken; {SPOKEN_INTRO}.'),
    (1213, 2615, 'song', 'start', 1731.11, 1732.94, f'Drops "…so let\'s stand and sing"; {SPOKEN_INTRO}.'),
    (1226, 2791, 'song', 'end', 3787.62, 3790.25, 'Adds 2.6 s of music, before "Incidentally".'),
    (1231, 2842, 'song', 'start', 272.15, 274.30, f'Drops "…we\'ll begin to stand and sing."; {SPOKEN_INTRO}.'),
    (1232, 2854, 'song', 'start', 112.77, 116.35, f'Drops "So let\'s stand and sing." over the introduction; {SPOKEN_INTRO}.'),
    (1237, 2954, 'song', 'start', 4579.14, 4581.07, f'Drops "Let\'s stand and sing."; {SPOKEN_INTRO}.'),
    (1244, 165615, 'song', 'start', 3825.04, 3826.80, f'Drops "let\'s stand and sing." over the introduction; {SPOKEN_INTRO}.'),
    (1251, 3147, 'song', 'start', 2230.13, 2236.0, f'Drops the spoken quotation "…to shun evil is understanding."; {SPOKEN_INTRO}.'),
    (1252, 3163, 'song', 'start', 1874.13, 1875.29, f'Drops "The young ones will go…"; {SPOKEN_INTRO}.'),
    (1267, 3342, 'song', 'start', 142.24, 144.81, f'Drops "…as we stand and sing together."; {SPOKEN_INTRO}.'),
    (1271, None, 'sermon', 'start', 1059.73, 1063.57, 'Drops 3.8 s of the music before the sermon.'),
    (1284, 3557, 'song', 'end', 475.90, 477.734, 'Adds 1.8 s of the song\'s last sound, no words.'),
    (1295, 3775, 'song', 'start', 1590.46, 1591.90, f'Drops "…his finished work there." over the introduction; {SPOKEN_INTRO}.'),
    (1302, 3840, 'song', 'start', 204.15, 205.50, f'Drops "…risen Saviour. Let\'s stand"; {SPOKEN_INTRO}.'),
    (1307, 4665, 'song', 'start', 3858.86, 3860.12, f'Drops a spoken "Jesus."; {SPOKEN_INTRO}.'),
    (1320, None, 'sermon', 'start', 229.06, 235.29, 'Drops the reader\'s thanks, "That would be great. Thank you."'),
    (1326, 4136, 'song', 'start', 1277.84, 1279.05, f'Drops the title spoken; {SPOKEN_INTRO}.'),
    (1330, 4189, 'song', 'end', 3626.14, 3627.68, 'Adds 1.5 s of music, no words.'),
    (1330, 4190, 'short_talk', 'start', 3626.24, 3627.68, 'Drops 1.4 s of the music before the testimony.'),
]
for run, section_id, section_type, edge, executed, round_cut, note in EITHER_MOVES:
    owner = f's{section_id}' if section_id else 'sermon'
    records.append({'id': f'claude-review-20261030:{run}-{owner}-{edge}', 'source': 'claude-review-20261030', 'ruled_on': '2026-10-30',
                    'ruled_at': '2026-10-30T10:00:00Z', 'run': run, 'output': 'section' if section_id else 'sermon',
                    'section_id': section_id, 'section_type': section_type, 'title': None, 'edge': edge,
                    'heard': max(executed, round_cut) if edge == 'end' else executed, 'answer': None, 'expect': 'either',
                    'ruled_time': None, 'note': 'Claude review 10-30: '+note, 'answer_source': 'claude-review',
                    'points': [executed, round_cut]})

# 10-30, defect-fix plan F6: a K16 kept line's last word decoded just after a whole-second next cue's start is kept.
# 1375's 10-30 review end (1318.00) cut into "life." (decoded 1318.01–1318.56); 1031's either ends (10-29) keep their
# two points, and the end after "joy." (decoded to 463.60, "We have one more song" from 466.50) is added beside them.
for ruling_id, run, points, note in [
    ('claude-review-20261030:1375-sermon-end-f6', 1375, [1318.86], 'the end after "Of life." (decoded to 1318.56), before "These words."; '
     'supersedes this review\'s 1318.00, which cut into "life".'),
    ('claude-review-20261030:1031-sermon-end-f6', 1031, [462.72, 463.9, 489.72], 'the operator\'s either points (10-29), and the end '
     'after "…you will find joy." (decoded to 463.60), which the earlier point cut into.'),
]:
    records.append({'id': ruling_id, 'source': 'claude-review-20261030', 'ruled_on': '2026-10-30', 'ruled_at': '2026-10-30T18:00:00Z',
                    'run': run, 'output': 'sermon', 'section_id': None, 'section_type': 'sermon', 'title': None, 'edge': 'end',
                    'heard': points[-1] if len(points) == 1 else points[1], 'answer': None,
                    'expect': 'keep' if len(points) == 1 else 'either', 'ruled_time': None, 'note': 'Claude review 10-30: '+note,
                    'answer_source': 'claude-review', **({'points': points} if len(points) > 1 else {})})

# 10-30, defect-fix plan F1 and F2/F3: Sung Or Spoken (11 song starts the round moves later) and Speech Over The Last
# Chords (9 song ends the round moves), A the executed cut, B the round's. Neither answers name approximate times
# ("Should be about 58:28"), so they stay in their notes.
for source in ('song-openings-listening-20261030', 'song-ends-listening-20261030'):
    records.extend(edge_listening_rulings(
        source, load(f'{source}/questions.json'),
        [{**answer, 'choice': answer['choice'].lower()} for answer in saved(f'{source}/answers/rulings').values()],
    ))

# 10-30, F2 reopened (register E24): Song Ends With The Fade, A the song ending at its speech cue's start with E7's
# fade, B the round's end as rendered. The page was cut from 13 questions to 6 after the first three answers (1012,
# 1111, 1216: no speech in either version), so the first version's questions are kept beside the page's.
source = 'song-end-fade-20261030'
records.extend(edge_listening_rulings(
    source, [*load(f'{source}/questions-v1.json'), *[q for q in load(f'{source}/questions.json')
                                                    if q['id'] not in {v1['id'] for v1 in load(f'{source}/questions-v1.json')}]],
    [{**answer, 'choice': answer['choice'].lower()} for answer in saved(f'{source}/answers/rulings').values()],
))

# 10-30: Fade Rule Check, the eight song ends the F2 rule moves that lost a hard-cut ruling or moved unruled by over
# 1 s; A the rule's cut with its fade, B the end before the rule.
records.extend(edge_listening_rulings(
    'song-end-fade-check-20261030', load('song-end-fade-check-20261030/questions.json'),
    [{**answer, 'choice': answer['choice'].lower()} for answer in saved('song-end-fade-check-20261030/answers/rulings').values()],
))

# 10-30, defect-fix plan F4: a spoken start no longer opens on the closing Amen before it. 1255 §3180's start (its
# full-pass id §3181, re-keyed by capture) was heard right in the full pass, before the 10-28 Amen ruling, with the
# congregation's answering "Amen." (559.98–560.34) at its head; as the triage's thirteen sermon ends, either stands.
for run, section_id, section_type, edge, executed, round_cut, note in [
    (1255, 3180, 'short_talk', 'start', 559.98, 560.34, 'The talk opens after the congregation\'s answering "Amen." '
     '(10-28 Amen ruling); the full-pass start that keeps it was heard before that ruling.'),
]:
    records.append({'id': f'claude-review-20261030:{run}-s{section_id}-{edge}', 'source': 'claude-review-20261030', 'ruled_on': '2026-10-30',
                    'ruled_at': '2026-10-30T15:00:00Z', 'run': run, 'output': 'section', 'section_id': section_id,
                    'section_type': section_type, 'title': None, 'edge': edge, 'heard': executed, 'answer': None, 'expect': 'either',
                    'ruled_time': None, 'note': 'Claude review 10-30: '+note, 'answer_source': 'claude-review',
                    'points': [executed, round_cut]})

# 10-30: Sermon Ends To Hear, the three moved sermon ends Claude's triage could not settle (A now, or for 1070 its 10-28
# ruled end; B the round's). 1070's A: "B clips the preachers amen".
records.extend(edge_listening_rulings(
    'ends-to-hear-20261030', load('ends-to-hear-20261030/questions.json'),
    [{**answer, 'choice': answer['choice'].lower()} for answer in saved('ends-to-hear-20261030/answers/rulings').values()],
))

# 10-30: Whose Amen, the one unruled move the triage could not settle (A now, B the round's): "B is right, but A is
# acceptable - it's the preacher repeating their \"Amen\"". Either; on a sermon end the shorter, B, wins.
records.extend(edge_listening_rulings(
    'amen-1284-listening-20261030', load('amen-1284-listening-20261030/questions.json'),
    [{**answer, 'choice': answer['choice'].lower()} for answer in saved('amen-1284-listening-20261030/answers/rulings').values()],
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
    ('operator-20261030:958-sermon-end', '2026-10-30T01:53:01Z', 958, None, 'end', (1993.88, 2016.78),
     'Plan §4.1a step 4.0, operator 10-30: "either" reaffirmed; the K16 reword\'s end (2016.78, the point built '
     'round "Facing a Task Unfinished") is acceptable alongside the ruled 1993.88.'),
    ('operator-20261030:968-sermon-end', '2026-10-30T01:35:08Z', 968, None, 'end', (2670.9, 2672.68),
     'Plan §4.1a step 4, operator 10-30 (option 2, "Current version seems fine to me"): the K16 reword adopted as '
     'measured, its loss on 968 accepted; the reword\'s end (2670.9) stands beside the 10-29 keep (2672.68).'),
    ('operator-20261101:1268-s4736-end', '2026-11-01T19:54:57Z', 1268, 4736, 'end', (2315.84, 2338.9),
     'Plan §4.1a step 5, internal-join fix, operator 11-01 on "Two Part Ends" (A the fix\'s end on "…You see, God is '
     'eternal.", B the 10-22 cut after the tie into the next song): "Either is fine."'),
    ('operator-20261101:1028-s72822-end', '2026-11-01T19:55:40Z', 1028, 72822, 'end', (1748.32, 1752.61),
     'Plan §4.1a step 5, internal-join fix, operator 11-01 on "Two Part Ends" (A the fix\'s reading end after "…his word '
     'to us.", B keeping "We\'ll be looking at some of those verses in just a little while."): "Either is fine."'),
    ('operator-20261031:1096-sermon-end', '2026-10-31T23:30:00Z', 1096, None, 'end', (3682.99, 3709.6),
     'Plan §4.1a step 4, operator 10-31 on "The Invitation After The Sermon" (A the K16 v3 end after "…the Lord '
     'Jesus.", B after the invitation and visitor cards): "Either is fine. Without the invitation is probably '
     'better." The 10-18 "early" at 3692.28 heard a cut halfway through the invitation.'),
    ('operator-20261103:957-sermon-end', '2026-11-03T18:34:23Z', 957, None, 'end', (1573.9, 1605.53),
     'Plan §4.1a step 5, after the 11-02 round, operator 11-03 on "Three Round Sermon Ends" (A the round\'s end keeping '
     'the recited verse of the closing hymn, "Yes, finished. The Messiah dies…"; B before it, the 10-30 claude-review '
     'keep): "Either is fine."'),
    ('operator-20261103:1166-sermon-end', '2026-11-03T18:34:52Z', 1166, None, 'end', (1521.97, 1527.8),
     'Plan §4.1a step 5, after the 11-02 round, operator 11-03 on "Three Round Sermon Ends" (A the round\'s end after '
     '"…in just a little while", the song announcement out; B through "…before we pray, we\'ll sing again."): "Either '
     'is fine." Supersedes the 10-18 "early" at 1525.8, which heard a cut mid-sentence.'),
    ('operator-20261103:1247-sermon-end', '2026-11-03T18:35:14Z', 1247, None, 'end', (3626.08, 3628.04),
     'Plan §4.1a step 5, after the 11-02 round, operator 11-03 on "Three Round Sermon Ends" (A the round\'s end after '
     '"In Jesus\' name we ask.", B keeping the "Amen."): "Either is fine."'),
    ('operator-20261105:1378-s327080-start', '2026-11-05T10:36:07Z', 1378, 327080, 'start', (4358.8, 4364.78),
     'Operator 11-05 on "Where The Second Song Starts" (round-1378-song2-start-20261105; A the 10-22 ruled start in the '
     'silence after "What love could remember", B the 11-02 round\'s start on the fourth of four faint sounds before the '
     'music): "Either start is fine. They are noise, but it doesn\'t really matter."'),
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

# 10-31, K16 reword build (plan §4.1a step 4): 1071's recording ends after "Let's pray together. Thank you.", with no
# prayer recorded. The reword ends the sermon on its own last line, "…all these other things will be added unto you."
# (decoded to 1057.40); the 10-28 rule lets a dropped prayer go when the end still makes sense, so the 10-12 keep
# (1060.04) and the reword's end are either.
records.append({'id': 'claude-review-20261031:1071-sermon-end', 'source': 'claude-review-20261031', 'ruled_on': '2026-10-31',
                'ruled_at': '2026-10-31T12:00:00Z', 'run': 1071, 'output': 'sermon', 'section_id': None, 'section_type': 'sermon',
                'title': None, 'edge': 'end', 'heard': 1060.04, 'answer': None, 'expect': 'either', 'ruled_time': None,
                'note': 'Claude review 10-31: ends "…all these other things will be added unto you."; drops "Let\'s pray together. '
                        'Thank you." before an unrecorded prayer (sermon end ruling 10-28: a dropped prayer is fine when the end '
                        'makes sense).', 'answer_source': 'claude-review', 'points': [1057.7, 1060.04]})

# 11-05, K16 sentence lines (v3, plan §4.1a item 6): two ends the v3 lines move past older rulings, settled by the
# 10-28 sermon-end rulings rather than a prompt change (a closing-prayer prompt rule, measured 11-04 in
# k16-closing-prayer-20261104/, broke 1128's and 1157's own-last-line rulings). 1099's recording ends after "Let's
# pray together." with no prayer recorded: v3 ends on "…for God's people." (decoded to 1363.46), as 1128's B drops
# "Let's pray together." and 1071 drops it before an unrecorded prayer; the pre-v3 end after "together." (decoded to
# 1364.50) stays right. 965's v3 end keeps the preacher's "Amen." after "…and for his sake." (decoded 2730.36–2730.90),
# the 10-28 rule's end; the 10-18 keep before it stays acceptable.
for run, points, note in [
    (1099, [1363.6, 1364.78], 'ends "…This is not the end of the story for God\'s people."; drops "Let\'s pray together." '
     'before an unrecorded prayer (1128\'s 10-28 B; 1071), or keeps it whole. Supersedes the 10-12 clips_word at 1364.5, '
     'which heard "together" clipped.'),
    (965, [2729.98, 2731.2], 'ends on the preacher\'s "Amen." after "…in Jesus name, and for his sake." (sermon end '
     'ruling 10-28), or before it (the 10-18 keep).'),
]:
    records.append({'id': f'claude-review-20261105:{run}-sermon-end', 'source': 'claude-review-20261105', 'ruled_on': '2026-11-05',
                    'ruled_at': '2026-11-05T00:30:00Z', 'run': run, 'output': 'sermon', 'section_id': None, 'section_type': 'sermon',
                    'title': None, 'edge': 'end', 'heard': points[1], 'answer': None, 'expect': 'either', 'ruled_time': None,
                    'note': 'Claude review 11-05: '+note, 'answer_source': 'claude-review', 'points': points})

# 11-05, after the 11-02 round re-detected 1378's closing songs (§4618 → §4616, §272906 → §327080): "What love could
# remember" now ends at 4360.6 (song_end_silence), 1.8 s past the 10-22 keep at 4358.8, over a −55 dB blip at
# 4360.0–4360.2 inside the 4356.4–4361.2 silence between the songs (R11). The operator heard the louder sounds that
# follow it the same morning ("They are noise, but it doesn't really matter"), so either end stands.
records.append({'id': 'claude-review-20261105:1378-s4616-end', 'source': 'claude-review-20261105', 'ruled_on': '2026-11-05',
                'ruled_at': '2026-11-05T10:50:00Z', 'run': 1378, 'output': 'section', 'section_id': 4616, 'section_type': 'song',
                'title': 'What love could remember', 'edge': 'end', 'heard': 4360.6, 'answer': None, 'expect': 'either',
                'ruled_time': None, 'answer_source': 'claude-review', 'points': [4358.8, 4360.6],
                'note': 'Claude review 11-05: ends in the silence between the two closing songs, either at the 10-22 keep or '
                        'after a −55 dB blip of the noise the operator ruled harmless on "Where The Second Song Starts".'})

# 11-01, register E25: 1011 §1285 "From Heaven You Came" is a quiet solo voice from the end of a dropout (−95 dB to
# 458.81, then −55 to −70 dB) before the congregation and band at 520. Heard on "The Muted Song Opening"
# (muted-opening-1011-listening-20261101), the cut at 519.73 starts partway through the song; the operator ruled
# the whole song in, from its first sung line, flagged. The time is that first sound less the dead-feed rule's
# 0.3 s lead. Supersedes claude-review-20261030:1011-s1285-start ("adds 5.9 s of quiet"), which was wrong.
records.append({'id': 'operator-20261101:1011-s1285-start', 'source': 'operator-20261101', 'ruled_on': '2026-11-01',
                'ruled_at': '2026-11-01T21:40:00Z', 'run': 1011, 'output': 'section', 'section_id': 1285, 'section_type': 'song',
                'title': 'From Heaven You Came', 'edge': 'start', 'heard': 519.73, 'answer': 'earlier', 'expect': 'at',
                'ruled_time': 458.51,
                'note': 'Operator 11-01 (E25): "The singing is audible, so we could use it. … The choice that makes no sense '
                        'is starting the clip halfway through the song." Then: "A, but we should flag it, as we do for broken '
                        'talk recordings." The solo starts at 458.81, after a dropout.'})

# 11-06, register K19 (a start may drop only an opening invitation; keeping it is preferred) and the operator's answers on
# "Three Sermon Starts" (start-rule-listening-20261106; A the current start, B the K19 start rule's). 1137's 10-08 keep at
# 49.62 drops "Well, do have your Bibles open at Luke chapter 13."; K19 names that very sentence, so either start stands.
records.append({'id': 'operator-20261106:1137-sermon-start', 'source': 'operator-20261106', 'ruled_on': '2026-11-06',
                'ruled_at': '2026-11-06T00:30:00Z', 'run': 1137, 'output': 'sermon', 'section_id': None, 'section_type': 'sermon',
                'title': None, 'edge': 'start', 'heard': 45.0, 'answer': None, 'expect': 'either', 'ruled_time': None, 'points': [45.0, 49.62],
                'note': 'Operator 11-06 (K19), on this start\'s dropped "Well, do have your Bibles open at Luke chapter 13.": "Yes, but '
                        'only an invitation (and even here I\'d rather have it included)." Not a listening of 45.0.'})
for run, ruled_at, expect, heard, ruled, note in [
    (1105, '2026-11-06T10:05:10Z', 'keep', 5.55, None, '"B is better": opens after "…Hosea and chapter 5.", not inside "We\'re '
     'taking │ our way" (6.66, ruled right three times in October).'),
    (1292, '2026-11-06T10:04:52Z', 'keep', 1292.0, None, '"A is better": opens after the leader\'s "Thank you, Lisa.", not before '
     'it (1291.44).'),
    (1233, '2026-11-06T10:06:42Z', 'at', 1647.73, 1651.0, '"Neither are great. Should start at about 27:31 after the handover" '
     '(A 1647.73 after "I would love that.", B 1646.70).'),
]:
    records.append({'id': f'operator-20261106:{run}-sermon-start', 'source': 'operator-20261106', 'ruled_on': '2026-11-06',
                    'ruled_at': ruled_at, 'run': run, 'output': 'sermon', 'section_id': None, 'section_type': 'sermon', 'title': None,
                    'edge': 'start', 'heard': heard, 'answer': None, 'expect': expect, 'ruled_time': ruled,
                    'note': 'Operator 11-06 on "Three Sermon Starts": '+note})

# 11-06, register K18 (the preacher's introduction before the sermon's reading is in the sermon's media, however long):
# the operator on the two 10-18 keeps that start the sermon after such an introduction (talk_opening_lost, plan §4.1b).
for run, heard, note in [
    (990, 87.0, 'the 0–87 s "Introduction to the Scripture reading" ("This evening to 1 Timothy and chapter 3…"), typed other'),
    (1053, 72.81, 'the 0–72.8 s in no section ("Verses 1 to 21. I thought that I\'d worked it just right…")'),
]:
    records.append({'id': f'operator-20261106:{run}-sermon-start', 'source': 'operator-20261106', 'ruled_on': '2026-11-06',
                    'ruled_at': '2026-11-06T11:00:00Z', 'run': run, 'output': 'sermon', 'section_id': None, 'section_type': 'sermon',
                    'title': None, 'edge': 'start', 'heard': heard, 'answer': None, 'expect': 'outward', 'ruled_time': None,
                    'note': f'Operator 11-06 (K18): "{run} - should be in": the sermon starts before {note}. Supersedes the 10-18 keep.'})

# "Two Sermon Introductions" (k18-listening-20261106; A the current start, B the K18 introduction rule's).
records.append({'id': 'operator-20261106:1040-sermon-start', 'source': 'operator-20261106', 'ruled_on': '2026-11-06',
                'ruled_at': '2026-11-06T15:23:05Z', 'run': 1040, 'output': 'sermon', 'section_id': None, 'section_type': 'sermon',
                'title': None, 'edge': 'start', 'heard': 1595.46, 'answer': None, 'expect': 'either', 'ruled_time': None,
                'points': [1595.46, 1661.44],
                'note': 'Operator 11-06 (K18), "Either is fine": with the 66-s introduction ("Last Sunday morning, Gareth was '
                        'preaching… it\'s Luke chapter 1, 26 to 38.") or on the recorded reading (1661.44, ruled right 10-19).'})
records.append({'id': 'operator-20261106:1154-sermon-start', 'source': 'operator-20261106', 'ruled_on': '2026-11-06',
                'ruled_at': '2026-11-06T15:24:17Z', 'run': 1154, 'output': 'sermon', 'section_id': None, 'section_type': 'sermon',
                'title': None, 'edge': 'start', 'heard': 1490.16, 'answer': None, 'expect': 'keep', 'ruled_time': None,
                'note': 'Operator 11-06 (K18), "A is better": "There\'s a hard cut from the recorded song to the middle of the '
                        'reading introduction sentence" (B 1485.62). A avoids the hard cut by dropping the partial sentence.'})

# 11-06, operator "Yes to all" on the known-miss review: 1136's 10-18 "late" is a source limitation, not a cut defect.
# The feed is dead (about −90 dB) from 1.7 s to 15.3 s and the sermon resumes mid-thought at 15.36 s; the cut at 15.06
# keeps every recorded word after the dropout. The only earlier start keeps a 1.7 s fragment ("Thank") and 13 s of silence.
records.append({'id': 'operator-20261106:1136-sermon-start', 'source': 'operator-20261106', 'ruled_on': '2026-11-06',
                'ruled_at': '2026-11-06T18:00:00Z', 'run': 1136, 'output': 'sermon', 'section_id': None, 'section_type': 'sermon',
                'title': None, 'edge': 'start', 'heard': 15.06, 'answer': None, 'expect': 'keep', 'ruled_time': None,
                'note': 'Operator 11-06: the first words are lost in the source (dead feed 1.7–15.3 s), not by the cut. '
                        'Supersedes the 10-18 "late".'})

# "Opening Invitations" (k19-reach-listening-20261106; A the start as committed in 07449dc50, B the K19 reach-back trial's:
# detection cut inside an invitation or the reading's announcement, and B starts on that sentence). 1308 "Can't tell"
# (the announcement is inaudible on its near-dead feed): no ruling; its 10-18 "late" still stands, and B meets it.
def operator_k19(run, heard, expect, note, points=None, part=None, minute='00'):
    record = {'id': f'operator-20261106:{run}-sermon-start' + (f'-part-{part}' if part else ''), 'source': 'operator-20261106',
              'ruled_on': '2026-11-06', 'ruled_at': f'2026-11-06T22:{minute}:00Z', 'run': run, 'output': 'sermon',
              'section_id': None, 'section_type': 'sermon', 'title': None, 'edge': 'start', 'heard': heard, 'answer': None,
              'expect': expect, 'ruled_time': None, 'note': note}
    if points:
        record['points'] = points
    if part:
        record['part'] = {'section_id': part}
    return record


records.extend([
    operator_k19(1250, 2179.72, 'keep', 'Operator 11-06 (K19 reach-back), "B is better": the preacher\'s whole handover, "We\'re going '
                 'to have one more chapter read to us, and Lisa is going to do that.", not A\'s mid-sentence 2182.57.', part=4910),
    operator_k19(1339, 1803.21, 'keep', 'Operator 11-06 (K19 reach-back), "B is better": the reader\'s announcement "Genesis chapter '
                 '37 verses 1 to 11." kept (A 1809.85 opened on "Jacob lived").'),
    operator_k19(1247, 959.91, 'either', 'Operator 11-06 (K19 reach-back), "Either is fine": the whole "Well, let\'s turn to God\'s '
                 'word then, shall we?" (B) or detection\'s cut inside it (A).', points=[962.011, 959.91]),
    operator_k19(1244, 992.32, 'keep', 'Operator 11-06 (K19 reach-back), "B is better": "Well, we\'re going to turn to our Bible '
                 'reading for this morning." kept (A 997.04 opened after it).'),
])

# "Opening Thanks" (thanks-listening-20261106; A the current start on the preacher's thanks to someone by name, B just
# after it). Either on four, A on 1258's thanks naming the reading: no opening-thanks rule (cf. 1292 and 1233's B).
records.extend([
    operator_k19(941, 1689.92, 'either', 'Operator 11-06 (Opening Thanks), "Either is fine": with or without "Thank you, Andrew."',
                 points=[1689.92, 1692.65], minute='01'),
    operator_k19(1128, 171.43, 'either', 'Operator 11-06 (Opening Thanks), "Either is fine": with or without "Thank you, Richard." to '
                 'the reader.', points=[171.43, 179.23], part=2049, minute='01'),
    operator_k19(1230, 372.09, 'either', 'Operator 11-06 (Opening Thanks), "Either is fine": with or without "Thank you, Laurie."',
                 points=[372.09, 374.11], minute='01'),
    operator_k19(1117, 2157.01, 'either', 'Operator 11-06 (Opening Thanks), "Either is fine": with or without "Well, thank you, '
                 'Laurie, for leading the first part of our service this morning."', points=[2157.01, 2166.43], part=1967, minute='01'),
    operator_k19(1258, 2041.18, 'keep', 'Operator 11-06 (Opening Thanks), "A is better": keeps "Thank you to Laurie for reading from '
                 'Philippians chapter 2." (B 2044.95).', minute='01'),
])

# 11-10, plan §4.1d: compare the first silence (V1) against the cut that skipped it (V2).
for source in ('end-faults-20261110', 'end-faults-1959-20261110'):
    cuts = load(f'{source}/export.json')
    for question_id, ruling in saved(f'{source}/answers/rulings').items():
        section = question_id.split('-s')[1]
        cut = cuts[section]
        if cut['group'] != 'silence' or ruling['choice'] not in ('v1', 'either'):
            continue
        add(source, ruling, run=cut['run'], output='section', section_id=int(section), section_type='song',
            edge='end', heard=cut['planned_end'], expect='inward',
            heard_range=[cut['alt_end'], cut['planned_end']] if ruling['choice'] == 'either' else None)

# The earlier page heard 1265's extra seconds as noise. The other questions on that page were
# about the hold replay, not whether a shorter cut at the first silence was better.
source = 'later-endings-listening-20261110'
ruling = saved(f'{source}/answers/rulings')['1008-s1265']
cut = load(f'{source}/export.json')['1265']
add(source, ruling, run=cut['run'], output='section', section_id=1265, section_type='song',
    edge='end', heard=cut['planned_end'], expect='inward')

# The scope page compares the current and proposed cuts, including the two fallback paths.
source = 'silence-end-scope-20261110'
cuts = load(f'{source}/export.json')
answers = load(f'{source}/silence-end-scope-rulings.json')
if len(answers) != len(cuts) or {r['question_id'] for r in answers} != {f"{c['run']}-s{s}" for s, c in cuts.items()}:
    sys.exit(f'{source}: answers do not match the listening page')
for ruling in answers:
    section = ruling['question_id'].split('-s')[1]
    cut = cuts[section]
    versions = cut['versions']
    choice = ruling['choice']
    heard = versions[choice if choice in versions else 'before']['end']
    add(source, ruling, run=cut['run'], output='section', section_id=int(section), section_type='song',
        edge='end', heard=heard, expect='keep' if choice in versions else 'at' if choice == 'neither' else None,
        heard_range=[v['end'] for v in versions.values()] if choice == 'either' else None)

# 11-13, the final replay's moved ends against the ends banked before it (WP15). Each pair is the replayed end and
# the banked one in a neutral A/B order (versions.json). A chosen version is kept; "Either is fine" makes each of the
# two ends right and only those (E21), whatever the note leans towards. "Neither" is edge truth only where the note
# says which way both ends are wrong; the times it names are the ends heard, never a new end.
source = 'ruling-reconcile-20261113'
pairs = load(f'{source}/versions.json')
answers = saved(f'{source}/answers/rulings')
if not set(answers) <= set(pairs):
    sys.exit(f'{source}: answers do not match the listening page')
RECONCILE_NEITHER = {
    # "Both are basically the same. They include a loud intake of breath at the end."
    '1073-s267202': [('banked', 'inward')],
    # "A has "please remain", B has "please remain standing"." Both keep the next item's speech.
    '1200-s2509': [('replayed', 'inward')],
    # "A is better than B, which cuts the outro."
    '1222-s2733': [('banked', 'outward')],
    # "B is too early, A includes speech."
    '1307-s4653': [('replayed', 'outward'), ('banked', 'inward')],
}
for question_id, ruling in answers.items():
    pair = pairs[question_id]
    ends = {pair[letter]['version']: pair[letter]['end'] for letter in ('A', 'B')}
    run, which = question_id.split('-', 1)
    sermon = which == 'sermon-end'
    target = {'run': int(run), 'edge': 'end', 'output': 'sermon' if sermon else 'section',
              'section_id': None if sermon else int(which.removeprefix('s')), 'section_type': 'sermon' if sermon else 'song'}
    choice = ruling['choice']
    if choice in ('A', 'B'):
        add(source, {**ruling, 'choice': 'right', 'question_id': f"{question_id}-{pair[choice]['version']}"},
            heard=pair[choice]['end'], **target)
    elif choice == 'either':
        add(source, ruling, heard=ends['replayed'], heard_range=list(ends.values()), **target)
    elif choice == 'neither' and question_id in RECONCILE_NEITHER:
        for version, expect in RECONCILE_NEITHER[question_id]:
            add(source, {**ruling, 'question_id': f'{question_id}-{version}'}, heard=ends[version], expect=expect, **target)
    else:
        skipped[f'{source}:{choice}'] += 1

# A section ruling the page recorded without a section id is on the run's section of its type whose edge is nearest
# the cut heard, as RuledEndingsTestCase::latestRulings() and the round's analyze.py read it. Without that binding the
# newest id-less ruling on a run replaced every other on the same edge, whatever its section (1304's §3879 start by a
# §4884 one), and none was replaced by a later ruling that names its section (1221 §2718's 10-03 start by the 10-27
# either).
ruling_sections = load('section-id-drift-20261105/sections.json')

# Rulings whose section id now holds another item (re-detection shifts rows by section_order) take the section they
# were heard on; see section_binding.py. Sections: section-id-drift-20261105/export-sections.php (re-exported after the 11-02 round).
unbound = rebind_drifted_sections(records, ruling_sections)
if unbound:
    sys.exit(f"no section of the ruled type near the cut heard for {unbound}; check them or re-run export-sections.php")

for record in records:
    if record['output'] == 'section' and record['section_id'] is None:
        column = 'start' if record['edge'] == 'start' else 'end'
        candidates = [section for section in ruling_sections.get(str(record['run']), [])
                      if record.get('section_type') is None or section['type'] == record['section_type']]
        if not candidates:
            sys.exit(f"{record['id']}: no section to bind it to; re-run export-sections.php")
        record['bound_section_id'] = min(candidates, key=lambda section: abs(section[column] - record['heard']))['id']


# A later ruling on the same edge was made on a later cut, so it replaces the earlier ones. A later part of a sermon
# video is its own edge: its start never replaces the video's opening, nor the reverse.
def edge_of(record):
    return (record['run'], record['output'], record['section_id'] or record.get('bound_section_id'), record['edge'],
            (record.get('part') or {}).get('section_id'))


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
    're_bound': sum('page_section_id' in r for r in records),
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
