"""Preserve the re-cut listening, including corrected section identities.

Run through Sail: python3 scripts/rulings/recut.py [scratch root] [output file].
Unknown outcomes remain evidence, not passing or failing expectations.
"""

import json
import pathlib
import sys


def read(path):
    return json.loads(path.read_text())


def expectation(choice, edge):
    if choice == 'right':
        return 'keep'
    if choice not in ('early', 'late'):
        return None
    return 'outward' if (choice == 'early') == (edge == 'end') else 'inward'


def collect(scratch):
    records = []
    for directory, length in [('listening-edges-20261018', 12),
                              ('listening-edges2-20261018', 6),
                              ('listening-last-20261019', 12),
                              ('listening-recut-20261020', 12),
                              ('listening-1251-k16-20261020', 12)]:
        root = scratch / directory
        questions = {q['id']: q for q in read(root / 'questions.json')}
        for path in sorted((root / 'answers/rulings').glob('*.json')):
            answer = read(path)
            question = questions[answer['question_id']]
            edge = question['id'].rsplit('-', 1)[-1]
            if edge not in ('start', 'end'):
                continue
            heard = question['clips'][0]['offset'] + (length if edge == 'end' else 0)
            records.append(record(directory, question['id'], answer, question['run'],
                                  edge, heard, 'sermon', None, None, question['title']))

    root = scratch / 'listening-edges3-20261018'
    relisten = scratch / 'listening-relisten-20261019/answers/rulings-relisten'
    for item in read(root / 'answers/relabelled.json'):
        corrected_id = f'{item["run"]}-s{item["true_sid"]}-{item["edge"]}'
        replacement = relisten / (corrected_id + '.json')
        answer = read(replacement) if replacement.exists() else item
        records.append(record('listening-edges3-20261018', item['id'], answer,
                              item['run'], item['edge'], item['at'], 'section',
                              item['true_sid'], item['type'], item['title'],
                              page_section_id=item['page_sid'], corrected_id=corrected_id,
                              relistened=replacement.exists()))
    records += song_endings(scratch / 'listening-songs-20261020', 'new')
    records += song_endings(scratch / 'listening-song-changes-20261020', 'dip')
    supersede_with_reading_context(records, scratch / 'listening-final-20261021/song-questions')
    return sorted(records, key=lambda r: (r['run'], r['heard'], r['id']))


def song_endings(root, alternative):
    """A 10-20 blind A/B of two song ends, the current one and an alternative: each ending chosen is kept.

    The song-rule page's alternative is the 10-19 rules' end (`new`); the song-change page's is the
    quietest second near the change (`dip`). "Both" keeps both; "neither" keeps nothing and stays as
    evidence at the current ending.
    """
    questions = {q['id']: q for q in read(root / 'questions.json')}
    records = []
    for path in sorted((root / 'answers/rulings').glob('*.json')):
        answer = read(path)
        question = questions[answer['question_id']]
        key = question['key']
        chosen = {'1': [key['1']], '2': [key['2']], 'both': ['current', alternative]}.get(answer['choice'], [])
        for which in chosen or ['current']:
            records.append(record(root.name, f'{question["id"]}-{which}',
                                  {**answer, 'choice': 'right' if chosen else answer['choice']},
                                  question['run'], 'end', key[f'{which}_end'], 'section',
                                  key['section_id'], 'song', question['title'], ending=which))
    return records


def supersede_with_reading_context(records, root):
    """1328's start was heard as mid-reading (10-18, 10-20); its context question (10-21) settled it.

    The reading's opening verse fell in a 22-s dead feed, so 1407.14 s is the first reading the
    recording holds. "The current opening is right" turns both late rulings into keeps; the earlier
    answer stays on the record as `superseded`.
    """
    path = root / 'answers/rulings/1328-reading-opening-context.json'
    if not path.exists():
        return
    answer = read(path)
    if answer['choice'] != 'current':
        return
    for r in records:
        if r['run'] == 1328 and r['edge'] == 'start' and r['output'] == 'sermon':
            r['superseded'] = {'answer': r['answer'], 'expect': r['expect'], 'note': r['note']}
            r.update(answer='right', expect='keep', superseded_by=f'{root.parent.name}:{answer["question_id"]}',
                     note='The reading opens in a dead feed; the current opening is the first reading recorded.')


def record(source, question_id, answer, run, edge, heard, output, section_id, section_type, title, **extra):
    return dict(id=f'{source}:{question_id}', source=source, run=run, output=output,
                section_id=section_id, section_type=section_type, title=title, edge=edge,
                heard=round(heard, 3), answer=answer['choice'],
                expect=expectation(answer['choice'], edge), note=answer.get('note', ''),
                **extra)


if __name__ == '__main__':
    scratch = pathlib.Path(sys.argv[1] if len(sys.argv) > 1 else 'storage/scratch')
    output = pathlib.Path(sys.argv[2] if len(sys.argv) > 2 else 'tests/Fixtures/Rulings/recut-20261019.json')
    records = collect(scratch)
    output.write_text(json.dumps({'rulings': records}, indent=2, ensure_ascii=False) + '\n')
    print(f'{len(records)} reviewed edges; {sum(r["expect"] is None for r in records)} unassessable')
