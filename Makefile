# Mzian.net — developer shortcuts. Run `make help` for the list.
DC        = docker compose
PHP       = $(DC) exec php
CONSOLE   = $(PHP) php bin/console

.DEFAULT_GOAL := help
.PHONY: help up up-dev down build logs sh install migrate setup assets test test-unit qa phpstan cs cs-fix lint worker-restart

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-16s\033[0m %s\n", $$1, $$2}'

up: ## Start the stack (http://localhost)
	$(DC) up -d

up-dev: ## Start the stack with developer extras (assets watch, adminer, exposed ports)
	$(DC) -f docker-compose.yml -f docker-compose.dev.yml up -d

down: ## Stop the stack
	$(DC) down --remove-orphans

build: ## Build the Docker images
	$(DC) build

logs: ## Follow logs
	$(DC) logs -f --tail=100

sh: ## Open a shell in the PHP container
	$(PHP) sh

install: ## Install PHP and JS dependencies
	$(PHP) composer install
	$(DC) run --rm assets sh -c "npm ci && npm run build"

migrate: ## Run database migrations
	$(CONSOLE) doctrine:migrations:migrate --no-interaction

setup: ## Idempotent setup (catalog, providers, agents, demo accounts)
	$(CONSOLE) mzian:setup --demo

assets: ## Build CSS/JS
	$(DC) run --rm assets sh -c "npm ci && npm run build"

test: ## Run the whole PHPUnit test-suite
	$(PHP) php bin/phpunit

phpstan: ## Static analysis
	$(CONSOLE) cache:warmup --env=test && $(PHP) vendor/bin/phpstan analyse --memory-limit=1G

cs: ## Check coding standards
	$(PHP) vendor/bin/php-cs-fixer fix --dry-run --diff

cs-fix: ## Fix coding standards
	$(PHP) vendor/bin/php-cs-fixer fix

lint: ## Lint Twig, YAML, container and Doctrine mapping
	$(CONSOLE) lint:twig templates
	$(CONSOLE) lint:yaml config --parse-tags
	$(CONSOLE) lint:container
	$(CONSOLE) doctrine:schema:validate --skip-sync

qa: lint phpstan cs test ## Everything CI runs

worker-restart: ## Gracefully restart Messenger workers (after a deploy)
	$(CONSOLE) messenger:stop-workers
