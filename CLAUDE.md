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
node fix-audit-2026-10-14.mjs   # holds the 2026-10-14 audit's strings for VetPost review
node gen-vbo-aliases.mjs        # LAST: ontology synonyms for the vocabulary as it now stands
php check-audit-2026-10-14.php  # every flagged string is 'review', every POLICY one unchanged
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

**Held for review (2.5.0).** An audit of all 2,395 strings the migration would rewrite on the 14
Oct live export (`breed-mapping/audit-2026-10-14-flags.csv`) flagged 115 as WRONG, LOSSY, UNSURE
or SPECIES (142 pets): crosses collapsed to one breed ("PoodleX", "LabXStaffy"), fuzzy picks
("Bush Pony" -> the Bosnian Busa Pony, "Cav" -> Cavamalt), three-breed crosses cut to two, a
guinea pig "Short hair" given the cat's Domestic Short Hair. Daniel's decision (2026-10-14): do
not solve them - VetPost's admins review them by hand on a page in dpl-pet-profiles, which lists
pets whose breed canonicalises to `review` or `unknown`. So `fix-audit-2026-10-14.mjs` picks no
breed. It writes `includes/data/breed-review.php` (species => normalised string => the audit's
note), and `canonicalise()` returns `review` for a held string of that species, exactly as typed,
with no suggestion and a `reason` - before the map, the part-by-part step or an alias can answer.
`resolve()` returns `review` with rung `held`. Data alone could not do it: seven flagged strings
are not map keys (they resolve part by part, through an alias, or through another species' label),
and "short hair", "dsh" and "staffy" are right for one species and wrong for another, so the hold
is per species. It also removes the 103 map entries behind held strings, because the pickers offer
map entries as search aliases and crossbreed names ("labraspoodle" ticked Labrador and Poodle) -
all but four, which other pets use correctly or `apply-signoff-research.mjs` would put back; and
`gen-vbo-aliases.mjs` refuses horse "miniature" and dog "bully" and never aliases a held string.
The only vocabulary change is two ontology ids: horse Andalusian carried the Andalusian chicken's
and cat Angora the Angora goat's. POLICY rows and the English Staffordshire Bull Terrier split are
untouched (VetPost's). The Labraspoodle and "Burmese and Other: Tabby" pets are held too, although
the sign-off doc answers both by research. `breed-review.php` is in the seed fingerprint, so
changing it re-runs dpl-pet-profiles' migration. Offline test 6e, round-trip test section 7.

`includes/data/cross-name-counts.php` is generated too, from a live export rather than the map:
`php gen-cross-name-counts.php ../wp_vpp_pets_<date>.csv`. Re-run it whenever the map gains
or loses a single-word cross name; `test-cross-names.php` fails until it covers them exactly.

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

`breed-aliases.php` supplies the rest — 540 VBO `EXACT` synonyms for breeds already in the
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
`breed-mapping/vbo-alias-report.csv` — 540 of 1,132 candidates are accepted. Since 2.5 it also
refuses a short list of synonyms NZ owners use differently (`REFUSE`: horse "miniature", dog
"bully") and any string held for review.

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

## Review: admins decide what a breed text means (2.6.0, API 2.3)

Decided by Daniel, 2026-10-14. After the audit he asked for a manual review page instead of
correcting the flagged mappings ("so the admins of vetpost will review it manually"), and then
for it to live here, not in the pets plugin: "the concern is the pet breeds not pet profiles".
A first version (Pets → Breed Review, dpl-vp-pets 4.9.64) was on staging for an afternoon and
is gone.

**Pet Breeds → Review** (`dpl-breeds-review`, `manage_woocommerce`) lists every breed text that
does not resolve, per species, with its pet count; held strings show the audit's reason. An
admin either says what it means (labels from the species' list, a cross, or Mixed breed) or
keeps it as typed. The split of responsibilities:

- **This plugin owns the meaning.** A decision is stored in option `dpl_breeds_review_decisions`
  (species => normalised text => {action, value, text, pets, by, at}), never in the shipped data,
  so a re-seed cannot undo it. `resolve()` answers a "means X" decision first (rung `decided`, step
  0a, ahead of the hold) and `held_reason()` lifts the hold for it, so the text resolves
  everywhere - whole, inside a cross, through `canonicalise()` - and is stored correctly the next
  time anyone types it. "Keep as typed" deliberately does NOT lift the hold: several held strings
  still have their wrong map entry ("short hair" for a guinea pig), and lifting it would hand them
  straight back to it. A decision whose answer stops resolving (its label retired) is ignored.
- **The pets plugin owns its records.** This plugin never reads `wp_vpp_pets`. Texts arrive via
  `apply_filters( 'dpl_breed_review_sources', array() )`; a decision is handed back through
  `apply_filters( 'dpl_breed_review_apply', 0, $decision )` with the value and every spelling it
  was reported under, and dpl-vp-pets 4.9.65 writes it to those pets (original to `breed_raw`,
  race-guarded, profile sync) and returns the count for the admin's notice.

The answer must canonicalise to `resolved` for that species or it is refused; a text with no known
species is listed but cannot be decided (fix the pet's species first). The closest list name is a
hint, never pre-selected - a near spelling is the guess this page replaces. Undo removes the
decision; records already updated keep their new value. The queue is cached 15 minutes in the
`dpl_breeds_review_queue` transient, stamped with the seed fingerprint and the decisions, so new
data or any decision rebuilds it; the menu badge reads the cache only. Logged to WooCommerce under
`dpl-pet-breeds`. `includes/class-dpl-breeds-review.php`, `admin/class-dpl-breeds-review-admin.php`,
`php tests/test-review.php` (24; 6 mutants of the hooks and the class all caught).

Pending add-breed requests (Pet Breeds → Requests) are the same kind of question; folding them
into this queue is a possible next step, not done.

**On staging since 2026-10-14 19:05 NZ** (2.6.0 with dpl-vp-pets 4.9.65; re-seeded by itself
under WP-CLI). Verified with wp eval as admin: queue of 10 texts / 15 test pets, page and review
screen render, a real keep + undo round trip. No "means X" decision saved on staging yet - it
updates pets and fires their EDM profile sync. Full log in dpl-pet-profiles' CLAUDE.md, Releases.
Rollback: `~/private_html/deploy-backups/dpl-pet-breeds-2.4.0-2026-10-14-0605.tar.gz`.

**2.6.1 (staging 2026-10-14 19:45 NZ, production zip built):** the menu badge no longer goes blank -
`cached_count()` falls back to option `dpl_breeds_review_count` (written by every queue build), and
the page's save/keep/undo/refresh handlers rebuild the queue before redirecting. Verified in Chrome
(badge 8 -> 7 on the page right after a Keep, back to 8 after Undo); `test-review.php` 28. The
production package is `Pet Breeds/release-2026-10-14/dpl-pet-breeds-2.6.1.zip`.

## Add a breed, add a species, and who asked (2.7.0, API 2.4, schema 1.1.0)

Daniel's three issues, 2026-10-21: "need to be able to add a breed", "need a 'cow' species, or
maybe just be able to add a species?", and "where did the breed requests come from? ... user_id,
pet_id, order_id". **On staging since 2026-10-21 16:04 NZ**, not on production; release log below.
The consumer halves are done but uncommitted and not deployed: dpl-pet-profiles 4.9.74 (species
selects from `dpl_breed_species()`, `source` my-pets / staff-pet) and vet-pharmacy-prescriptions
2.0.5 (`order_id`, `prescription_id`, `source` prescription).

- **Pet Breeds → Breeds → Add a breed.** Until 2.7 the only way a breed entered the vocabulary
  outside the seed was a customer typing "Other: X" and an admin approving it; staging's one
  request ("Testbreed Notarealdog") shows how rarely that happens. The form takes a species (an
  existing one or a new one typed in), the label, and optional "also known as" spellings, and
  calls `DPL_Breeds_Vocabulary::add_breed()` - now the ONE write path into the table outside the
  seed; `DPL_Breeds_Requests::approve()` calls it too, so the guards live once. `source = 'admin'`,
  exempt from the re-seed's retire pass alongside `'request'` (`MANUAL_SOURCES`). A retired row
  added again is reactivated and moved to the manual source, or the next re-seed retired it again.
- **Adding as a label that is already there is an alias, not an error.** The old `duplicate_label`
  guard compared `slug <> slug`, so for a label whose slug is the existing one it never fired:
  approving "Testbreed" as "Border Collie" silently re-sourced the Border Collie row to 'request'
  and aliased the wording. Now the active row is left untouched and only the alias attaches, which
  is what an admin means by it. The guard still refuses a label equal to another breed's under a
  different slug (none shipped is; the test plants one). The map-shadow guard compares normalised,
  so re-adding "border collie" is not "shadowed" by the map's Border Collie.
- **A species is the species column**, so "add a species" is add_breed() under a new key:
  `species_for_new()` takes an existing species by any spelling, else slugifies to a new key
  (`/^[a-z][a-z0-9-]{2,31}$/`). `species_key()` learned `bovine`/`cattle` → `cow` (only once cow
  exists) and now matches whole words: by substring, "cattle" was a **cat** and "Bovine" a **sheep**
  ("ovine"). The shipped list already has `Angus` and `Cattle` under `other`, so a real cow species
  would want those moved - a seed change for VetPost. The other plugins hardcode their species
  lists (dpl-vp-pets template and staff form, VPPP, the scanner) and dpl-vp-pets' product matching
  needs `vpp_attribute_category_map` rows per species, so a new species here is not pickable until
  they are updated: follow-up in those repos, proposed, not done.
- **Requests remember every occasion.** `vpp_breed_requests.sources` (longtext JSON, schema
  1.1.0 - `maybe_upgrade()` adds it by dbDelta and forgets `dpl_breeds_seeded`, so the next staff
  page load re-seeds once) holds up to 50 of `{at, user_id, pet_id, order_id, prescription_id,
  source}`, appended on the first request and on every repeat, pending or rejected. The first
  requester stays in `pet_id`/`user_id`. `DPL_Breeds_Requests::occasion()` is pure: positive ints
  only, `source` as a key, `user_id` defaulting to the current user. The Requests page has an
  "Asked by" column (newest five: date, user link with "(staff)", pet link via
  `dpl_breed_request_pet_url` defaulting to dpl-vp-pets' edit screen, order link via WooCommerce,
  screen name) and the digest says where each was last asked from. The consumers pass `pet_id` and
  `user_id` today; `order_id`, `prescription_id` and `source` need their call sites updated
  (dpl-vp-pets `canonicalise_breed()`, VPPP `VPPP_Breeds::canonicalise()`) - proposed, not done.
- `DPL_Breeds_Repository::flush()` now also forgets the per-request vocabulary and cross-names
  copies (they were static locals, so a breed added in a request was invisible to `resolve()`
  until the next request; the admin pages redirected, so nothing noticed, but the new test did).
- `php tests/test-requests.php` (82): a stand-in for the three tables that answers the exact
  queries, loaded by the real `seed()`. Four mutants (no append on repeat, latin by substring,
  'admin' not exempt, re-add rewriting the row) each fail 2-4 checks. E2E section 13 gained the
  sources, the alias-on-approve semantics, an admin add that survives `seed()`, and a final check
  that no `zzz` test row is left in any of the three tables.
- **The E2E runner never reported a failure under WP-CLI** (fixed 2026-10-21). `wp eval-file`
  includes the file inside a function, so `$pass`/`$fail` were locals while the assertions counted
  into globals: the summary always read "PASSED : 0 / FAILED : 0", and there was no exit code at
  all. Every earlier "E2E passed" on staging was read off the [PASS]/[FAIL] lines, not the summary.
  Now `global` plus `exit( $fail ? 1 : 0 )`; a copy with one forced failure exits 1.

```
## Release dpl-pet-breeds 2.7.0 - staging
Commit/tag:        none - deployed from the working tree on Daniel's go-ahead (base 84165ca)
Deployed:          2026-10-21 16:04 NZ by Claude for Daniel, tar over SSH (rsync is not installed
                   on Daniel's PC; `tar -czf - ... | ssh vetpost-staging "tar -xzf - -C ..."`)
Files verified:    yes - header 2.7.0 / API 2.4.0, md5 of requests, vocabulary and admin match local
OPcache + caches:  validate_timestamps=1, revalidate_freq=2: picked up without restart; no front-end
                   file changed, no purge needed
Upgraders:         schema 1.0.0 -> 1.1.0, `sources` column added; re-seeded once under WP-CLI
                   (414 active rows, as shipped; matches the installed files)
Verified:          E2E with writes 124 pass / 0 fail, exit 0, no test rows left; read-only 100 / 0.
                   As admin (user 1, wp eval): Breeds page has Add a breed with the new-species
                   field; Requests shows "Asked by" for the real request (18 Sep, Daniel
                   Gulmatico (staff), pet #331 - pre-2.7, from the columns).
                   NOT verified in a browser, and no breed or species added for real.
Rollback:          ~/private_html/deploy-backups/dpl-pet-breeds-2.6.1-2026-10-21-0303.tar.gz
                   (the `sources` column can stay; 2.6.1 ignores it)
Package:           Pet Breeds/release-2026-10-21/dpl-pet-breeds-2.7.0.zip (bsdtar, forward-slash
                   paths - PowerShell 5.1's Compress-Archive writes backslashes, which unpack on
                   Linux as file names containing "\")
```

The first E2E run left one approved test request behind (a reused variable dropped it from the
cleanup list) while still passing "test artefacts cleaned up"; it was deleted by id and the test
now checks the tables themselves.

**Consumers and a real cow species on staging, 2026-10-21 16:20 NZ** (Daniel: "deploy dpl-vp-pets
4.9.74 and vet-pharmacy-prescriptions 2.0.5 to staging, then add a cow species with one breed").
Recorded here because other sessions were editing both consumer repos at the time.

```
## Release dpl-vp-pets 4.9.74 (species only) - staging
Built from:        staging's own 4.9.73 + the six species files from the dpl-pet-profiles working
                   tree (dpl-vp-pets.php, includes/class-dpl-vp-pets-db.php,
                   admin/class-dpl-vp-pets-backend.php, public/class-dpl-vp-pets-frontend.php,
                   public/partials/template-my-pets.php, tests/test-species-options.php).
                   NOT the whole tree: it also holds a "Database character set" tool (latin1 ->
                   utf8mb4, class-dpl-vp-pets-charset*.php, activator charset clauses) that the
                   running "Reminder email template setup" session was still writing. Its release
                   must not reuse 4.9.74 - staging already has a 4.9.74 without it.
Files verified:    md5 of all six match; php -l clean; Plugins list 4.9.74
Tests (the build): species-options 27, staff-breed-form passes to section 5 then dies on
                   MINUTE_IN_SECONDS (stub missing; same against breeds 2.6.1 - test env only),
                   email-optins-hidden 27/1 (expects LF; working copies are CRLF - same on 4.9.73)
Rollback:          ~/private_html/deploy-backups/dpl-pet-profiles-4.9.73-2026-10-21-0318.tar.gz

## Release vet-pharmacy-prescriptions 2.0.5 - staging
Built from:        a snapshot of the working tree taken before the dropdown session started
                   editing it; staging-vs-snapshot (CR-insensitive, 292 php/js/css files) differed
                   in exactly the four 2.0.5 files, and only those were sent.
Files verified:    md5 match; php -l clean; Plugins list 2.0.5; test-breeds-integration 50/0
Rollback:          ~/private_html/deploy-backups/vet-pharmacy-prescriptions-2.0.4-2026-10-21-0318.tar.gz

## Cow species
Added:             cow / Hereford (breed id 453, source admin, alias "hereford cattle") through
                   DPL_Breeds_Vocabulary::add_breed() as user 1; logged under dpl-pet-breeds.
                   Resolves as "Hereford" for cow, "Bovine" and "cattle"; "Hereford x" ->
                   "Hereford (cross)". Undo: retire row 453 (status = 'retired'), never delete.
Pet forms:         cow offered in My Pets add + edit (latest UI) and the staff pet screen, as
                   staff. NOT for customers: vpp_launch_to_everyone is off, so they get the frozen
                   -stable template with its ten (checked as customer user 16). NOT in the
                   prescriptions admin or the scanner yet (session "VPPP and scanner: species
                   dropdown from breed API" is building that).
Not set up:        product matching (no vpp_attribute_category_map rows for cow); Angus and
                   Cattle still sit under `other`.
Found:             species_options() keeps stored species as RAW text, so user 1's My Pets offers
                   "Canine" and a second "Dog" - in the ADD form too. Sent to the species session
                   to fix; must be fixed before production.
                   daniel@digitalpie.co.nz is user 63 and an ADMINISTRATOR on staging, not the
                   plain Customer the release procedure assumes; use a real customer to check.
Breed migration:   dpl-vp-pets' automatic breed migration is switched on on staging and re-ran when
                   breeds 2.7.0 landed: 122 pets scanned, 0 changed.
```

**Round 2 on staging, 2026-10-21 19:58 NZ** (Daniel: "put those who are not in staging to that
place"). Eleven runtime files, no tests or notes (the release procedure now ships runtime files
only). Each repo was snapshotted and every php/js/css file diffed against staging, CR-insensitive,
before sending; the diffs were exactly the three changes below.

```
## Release dpl-vp-pets 4.9.74 (species fix) - staging
What:              species keep-list fix: Add form lists real species only; a stored "Canine" /
                   "Canine (Dog)" / "Dog" selects dog (species_select_value(), petData species_key,
                   one JS line) instead of adding an option. Version NOT bumped - still 4.9.74, same
                   as round 1; assets are versioned by file time (4.9.71), so the JS change reaches
                   browsers. Files: backend, db, frontend, template-my-pets.php, frontend JS.
                   The charset tool is no longer in the tree; "My Account and Pet Profiles
                   improvements" was running in the repo, but none of its work was in these files.
Note:              saving an edited pet stored as "Canine" now stores "dog".
Rollback:          deploy-backups/dpl-pet-profiles-4.9.74-species-2026-10-21-0657.tar.gz

## Release vet-pharmacy-prescriptions 2.0.6 - staging
What:              species select (staff pet form) and add-pet modal from dpl_breed_species();
                   label codes moved to VPPP_Breeds::species_code(), a species with no code prints OT.
Rollback:          deploy-backups/vet-pharmacy-prescriptions-2.0.5-2026-10-21-0657.tar.gz

## Release dpl-prescriptions-ai 1.8.8 - staging
What:              scanner edit page species select from dpl_breed_species().
Rollback:          deploy-backups/dpl-prescriptions-ai-1.8.7-2026-10-21-0657.tar.gz

Pre-flight:        php -l + node --check clean; tests on the snapshots: pets 10 suites all pass
                   (species-options 49, staff-breed-form 50, email-optins-hidden 28), VPPP
                   breeds-integration 50 + species-options 22, scanner breeds-ai 41 + species 13.
Files verified:    md5 of all 11 match; Plugins list 4.9.74 / 2.0.6 / 1.8.8; no new PHP errors
                   (split-orders-add-on's session_start warnings predate this).
Verified (wp eval, not a browser):
                   cow in My Pets add + edit and the staff pet screen (admin), VPPP list and
                   add-pet modal, scanner list. My Pets for admin user 1 no longer offers "Canine"
                   or a second "Dog". Staff screen, pet #19 stored "Canine": dog pre-selected.
                   Customer user 16: unchanged ten, no cow (launch switch off).
Gap:               VPPP and the scanner copied the round-1 rule, so a pet stored as "Canine" shows
                   an extra "Canine" option (selected) on those two screens. No data loss; should get
                   the same species_select_value() mapping before production.
Server leftovers:  round 1 and the 2.7.0 deploy put tests/ (and dpl-pet-breeds' CLAUDE.md,
                   README.md) on staging, which the new runtime-only policy forbids. Not removed.
```

**Round 3 on staging, 2026-10-21 20:10 NZ** (Daniel: "give VPPP and the scanner the same Canine ->
dog mapping as the pets plugin, test it, and deploy to staging"). Done in this session.

```
## Release vet-pharmacy-prescriptions 2.0.7 - staging
What:              VPPP_Breeds::species_select_value() (same rule as dpl-vp-pets 4.9.74);
                   species_options() keeps only a stored value that maps to nothing; the
                   prescription pet form selects the mapped key; fetch_pet_data() adds species_key;
                   vppp-prescriptions-backend.js and vppp-orders-backend.js set the select from
                   pet.species_key || pet.species (it went blank for a stored "Canine").
Tests:             species-options 41 (was 22), breeds-integration 50. Mutants caught: keep raw
                   again (6 fail), no mapping (6), orders JS raw (1).
Rollback:          deploy-backups/vet-pharmacy-prescriptions-2.0.6-2026-10-21-0707.tar.gz

## Release dpl-prescriptions-ai 1.8.9, then 1.8.10 - staging
What:              1.8.9: EditPage::species_select_value(), the same keep rule, the "Animal" select
                   selects the mapped key, the pet picker reads species_key. 1.8.10: adding a NEW pet
                   from a scan copied the scanned text into the select (blank for "Canine"/"Dog");
                   the scanned species box now carries data-species-key, used while the text is as
                   scanned, an edited text matched lower-cased.
Tests:             species-options 24 (was 13), breeds-ai 41. Mutants caught: select raw (1), keep
                   raw (4), and for 1.8.10 ignore the attribute / no lower-case / no attribute (1
                   each) - after tightening the check, which first let the attribute mutant pass.
Rollback:          deploy-backups/dpl-prescriptions-ai-1.8.9-2026-10-21-0709.tar.gz
                   (1.8.8: deploy-backups/dpl-prescriptions-ai-1.8.8-2026-10-21-0707.tar.gz)

Deploy:            each repo diffed against staging first (only these files differed), backed up,
                   runtime files only, md5 of all files match, php -l clean, Plugins list 2.0.7 /
                   1.8.10, no new PHP errors.
Verified (wp eval, not a browser):
                   both plugins: Canine, Canine (Dog), Dog -> dog; Bovine, cow -> cow; Unicorn kept;
                   options for a pet stored Canine are the eleven with no "Canine". fetch_pet_data
                   for pet #19 (stored "Canine") returns species_key "dog".
                   NOT rendered on staging: the scanner review page with a real scan (needs a scan
                   record), so 1.8.10's data-species-key path is proven by tests only.
```

## Tests

Two layers. Run the offline suites on every change; they need nothing but PHP.

```bash
php tests/test-data-offline.php          # 95 checks
php tests/test-resolver-roundtrip.php    # 75 checks
php tests/test-auto-reseed.php           # 30 checks
php tests/test-cross-names.php           # 23 checks
php tests/test-review.php                # 28 checks
php tests/test-requests.php              # 82 checks (2.7)
```

The offline test proves the invariants that make the design safe, over all 2,048 map entries
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

Read-only by default, so it is safe against production. It exits non-zero on a failure (since
2026-10-21; before that the summary always read zero). The two sections that write — seed
idempotency and the request queue — are skipped unless you opt in with
`DPL_BREEDS_E2E_WRITES=1`, and both clean up after themselves. Both runners are CLI-only and
return 403 over HTTP.

Section 7 of the offline test and section 11 of the E2E test assert **known gaps as current
behaviour on purpose** — `cross bred` being unmapped, cross names being unsearchable. Fixing one makes that section fail, which is the prompt to
update it. A gap nobody tracks is worse than a failing test.

## Status

On the live export of 14 Oct 2026 (20,759 pets, 15,292 with a breed), **97.5% resolve without a
human decision** (14,908 pets; 178 review, 206 unknown). It was 98.4% before 2.5 held the audit's
143 pets for VetPost's admins (the 142 audited plus "Labradoodle X", the same string as the held
"labradoodle x"), and 97.1% before the sign-off research. 2,048 source strings, **414 seeded
rows** — 441 originally; seven conflicting labels retired on 2026-09-02; on 2026-10-13 the ten
per-species "Crossbreed" rows plus "Horse crossbreed" and "Maltese Jack Russell Mix" when
crossbreed became a flag; on 2026-10-14 the research applied (+4 rows: Collie and the three Poodle
sizes; -12: four "Other", Cavachon, Labraspoodle, Horse, five merged duplicates). 113 (species,
string) pairs held for review. 540 ontology aliases. Zero label conflicts.
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
  unreachable by `dpl_breed_search()`. **Partly closed in 2.4.0 (API 2.2):**
  `dpl_breed_cross_names()` exposes the 25 single-word ones (labradoodle, cavoodle, spoodle,
  cockapoo, groodle…) with both breeds and the exact stored value, and the three pickers (My
  Pets latest UI, the staff pet screen, the prescriptions picker) match them as you type and
  tick both breeds. They are derived from `breed-map.php` at runtime, so there is nothing new to
  keep in step; squashed pairs ("gsdxlab") and spelled-out pairs ("lab x staffy") still resolve
  but are not offered. Typo variants stay matchable, and `includes/data/cross-name-counts.php`
  (pets per spelling, from the 14 Oct live export via `breed-mapping/gen-cross-name-counts.php`)
  makes the row read "Labradoodle", not "Labradoddle". `php tests/test-cross-names.php` (23).
- `?`, `???` and `cat` read as *Mixed breed*. They mean nobody recorded the breed, so they
  should probably be *unknown*. Undecided.
- whether Domestic Short Hair / Long Hair are breeds at all is the cat equivalent of the
  crossbreed decision. Undecided; left as breeds.
- `labxstaffy` was recorded as a purebred Labrador. Since 2.5 it is held for review with the
  rest of the 2026-10-14 audit's strings, and its map entry is gone.
- The map has no species, so a string held for one species still resolves for the others ("short
  hair" is a cat's Domestic Short Hair) and with no species at all. And a map entry or a label of
  another species still answers when nothing is held: a cat typed "Dachshund" stores the dog
  breed (7 such pets on the live export, none flagged by the audit). Not changed in 2.5;
  VetPost's review page shows them only once they are held or unmatched. 2.7's test shows the
  same thing for a new species: "Hereford x Angus" under cow resolves because Angus is an `other`
  label.
- **`UNIQUE(species, slug)` on `wp_vpp_breeds` does not exist on staging** (found 2026-10-21).
  Four retired dog rows inherited from dpl-pet-profiles v3.5 have an empty slug (ids 26 Cavoodle,
  28 Cocker Spaniel, 30 Corgi, 56 Mixed Breed), so dbDelta's `ADD UNIQUE KEY species_slug` fails
  with "Duplicate entry 'dog-'" - logged in August on activation and again on every schema bump.
  Nothing relies on the key (seed and add_breed() select before writing), and the alias and
  request tables' unique keys, which the DSH defence and request dedupe DO rely on, are present.
  Fix: give those retired rows unique slugs, then let dbDelta add the key. Production probably has
  the same rows; check before its schema bump. Not done - a data change for Daniel to approve.
- Seed rows still carrying another species' ontology id, which `gen-vbo-aliases.mjs` mines for
  aliases ("myanmar swamp buffalo" and "bama kywe" find the Burmese cat, "iranian" the Persian):
  Burmese, Persian, Bengal, Siamese, Manx, Siberian (cat); Maltese, Greyhound, Pomeranian,
  Papillon, Newfoundland (dog). Plus German Spitz on the American Eskimo Dog's id and Havana Brown
  on Havana's. Only Andalusian and Angora were corrected in 2.5. Metadata only; no pet changes.
