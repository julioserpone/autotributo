SHELL := /bin/bash
.DEFAULT_GOAL := help

DC        := docker compose
APP       := $(DC) exec app
ARTISAN   := $(APP) php artisan
NODE      := $(DC) exec vite

export UID := $(shell id -u)
export GID := $(shell id -g)

.PHONY: help up down restart build rebuild ps logs shell app-logs \
        install key migrate fresh seed tinker art composer npm \
        test test-unit test-feature test-contract coverage \
        lint lint-fix analyse check security e2e \
        psql redis minio-url mailpit-url reverb-logs queue-logs \
        reset

help: ## Muestra esta ayuda
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-22s\033[0m %s\n", $$1, $$2}'

## --- Contenedores ---------------------------------------------------------
up: ## Levanta todo el stack en segundo plano
	$(DC) up -d
	@echo "App:      http://localhost:8000  (tenant demo: http://demo.localhost:8000)"
	@echo "Vite:     http://localhost:5173"
	@echo "MinIO:    http://localhost:9001  (autotributo / autotributo-secret)"
	@echo "Mailpit:  http://localhost:8025"
	@echo "Reverb:   ws://localhost:8080"

down: ## Detiene el stack (conserva volúmenes)
	$(DC) down

restart: ## Reinicia app, queue, scheduler y reverb
	$(DC) restart app queue scheduler reverb web

build: ## Construye la imagen PHP
	$(DC) build app

rebuild: ## Reconstruye sin caché
	$(DC) build --no-cache app

ps: ## Estado de los servicios
	$(DC) ps

logs: ## Logs de todos los servicios
	$(DC) logs -f --tail=100

## --- Instalación ----------------------------------------------------------
install: ## composer install + npm ci + migraciones + seed
	$(APP) composer install --no-interaction --prefer-dist
	$(NODE) npm ci
	@test -f .env || cp .env.example .env
	$(ARTISAN) key:generate --ansi
	$(ARTISAN) migrate --seed --ansi

key: ## Genera APP_KEY
	$(ARTISAN) key:generate

migrate: ## Ejecuta migraciones
	$(ARTISAN) migrate

fresh: ## Base de datos limpia + seed (solo local)
	$(ARTISAN) migrate:fresh --seed

seed: ## Ejecuta seeders
	$(ARTISAN) db:seed

tinker: ## Consola interactiva
	$(ARTISAN) tinker

art: ## Ejemplo: make art cmd="route:list"
	$(ARTISAN) $(cmd)

composer: ## Ejemplo: make composer cmd="require paquete/paquete"
	$(APP) composer $(cmd)

npm: ## Ejemplo: make npm cmd="add pinia"
	$(NODE) npm $(cmd)

## --- Calidad --------------------------------------------------------------
test: ## Suite completa (unit + feature + contract)
	$(ARTISAN) test --parallel

test-unit: ## Solo tests unitarios
	$(ARTISAN) test --testsuite=Unit

test-feature: ## Solo tests de feature
	$(ARTISAN) test --testsuite=Feature

test-contract: ## Tests de contrato (adaptadores externos)
	$(ARTISAN) test --testsuite=Contract

coverage: ## Cobertura con umbral mínimo
	$(APP) ./vendor/bin/pest --coverage --min=80

lint: ## Verifica estilo (Pint) y tipos (PHPStan)
	$(APP) ./vendor/bin/pint --test
	$(APP) ./vendor/bin/phpstan analyse --memory-limit=1G

lint-fix: ## Corrige estilo automáticamente
	$(APP) ./vendor/bin/pint

analyse: ## Solo análisis estático
	$(APP) ./vendor/bin/phpstan analyse --memory-limit=1G

check: lint test ## Gate completo local (equivalente a CI)
	@echo "OK: lint + tests"

security: ## Auditorías de dependencias y secretos
	$(APP) composer audit
	$(DC) exec vite npm audit --audit-level=high || true

e2e: ## Playwright contra el stack local (requiere: npx playwright install)
	npx playwright test

## --- Utilidades -----------------------------------------------------------
shell: ## Shell dentro del contenedor app
	$(APP) sh

app-logs: ## Logs de Laravel
	$(APP) tail -f storage/logs/laravel.log

psql: ## Consola psql
	$(DC) exec db psql -U autotributo -d autotributo

redis: ## Consola valkey-cli
	$(DC) exec valkey valkey-cli

minio-url: ## Info de MinIO
	@echo "API: http://localhost:9000  Consola: http://localhost:9001"

mailpit-url: ## Info de Mailpit
	@echo "http://localhost:8025"

reverb-logs: ## Logs de Reverb
	$(DC) logs -f reverb

queue-logs: ## Logs del worker de colas
	$(DC) logs -f queue

reset: ## Borra contenedores y volúmenes (DESTRUCTIVO)
	$(DC) down -v
