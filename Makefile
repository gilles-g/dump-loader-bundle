.DEFAULT_GOAL := help

PHP          := php
COMPOSER     := composer
PHPUNIT      := vendor/bin/phpunit
PHPSTAN      := vendor/bin/phpstan
PHP_CS_FIXER := vendor/bin/php-cs-fixer
RECTOR       := vendor/bin/rector
KERNEL_CACHE := /tmp/DumpLoaderBundle

.PHONY: help
help:
	@grep -E '^[a-zA-Z0-9_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-18s\033[0m %s\n", $$1, $$2}'

.PHONY: install
install: ## Installe les dependances (COMPOSER_HOME isole : le token global de la machine est invalide)
	COMPOSER_HOME=$$(mktemp -d) $(COMPOSER) install --no-interaction --no-progress

.PHONY: update
update: ## Met a jour les dependances
	COMPOSER_HOME=$$(mktemp -d) $(COMPOSER) update --no-interaction --no-progress

.PHONY: dump-autoload
dump-autoload: ## Regenere l'autoload (apres un changement de namespace ou de prefixe PSR-4)
	COMPOSER_HOME=$$(mktemp -d) $(COMPOSER) dump-autoload

.PHONY: quality
quality: phpcsfix phpstan test ## Chaine complete : cs-fix, phpstan, tests

.PHONY: ci
ci: phpcscheck phpstan rector-check test ## Chaine de verification sans ecriture (comme la CI)

.PHONY: phpcsfix
phpcsfix: ## Corrige le style et pose les en-tetes de licence
	$(PHP_CS_FIXER) fix

.PHONY: phpcscheck
phpcscheck: ## Verifie le style sans rien ecrire
	$(PHP_CS_FIXER) fix --dry-run --diff

.PHONY: phpstan
phpstan: ## Analyse statique (level max)
	$(PHPSTAN) analyse --no-progress

.PHONY: phpstan-baseline
phpstan-baseline: ## Regenere la baseline PHPStan
	$(PHPSTAN) analyse --no-progress --generate-baseline

.PHONY: rector
rector: ## Applique les regles Rector
	$(RECTOR) process

.PHONY: rector-check
rector-check: ## Liste ce que Rector changerait, sans ecrire
	$(RECTOR) process --dry-run

.PHONY: test
test: clear-kernel-cache ## Lance toute la suite de tests
	$(PHPUNIT)

.PHONY: test-unit
test-unit: ## Lance les tests unitaires
	$(PHPUNIT) tests/Unit

.PHONY: test-integration
test-integration: clear-kernel-cache ## Lance les tests d'integration
	$(PHPUNIT) tests/Integration

.PHONY: test-mysql
test-mysql: clear-kernel-cache ## Lance les tests du groupe mysql (exige DUMP_LOADER_TEST_DSN et le client mysql)
	$(PHPUNIT) --group mysql

.PHONY: docker-build
docker-build: ## Reconstruit l'image php du compose (apres un changement de .docker/php)
	docker compose build php

.PHONY: test-docker
test-docker: ## Lance toute la suite dans le conteneur php (meme environnement que la couverture)
	docker compose run --rm --no-deps php vendor/bin/phpunit

.PHONY: test-mysql-docker
test-mysql-docker: ## Lance le groupe mysql dans le conteneur php, contre la base du compose
	docker compose up -d --wait database
	docker compose run --rm php vendor/bin/phpunit --group mysql

.PHONY: test-mysql-docker-filter
test-mysql-docker-filter: ## Lance les tests du groupe mysql correspondant a FILTER=... dans le conteneur php
	docker compose up -d --wait database
	docker compose run --rm php vendor/bin/phpunit --group mysql --filter='$(FILTER)'

.PHONY: test-filter
test-filter: clear-kernel-cache ## Lance les tests correspondant a FILTER=... (ex: make test-filter FILTER=it_should_abort)
	$(PHPUNIT) --filter=$(FILTER)

.PHONY: test-file
test-file: clear-kernel-cache ## Lance un fichier de test : make test-file FILE=tests/Unit/...Test.php
	$(PHPUNIT) $(FILE)

.PHONY: coverage
coverage: clear-kernel-cache ## Couverture en texte (exige 100%)
	XDEBUG_MODE=coverage $(PHPUNIT) --coverage-text

.PHONY: coverage-docker
coverage-docker: ## Couverture dans le conteneur php (pcov ; aucun driver sur la machine hote)
	docker compose run --rm --no-deps php sh -c 'php -d pcov.enabled=1 vendor/bin/phpunit --coverage-text'

.PHONY: coverage-clover-docker
coverage-clover-docker: ## Produit clover.xml via le conteneur php (pour analyser les lignes non couvertes)
	docker compose run --rm --no-deps php sh -c 'php -d pcov.enabled=1 vendor/bin/phpunit --coverage-clover=clover.xml'

.PHONY: coverage-clover
coverage-clover: clear-kernel-cache ## Couverture au format clover (utilise par la CI)
	XDEBUG_MODE=coverage $(PHPUNIT) --coverage-text --coverage-clover=clover.xml

.PHONY: coverage-html
coverage-html: clear-kernel-cache ## Couverture HTML dans var/coverage
	XDEBUG_MODE=coverage $(PHPUNIT) --coverage-html var/coverage

.PHONY: clear-kernel-cache
clear-kernel-cache: ## Vide le cache du kernel de test (sinon services.php compile masque les changements)
	rm -rf $(KERNEL_CACHE)

.PHONY: clean
clean: clear-kernel-cache ## Vide tous les caches d'outillage
	rm -rf .phpunit.cache .php-cs-fixer.cache var/coverage
