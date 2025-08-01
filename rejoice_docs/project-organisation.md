
# Project Organisation – ReJoIce (AI Voice Note App)

## 1. Overview

ReJoIce is structured as a **monorepo** using Laravel for backend and API, with React (via Laravel Breeze API) for the frontend. Vite handles frontend builds. Docker Compose orchestrates the app, Postgres, and Qdrant.

---

## 2. Directory Structure
/rejoice
├── app/                 # Laravel backend (controllers, models, services)
│   ├── Http/Controllers # API endpoints
│   ├── Models           # Eloquent models (Note, Chunk, AudioFile)
│   ├── Services         # AI, vectorization, audio services
│   └── Jobs             # Background jobs (AI processing, embeddings)
│
├── database/
│   ├── migrations       # DB schema migrations
│   └── seeders          # Seed data (optional)
│
├── resources/
│   └── js/              # React frontend (Breeze API mode)
│       ├── components   # UI components (text editor, buttons, etc.)
│       ├── pages        # Screens (Note List, Note Editor, Search)
│       ├── hooks        # Custom React hooks (state, API calls)
│       └── utils        # Helpers (diff check, embedding batching)
│
├── storage/             # Audio file storage
│   └── app/audio        # {note_id}/{uuid}.webm
│
├── tests/               # PHPUnit tests (API, delete cascades)
├── docker-compose.yml
├── Dockerfile
└── .env.example
---

## 3. Key Components

- **Models**  
  - `Note`: Top-level container for content  
  - `Chunk`: Editable text blocks (dictation, AI, edited)  
  - `AudioFile`: Raw recordings linked to notes/chunks  

- **Services**  
  - `AIService`: Calls Gemini for refinement & embeddings  
  - `VectorService`: Handles Qdrant operations (insert, delete, search)  
  - `AudioService`: Manages storage, pathing, and cleanup  

- **Frontend (React)**  
  - `TextEditor`: Block-based editor for chunks  
  - `NoteList`, `NoteEditor`, `SearchScreen`: Main pages  
  - `useApi`: Hook for calling Laravel API endpoints

---

## 4. Developer Setup

### Prerequisites
- Docker + Docker Compose
- Node.js (for local development)
- PHP 8.2+ & Composer

### Setup Steps
1. Clone repo:
   ```bash
   git clone https://github.com/your-org/rejoice.git
   cd rejoice


	2.	Copy .env.example to .env and set:
	3.	Start containers:
	4.	Run migrations:
	5.	Install Breeze (API mode) + React:
⸻

5. Feature Development Workflow
	1.	Add DB changes → Create Laravel migration
	2.	Add API endpoint → Controller + route in Laravel
	3.	Implement service logic (AI/vector/audio)
	4.	Expose via React hooks/components
	5.	Write tests (unit + integration)
	6.	Commit & run containers → docker-compose up --build

⸻

6. Deployment
	•	Production Build
	•	Run npm run build inside container → assets compiled to public/
	•	Deploy via single Docker image (Laravel + compiled React)
	•	Persistent Data
	•	Postgres and Qdrant volumes must be mounted for data survival

⸻

7. Documentation
	•	/docs contains:
	•	mvp-decisions.md
	•	risk-mitigation.md
	•	workflows-and-screens.md
	•	prd.md
	•	project-organisation.md

⸻

8. Onboarding Checklist
	•	Install Docker
	•	Configure .env
	•	Run docker-compose up
	•	Confirm endpoints via Postman:
	•	GET /api/notes
	•	POST /api/search/semantic
	•	Run initial tests: php artisan test◊◊