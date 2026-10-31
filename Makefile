# Everything runs in Docker; the host needs only Docker (with Compose v2), make and openssl.
COMPOSE := docker compose
API := $(COMPOSE) exec -T api
NAME ?= dev

.PHONY: up down logs ingest seed key test test-api test-mcp

## Build and start everything (Postgres, Redis, API, MCP server), migrate, and seed an empty database.
up: .env
	$(COMPOSE) up -d --build --wait
	$(API) php artisan db:seed-if-empty
	@echo ""
	@echo "API:        http://localhost:$${API_HOST_PORT:-8000}/api/v1/ads"
	@echo "MCP server: http://localhost:$${MCP_HOST_PORT:-3001}/mcp"
	@echo "Create an API key:  make key"

## The containers' APP_KEY, generated once (signs cursors; must be at least 32 characters).
.env:
	@echo "APP_KEY=base64:$$(openssl rand -base64 32)" > .env
	@echo "Created .env with a new APP_KEY."

down:
	$(COMPOSE) down

logs:
	$(COMPOSE) logs -f api mcp

## Live ingest: inserts and deactivates ads every second until Ctrl-C.
ingest:
	$(COMPOSE) exec api php artisan ads:ingest

## Replace all ads with the deterministic seed data.
seed:
	$(API) php artisan db:seed --force

## Create a search:read key and print it once: make key [NAME=label]
key:
	$(API) php artisan keys:create "$(NAME)"

test: test-api test-mcp

## Pest (unit, feature and concurrency suites; serial, because tests share Redis database 15) against the test database.
test-api:
	$(API) ./vendor/bin/pest

## Vitest unit tests, then the end-to-end tests against the real API on the test database.
test-mcp:
	$(COMPOSE) exec -T mcp npm test
	$(COMPOSE) exec -T -w /repo/mcp api npm run test:e2e
