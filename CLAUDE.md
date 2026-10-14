# DPL Pet Breeds

This plugin owns the pet breed vocabulary for the VetPost plugins. It is the single source of
truth: the canonical breed list, the aliases, the resolution map, and the add-breed queue.

**Read these first:**

1. `README.md` in this directory — the consumer contract. Function shapes, guarantees, hooks.
2. `../dpl-pet-profiles/CLAUDE.md` — full project context: how the vocabulary was built, every
   decision already made and why, the twenty hard-won lessons, and what is still outstanding.
   **Read it before changing anything here**, or you will re-make a mistake that has already
   been made and documented.

## The one rule

Only this plugin knows anything about breeds. If you find breed logic, a breed list, or a
hardcoded `" x "`, `" and "` or `"(cross)"` in another plugin, that is a bug — it should be
calling `dpl_breed_*()`.

## Crossbreed is a flag, not a breed (API 2.0, October 2026)

A pet has zero, one or two **real** breeds plus a `cross` flag. An unknown parent is implied,
never stored. Displayed as `Labrador Retriever` / `Staffordshire Bull Terrier (cross)` /
`Labrador Retriever x Poodle` / `Mixed breed`.

Never reintroduce a "Crossbreed", "unknown" or "mix" breed row — under any name. That was the
original design and it was wrong: "Crossbreed" was the most common breed in the data (~1,894
pets), useless for product matching, and it consumed one of the two breed slots. VBO itself
models some crosses as breeds ("Horse crossbreed", "Maltese Jack Russell Mix"); section 5b of the
offline test catches those by word, not just by exact name.

Converting to this model uncovered a defect that had been sitting in the signed-off map: **38
source strings that explicitly say "cross" were mapped to a purebred.** The worst was
`crossbreed and labrador retriever` → plain Labrador — and that string is exactly what the old
picker submitted when someone ticked Crossbreed and Labrador. The 1.x migration would have
turned every one of those crosses into a pedigree. All 38 are fixed and guarded.

The consumers have been moved to 2.1 (`dpl_breed_canonicalise()` as the single write path).
pet-profiles is merged into its `main` as 4.9.53 (2026-10-13, not pushed);
vet-pharmacy-prescriptions (`main-without-pets`) and dpl-prescriptions-ai (`master`) are still
uncommitted. README.md lists the breaking changes.

## Do not hand-edit

`includes/data/breed-map.php` and `includes/data/breeds-seed.php` are generated. The hard
matching happens offline, once, and gets reviewed; production only looks up its conclusions.
Regenerate from the data workspace:

```bash
cd "C:/Users/DIgitalpie/OneDrive/Desktop/Pet Breeds/breed-mapping"
node resolve3.mjs && node export.mjs && node guard-split.mjs
node gen-plugin-data.mjs && node gen-seed-extras.mjs
node apply-cross-model.mjs      # Crossbreed -> flag; the generators above still emit 1.x form
node fix-conflicts.mjs          # map-vs-vocabulary decisions, not optional
node apply-signoff-research.mjs # the 2026-10-14 sign-off research, not optional
node gen-vbo-aliases.mjs        # LAST: ontology synonyms for the vocabulary as it now stands
```

`apply-signoff-research.mjs` applies the research answers from the VetPost Breed List Sign-off
doc (https://claude.ai/code/artifact/a58474ce-b1e4-4241-a4db-243037bf7230), each checked against
Dogs NZ, the NZ Cat Fancy and the VeNom codes VBO carries: Collie as its own entry (VeNom
"Collie unspecified", no longer guessed as Border Collie); Toy, Miniature and Standard Poodle
(Dogs NZ registers all three; plain Poodle stays for unsized dogs and designer crosses); cat coat
colours as Mixed breed; Cavachon and Labraspoodle as crosses; five two-name duplicates merged;
"Other" and "Horse" retired; five wrong or missing ontology ids corrected. The two questions
still open for VetPost - Stationbred / NZ Sport Horse, and unknown vs mixed - are not applied.
`gen-vbo-aliases.mjs` runs after it because aliases are derived from the final vocabulary.

`apply-cross-model.mjs` is not a one-off migration: `gen-plugin-data.mjs` still writes
`array('Crossbreed', …)`, so every full regeneration must pass through it. It is idempotent and
prints every change it makes, including the rule-driven ones, so a human can audit them.

## Where aliases come from

Two sources, and the precedence between them is load-bearing.

`breed-map.php` supplies the reviewed ones: every source string with a purebred single-breed
destination becomes a searchable alias (721 of them). That only ever covers spellings VP
customers have already typed, because the map is built from the export. The rule lives in
`DPL_Breeds_Activator::map_aliases()` alone; `seed()` and the round-trip test both call it.

**Strings the old picker wrote are not aliases** (2.2.1). `"x and other: y"` and `"other: y"`
were its serialisation, not anything a person types, but 125 of them are purebred, so they were
seeded as aliases — and on staging a customer typing "other" to reach the Other option was
offered Labrador, Poodle, Border Collie… as "matched from other spellings". They still
resolve, through the map rung; the round-trip test proves that for all 269.

`breed-aliases.php` supplies the rest — 543 VBO `EXACT` synonyms for breeds already in the
seed, generated by `gen-vbo-aliases.mjs`. It adds **no breeds**, which is why it needs no
sign-off round: an alias makes an approved breed findable by another name without touching the
canonical list.

**VBO lists `DSH` as a synonym of German Shepherd Dog.** That is the 399-cat bug. There are
two independent defences: the importer rejects any synonym that is already a map key or a
canonical label, and the activator inserts ontology aliases *after* the map ones with
`INSERT IGNORE` against `UNIQUE(species, alias)`, so a curated alias always wins a collision.
Section 6c of the offline test asserts both. Until 2.2.1 the round-trip test built its
in-memory vocabulary in the *opposite* order, letting the ontology win; it now follows
`seed()`'s order. Rejections and their reasons are in
`breed-mapping/vbo-alias-report.csv` — 628 of 1,155 candidates were refused.

## Re-seeding on deploy (2.2.0)

Replacing the plugin's files does not run the seed. Until 2.2.0 the only automatic re-seed was
keyed on `DB_VERSION`, a **schema** constant that never moved past 1.0.0 — so 1.0 → 2.0 → 2.1
never re-seeded, and staging ran 2.1 code against the 1.x vocabulary ("Crossbreed" still a dog
breed) until someone pressed the button.

Now `dpl_breeds_seeded` records the plugin version plus an md5 of the three data files, and the
first admin page a member of staff loads after either changes re-seeds and shows a one-time
notice. Things that are deliberate, so do not "simplify" them away:

- **Content, not version alone.** Data regenerated and shipped without a version bump is still
  caught. Content, not mtime, so re-uploading an identical build does nothing.
- **Staff `admin_init` only, never AJAX or cron.** `admin-ajax.php` fires `admin_init` too, so
  the customer breed search would otherwise pay for ~1,800 writes. WP-CLI also triggers it, on
  `init`.
- **An atomic lock** (`INSERT IGNORE` into `wp_options`, then re-check uncached). Two staff
  loading pages together would otherwise interleave two seeds. A lock older than 10 minutes is
  taken over by compare-and-swap, so a request that fataled mid-seed cannot block forever and
  only one successor wins.
- `maybe_upgrade()` is schema-only now; a schema bump forces a re-seed by forgetting
  `dpl_breeds_seeded`.

`tests/test-auto-reseed.php` covers the fingerprint, the decision, the lock (including a real
interleaving of two successors) and `maybe_reseed()`'s control flow. The Breeds admin page shows
the last seed and whether it matches the installed files.

`fix-conflicts.mjs` holds the reviewed map-vs-vocabulary decisions — the strings that are both
a canonical label and a map key pointing elsewhere. It is the single place those judgements
live, deliberately: encoding them in two generators is how the earlier drift happened. It is
idempotent, so run it as often as you like.

**Note:** `vbo.obo` was re-downloaded on 2026-09-02 (VBO release 2026-04-15), but
`resolution3.json` is still missing, so `resolve3.mjs` and the generators after it cannot run.
`apply-cross-model.mjs`, `fix-conflicts.mjs`, `apply-signoff-research.mjs` and
`gen-vbo-aliases.mjs` all work on the shipped files, so corrections do not require the full
pipeline. `guard-split.mjs` needs `resolution3.json` too; until it is back, its two checks (no two
rows of a species share an ontology id; no label is another row's ontology synonym) have to be
run by hand. On 2026-10-14 they found six "synonym" pairs, all ontology naming quirks rather than
duplicates (e.g. VBO lists "Jack Russell Terrier" as a synonym of Parson Russell Terrier, which
Dogs NZ registers separately; "Collie" as a synonym of Collie Rough, where we keep VeNom's
separate "Collie unspecified" on purpose).

Run `guard-split.mjs` after **any** vocabulary change. One real breed arriving under two labels
was the single most common defect during the build — six were found, and none of them would
ever show up in a success metric.

## Tests

Two layers. Run the offline suites on every change; they need nothing but PHP.

```bash
php tests/test-data-offline.php          # 79 checks
php tests/test-resolver-roundtrip.php    # 62 checks
php tests/test-auto-reseed.php           # 30 checks
```

The offline test proves the invariants that make the design safe, over all 2,151 map entries
rather than by sampling: every destination exists in the vocabulary (never invents), no label
contains a separator (`" x "`, `"(cross)"`, `" and "`), no label is a crossbreed in disguise,
no source string that says "cross" is recorded as a purebred, nothing exceeds `varchar(64)`,
nothing yields more than two breeds, and `format`/`decode` round-trips breeds *and* flag.

The round-trip test runs the **real** resolver against an in-memory vocabulary and proves
guarantee 7: `resolve(format(resolve(x)))` keeps breeds and flag for every map entry. It is the
only test of `resolve()` that needs no database. All three exit non-zero, so they can gate a
build.

The live layer needs a real site and WP-CLI:

```bash
wp eval-file wp-content/plugins/dpl-pet-breeds/tests/test-breeds-e2e.php
```

Read-only by default, so it is safe against production. The two sections that write — seed
idempotency and the request queue — are skipped unless you opt in with
`DPL_BREEDS_E2E_WRITES=1`, and both clean up after themselves. Both runners are CLI-only and
return 403 over HTTP.

Section 7 of the offline test and section 11 of the E2E test assert **known gaps as current
behaviour on purpose** — `labxstaffy` dropping its staffy half, `cross bred` being unmapped,
cross names being unsearchable. Fixing one makes that section fail, which is the prompt to
update it. A gap nobody tracks is worse than a failing test.

## Status

On the live export of 14 Oct 2026 (20,759 pets, 15,292 with a breed), **98.4% resolve without a
human decision** (97.1% before the sign-off research was applied; 98.8% on the August export the
map was built from). 2,151 source strings, **414 seeded rows** — 441 originally; seven conflicting
labels retired on 2026-09-02; on 2026-10-13 the ten per-species "Crossbreed" rows plus "Horse
crossbreed" and "Maltese Jack Russell Mix" when crossbreed became a flag; on 2026-10-14 the
research applied (+4 rows: Collie and the three Poodle sizes; -12: four "Other", Cavachon,
Labraspoodle, Horse, five merged duplicates). 543 ontology aliases. Zero label conflicts.
Re-measure with `breed-mapping/analyse-export.php <export.csv> [baseline.csv]`.

Seven labels were retired on 2026-09-02 because each was both a pickable breed *and* a map key
that rewrote it to something else — so the picker offered values that silently became other
values. `Cavapoo` (a cross, not a breed with its own standard, consistent with retiring
Cavoodle) plus six duplicates inherited from the original seed: `Collie` (reinstated on
2026-10-14 as its own entry, VeNom "Collie unspecified"), `German Shepherd`,
`Husky`, `English Bulldog`, `Arabian`, `Mini Lop`. Two map entries were also corrected:
`quarter horse` had been rewriting a major breed to "Crossbreed and Horse", and
`australian labradoodle` was flattening a registered breed into Labrador + Poodle.

API 1.x was verified live on staging in September. Plugin 2.1.0 (API 2.1) went to staging on
2026-10-13 and was re-seeded by hand; 28 live checks passed. Nothing is on production. Staging's
pets plugin was a 4.9.52 build from `main` that did not use this plugin — the integration had
been overwritten by a later deploy. It came back via the 4.9.53 merge (`6cd02a2`), which carries
both the integration and the 4.9.52 email system.

Known gaps, all asserted as current behaviour so fixing one fails a test:
- an alias can only hang off a single breed row, so the 776 two-breed map entries are
  unreachable by `dpl_breed_search()` — 31 are single words people type (cavoodle, cockapoo).
  Resolution is correct; discovery is not.
- `?`, `???` and `cat` read as *Mixed breed*. They mean nobody recorded the breed, so they
  should probably be *unknown*. Undecided.
- whether Domestic Short Hair / Long Hair are breeds at all is the cat equivalent of the
  crossbreed decision. Undecided; left as breeds.
- `labxstaffy` is recorded as a purebred Labrador. More entries probably lost a named second
  parent the same way (three were found and fixed by hand); recovering the rest needs
  `resolution3.json` and a pipeline re-run.
