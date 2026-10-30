"""Re-bind section rulings whose section id now holds another item.

A section id names a row, not an item: when re-detection inserts a section, sync matches rows by section_order, so
every later row takes the next item and an older ruling's id now holds a neighbour (949's "I Will Sing Of The Lamb"
§4857 became the reading before it, 1036's "Holy Holy Holy" §1479 the sermon after it, 1036's talk §1476 is gone).
A ruling whose id is gone, holds another type, or holds another title the page named while a section of that title
exists and the held section's edge is not at the cut heard, is re-bound to the section of its type (and title, where
one matches) whose edge is nearest the cut heard. An edge at the cut wins over a title: 1012's 4.66 song start was
heard on a clip re-detection later split, so §1288 still opens there under the first song's title.
"""

import re

SECTION_TYPES = {'talk': 'short_talk'}
NAMED = (re.compile(r'“(.+?)”'), re.compile(r'^(?:Song|Short talk|Talk): (.+?) \('), re.compile(r'^(.+?) (?:opens|ends) \('))
# The 10-18 edges3 page's titles named the wrong section by design (relabelled.json joins the true one).
TITLES_NAME_OTHER_SECTIONS = {'listening-edges3-20261018'}
REBIND_REACH = 30.0
EDGE_AT_CUT = 5.0


def normalised_title(title):
    return re.sub(r'[^a-z0-9]+', ' ', (title or '').lower()).strip()


def named_title(record):
    if record['source'] in TITLES_NAME_OTHER_SECTIONS:
        return None
    for pattern in NAMED:
        match = pattern.search(record.get('title') or '')
        if match:
            return normalised_title(match.group(1))
    return None


def rebind(records, sections_by_run):
    """Re-bind drifted section rulings in place, keeping the page's id as page_section_id. Returns the ids of rulings
    with no section of their type within REBIND_REACH of the cut heard, which are left as they are."""
    unbound = []
    for record in records:
        if record['output'] != 'section' or record['section_id'] is None:
            continue
        sections = sections_by_run.get(str(record['run']), [])
        column = 'start' if record['edge'] == 'start' else 'end'
        held = next((section for section in sections if section['id'] == record['section_id']), None)
        wanted_type = SECTION_TYPES.get(record.get('section_type'), record.get('section_type'))
        name = named_title(record)
        titled = [section for section in sections if name and normalised_title(section['title']) == name]
        if held is not None and (wanted_type is None or held['type'] == wanted_type) \
                and (name is None or not titled or normalised_title(held['title']) == name
                     or abs(held[column] - record['heard']) <= EDGE_AT_CUT):
            continue
        candidates = [section for section in sections if wanted_type is None or section['type'] == wanted_type]
        named = [section for section in candidates if section in titled]
        target = min(named or candidates, key=lambda section: abs(section[column] - record['heard']), default=None)
        if target is None or abs(target[column] - record['heard']) > REBIND_REACH:
            unbound.append(record['id'])
            continue
        record['page_section_id'] = record['section_id']
        record['section_id'] = target['id']

    return unbound
