#!/bin/bash

# Start ReJoIce Development Environment
echo "🚀 Starting ReJoIce Development Environment..."

# Function to cleanup background processes
cleanup() {
    echo "🛑 Stopping all services..."
    jobs -p | xargs kill 2>/dev/null
    exit 0
}

# Set trap to cleanup on script exit
trap cleanup SIGINT SIGTERM EXIT

# Start Laravel backend
echo "📱 Starting Laravel backend on port 8080..."
php artisan serve --port=8080 &

# Start Vite frontend
echo "⚡ Starting Vite frontend on port 3456..."
npm run dev &

# Optional: Start Qdrant (uncomment if needed)
# echo "🔍 Starting Qdrant vector search on port 6444..."
# docker run -d --name qdrant -p 6444:6333 qdrant/qdrant 2>/dev/null || echo "Qdrant already running"

echo ""
echo "✅ All services started!"
echo "🌐 Laravel App: http://localhost:8080"
echo "⚡ Vite Dev: http://localhost:3456 (automatic)"
echo ""
echo "Press Ctrl+C to stop all services"

# Wait for background processes
wait