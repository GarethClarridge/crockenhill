"""Join the 10-25 song-edges answers (two versions per edge, A and B) to the cuts each version made.
A key may name its output and section type (the sermon video, a talk); a song section is the default.

A chosen version is kept. "Either is fine" makes the stretch between them optional, so any cut between
the two is right; `heard` is the one that keeps more, which E21 prefers. "Neither" is edge truth only
when the note names the time exactly ("should be 3:36"); an approximate one ("something like 26:09")
stays in the note.
"""

import re


EXACT = re.compile(r'should (?:really )?be (?:at )?((?:\d+:)?\d{1,2}:\d{2}(?:\.\d+)?)', re.IGNORECASE)


def seconds(clock):
    value = 0.0
    for part in clock.split(':'):
        value = value * 60 + float(part)
    return value


def collect(source, questions, answers):
    keys = {question['id']: question['key'] for question in questions}
    records = []
    for answer in answers:
        key = keys.get(answer['question_id'])
        if key is None:
            continue
        times, edge, choice = key['times'], key['edge'], answer['choice']
        expect, heard, at, between = 'keep', None, None, None
        if choice in ('a', 'b'):
            heard = times[choice]
        elif choice == 'both':
            between = sorted(times.values())
            heard = between[1] if edge == 'end' else between[0]
            expect = 'between'
        elif choice == 'neither' and (match := EXACT.search(answer.get('note', ''))):
            # The cut heard is the one the operator preferred of the two, or B, the cut now.
            heard, at, expect = times['b'], seconds(match.group(1)), 'at'
        else:
            continue
        records.append({
            'id': f"{source}:{answer['question_id']}", 'source': source,
            'ruled_on': answer['saved_at'][:10], 'ruled_at': answer['saved_at'],
            'run': int(answer['run']), 'output': key.get('output', 'section'),
            'section_id': None if key['section'] is None else int(key['section']),
            'section_type': key.get('type', 'song'), 'title': answer.get('title'), 'edge': edge,
            'heard': round(float(heard), 3), 'answer': choice, 'expect': expect, 'ruled_time': at,
            'note': answer.get('note', ''),
            **({'range': [round(float(t), 3) for t in between]} if between else {}),
        })
    return records
