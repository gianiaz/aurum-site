COMPOSE ?= docker compose
APP_PORT ?= 8081
URL := http://127.0.0.1:$(APP_PORT)

ifneq (,$(wildcard .env.local))
include .env.local
export BREVO_API_KEY
export BREVO_LIST_ID
endif

.PHONY: help build up down restart logs php-logs ps shell lint test-newsletter clean

help:
	@printf "Aurum local commands\n\n"
	@printf "  make build    Build the PHP image\n"
	@printf "  make up       Start the local site at $(URL)\n"
	@printf "  make down     Stop the local site\n"
	@printf "  make restart  Restart the local site\n"
	@printf "  make logs     Follow web logs\n"
	@printf "  make php-logs Follow PHP logs\n"
	@printf "  make ps       Show containers\n"
	@printf "  make shell    Open a shell in the PHP container\n"
	@printf "  make lint     Run PHP syntax check\n"
	@printf "  make test-newsletter EMAIL=you@example.com  Test Brevo subscription endpoint\n"
	@printf "  make clean    Stop and remove local containers\n"

build:
	$(COMPOSE) build

up:
	APP_PORT=$(APP_PORT) $(COMPOSE) up -d
	@printf "Aurum is running at $(URL)\n"

down:
	$(COMPOSE) down

restart:
	$(COMPOSE) restart nginx php
	@printf "Aurum is running at $(URL)\n"

logs:
	$(COMPOSE) logs -f nginx

php-logs:
	$(COMPOSE) logs -f php

ps:
	$(COMPOSE) ps

shell:
	$(COMPOSE) exec php sh

lint:
	$(COMPOSE) run --rm php php -l index.php
	$(COMPOSE) run --rm php php -l router.php
	$(COMPOSE) run --rm php php -l sitemap.php
	$(COMPOSE) run --rm php php -l robots.php
	$(COMPOSE) run --rm php php -l subscribe.php

test-newsletter:
	@test -n "$(EMAIL)" || (printf "Usage: make test-newsletter EMAIL=you@example.com\n" && exit 1)
	curl -s -X POST http://127.0.0.1:$(APP_PORT)/newsletter/subscribe -F email="$(EMAIL)" -F locale=it -F consent=1

clean:
	$(COMPOSE) down --remove-orphans
