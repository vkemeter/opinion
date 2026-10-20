## Development tasks of the extension. Run "make" or "make help" for the list.
## The PHP tasks run in containers (Build/Scripts/runTests.sh), no local PHP needed.

PHP ?= 8.4
DB ?= sqlite
CONTAINER ?=

RUN_TESTS = Build/Scripts/runTests.sh -p $(PHP)$(if $(CONTAINER), -b $(CONTAINER))
DOCS_IMAGE = ghcr.io/typo3-documentation/render-guides:latest
DOCS_RUN = docker run --rm --pull always -v "$(CURDIR)":/project $(DOCS_IMAGE) --config=Documentation --no-progress

.DEFAULT_GOAL := help

.PHONY: help
help: ## Show this help
	@awk 'BEGIN {FS = ":.*## "} /^[a-zA-Z_-]+:.*## / {printf "  \033[36m%-21s\033[0m %s\n", $$1, $$2}' $(MAKEFILE_LIST)
	@echo
	@echo "  Variables: PHP=$(PHP) (8.4|8.5)  DB=$(DB) (sqlite|mariadb|mysql|postgres)  CONTAINER=docker|podman"
	@echo "  Example:   make test-functional DB=mariadb PHP=8.5"

.PHONY: install
install: install-php install-browser ## Install all dependencies

.PHONY: install-php
install-php: ## Install the Composer dependencies into .Build/
	$(RUN_TESTS) -s composerUpdate

.PHONY: install-browser
install-browser: ## Install the Node tools (Playwright, ESLint) and Chromium
	npm --prefix Build ci
	npx --prefix Build playwright install chromium

.PHONY: test
test: test-unit test-functional test-browser ## Run unit, functional and browser tests

.PHONY: test-unit
test-unit: ## Run the unit tests
	$(RUN_TESTS) -s unit

.PHONY: test-functional
test-functional: ## Run the functional tests (DB=sqlite|mariadb|mysql|postgres)
	$(RUN_TESTS) -s functional -d $(DB)

.PHONY: test-functional-all
test-functional-all: ## Run the functional tests on all databases
	$(MAKE) test-functional DB=sqlite
	$(MAKE) test-functional DB=mariadb
	$(MAKE) test-functional DB=mysql
	$(MAKE) test-functional DB=postgres

.PHONY: test-browser
test-browser: ## Run the browser test of the dialog (headless Chromium)
	node Tests/Browser/dialog.test.mjs

.PHONY: check
check: lint lint-js cgl phpstan docs-test ## Run all checks without changing files

.PHONY: lint
lint: ## Check the PHP syntax
	$(RUN_TESTS) -s lint

.PHONY: lint-js
lint-js: ## Check the JavaScript with ESLint
	npm --prefix Build run --silent lint:js

.PHONY: cgl
cgl: ## Check the PHP coding guidelines (php-cs-fixer dry run)
	$(RUN_TESTS) -s cgl -n

.PHONY: phpstan
phpstan: ## Run PHPStan
	$(RUN_TESTS) -s phpstan

.PHONY: fix
fix: ## Fix the PHP coding guidelines (php-cs-fixer)
	$(RUN_TESTS) -s cgl

.PHONY: docs
docs: ## Render the documentation to Documentation-GENERATED-temp/
	$(DOCS_RUN)
	@echo "Open Documentation-GENERATED-temp/Index.html"

.PHONY: docs-test
docs-test: ## Render the documentation in test mode (fails on warnings)
	$(DOCS_RUN) --minimal-test

.PHONY: ci
ci: check test-unit test-functional-all test-browser ## Run everything the CI runs (also PHP 8.5)
	$(MAKE) test-unit test-functional PHP=8.5 DB=sqlite

.PHONY: clean
clean: ## Remove dependencies, caches and rendered documentation
	rm -rf .Build Build/node_modules Documentation-GENERATED-temp var
