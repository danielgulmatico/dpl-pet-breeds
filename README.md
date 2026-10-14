# DPL Pet Breeds

Centralised pet breed vocabulary for the VetPost plugins. It owns the canonical breed list,
resolves messy free-text breed strings to it, and exposes both through a stable API so no other
plugin has to hold breed knowledge of its own.

**API 2.1.** Crossbreed is a flag, not a breed, and `dpl_breed_canonicalise()` is the one write path. If you consumed 1.x, read
[Upgrading from 1.x](#upgrading-from-1x) before deploying — the most common 1.x call silently
turns crosses into pedigrees.

## Why this exists

Breed was free text. 2,082 distinct strings described roughly 400 actual breeds — `labrador`,
`lab`, `labrador x`, `chocolate labrador` and a dozen more spellings were the same dog to a
human and unrelated values to the database. Nothing could count, filter or target by breed.

This plugin holds the answer once, so every consumer gets the same answer.

## Quick start for a consumer

```php
if ( ! function_exists( 'dpl_breed_api_version' )
	|| version_compare( dpl_breed_api_version(), '2.0.0', '<' ) ) {
	return;   // inactive, or a contract this code was not written for - degrade, do not guess
}

$r = dpl_breed_resolve( 'staffy x', 'dog' );
// [ 'status' => 'resolved',
//   'breeds' => [ ['slug' => 'staffordshire-bull-terrier', 'label' => 'Staffordshire Bull Terrier'] ],
//   'cross'  => true,  ... ]

$stored = dpl_breed_format( $r );       // "Staffordshire Bull Terrier (cross)" - pass the WHOLE result
$back   = dpl_breed_decode( $stored );  // [ 'breeds' => ['Staffordshire Bull Terrier'], 'cross' => true ]
```

## Crossbreed is a flag, not a breed

A pet has a list of **real** breeds — zero, one or two — and a `cross` flag. An unknown parent
is **implied**, never stored:

| Typed | `cross` | `breeds` | Stored and displayed as |
|---|---|---|---|
| `labrador` | false | Labrador Retriever | `Labrador Retriever` |
| `staffy x` | true | Staffordshire Bull Terrier | `Staffordshire Bull Terrier (cross)` |
| `labradoodle` | true | Labrador Retriever, Poodle | `Labrador Retriever x Poodle` |
| `moggy` | true | *(none)* | `Mixed breed` |

There is deliberately no "Crossbreed", "unknown" or "mix" breed. Any of them would need a row in
the breeds table, and that is exactly what was wrong before: "Crossbreed" was the single most
common breed in the export (~1,894 pets), it was meaningless for product matching, and it took
one of the two breed slots — so `bichon maltese cross` lost the Bichon.

## Upgrading from 1.x

Breaking changes, in order of how much damage they do if missed:

1. **Pass the whole result to `dpl_breed_format( $r )`.** The 1.x habit,
   `dpl_breed_format( $r['breeds'] )`, cannot see the flag: two breeds are still inferred as a
   cross, but a **one-parent cross becomes a purebred**. "Staffordshire Bull Terrier (cross)"
   is silently re-stored as a pedigree Staffordshire.
2. **A `resolved` result can have zero breeds.** That is a mixed breed with no known parent —
   a complete answer, not a failure. Code that treats empty `breeds` as unmatched will discard
   every mixed breed.
3. **Use `dpl_breed_decode()` where you parse and write back.** `dpl_breed_parse()` keeps its
   1.x shape — labels only — so parse → edit → format drops the flag the same way as #1.
4. **The stored form changed** to `A x B`, `A (cross)` and `Mixed breed`. The 1.x forms
   (`A and B`, `Crossbreed and A`, `Crossbreed`) are still **read**, never written, so values
   already in the pets table keep resolving.
5. **The picker delimiter is now `" x "`.** Anything that localised `breedDelimiter` from this
   plugin picks it up automatically.

## Guarantees

1. **No invented names.** Every label traces to the Vertebrate Breed Ontology, VeNom, or a named
   breed registry (ACBA, AFRMA, ARBA, NZ Cat Fancy).
2. **It says "I don't know" rather than guessing.** `dpl_breed_resolve()` returns `status` of
   `resolved`, `review` or `unknown`. **Consumers must handle the latter two** — a wrong breed is
   worse than an absent one.
3. **Slugs are stable and never reused.** Retiring a breed sets `status`; rows are never deleted.
   Store the slug, not the label.
4. **At most two known breeds.** There is no three-breed case; `format()` refuses one.
5. **`dpl_breed_format()` output always fits the storage column**, or returns `''`.
6. **Crossbreed is a flag, never a breed** — under any name.
7. **Round-trip.** `dpl_breed_resolve( dpl_breed_format( $r ) )` returns the same breeds and the
   same `cross` for any resolved `$r`, so a migration can verify its own output.

## API

| Function | Purpose |
|---|---|
| `dpl_breed_api_version()` | Contract version. Semver; major only moves on a breaking shape change. **Gate on it.** |
| `dpl_breed_search( $q, $args )` | Typeahead over labels **and** aliases. `$args`: `species`, `limit`, `include_aliases`. |
| `dpl_breed_resolve( $raw, $species )` | Free text → `{status, breeds, cross, rung, …}`. The import/migration workhorse. |
| `dpl_breed_get( $slug, $species )` | One breed by slug. |
| `dpl_breed_list( $species, $args )` | The picker list, most common first. |
| `dpl_breed_species()` | Species that have a list. |
| `dpl_breed_format( $r )` | The stored and display form. Pass the whole result, or a label list plus `$cross`. |
| `dpl_breed_decode( $stored )` | Exact inverse of `format()`: `{breeds, cross}`. |
| `dpl_breed_parse( $stored )` | Labels only, **no flag**. Kept for 1.x callers — never use it to write back. |
| `dpl_breed_request( $raw, $species, $ctx )` | File an add-breed request. Returns a suggestion instead if it's a misspelling. |
| `dpl_breed_canonicalise( $raw, $species, $ctx )` | **2.1.** Any input → the value to store in `wp_vpp_pets.breed`. **Every writer should use this.** |
| `dpl_breed_species_key( $species )` | **2.1.** Any spelling → a vocabulary key: `Canine (Dog)` → `dog`, `guinea pig` → `guinea`. |
| `dpl_breed_tokens()` | **2.1.** Separators and markers, for a UI that composes values client-side. |

## Writing a breed

`wp_vpp_pets.breed` has four writers — My Pets and the staff Add/Edit Pet screen (both
dpl-vp-pets), the prescriptions admin (vet-pharmacy-prescriptions) and the AI prescription
scanner (dpl-prescriptions-ai). Several writers with their own ideas of a breed value is how the
free text got there, so all of them call one function:

```php
$c = dpl_breed_canonicalise( $posted, $species, array( 'request' => true ) );
$wpdb->update( $pets, array( 'breed' => $c['value'] ), array( 'id' => $id ) );   // always fits
```

It tries the whole value, then each part, so a picker's `Labrador Retriever x Other: Wolfdog`
and the scanner's `Border Collie Cross` both work. An explicit cross marker around a known breed
adds the flag but never a breed. It **never guesses**: only a `resolved` answer is stored, and
otherwise the input is kept (fitted to the column, cut on a character) with a `suggestion` for a
human. `request` files an add-breed request for a deliberate `Other: X` — leave it off for bulk
or OCR input. `accept_suggestion` stores a near-miss as its closest match; it is guessing, so it
is off by default.

It is idempotent: canonicalising a stored value changes nothing, which the tests prove for every
map entry — including the `Other: <stored value>` form an edit screen posts back.

**Held strings (2.5).** Some strings are held for a person: `includes/data/breed-review.php` lists,
per species, the ones a 2026-10-14 audit found resolving to a wrong or lossy breed. For a held
string of that species `canonicalise()` returns `review`, keeps the input exactly as supplied,
offers no `suggestion`, and adds a `reason` (the audit's note). VetPost's admins decide them by
hand in **Pet Breeds → Review** (2.6, below). The same string under another species, or with no
species, is unaffected.

Never build or split the stored form yourself. No canonical label contains `" x "`,
`"(cross)"` or `" and "`, and the tests assert it — but that is this plugin's invariant to keep,
not something a consumer should rely on.

## How resolution works

Strictest first, stopping at the first hit:

0. **decided** (2.6) — an admin said what this string means for this species in Pet Breeds →
   Review. Outranks everything, the hold included.
0. **held** (2.5) — a string held for review for this species: always `review`, nothing else runs.
   A "means X" decision lifts the hold; "keep as typed" does not.
1. **map** — the reviewed lookup of 2,048 source strings. Production never re-derives anything.
2. **stored** — a value this plugin wrote (`A x B`, `A (cross)`, `Mixed breed`, or a 1.x form).
   Accepted only if every component is *already* a canonical label verbatim, so free text such
   as `lab x staffy` is never reassembled from guesses.
3. **alias** — searchable synonyms, including approved add-breed requests.
4. **label** — the string *is* a canonical breed name.
5. **search** — close enough to propose, never to apply: always `review`.

## Search is deliberately tiered

Exact label → label prefix → alias exact → alias prefix → substring → fuzzy. Fuzzy only runs when
everything stricter came back empty, and within each tier results are ordered by how many pets
actually have that breed. So `chi` returns Chihuahua before Chinese Shar-Pei, because the data
says so — not alphabetically.

Aliases are why `staffy`, `lab`, `dsh`, `nanny dog` and `red setter` all find their target even
though none of them is a canonical label.

## Hooks

```php
apply_filters( 'dpl_breed_search_results', $results, $query, $species );
apply_filters( 'dpl_breed_resolve_result', $result, $raw, $species );
apply_filters( 'dpl_breed_request_digest_recipients', $emails );
do_action( 'dpl_breeds_ready', $api_version );
do_action( 'dpl_breeds_reseeded', $added, $retired );
do_action( 'dpl_breeds_auto_reseeded', $counts );   // 2.2: after an automatic re-seed only
do_action( 'dpl_breed_request_created', $id, $data );
do_action( 'dpl_breed_request_approved', $id, $breed_id );

// 2.6 (API 2.3) - Pet Breeds -> Review. Implemented by a plugin that STORES breeds:
apply_filters( 'dpl_breed_review_sources', $rows );          // add {source, species, text, count} rows
apply_filters( 'dpl_breed_review_apply', $applied, $decision ); // write $decision['value'] to your records
                                                              // holding any of $decision['variants']
                                                              // ([species, text] as you reported them);
                                                              // return $applied + how many changed
```

## Review (2.6)

**Pet Breeds → Review** lists every breed text the resolver cannot place, with how many pets hold
it. This plugin never reads another plugin's records: the texts come from the plugins that store
breeds (`dpl_breed_review_sources`; dpl-vp-pets 4.9.65 reports its active pets), grouped per
species and text whatever the spelling. For each, an admin either:

- **says what it means** — one or two labels, a cross, or Mixed breed, from the species' list.
  Stored as a decision (option `dpl_breeds_review_decisions`), answered by the resolver from then
  on (rung `decided`), so the same text typed anywhere later is stored as that breed. It is handed
  to the consumers (`dpl_breed_review_apply`) to update the records that hold it now.
- **keeps it as typed** — no record changes, the text leaves the queue, the hold stays.

Decisions survive a re-seed (they are not in the shipped data) and can be undone from the page;
undo does not revert records already updated - the consumer keeps their original (dpl-vp-pets in
`breed_raw`). The closest list name is shown as a hint and never pre-selected. Decisions are
logged to WooCommerce's log under `dpl-pet-breeds`. Nothing on the page runs by itself.

## Tables

| Table | Notes |
|---|---|
| `{prefix}vpp_breeds` | The vocabulary. **Adopted** from dpl-pet-profiles v3.5 rather than duplicated, so existing rows and ids survive. |
| `{prefix}vpp_breed_aliases` | Searchable synonyms. Rebuilt on re-seed from the map and the ontology; request-sourced aliases are preserved. |
| `{prefix}vpp_breed_requests` | Add-breed moderation queue, deduped by `(species, normalised)`, status-aware. |

The re-seed **upserts on `(species, slug)` and never `DELETE + INSERT`**, because consumers may
already hold a slug and reassigning ids would silently repoint their data. Breeds approved
through the request queue live outside the shipped seed and are never retired by a re-seed.

**It runs automatically (2.2.0).** Updating the plugin's files does not run the seed, so the
plugin records what it last seeded — its version plus a fingerprint of the data files — and the
first admin page a member of staff loads after either changes re-seeds and shows a one-time
notice. Never on AJAX or cron requests, so customers never pay for it; under WP-CLI it runs on
`init`. A lock stops two requests seeding at once. The *Re-seed* button still forces one, and
the Breeds page shows when the last seed ran and whether it matches the installed files.

## Where the data comes from

`includes/data/` is generated, not hand-edited:

- `breed-map.php` — 2,048 source strings → canonical breeds plus the cross flag
- `breeds-seed.php` — 414 breed rows across 10 species
- `breed-aliases.php` — 540 Vertebrate Breed Ontology `EXACT` synonyms for breeds already held
- `breed-review.php` — 113 (species, string) pairs held for a person to review (2.5)

The hard matching — ontology lookups, alias overrides, fuzzy matching, portmanteau
decomposition — happens **once, offline, and gets reviewed** in the *Pet Breeds* workspace.
Production only ever does a lookup against the reviewed conclusions, so it can never reach a
different answer than the one that was signed off. See `CLAUDE.md` for the pipeline.

## Add-breed requests

The moderated replacement for a free-text breed field:

1. Search finds nothing → offer to request it.
2. `dpl_breed_request()` runs the resolver first. A misspelling returns a suggestion and **creates
   nothing**; a mixed breed is recorded as one. That absorbs most requests.
3. Otherwise it upserts, so five people asking for the same breed is one row with a count of five.
4. A daily digest goes to the admin. Approving adds the breed *and* aliases the requested wording
   to it, so the same phrasing resolves next time. Approval refuses a label that would duplicate
   an existing breed or be shadowed by the map.
5. Rejected strings keep counting. Three or more requests for something declined surfaces it
   again — repeated demand is how you learn a rejection was wrong.

## Tests

```bash
php tests/test-data-offline.php          # the data and pure logic - no WordPress needed
php tests/test-resolver-roundtrip.php    # the real resolver over all 2,048 entries, in memory
php tests/test-auto-reseed.php           # when to re-seed, and the lock around it
php tests/test-review.php                # Review decisions: the decided rung, the hold, the hand-off
wp eval-file wp-content/plugins/dpl-pet-breeds/tests/test-breeds-e2e.php   # a live site
```

The offline ones need nothing but PHP and exit non-zero on failure, so they can gate a build. All
are CLI-only and return 403 over HTTP.
