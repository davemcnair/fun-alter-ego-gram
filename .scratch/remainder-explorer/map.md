# Remainder explorer spec

Label: wayfinder:map

## Destination

A handoff spec for Remainder explorer on Target show: a toggleable scratchpad that pins visible Word Matches against the Target Signature, shows Remainder as an alphabetical letter bag, live-shrinks that bag while inventing a fun/ok catalog word, and lets Add word accept any word whose Signature fits Remainder. AlterEgos still come from Target search.

## Notes

Domain: Fun Alter-Ego-Gram. Consult `CONTEXT.md` every session. Skills: `/grilling`, `/domain-modeling`. Plan; do not implement the mode.

Working name: **Remainder explorer**. Standing preferences locked while charting:

- Scratchpad into the Word catalog, not a hand-built AlterEgo editor.
- Distinct toggle on Target show. Off = today’s filter-by-word. On = pin words against the Target Signature; words that no longer fit dim.
- Add word while on: new word must be a subset of Remainder, not necessarily the whole bag. Off: Add word stays unrestricted.
- Invented words are ordinary fun/ok catalog words. No new list type.
- Letter-only: do not infer remaining Pattern slots.
- Remainder shows as a stable alphabetical bag of leftover letters (repeats carry the counts).
- Any Word Match currently visible can be pinned if its Signature still fits Remainder. Existing used/deferred filters still hide rows.
- Add word input starts empty. Typed letters that fit leave the bag live; overflow blocks submit.

## Decisions so far

<!-- the index — one line per closed ticket: enough to judge relevance, then zoom the link for the detail the ticket holds -->

## Not yet specified

- Whether the Target display name should mark letters already spent by pins.
- Whether a dimmed word should explain why it no longer fits.
- Whether Add word should guess Token type from leftover length.
- Mode copy and toggle labels beyond a simple on/off.

## Out of scope

- Nearly as a bent-spelling flag (`is_nearly`).
- Hand-assembled AlterEgo as this mode’s output.
- Pattern-aware remaining-slot inference.
- Replacing filter-by-word clicks with Remainder pinning.
- Requiring the invented word to consume the entire Remainder.
- Changing Target search / DFS.
- `remainder.txt` leftover word-list files during catalog build.
