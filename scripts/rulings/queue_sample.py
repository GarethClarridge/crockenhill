"""Join the 10-24/25 review-queue answers to their sampled edges, without processing media.

Approximate times remain in the note; only an explicit, precise timestamp becomes an
`at` expectation. A mixed/unsure answer supplies no truth about unspecified edges.
"""

import re


CLOCK = re.compile(r'(?<!\d)(?:\d+:)?\d{1,2}:\d{2}(?:\.\d+)?')


def seconds(clock):
    value = 0.0
    for part in clock.split(':'):
        value = value * 60 + float(part)
    return value


def edges(stratum, section, answer):
    choice = answer['choice']
    note = answer.get('note', '')
    times = [seconds(clock) for clock in CLOCK.findall(note)]
    if stratum == 'song_trailing_content':
        expect = {'song': 'keep', 'short': 'outward', 'other': 'inward'}.get(choice)
        return [('end', expect, None)] if expect else []
    if stratum == 'song_low_confidence':
        if choice == 'ok':
            return [('start', 'keep', None), ('end', 'keep', None)]
        if choice != 'cut':
            return []
        if section == 752 and len(times) == 2:
            return [('start', 'at', times[0]), ('end', 'at', times[1])]
        if section == 163668:
            return [('end', 'outward', None)]
        if section == 3769 and len(times) == 1:
            return [('start', 'outward', None), ('end', 'at', times[0])]
    if stratum == 'sermon_adjacent_speech_unowned' and section == 3242 and choice == 'mixed' and len(times) == 1:
        return [('start', 'at', times[0])]
    return []


def record(source, answer, member, edge, expect, at, sermon=False):
    return {
        'id': f"{source}:{answer['question_id']}-{edge}", 'source': source,
        'ruled_on': answer['saved_at'][:10], 'ruled_at': answer['saved_at'],
        'run': int(member['run']), 'output': 'section',
        'section_id': int(member['section_id']),
        'section_type': 'sermon' if sermon else 'song', 'title': member.get('title'),
        'edge': edge, 'heard': round(float(member[edge]), 3), 'answer': answer['choice'],
        'expect': expect, 'ruled_time': at, 'note': answer.get('note', ''),
    }


def collect(sample, answers, followup=None):
    members = {f"{m['section_id']}-{s['stratum']}": (s['stratum'], m)
               for s in sample['strata'] for m in s['members']}
    records = []
    for answer in answers:
        matched = members.get(answer['question_id'])
        if matched is None:
            continue
        stratum, member = matched
        for edge, expect, at in edges(stratum, member['section_id'], answer):
            records.append(record('queue-sample-20261024', answer, member, edge, expect, at,
                                  sermon=stratum.startswith('sermon_')))
    if followup is not None and followup['question_id'] == '2659-sermon_start' and followup['choice'] == 'before':
        times = CLOCK.findall(followup.get('note', ''))
        if len(times) == 1:
            records.append(record('queue-followup-20261025', followup,
                                  {'run': followup['run'], 'section_id': 2659, 'start': 2010.3}, 'start', 'at', seconds(times[0]), sermon=True))
    return records
