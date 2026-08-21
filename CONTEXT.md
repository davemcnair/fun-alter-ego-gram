# Fun Alter-Ego-Gram

A constrained anagram generator: a Target name is rewritten as AlterEgos by filling Patterns of Tokens whose letters match the Target's Signature.

## Language

**Target**:
A display name whose letters will be anagrammed.
_Avoid_: source, input name, search subject

**Signature**:
The letter-multiset identity of a string after transliteration to ASCII a–z (sorted letters plus per-letter counts). Target names and Token words share the same Signature.
_Avoid_: histogram, key, fingerprint

**Remainder**:
The leftover letter-multiset of a Target Signature after subtracting the Signatures of a chosen set of Token words.
_Avoid_: leftover letters (alone), unused letters, remainder.txt (leftover word-list files during catalog build)

**Token**:
A slot type in a name (title, forename, initials, prefix, surname, suffix, honorific).
_Avoid_: part, field, component

**Pattern**:
A template of Token slots that an AlterEgo must fill, e.g. `{title}{forename}{surname:2}`.
_Avoid_: template (alone), layout

**AlterEgo**:
The canonical display phrase for one exact filling of a Pattern: hyphenated forename/surname runs, prefix glued to the following surname.
_Avoid_: result, match, phrase (alone)

**Target search**:
Matching a Target's Signature to Token signatures, filling its Patterns, and producing AlterEgos.
_Avoid_: processTarget, fill pipeline, DFS pipeline

**Target progress**:
The current search state of a Target: Patterns filled or deferred, AlterEgos, and matched Token words.
_Avoid_: show DTO, progress payload

**Word catalog**:
The curated Token words (fun/ok/boring) and their deferral and commit state.
_Avoid_: word store, token word list

**Representative**:
The one non-deferred Token word for a Token Signature. When a fun word exists, it is the Representative.
_Avoid_: live word, selected anagram, primary word

**Pattern catalog**:
The generated Pattern templates, their popularity ranks, and the export of that table.
_Avoid_: pattern generation, pattern scorer
