# VectorService Implementation Plan

## Overview
VectorService handles vector operations for semantic search including text segmentation, embedding generation, Qdrant storage, and similarity search for the ReJoice application.

## Requirements (from documentation analysis)

### Core Functionality
- **Text Segmentation**: Break text into 300-word segments with 50-word overlap
- **Embedding Generation**: Use existing CustomGeminiEmbedder for 768-dimensional vectors
- **Vector Storage**: Store vectors in Qdrant with comprehensive metadata
- **Change Detection**: Re-embed when text changes >20% (Levenshtein similarity)
- **Semantic Search**: Query similar vectors and return relevant chunks/notes
- **Cleanup Operations**: Remove vectors when notes/chunks are deleted

### Integration Points (from existing codebase)
- **QdrantService**: Existing service for collection management and basic operations
- **CustomGeminiEmbedder**: Existing embedder for generating 768-dim vectors  
- **VectorEmbedding Model**: Database model for storing vector metadata
- **Chunk Model**: Source of text content to vectorize

### Vectorization Strategy (from mvp-decisions.md)
- **Granularity**: Audio-file level text, not individual chunks
- **Segmentation**: 300-word segments with 50-word overlaps if text > 300 words
- **Metadata**: Include note_id, audio_id, chunk_ids, source_text, created_at
- **Re-embedding**: Compare using Levenshtein similarity, re-embed if >20% difference

## Implementation Tasks

### 1. Create VectorService Class
**Location**: `app/Services/VectorService.php`

**Methods needed**:
- `vectorizeContent($noteId, $audioId, $text, $chunkIds = [])` - Main vectorization entry point
- `segmentText($text, $maxWords = 300, $overlapWords = 50)` - Break text into segments
- `generateEmbeddings($segments)` - Generate embeddings for text segments
- `storeVectors($vectors, $metadata)` - Store vectors in Qdrant with metadata
- `searchSimilar($query, $limit = 10, $threshold = 0.7)` - Semantic similarity search
- `shouldReembed($oldText, $newText, $threshold = 0.2)` - Change detection logic
- `deleteVectorsByNote($noteId)` - Cleanup vectors for deleted note
- `deleteVectorsByAudio($audioId)` - Cleanup vectors for deleted audio
- `getVectorStats()` - Statistics for monitoring and debugging

### 2. Text Segmentation Logic
**Requirements**:
- Split text into segments of ~300 words
- Overlap segments by ~50 words for context continuity
- Handle edge cases (short text, punctuation boundaries)
- Preserve sentence boundaries when possible

**Algorithm**:
```php
function segmentText($text, $maxWords = 300, $overlapWords = 50) {
    $words = explode(' ', $text);
    if (count($words) <= $maxWords) return [$text];
    
    $segments = [];
    $start = 0;
    
    while ($start < count($words)) {
        $end = min($start + $maxWords, count($words));
        $segment = implode(' ', array_slice($words, $start, $end - $start));
        $segments[] = $segment;
        
        if ($end >= count($words)) break;
        $start = $end - $overlapWords;
    }
    
    return $segments;
}
```

### 3. Change Detection Implementation
**Levenshtein Similarity Logic** (from mvp-decisions.md):
```php
function shouldReembed($oldText, $newText, $threshold = 0.2) {
    $distance = levenshtein($oldText, $newText);
    $maxLength = max(strlen($oldText), strlen($newText));
    $similarity = $distance / $maxLength;
    return $similarity > $threshold;
}
```

### 4. Qdrant Integration
**Vector Storage Structure**:
- Use existing QdrantService for low-level operations
- Store metadata payload as specified in data-schemas.md:
```json
{
  "note_id": "uuid-of-note",
  "audio_id": "uuid-of-audio-file", 
  "chunk_ids": ["uuid1", "uuid2"],
  "source_text": "Concatenated or segmented text",
  "created_at": "2025-07-31T12:00:00Z"
}
```

### 5. Database Integration
**VectorEmbedding Model Updates**:
- Create records for each vector stored in Qdrant
- Store qdrant_point_id for cross-reference
- Store text_hash for change detection
- Track source text and embedding model used

### 6. Search Implementation
**Semantic Search Flow**:
1. Generate embedding for search query
2. Query Qdrant for similar vectors
3. Filter results by score threshold
4. Return note/chunk information with relevance scores
5. Group results by note for better UX

### 7. Background Job Integration
**Create jobs for**:
- `VectorizeContentJob` - Handle vectorization in background
- `ReembedContentJob` - Handle re-embedding when text changes
- Integration with existing ProcessChunkWithAI job workflow

## Testing Strategy

### Unit Tests
- Text segmentation with various input sizes
- Change detection algorithm accuracy
- Embedding generation and validation
- Vector storage and retrieval
- Search ranking and filtering
- Cleanup operations

### Integration Tests
- Full vectorization workflow
- Search accuracy and relevance
- Database consistency
- Qdrant collection management
- Error handling and fallbacks

### Test Files Needed
- `tests/Unit/Services/VectorServiceTest.php`
- `tests/Unit/Jobs/VectorizeContentJobTest.php`
- `tests/Feature/VectorSearchTest.php`
- Sample text content for testing various scenarios

## Configuration Requirements

### Environment Variables
- Existing Qdrant configuration (LARQ_HOST, LARQ_API_KEY)
- Existing Gemini configuration (GEMINI_API_KEY, GEMINI_EMBEDDING_MODEL)
- Vector service specific config:
  - `VECTOR_SEGMENT_MAX_WORDS=300`
  - `VECTOR_SEGMENT_OVERLAP_WORDS=50`
  - `VECTOR_SIMILARITY_THRESHOLD=0.2`
  - `VECTOR_SEARCH_LIMIT=10`

### Collection Configuration
- Use existing `voice_notes_v1` collection
- Ensure 768-dimensional vector support
- Configure distance metric (cosine similarity recommended)

## Integration Requirements

### Model Relationships
- VectorEmbedding → Note (via note_id)
- VectorEmbedding → AudioFile (via audio_id)
- VectorEmbedding → Chunks (via chunk_ids array)

### Service Dependencies
- QdrantService: Low-level Qdrant operations
- CustomGeminiEmbedder: Embedding generation
- AIService: May trigger re-vectorization after AI processing

### Event Integration
- Note deletion → Delete all related vectors
- Audio deletion → Delete audio-specific vectors
- Chunk updates → Check for re-embedding need
- AI processing completion → Queue vectorization

## Performance Considerations
- **Batch Processing**: Process multiple segments efficiently
- **Caching**: Cache embeddings for identical text
- **Rate Limiting**: Respect Gemini API limits for embedding generation
- **Database Queries**: Optimize VectorEmbedding lookups
- **Memory Usage**: Handle large text content efficiently

## Error Handling
- **Embedding Failures**: Graceful fallback, retry logic
- **Qdrant Unavailable**: Queue for later processing
- **Text Too Large**: Implement chunking strategies
- **Invalid Input**: Validation and sanitization
- **Cleanup Failures**: Ensure consistency, manual cleanup tools

## Documentation Updates After Implementation

### Files to Update
1. **`rejoice_docs/CHANGELOG.md`** - Add VectorService implementation entry
2. **`rejoice_docs/api-endpoints.md`** - Verify search endpoint implementation
3. **`rejoice_docs/mvp-decisions.md`** - Confirm vectorization approach matches
4. **`rejoice_docs/developer-setup.md`** - Add any new configuration requirements

### New Documentation
- Document vector search API response format
- Update embedding model configuration
- Document cleanup and maintenance procedures

## Success Criteria
- [ ] VectorService class created with all required methods
- [ ] Text segmentation working correctly with overlap
- [ ] Embedding generation integrated with existing CustomGeminiEmbedder
- [ ] Vector storage in Qdrant with proper metadata
- [ ] Semantic search returning relevant results
- [ ] Change detection and re-embedding working
- [ ] Cleanup operations removing orphaned vectors
- [ ] All tests passing (unit, integration, feature)
- [ ] Documentation updated
- [ ] No linting errors
- [ ] Performance benchmarks acceptable

## Completion Definition
VectorService is complete when:
1. All success criteria met
2. Tests passing (unit + integration + feature)
3. Documentation updated
4. Integration with existing AudioFile/Chunk/Note models working
5. Search functionality returning accurate results
6. Background job system processing vectorization requests
7. Ready for API controller implementation in Phase 5

---

**Next**: After VectorService completion and this planning doc removal, continue with remaining background jobs and move to Phase 5 API controllers.