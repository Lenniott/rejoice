# ReJoIce Laravel Project Setup Plan

## Overview
Setting up the ReJoIce AI Voice Note App based on the PRD and documentation. This is a comprehensive Laravel 12 + React + PostgreSQL + Qdrant vector database application with Docker containerization.

## Current State Analysis
- **Current Directory**: Documentation folder with comprehensive specs
- **Target**: Create full Laravel application with all components
- **No Existing Code**: Starting from scratch based on detailed documentation

## Architecture Summary
- **Backend**: Laravel 12 with Breeze API authentication
- **Frontend**: React + TailwindCSS + Vite (via Breeze)
- **Databases**: PostgreSQL (structured data) + Qdrant (vector search)
- **AI Integration**: Google Gemini 2.5 Flash for transcription and embeddings
- **Deployment**: Docker Compose with persistent volumes
- **Data Flow**: Lossless audio capture → dictation → AI enhancement → vectorization → semantic search

## Setup Plan

### Phase 1: Core Laravel Setup ✅ **COMPLETE**
1. ✅ **Create Laravel 12 project** in new `rejoice/` directory
2. ✅ **Install and configure Laravel Breeze** with React API mode  
3. ✅ **Set up basic project structure** following project-organisation.md
4. ✅ **Configure environment files** (.env setup for all services)

**Status**: Fully functional Laravel Breeze React authentication platform
- 🟢 Authentication system (Login, Register, Password Reset)
- 🟢 React frontend with Inertia.js + Tailwind CSS
- 🟢 Development servers configured (Laravel:8080, Vite:3456)  
- 🟢 Single-command startup script (`./start-dev.sh`)
- 🟢 No security vulnerabilities
- 🟢 Ready for Phase 2 development

### Phase 2: Database and Models
1. **Create database migrations** for:
   - Notes table (id, title, timestamps)
   - AudioFiles table (id, note_id, path, timestamps)
   - Chunks table (id, note_id, audio_id, dictation_text, ai_text, edited_text, active_version)
2. **Create Eloquent models** with proper relationships
3. **Set up PostgreSQL connection** in Laravel config

### Phase 3: Vector Database Integration
1. **Install Laravel Qdrant SDK** (wontonee/laravel-qdrant-sdk)
2. **Configure Qdrant connection** and service classes
3. **Create VectorService** for embedding management
4. **Set up embedding metadata structure** as per mvp-decisions.md

### Phase 4: Core Services
1. **AudioService**: File storage, path management, cleanup
2. **AIService**: Gemini integration for transcription and embeddings
3. **VectorService**: Qdrant operations (insert, delete, search)
4. **Implement background jobs** for AI processing and vectorization

### Phase 5: API Controllers
1. **NotesController**: CRUD operations with cascade delete
2. **AudioController**: Upload, storage, chunk creation
3. **ChunksController**: Editing, AI processing
4. **SearchController**: Semantic search via Qdrant
5. **VectorizationController**: Manual re-embedding triggers

### Phase 6: Frontend React Components
1. **Install and configure React** via Breeze
2. **Create base components**: NoteList, NoteEditor, SearchScreen
3. **Implement TextEditor** for chunk editing
4. **Add audio recording** and playback functionality
5. **Create API hooks** for Laravel backend integration

### Phase 7: Docker and Deployment
1. **Create Dockerfile** for Laravel app
2. **Set up docker-compose.yml** with:
   - Laravel app container
   - PostgreSQL container with persistent volume
   - Qdrant container with persistent volume
3. **Configure container networking** and environment variables
4. **Test full stack** deployment

### Phase 8: Testing and Validation
1. **Create PHPUnit tests** for critical paths:
   - CRUD operations
   - Delete cascade functionality
   - Vector search integration
   - Audio file management
2. **Test API endpoints** as per api-endpoints.md
3. **Validate end-to-end workflows** from documentation

### Phase 9: Documentation and Finalization
1. **Update CHANGELOG.md** with setup completion
2. **Create .env.example** with all required variables
3. **Validate against developer-setup.md** instructions
4. **Test full workflow**: record → dictation → AI → vectorization → search

## Technical Considerations

### File Structure (following project-organisation.md)
```
rejoice/
├── app/
│   ├── Http/Controllers/    # API endpoints
│   ├── Models/             # Note, Chunk, AudioFile
│   ├── Services/           # AI, Vector, Audio services
│   └── Jobs/               # Background processing
├── database/migrations/    # Schema definitions
├── resources/js/           # React frontend
├── storage/app/audio/      # Audio file storage
├── docker-compose.yml      # Container orchestration
└── Dockerfile             # App container definition
```

### Environment Variables Required
- Database: PostgreSQL connection
- Qdrant: Vector database connection
- Gemini: AI API key and model config
- Laravel: APP_KEY, authentication settings

### Critical Success Factors
1. **Lossless audio capture**: Always save audio even if other steps fail
2. **Proper cascade deletion**: Remove all related data (DB, files, vectors)
3. **Efficient vectorization**: 300-word segments with 50-word overlap
4. **API-first architecture**: Clean separation between Laravel API and React frontend

## Risk Mitigation
- **Service failures**: Graceful fallbacks for AI, dictation, and search
- **Data consistency**: Database transactions for complex operations
- **Storage management**: Proper cleanup of audio files and vectors
- **Performance**: Background jobs for heavy AI and vectorization tasks

## Success Criteria
- [ ] Laravel app runs successfully in Docker
- [ ] PostgreSQL database with proper migrations
- [ ] Qdrant vector database operational
- [ ] React frontend builds and serves
- [ ] All API endpoints functional as per api-endpoints.md
- [ ] Audio upload, storage, and playback working
- [ ] AI transcription and embedding pipeline operational
- [ ] Semantic search returning relevant results
- [ ] Full workflow: record → process → search works end-to-end

## Next Steps
Execute this plan systematically, validating each phase before proceeding to the next. Focus on getting core functionality working before optimizing performance or adding advanced features.