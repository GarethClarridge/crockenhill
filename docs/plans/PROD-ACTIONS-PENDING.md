# Pending production actions

Changes that are merged in code but still need applying to production by hand, because
they touch `.env.production` or production data rather than the repository.

**Started 2026-09-04. This is not a complete audit of everything outstanding on prod** —
it begins with the speaker-identification items and should be added to as further
deploy-time actions arise. Tick items off here when they are done, with the date.

---

## 1. Disable speaker identification (from `619634926`)

**Status:** not yet applied · **Urgency:** before the next historic video processing run

Speaker identification is disabled in the repository's `.env` but production reads its own.
Until this is set, production keeps scoring against four profiles built from a single
four-month window, on an encoder measured at 28% EER on this corpus, gated by a threshold
calibrated in one acoustic domain.

In `/srv/crockenhill/.env.production`:

```
SPEAKER_IDENTIFICATION_ENABLED=false
```

Then restart the app container so the config cache picks it up.

**Verify:**

```bash
cd /srv/crockenhill && docker compose -f docker-compose.prod.yml --env-file .env.production \
  exec -T app php artisan config:show media-processing.speaker_identification.enabled
```

Expect `false`. `IdentifySpeaker`'s first gate then records `outcome=skipped` and returns,
so no job chain changes and nothing else is affected.

## 2. Retire the 21 zero-vector speaker profiles (from `619634926`)

**Status:** not yet applied · **Urgency:** low — tidying, not a fault

Production carries 25 active speaker profiles of which **21 are literal zero vectors**,
left behind by the old bootstrap command when every extraction for a preacher failed. They
are inert (cosine similarity against a zero-norm vector is 0.0, so they sort below every
real candidate and never distort a match or a margin) but they misreport 21 preachers as
identifiable when they can never be identified.

Dry run first — it lists what it would change and makes no writes:

```bash
cd /srv/crockenhill && docker compose -f docker-compose.prod.yml --env-file .env.production \
  exec -T app php artisan speaker-profiles:deactivate-empty
```

Then apply:

```bash
cd /srv/crockenhill && docker compose -f docker-compose.prod.yml --env-file .env.production \
  exec -T app php artisan speaker-profiles:deactivate-empty --apply
```

**Expect 21 rows.** Four real profiles (Mark Drury, Gareth Clarridge, Peter Clarridge,
Laurie Everest) must remain active. The bootstrap command no longer creates profiles in
this state, so this is a one-off cleanup of rows predating the fix.

## 3. Correct the 53 "Visiting Speaker" sermons

**Status:** not started · **Urgency:** low · **Needs a person, not a command**

`IdentifySpeaker` used to stamp a "Visiting Speaker" fallback on every declined match. The
cause is fixed; the existing rows are untouched and still carry a name nobody chose
(`preacher_source=default`).

[`SPEAKER-IDENTIFICATION-ROOT-CAUSE-2026-09-03.md`](SPEAKER-IDENTIFICATION-ROOT-CAUSE-2026-09-03.md)
§11.5 holds proposals for 32 of those recordings — 24 at a confidence band measured at
96–99% accuracy, 8 at ~87%, 2 too weak to use. They are proposals for confirmation, not
assignments.

## 4. Listen to three probable mislabels

**Status:** not started · **Urgency:** low · **Needs an ear**

Three preachers hold one archive and one production recording whose voices sit in impostor
territory, so one record of each pair is wrong (different people average 0.197, the same
person 0.662):

| name | across-set similarity |
|---|---|
| Andy Laws | **0.232** |
| Dave Manderscheid | **0.335** |
| John Stevens | **0.396** |

Detail in §11.6 of the same record, which also lists nine preacher name variants (typos and
organisation suffixes) each currently holding a separate preacher record and public sermon
listing.

---

## Not on this list

Re-enabling speaker identification is **not** a pending action — it is a decision that
depends on re-enrolling profiles from production's ~700 hand-assigned sermons, and
possibly on adopting a different encoder. See §10.5, §11 and §12.7 of the root-cause
record.
