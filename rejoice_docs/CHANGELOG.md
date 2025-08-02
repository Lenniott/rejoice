# Changelog

All notable changes to this project will be documented in this file.

## [2025-08-01] - SQLite Database Implementation Complete

### Added
- **SQLite Database Implementation**: Complete database setup with SQLite instead of PostgreSQL
  - Created 5 comprehensive database migrations with UUID primary keys and proper foreign key constraints
  - Implemented User, Note, AudioFile, Chunk, and VectorEmbedding models with full relationships
  - Added UUID auto-generation for all models with proper Laravel Eloquent configuration
  - Created comprehensive test suite with 10 database tests covering all CRUD operations and relationships
- **Database Testing Suite**: Added `tests/Feature/DatabaseTest.php` with comprehensive tests for:
  - Database connection verification
  - UUID functionality for all models  
  - Model relationships and foreign key constraints
  - Cascade delete functionality
  - Text version management in chunks
  - Vector embedding change detection
- **Model Documentation**: Added detailed plain English documentation to all model files explaining requirements and data flow

### Changed
- **Database System**: Switched from PostgreSQL to SQLite for simplified local development
- **Documentation Updates**: Updated `developer-setup.md` and `data-schemas.md` to reflect SQLite usage
- **Environment Configuration**: Updated `.env` configuration for SQLite database setup
- **User Model**: Updated to use UUIDs instead of auto-incrementing integers

### Fixed
- Database migrations now properly support SQLite with correct foreign key constraints
- All authentication tests pass with UUID implementation

### Technical Details
- Database: SQLite with in-memory testing
- Primary Keys: UUIDs for all custom models
- Foreign Keys: Proper cascade delete relationships
- Tests: 35 total tests passing (127 assertions)  
- Models: User, Note, AudioFile, Chunk, VectorEmbedding with full relationships
- Migrations: 8 total migrations (5 custom + 3 Laravel defaults)
- **Docker**: Complete containerization with Laravel + Qdrant + Nginx
- **Vector Search**: Qdrant integration with wontonee/laravel-qdrant-sdk
- **AI Ready**: Supports Gemini and OpenAI embeddings (API keys required)

## [2025-07-31] - Laravel Breeze React Setup Complete

### Fixed
- **Missing package.json**: Laravel Breeze React installation was incomplete and failed to create the package.json file
- **Node dependencies**: Created package.json with proper Laravel Breeze React dependencies including:
  - @vitejs/plugin-react for React support
  - @inertiajs/react for Inertia.js React adapter
  - @headlessui/react for accessible UI components
  - @tailwindcss/forms for form styling
  - React 18.2.0 and React DOM
  - Vite 5.0 for build tooling
  - Laravel Vite plugin for Laravel integration
- **Security vulnerabilities**: Resolved 2 moderate security vulnerabilities in esbuild and vite
- **Missing bootstrap.js**: Created missing bootstrap.js file with axios configuration and CSRF token handling
- **Peer dependency warnings**: Updated laravel-vite-plugin from 1.3.0 to 2.0.0 to support Vite 7.x

### Updated
- **Vite**: Upgraded from 5.0 to 7.0.6 to address security vulnerabilities
- **Laravel Vite Plugin**: Updated to 2.0.0 for Vite 7.x compatibility

### Added
- **Custom ports**: Configured Vite development server to use port 3456 and Laravel app to use port 8080 to avoid conflicts with existing services on port 8000

### Root Cause
- The `php artisan breeze:install react` command successfully created React scaffold files but failed during the npm dependency installation phase due to missing package.json
- This left the project in a partially configured state with React components but no way to build them
- Additional missing files (bootstrap.js) were also not created during the incomplete installation

### Resolution
- Manually created package.json with standard Laravel Breeze React dependencies
- Successfully ran `npm install` to install all required Node.js packages
- Applied security updates using `npm audit fix --force`
- Updated laravel-vite-plugin to maintain compatibility with Vite 7.x
- Created missing bootstrap.js file for axios setup
- Configured Vite to use port 3456 for development server
- Created .env file and set APP_URL to http://localhost:8080
- Generated application encryption key
- Updated developer-setup.md to accurately reflect current authentication platform and available pages
- Created database-schema.dbml file with complete DBML representation of proposed database schema
- Updated data-schemas.md.md to include users table and reference DBML file
- Verified build process works correctly with `npm run build`
- Project now has complete React + Inertia.js + Tailwind setup ready for development

### Verification
- ✅ All security vulnerabilities resolved (0 vulnerabilities found)
- ✅ No peer dependency warnings
- ✅ Build process completes successfully
- ✅ Vite development server runs on port 3456
- ✅ Laravel application runs on port 8080
- ✅ All Laravel Breeze React components generated properly