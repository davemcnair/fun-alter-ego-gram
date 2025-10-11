# Fun Alter-Ego-Gram: High-Level Technical Overview

## Purpose & Core Concept

**Fun Alter-Ego-Gram** is a sophisticated pattern-driven constrained anagram generator that creates amusing alter egos from names. The system transforms input names like "David McNair" into funny anagrams like "Vicar Dan Dim", "Dr Adam Vinci", or "Ivan Dim-Card" using advanced algorithms and curated word databases.

### Key Innovation
The system uses **signature-based anagram matching** with **depth-first search (DFS)** to efficiently explore massive state spaces while prioritizing funny results through curated word classifications.

## Architecture Overview

### Technology Stack
- **Backend**: Laravel 12 (PHP 8.4+)
- **Database**: SQLite with optimized schema
- **Frontend**: Vue 3 + Tailwind CSS + Vite
- **Queue System**: Laravel queues for async processing
- **Key Packages**: 
  - `staudenmeir/eloquent-has-many-deep` for complex relationships
  - `laravel/prompts` for CLI interactions
  - `laravel/mcp` for Model Context Protocol integration

### Application Layers

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

## Core Domain Model

### Key Entities

#### 1. **Token** - Name Component Types
- **title**: Honorifics (Dr, Mr, Ms, etc.)
- **forename**: First names  
- **initials**: Middle initials (R., I.P., etc.)
- **prefix**: Name prefixes (Mc, de la, van, etc.)
- **surname**: Last names (primary source of humor)
- **suffix**: Name suffixes (-tastic, -icious, etc.)
- **honorific**: Post-nominal titles (IV, OBE, Esq, etc.)

#### 2. **Signature** - Anagram Matching Engine
- **Purpose**: Enables fast anagram matching via letter frequency histograms
- **Properties**: 
  - `signature`: Sorted string of letters (e.g., "aaddimnrv")
  - Individual letter counts (a_count, b_count, etc.)
- **Example**: "David McNair" → "aaddimnrv" = "Vicar Dan Dim"

#### 3. **Pattern** - Name Templates
- **Template Syntax**: `{title}{forename}{surname:2}` (colon indicates repetition)
- **Examples**:
  - `{forename}{surname}` → "Dave Kinky"
  - `{title}{forename}{surname:2}` → "Dr Dave Kinky Boot"
  - `{title}{forename}{initials}{surname:2}` → "Dr Dave R. Kinky Boot"

#### 4. **Target** - Input Names
- **Status Flow**: `filterable → fillable → processing → processed`
- **Properties**: Original name, signature, processing status, match counts

#### 5. **AlterEgo** - Generated Results
- **Properties**: Generated phrase, starred status, fun/boring flags
- **Classification**: Tracks whether results contain fun, boring, or "nearly" words

## Core Algorithms

### 1. Signature Generation
```php
// Normalizes and creates letter frequency histograms
"David McNair" → {
    signature: "aaddimnrv",
    letter_counts: {a:2, d:2, i:2, m:1, n:1, r:1, v:1}
}
```

### 2. Depth-First Search (DFS)
- **Purpose**: Find all valid token signature combinations that exactly consume target letters
- **Optimizations**:
  - Histogram intersection pruning
  - Viability checks at each step
  - Pre-computed letter pools for remaining tokens
- **Performance**: Handles ~18 character names, times out on longer inputs

### 3. Pattern Matching
- **Process**: Recursively fills pattern slots while maintaining exact letter coverage
- **Pruning**: Eliminates branches that can't complete to valid anagrams
- **Output**: Yields signatured patterns (exact token signature combinations)

## Key Services

### **TargetService** - Orchestration
- Creates targets from names
- Generates signatures and matches token signatures
- Manages target lifecycle and status updates

### **DfsService** - Core Algorithm
- Executes depth-first search for valid combinations
- Implements histogram-based filtering and pruning
- Yields signatured patterns when exact coverage achieved

### **PhraseBuilderService** - Formatting
- Converts word arrays to formatted name strings
- Handles capitalization, punctuation, and hyphenation
- Manages prefix/suffix formatting rules

### **SignatureFillService** - Pre-computation
- Finds viable token signatures for targets
- Pre-computes letter count histograms
- Groups candidates by token type for DFS

### **ExpandSignaturedPatternService** - Result Generation
- Expands signatured patterns into actual alter ego phrases
- Applies fun-first ordering
- Creates AlterEgo records with proper classification

## Data Flow

### Target Creation Flow
```
1. User submits name → TargetService.create()
2. Generate signature → Match token signatures
3. Match token signature words → Update status to filterable
4. Queue pattern processing jobs
```

### Pattern Search Flow
```
1. User triggers search → Get patterns by popularity rank
2. For each pattern: DfsService.dfs() → yields signatured patterns
3. ExpandSignaturedPatternService → creates AlterEgos
4. Update pattern status and return results
```

### Word Curation Flow
```
1. User views results → Promotes/demotes words (fun ↔ ok ↔ boring)
2. Mark words as uncommitted → Regenerate affected patterns
3. Commit changes to resource files with backup
```

## Resource Management

### Word Database Structure
```
resources/token_words/
├── forename/
│   ├── fun_boys.txt, fun_girls.txt, fun_both.txt
│   ├── ok_boys.txt, ok_girls.txt, ok_both.txt
│   └── boring_boys.txt, boring_girls.txt, boring_both.txt
├── surname/
│   ├── fun.txt, ok.txt, boring.txt
└── [other token types]/
    └── ok.txt
```

### Pattern Templates
```
resources/patterns/templates.txt
{forename}{surname}
{forename}{surname:2}
{title}{forename}{surname}
{title}{forename}{surname:2}
{title}{forename}{initials}{surname:2}
```

## API Architecture

### Core Endpoints
- **Target Management**: CRUD operations, bulk operations
- **Pattern Processing**: Search, progress tracking, reprocessing
- **Word Curation**: Add, update, promote words, commit resources
- **AlterEgo Management**: Star/unstar, rephrase, view results

### Key Features
- **Async Processing**: Queue-based pattern searching
- **Progress Tracking**: Real-time status updates
- **Word Curation**: Interactive word classification
- **Resource Management**: File-based word storage with commit workflow

## Custom Architecture Patterns

### 1. **Signature-Based Anagram Matching**
- Uses letter frequency histograms instead of character-by-character comparison
- Enables fast anagram detection across large word databases
- Supports efficient pruning during DFS traversal

### 2. **Pattern-Driven Generation**
- Template-based name generation with slot repetition support
- Popularity-ranked pattern ordering for progressive search
- Length-based filtering to prevent impossible combinations

### 3. **Curatable Word Classification**
- Three-tier system: fun, ok, boring
- Gender-aware filtering for forenames
- "Nearly words" support for creative spellings
- Deferred display for non-fun words

### 4. **Resumable Search State**
- Queue-based processing with job batching
- Progress watermarks for long-running searches
- Status tracking across target lifecycle

### 5. **Resource File Management**
- File-based word storage with structured directories
- Commit workflow with backup and changelog
- Uncommitted word tracking for curation

## Performance Characteristics

### Current Limitations
- **Long targets timeout**: >18 letters may timeout during processing
- **Memory usage**: Large signature databases require optimization
- **Search complexity**: Exponential growth with pattern complexity

### Optimization Strategies
- **Histogram pruning**: Early elimination of impossible branches
- **Pre-computed pools**: Cached letter count data
- **Progressive search**: Start with common patterns, expand to exotic
- **Async processing**: Queue-based pattern searching

## Future Enhancements

### Planned Features
- **Resumable search state**: Handle longer names through checkpointing
- **AI-powered humor scoring**: Machine learning for funniness detection
- **Multi-language support**: Pluggable language expansion modules
- **Social features**: Sharing and collaborative curation
- **MCP integration**: Model Context Protocol exposure
