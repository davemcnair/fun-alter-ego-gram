# Fun Alter-Ego-Gram: Technical Specification

## 1. Overview

Fun Alter-Ego-Gram is a pattern-driven constrained anagram generator that creates amusing alter egos from names. The system uses depth-first search (DFS) with multiple optimization strategies to efficiently explore a massive state space and generate funny, plausible fake names.

### Core Concept
Input: "David McNair" → Output: "Vicar Dan Dim", "Dr Adam Vinci", "Ivan Dim-Card"

## 2. Domain Model

### 2.1 Core Entities

#### Token
Represents a type of name component (e.g., forename, surname, title).

**Constants:**
- `TOKEN_NAME_TITLE` - Honorific titles (Dr, Mr, Ms, etc.)
- `TOKEN_NAME_FORENAME` - First names
- `TOKEN_NAME_INITIALS` - Middle initials (R., I.P., etc.)
- `TOKEN_NAME_PREFIX` - Name prefixes (Mc, de la, van, etc.)
- `TOKEN_NAME_SURNAME` - Last names (the primary source of humor)
- `TOKEN_NAME_SUFFIX` - Name suffixes (-tastic, -icious, etc.)
- `TOKEN_NAME_HONORIFIC` - Post-nominal titles (IV, OBE, Esq, etc.)

**Properties:**
- `name` - Token type name
- `is_curatable` - Whether words can be tagged as fun/boring/ok
- `is_genderable` - Whether gender filtering applies

#### Signature
Represents the normalized letter frequency histogram of a string.

**Purpose:** Enables fast anagram matching by comparing letter distributions.

**Properties:**
- `normalized_key` - Sorted string of letters (e.g., "aaddimnrv" for "David McNair")
- `letter_counts` - JSON histogram: `{"a":2, "d":2, "i":2, "m":1, "n":1, "r":1, "v":1}`

**Example:**
```
"David McNair" → normalized_key: "aaddimnrv"
"Vicar Dan Dim" → normalized_key: "aaddimnrv" (same signature = valid anagram)
```

#### TokenSignature
Links a Token to a Signature representing possible words for that token type.

**Properties:**
- `token_id` - Foreign key to Token
- `signature_id` - Foreign key to Signature
- `word_count` - Number of words with this signature (optimization)

#### TokenSignatureWord
An actual word that matches a token signature.

**Properties:**
- `token_signature_id` - Parent signature
- `word` - The actual word (e.g., "moist", "kinky", "admiral")
- `list_type` - Classification: `"fun"`, `"ok"`, `"boring"`
- `is_deferred` - display of non-fun words can be deferred, 
but at least 1 word per signature must be displayed 
- `is_nearly` - "Nearly words" (bent spellings, slang) - toggled on/off
- `committed_at` - Timestamp when word was committed to resource files

**Derived:**
- `is_promotable` - Computed: `list_type === 'ok' && token in [forename, surname]`

#### Pattern
A template for generating names.

**Properties:**
- `template` - Template string: `"{title}{forename}{surname:2}"`
- `popularity_rank` - Sort order (0 = most common/normal)
- `pattern_type` - Type classification: standard, exotic
- `min_total_length` - Minimum letters required
- `forename_count` - Number of forename slots
- `surname_count` - Number of surname slots
- `has_title`, `has_initials`, `has_prefix`, `has_suffix`, `has_honorific` - Boolean flags

**Pattern Syntax:**
- `{token}` - Single token slot
- `{token:N}` - N repeated slots (e.g., `{surname:2}` = two surnames)

**Examples:**
```
{forename}{surname}                          → "Dave Kinky"
{forename}{surname:2}                        → "Dave Kinky Boot"
{title}{forename}{surname}                   → "Dr Dave Kinky"
{title}{forename}{initials}{surname:2}       → "Dr Dave R. Kinky Boot"
{forename}{prefix}{surname:2}{suffix}        → "Dave Mc Kinky Boot-icious"
```

#### Target
A name to generate alter egos for.

**Properties:**
- `name` - Original name (e.g., "David McNair")
- `normalized_key` - Sorted letters for deduplication
- `signature_id` - Foreign key to Signature
- `status` - `TargetStatus` enum: `filterable`, `fillable`, `processing`, `processed`
- `matches_seen_at` - When user last viewed new matches
- `last_processed_matches_at` - Processing watermark
- `filled_matches_count` - Number of matching words found
- `new_matches_count` - Number of new words since last view

**Status Flow:**
```
filterable → fillable → processing → processed
    ↓            ↓            ↓            ↓
 (new)      (words found) (searching) (complete)
```

#### TargetPattern
Links a Target to a Pattern for processing.

**Properties:**
- `target_id`, `pattern_id` - Relationship keys
- `popularity_rank` - Inherited from Pattern
- `status` - `TargetPatternStatus` enum: `pending`, `processing`, `completed`, `deferred`
- `started_at`, `finished_at`, `elapsed_ms` - Timing metrics

#### TargetTokenSignature
Matches between target letters and available token signatures.

**Purpose:** Pre-computed viable word pools for DFS.

**Properties:**
- `target_id` - Target being processed
- `token_signature_id` - Matching signature
- `usedInPattern` - Whether signature was used in any completed pattern

#### TargetTokenSignatureWord
Specific words available for a target.

**Purpose:** Tracks which words match and whether they've been used.

**Properties:**
- `target_id` - Target being processed
- `token_signature_word_id` - Available word
- `usedInPhrase` - Whether word appears in any generated alter ego

#### TargetSignaturedPattern
A unique combination of token signatures that fills a pattern exactly.

**Purpose:** Represents one way to fill all slots in a pattern using specific signatures.

**Properties:**
- `target_pattern_id` - Pattern being filled
- Created implicitly during DFS when exact letter coverage achieved

**Relationships:**
- Many-to-many with `TargetTokenSignature` via pivot table
- Pivot includes `position` field to maintain slot order

#### AlterEgo
A generated funny name phrase.

**Properties:**
- `target_signatured_pattern_id` - Parent signatured pattern
- `phrase` - Generated name (e.g., "Dr Adam Vinci")
- `starred` - User-marked favorite
- `isFun` - Contains any "fun" words
- `hasBoring` - Contains any "boring" words
- `hasDeferred` - Contains any "nearly" words

**Relationships:**
- Many-to-many

#### AlterEgoGroup
For related alter-egos - could be woven into bios, illustrations.
- todo: type of relationship?
- todo: friend group?

**Properties:**
- `type` - could be intra-Target, inter-Target
**Relationships:**
- One-to-many

#### GroupedAlterEgo
Related alter-egos - could be woven into bios, illustrations.

**Properties:**
- `alter_ego_group_id` 
- `alter_ego_id` 
**Relationships:**
- Many-to-many

### 2.2 Entity Relationships

```
Token (1) ←→ (∞) TokenSignature (∞) ←→ (1) Signature
                        ↓
                  TokenSignatureWord

Target (1) ←→ (1) Signature
  ↓
TargetPattern (∞) ←→ (1) Pattern
  ↓
TargetSignaturedPattern
  ↓
AlterEgo

Target (1) ←→ (∞) TargetTokenSignature (∞) ←→ (1) TokenSignature
Target (1) ←→ (∞) TargetTokenSignatureWord (∞) ←→ (1) TokenSignatureWord

TargetSignaturedPattern (∞) ←→ (∞) TargetTokenSignature [pivot: position]
AlterEgo (∞) ←→ (∞) TargetTokenSignatureWord
```

## 3. Core Algorithms

### 3.1 Signature Generation

**Purpose:** Create normalized letter histograms for anagram matching.

**Algorithm:**
```php
function generateSignature(string $text): array
{
    // 1. Normalize: lowercase, remove non-letters
    $normalized = preg_replace('/[^a-z]/i', '', strtolower($text));

    // 2. Sort letters alphabetically
    $sorted = str_split($normalized);
    sort($sorted);
    $normalizedKey = implode('', $sorted);

    // 3. Count letter frequencies
    $letterCounts = array_count_values(str_split($normalized));

    return [
        'normalized_key' => $normalizedKey,
        'letter_counts' => $letterCounts
    ];
}
```

**Example:**
```
Input: "David McNair"
Output: {
    normalized_key: "aaddimnrv",
    letter_counts: {a:2, d:2, i:2, m:1, n:1, r:1, v:1}
}
```

### 3.2 Pattern Matching (DFS)

**Purpose:** Find all valid ways to fill pattern slots with token signatures that exactly consume target letters.

**Service:** `DfsService`

**Core Algorithm:**
**Key Optimizations:**

- Given pattern positions and available signatures per position
- Recursively fills each position while maintaining letter count exactness
- Prunes branches that can't complete to valid anagrams
- Current performance: handles ~18 char names, times out on longer

### 3.3 Phrase Building

**Purpose:** Convert token signature IDs to actual words and format as a proper name.

**Service:** `PhraseBuilderService`

**Algorithm:**
```php
function buildPhrase(array $tokenSignatureIds): string
{
    $words = [];
    foreach ($tokenSignatureIds as $position => $id) {
        $word = TokenSignatureWord::find($id)->word;
        $words[$position] = $word;
    }

    // Apply formatting rules
    // - Capitalize appropriately
    // - Insert hyphens for suffixes
    // - Insert spaces/periods for initials
    // - Handle prefixes (de la, van, etc.)

    return formatName($words);
}
```

## 4. Architecture

### 4.1 Technology Stack

**Backend:**
- Laravel 12 (PHP 8.2+)
- SQLite database
- Queue system for async processing

**Frontend:**
- Vue 3
- Tailwind CSS
- Vite build system
- Nuxt (planned)

**Key Packages:**
- `spatie/laravel-data` - DTOs
- `staudenmeir/eloquent-has-many-deep` - Deep relationships

### 4.2 Application Layers

```
┌─────────────────────────────────────┐
│         Presentation Layer          │
│  (Controllers, Views, API Routes)   │
└─────────────────────────────────────┘
              ↓
┌─────────────────────────────────────┐
│         Application Layer           │
│  (Services, Jobs, DTOs)             │
└─────────────────────────────────────┘
              ↓
┌─────────────────────────────────────┐
│           Domain Layer              │
│  (Models, Enums, Traits)            │
└─────────────────────────────────────┘
              ↓
┌─────────────────────────────────────┐
│       Infrastructure Layer          │
│  (Database, Cache, Storage)         │
└─────────────────────────────────────┘
```

### 4.3 Key Services

#### TargetService
Orchestrates target creation and lifecycle management.

**Responsibilities:**
- Create targets from names
- Generate signatures
- Match token signatures
- Match token signature words
- Update target status

#### DfsService
Executes depth-first search to find valid token signature combinations.

**Responsibilities:**
- Recursive pattern slot filling
- Viability pruning
- Histogram-based filtering

#### SignatureFillService
Finds all viable token signatures for a target.

**Responsibilities:**
- Query token signatures that fit within target letters
- Pre-compute letter count histograms
- Group candidates by token type

#### ExpandSignaturedPatternService
Expands signatured patterns into actual alter ego phrases.

**Responsibilities:**
- Select words from token signatures
- Apply fun-first ordering
- Generate permutations
- Create AlterEgo records

#### PhraseBuilderService
Converts word arrays into formatted name strings.

**Responsibilities:**
- Apply capitalization rules
- Insert punctuation (hyphens, periods)
- Handle prefix/suffix formatting

#### PatternGenerationService
Generates all viable patterns from a template superset.

**Responsibilities:**
- Create pattern permutations
- Filter by length constraints
- Calculate popularity rank

#### WordStoreService / WordCommitService
Manages word resources and persistence.

**Responsibilities:**
- Import words from resource files
- Update word classifications
- Commit changes back to resource files
- Create backups and changelogs

#### ScorePatternService
Ranks patterns by "normalcy" (commonness).

**Responsibilities:**
- Assign popularity ranks
- Define pattern ordering strategy

### 4.4 Data Flow

#### Target Creation Flow
```
1. User submits name
2. TargetController → TargetService.create()
3. Generate signature
4. Match token signatures (SignatureFillService)
5. Match token signature words (WordMatchService)
6. Update target status → filterable
7. Queue pattern processing jobs
```

#### Pattern Search Flow
```
1. User triggers pattern search
2. TargetController → searchTargetPattern()
3. Get target patterns by popularity rank
4. For each pending pattern:
   a. DfsService.dfs() → yields signatured patterns
   b. ExpandSignaturedPatternService → creates AlterEgos
   c. Update TargetPattern status
5. Return progress/results
```

#### Word Curation Flow
```
1. User views target results
2. User promotes/demotes words (fun ↔ ok ↔ boring)
3. WordController → WordService.updateListType()
4. Mark word as uncommitted (committed_at = null)
5. Regenerate affected patterns (if requested)
```

#### Resource Commit Flow
```
1. User clicks "Commit Resources"
2. WordController → WordCommitService.commit()
3. Backup current resource files (zip)
4. Update changelog.txt with changes
5. Merge uncommitted words to resource files
6. Mark words as committed
```

## 5. Resource Files

### 5.1 Token Word Files

**Location:** `resources/token_words/`

**Structure:**
- todo: gendered titles, suffixes
```
token_words/
  ├── title/
  │   └── ok.txt
  ├── forename/
  │   ├── fun_boys.txt
  │   ├── fun_girls.txt
  │   ├── fun_both.txt
  │   ├── ok_boys.txt
  │   ├── ok_girls.txt
  │   ├── ok_both.txt
  │   ├── boring_boys.txt
  │   ├── boring_girls.txt
  │   └── boring_both.txt
  ├── initials/
  │   └── ok.txt
  ├── prefix/
  │   └── ok.txt
  ├── surname/
  │   ├── fun.txt
  │   ├── ok.txt
  │   └── boring.txt
  ├── suffix/
  │   └── ok.txt
  └── honorific/
      └── ok.txt
```

**File Format:**
- One word per line
- UTF-8 encoding
- No metadata (filename determines token + list type + gender)

### 5.2 Pattern Files

**Location:** `resources/patterns/`

**Structure:**
```
patterns/
  └── templates.txt
```

**Format:**
```
{forename}{surname}
{forename}{surname:2}
{title}{forename}{surname}
{title}{forename}{surname:2}
{title}{forename}{initials}{surname:2}
...
```

## 6. API Endpoints

### 6.1 Target Management

```
POST   /targets                  - Create target
GET    /targets                  - List targets
GET    /targets/{id}             - Show target details
DELETE /targets/{id}             - Delete target
POST   /targets/bulk-destroy     - Delete multiple targets
```

### 6.2 Target Processing

```
POST   /api/targets/{id}/process-new-matches  - Reprocess patterns
GET    /api/targets/{id}/progress             - Get processing status
GET    /api/targets/{id}/new-matches          - Get new word matches
POST   /api/targets/{id}/mark-matches-seen    - Mark matches as viewed
```

### 6.3 AlterEgo Management

```
POST   /api/targets/{id}/star      - Star an alter ego
POST   /api/targets/{id}/unstar    - Unstar an alter ego
POST   /api/targets/{id}/rephrase  - Regenerate phrase formatting
```

### 6.4 Word Curation

```
GET    /words                          - List words
POST   /words                          - Add word
PUT    /words/{id}                     - Update word
DELETE /words/{id}                     - Delete word
POST   /words/{id}/promote             - Promote word (ok → fun)
POST   /words/commit-resources         - Commit changes to files
```

### 6.5 Pattern Management

```
GET    /patterns               - List patterns
POST   /patterns               - Create pattern
PUT    /patterns/{id}          - Update pattern
DELETE /patterns/{id}          - Delete pattern
POST   /patterns/reorder       - Update popularity ranks
POST   /patterns/export        - Export patterns
```

## 7. Current Limitations & Known Issues

### 7.1 Performance
- **Long targets timeout:** Targets with >18 letters may timeout during processing
- **Solution planned:** Implement resumable search state, more workers, webhook notifications

### 7.2 Word Curation
- **UI not wired:** Word curation interface exists but not fully integrated
- **Manual updates required:** Resource file updates currently require manual merge

### 7.3 Services Not Implemented
- **FunninessService:** AI-powered humor scoring
- **NearlyService:** Pluggable "bent spelling" expansions
- **DissonanceService:** Detect phonetic clashes
- **GenderInconsistencyService:** Flag gendered word mismatches
- **BioService:** AI generated/user polished character bios and avatar image prompt
- **AvatarService:** AI generated character illustrations

### 7.4 Data Quality
- **Resource lists only part curated:** Some fun, boring words in "ok" lists
- **Duplicate handling:** Order duplicates for multiple tokens not eliminated
- **Missing adjacency tests:** No validation of word combinations

## 8. Future Enhancements

### 8.1 Pluggable Expansions

**Nearly Words Rules:**
- Apostrophes: `king` → `kin'g`
- Double consonants: `horace` → `horrace`
- Yoof speak: `er` → `a`, `th` → `f`
- Regional dialects: Scottish (`n't` → `ae`)

### 8.2 Multi-Language Support
- Scottish, German, French, Italian, Spanish
- Franglais (French-English hybrids)
- Pluggable language expansion modules

### 8.3 Themes & Tagging
- Pet names
- Risqué words
- Hilarious combinations
- User-defined themes

### 8.4 Social Features
- Friends/sharing
  with `TargetTokenSignatureWord` tracking constituent words

### 8.5 MCP Exposure
Expose functionality via Model Context Protocol:
- Resources (word lists, patterns)
- AlterEgo generation API

## 9. Testing Strategy

### 9.1 Unit Tests
- Signature generation
- Histogram operations
- Pattern parsing
- Phrase formatting
- Word classification logic

### 9.2 Integration Tests
- DFS algorithm correctness
- Target creation flow
- Pattern processing pipeline
- Word curation workflow
- Resource commit process

### 9.3 Test Data
- Sample names with known anagram results
- Edge cases: short names, long names, special characters
- Performance benchmarks: measure DFS iterations

## 10. Glossary

- **Alter Ego:** A generated funny anagram name
- **Signature:** Letter frequency histogram enabling anagram matching
- **Token:** Name component type (forename, surname, etc.)
- **Pattern:** Template defining name structure
- **Signatured Pattern:** Pattern with specific token signatures assigned
- **DFS:** Depth-first search algorithm
- **Viability Check:** Histogram intersection pruning
- **Nearly Word:** Bent spelling or slang variant
- **Curatable:** Can be classified as fun/ok/boring
- **Genderable:** Can be filtered by gender
- **Deferred:** Not displayed by default 
- **Committed:** Word persisted to resource files
- **Promotable:** Curatable ok word
