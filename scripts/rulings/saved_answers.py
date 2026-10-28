"""Read saved listening answers before they are ordered into the rulings register."""

import glob
import json
import os
import re
from datetime import datetime

SCRATCH = 'storage/scratch'


def load(path):
    with open(path) as handle:
        value = json.load(handle)
    if isinstance(value, dict) and 'saved_at' in value:
        saved_at = value['saved_at']
        try:
            if not isinstance(saved_at, str) or re.search(r'[T ]\d{2}:\d{2}', saved_at) is None:
                raise ValueError
            datetime.fromisoformat(saved_at.replace('Z', '+00:00'))
        except ValueError as error:
            raise ValueError(f'{path}: saved_at must include a valid time of day') from error
    return value


def saved(directory):
    rulings = {}
    for path in sorted(glob.glob(os.path.join(SCRATCH, directory, '*.json'))):
        ruling = load(path)
        rulings[ruling['question_id']] = ruling
    return rulings
