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
                              ('listening-last-20261019', 12)]:
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
    return sorted(records, key=lambda r: (r['run'], r['heard'], r['id']))


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
