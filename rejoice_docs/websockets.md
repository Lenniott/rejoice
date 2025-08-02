# Real‑Time Updates – WebSockets Integration
## **Why WebSockets?**

ReJoIce is designed for **instant capture and seamless editing of voice notes**. Traditional REST polling would create delays and unnecessary load, especially when users expect immediate feedback after recording, AI processing, or semantic search updates.

WebSockets enable **two‑way, persistent communication** between client and server, allowing:
- **Instant note list refresh** (new notes appear without reload).
- **Real‑time AI processing updates** (e.g., “AI processing complete” notifications).
- **Immediate search results refresh** when vectorisation completes.

---

## **Chosen Approach**

- **Protocol:** WebSocket (via Laravel Echo + Pusher or Laravel WebSockets)
- **Backend:** Laravel Broadcasting API to publish events (e.g., NoteCreated, NoteUpdated)
- **Frontend:** React client subscribes via Echo to receive updates and trigger UI refresh. 

---

## **Integration Steps**

### **1.** ### **Install WebSocket Package**
Use [Laravel WebSockets](https://beyondco.de/docs/laravel-websockets/getting-started/installation) for local‑first architecture (no external service required):
```
composer require beyondcode/laravel-websockets
php artisan vendor:publish --provider="BeyondCode\LaravelWebSockets\WebSocketsServiceProvider"
php artisan migrate
```
---

### **2.** ### **Configure Broadcasting**
In .env:
```
BROADCAST_DRIVER=pusher
PUSHER_APP_ID=local
PUSHER_APP_KEY=local
PUSHER_APP_SECRET=local
PUSHER_HOST=127.0.0.1
PUSHER_PORT=6001
PUSHER_SCHEME=http
```
Update config/broadcasting.php:
- Set pusher connection to match .env variables.
- Ensure websockets driver is enabled.

---
### **3.** **Run WebSocket Server**
Add to start-dev.sh for automatic local start:
```
php artisan websockets:serve &
```
---
### **4.** **Broadcast Events**
Create events for key workflows:
- **NoteCreated** (triggered on new note creation)
- **ChunkUpdated** (triggered on AI completion or manual edits)
- **VectorizationCompleted** (triggered after re‑embedding)

Example event:
```
class NoteCreated implements ShouldBroadcast
{
    public $note;
    public function __construct($note)
    {
        $this->note = $note;
    }
    public function broadcastOn()
    {
        return new Channel('notes');
    }
}
```

---

### **5.** **Frontend Subscription (React)**
Use Laravel Echo in resources/js/bootstrap.js:
```
import Echo from 'laravel-echo';
window.Pusher = require('pusher-js');

window.Echo = new Echo({
    broadcaster: 'pusher',
    key: 'local',
    wsHost: window.location.hostname,
    wsPort: 6001,
    forceTLS: false,
    disableStats: true,
});

window.Echo.channel('notes')
    .listen('NoteCreated', (e) => {
        // Update note list in real-time
        refreshNotesUI(e.note);
    });
```

---

## **Security**
- Use **private channels** when multi‑user support is added (future proof).
- Currently, MVP is **single‑user local**; no external authentication needed.

---

## **Benefits**
- Aligns with ReJoIce’s **lossless + instant capture** principles.
- Reduces cognitive friction — no refreshes needed for state sync.
- Scalable for future multi‑user or collaborative features.
