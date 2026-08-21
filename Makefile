.PHONY: help start up stop down restart logs bash caddy-reload test-mercure status clean

# Default goal
help:
	@echo "======================================================================"
	@echo "             🐘 FrankenPHP Development Server CLI Tooling"
	@echo "======================================================================"
	@echo "  make start (or up)     - Build and start all services in background"
	@echo "  make stop (or down)    - Stop all running services"
	@echo "  make restart           - Restart services"
	@echo "  make status            - View status of containers and ports"
	@echo "  make logs              - Follow live server logs"
	@echo "  make bash              - Open an interactive shell inside FrankenPHP"
	@echo "  make caddy-reload      - Live-reload Caddyfile configuration"
	@echo "  make test-mercure      - Publish a test message to Mercure Hub"
	@echo "  make composer CMD=...  - Run composer commands inside container"
	@echo "======================================================================"

start: up

up:
	docker compose up -d --build

stop: down

down:
	docker compose down

restart:
	docker compose restart

status:
	@docker compose ps

logs:
	docker compose logs -f frankenphp

bash:
	docker compose exec frankenphp bash

caddy-reload:
	docker compose exec frankenphp frankenphp reload --config /etc/caddy/Caddyfile

test-mercure:
	@curl -s -X POST http://localhost/mercure-test.php -d "topic=https://example.com/notifications&message=CLI+Test+at+$$(date +%H:%M:%S)" | grep -o '"success":true' && echo " ✅ Mercure Event successfully published!" || echo " ❌ Failed to publish Mercure event"

composer:
	docker compose exec frankenphp composer $(CMD)

clean:
	docker compose down -v
