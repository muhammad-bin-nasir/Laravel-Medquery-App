# Chat API Laravel Port (from Project)

This app ports the `Project/app/api/routes/chat.py` API surface into Laravel.

## What Was Ported

- Chat endpoints under `/api/chat/*`
- JWT bearer auth middleware and login endpoints under `/api/auth/*`
- Project-aligned tables:
	- `users`
	- `businesses`
	- `workspaces`
	- `workspace_config`
	- `documents`
	- `document_chunks`
	- `system_config`
	- `chat_headers`
	- `chat_requests`
	- `chat_responses`

## Environment

Set these in `.env`:

```dotenv
DB_CONNECTION=mariadb
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=test_app
DB_USERNAME=root
DB_PASSWORD=

SESSION_DRIVER=database
JWT_SECRET_KEY=your-secret
JWT_ACCESS_TOKEN_EXPIRE_MINUTES=60
OPENAI_BASE_URL=https://api.openai.com/v1
OPENAI_API_KEY=your-openai-key
```

## Setup

```bash
php artisan migrate:fresh --seed --force
php artisan serve
```

## Seeded Test Data

The seeder creates these defaults:

- Business: `acme`
- Workspace: `main`
- User email: `admin@acme.test`
- User password: `Admin@12345`
- User role: `admin`

## Session JWT Flow

1. Login (new session token)

```http
POST /api/auth/login
Content-Type: application/json

{
	"email": "admin@acme.test",
	"password": "Admin@12345"
}
```

Response includes:

- `session.access_token`
- `session.token_type` (`bearer`)
- `session.expires_in`

2. Call chat APIs with bearer token

```http
Authorization: Bearer <access_token>
```

3. Refresh token

```http
POST /api/auth/refresh
Authorization: Bearer <access_token>
```

## Chat Endpoints

- `POST /api/chat/generate`
- `POST /api/chat/stream`
- `GET /api/chat/headers/me`
- `DELETE /api/chat/headers/{chat_id}`
- `GET /api/chat/history/{user_id}`
- `GET /api/chat/test-stream`

## Notes

- `document_chunks.embedding` uses pgvector when running on PostgreSQL.
- For non-PostgreSQL databases, `embedding` is stored as text for compatibility.
- JWT auth is stateless and does not require `sessions` data to validate tokens.
- We still keep `sessions` table for Laravel `web` middleware and browser routes when `SESSION_DRIVER=database`.
