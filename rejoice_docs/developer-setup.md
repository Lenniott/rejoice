# Developer Setup Guide – ReJoIce

This guide walks through every step to get ReJoIce running locally—all backend, frontend, and vector search—using Docker, Laravel 12, Breeze with React, and Qdrant.

## 🎉 Current Status: READY TO USE!

Your Laravel Breeze React authentication platform is **fully working** and ready for development:

- ✅ **Authentication System**: Login, Register, Password Reset
- ✅ **React Frontend**: Dashboard, Profile, Welcome pages  
- ✅ **Development Servers**: Laravel (8080), Vite (3456)
- ✅ **No Port Conflicts**: Configured to avoid localhost:8000

**Quick Start**: Visit `http://localhost:8080` to see your working application!

---

## 1. Prerequisites

- Docker & Docker Compose
- PHP 8.2+
- Composer
- Node.js & npm (for frontend and asset builds)

---

## 2. Create Laravel Project

```bash
composer global require laravel/installer
laravel new rejoice
cd rejoice
````

Supports Laravel 12.x ([portable.io][1], [Qdrant][2], [laravel.com][3])

---

## 3. Install Breeze & React (API setup)

```bash
composer require laravel/breeze --dev
php artisan breeze:install react
```

This enables React with API-based authentication using Laravel Breeze ([Kinsta®][4])

* When prompted, choose “React with Inertia” or “API + React” stack
* Enables login, password reset, and API token auth

---

## 4. Install Dependencies & Build Assets

```bash
npm install
npm run dev
php artisan migrate
```

Laravel uses **Vite** (via Breeze) to compile React, Tailwind, JS/CSS assets ([laravel.com][5], [laravel.com][6])

---

## 5. Setup Qdrant Vector DB

**Docker run locally**:

```bash
docker run -d \
  --name qdrant \
  -p 6333:6333 \
  -v $(pwd)/qdrant_storage:/qdrant/storage \
  qdrant/qdrant
```

This starts Qdrant with persisted volume storage ([Qdrant][7])

---

## 6. Install Laravel Qdrant SDK

```bash
composer require wontonee/laravel-qdrant-sdk
php artisan vendor:publish --provider="Wontonee\LarQ\Providers\LarQServiceProvider" --tag=larq-config
```

Configures vector search with Gemini/openAI model support ([packagist.org][8])

Configure your `.env`:

```
LARQ_HOST=http://qdrant:6333
LARQ_API_KEY=
GEMINI_API_KEY=YOUR_KEY
GEMINI_MODEL=models/embedding-001
```

---

## 7. Docker Compose Setup (Laravel + Postgres + Qdrant)

Example `docker-compose.yml`:

```yaml
services:
  app:
    build: .
    ports: ["3456:3456"]
    volumes: [".:/var/www/html"]
  db:
    image: postgres:15
    environment:
      POSTGRES_DB: rejoice
      POSTGRES_USER: rejoice
      POSTGRES_PASSWORD: secret
    volumes:
      - postgres_data:/var/lib/postgresql/data
  qdrant:
    image: qdrant/qdrant
    ports: ["6333:6333"]
    volumes:
      - qdrant_storage:/qdrant/storage
volumes:
  postgres_data:
  qdrant_storage:
```

Start all services:

```bash
docker-compose up -d --build
```

---

## 8. Final Installation Steps

```bash
php artisan migrate
php artisan key:generate
npm run build
```

* Runs database migrations
* Generates application encryption key
* Builds production-ready assets into `public/`

---

## 9. Access the Authentication Platform

Laravel Breeze has created a complete authentication system with React frontend:

### **Available Pages:**
- **Landing Page**: `http://localhost:8080/` - Welcome page with login/register links
- **Login**: `http://localhost:8080/login` - User authentication
- **Register**: `http://localhost:8080/register` - New user registration  
- **Dashboard**: `http://localhost:8080/dashboard` - Main authenticated area
- **Profile**: `http://localhost:8080/profile` - User profile management

### **Authentication Flow:**
1. Visit `http://localhost:8080/`
2. Click "Login" or "Register" 
3. Create account or sign in
4. Access protected `/dashboard` and `/profile` areas
5. Full password reset flow available at `/forgot-password`

### **React Components Created:**
- **Auth Pages**: Login, Register, ForgotPassword, ResetPassword, VerifyEmail, ConfirmPassword
- **Platform Pages**: Welcome, Dashboard, Profile management
- **Layouts**: GuestLayout (public), AuthenticatedLayout (authenticated users)
- **UI Components**: Buttons, forms, navigation, modals

---

## 10. Development Workflow

### **🚀 Single Command Start (Recommended)**

```bash
./start-dev.sh
```

This script automatically starts all development services:
- ✅ Laravel backend on port 8080
- ✅ Vite frontend on port 3456  
- ✅ Handles cleanup when you stop (Ctrl+C)

### **Manual Start (Alternative)**

```bash
# Terminal 1: Start Laravel (backend)
php artisan serve --port=8080

# Terminal 2: Start Vite (frontend assets)
npm run dev

# Terminal 3: Start Qdrant (vector search) - Optional
docker run -d --name qdrant -p 6333:6333 qdrant/qdrant
```

**Ports Used:**
- Laravel App: `http://localhost:8080`
- Vite Dev Server: `http://localhost:3456` (automatic)
- Qdrant Vector DB: `http://localhost:6333` (incomplete)

---

## 11. API Endpoints (Optional)

For API development, test these endpoints:

* `GET /api/notes`
* `POST /api/notes`, `PATCH /api/notes/{id}`
* `POST /api/notes/{id}/audio`
* `POST /api/search/semantic`

Use Postman or browser to test API calls.

---

## 12. References & Docs

* Laravel 12 installation & starter kits ([laravel.com][3], [laravel.com][5])
* Laravel Breeze (React/API) setup guide ([Kinsta®][4])
* Qdrant local dev & Docker instructions ([Qdrant][7])
* Laravel Qdrant SDK integration ([packagist.org][8])

---

## Summary Checklist

| Step | Description                      | Status |
| ---- | -------------------------------- | ------ |
| ✔️   | Install Laravel & dependencies   | Complete |
| ✔️   | Add Breeze (React + Auth)        | Complete |
| ✔️   | Run migrations, generate app key | Complete |
| ✔️   | Build frontend assets            | Complete |
| ✔️   | **Access authentication pages**  | **Ready** |
| ✔️   | **Test login/register flow**     | **Ready** |
| ✔️   | **Access dashboard/profile**     | **Ready** |
| 🔄   | Launch Qdrant via Docker         | incomplete |
| 🔄   | Install and configure Qdrant SDK | incomplete |
| 🔄   | Start full Docker Compose stack  | incomplete |
| 🔄   | Test API endpoints               | incomplete |

### **🎉 Ready to Use:**
Your Laravel Breeze React authentication platform is fully working at `http://localhost:8080`

[1]: https://portable.io/connectors/laravel-forge/qdrant?utm_source=chatgpt.com "Integrate data from Laravel Forge and Qdrant - Portable"
[2]: https://qdrant.tech/documentation/tutorials/?utm_source=chatgpt.com "Database Tutorials - Qdrant"
[3]: https://laravel.com/docs/12.x/installation?utm_source=chatgpt.com "Installation - Laravel 12.x - The PHP Framework For Web Artisans"
[4]: https://kinsta.com/blog/laravel-breeze/?utm_source=chatgpt.com "Authentication in Laravel Using Breeze - Kinsta®"
[5]: https://laravel.com/docs/12.x/starter-kits?utm_source=chatgpt.com "Starter Kits - Laravel 12.x - The PHP Framework For Web Artisans"
[7]: https://qdrant.tech/?utm_source=chatgpt.com "Qdrant - Vector Database - Qdrant"
[8]: https://packagist.org/packages/wontonee/laravel-qdrant-sdk?utm_source=chatgpt.com "wontonee/laravel-qdrant-sdk - Packagist"
