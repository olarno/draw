help:
	@echo "This makefile is intended mainly for development purpose."
	@echo ""
	@echo "Targets:"
	@echo "    install:               This will setup the development environment or reset the current one"
	@echo "    up:                    docker-compose up the development environment"
	@echo "    down:                  docker-compose down the development environment"
	@echo "    clear:                 clear all data generated by the build process except the ./env file"
	@echo "    provision:             provision the data, this should be executed after a clear"
	@echo "    build:                 rebuild all the needed container"
	@echo "    php:                   will connect to the php container"
	@echo "    test-reset-db:         will clear the database, load fixture, clear the redis cache and load elastic search"
	@echo "    clear-temp:            empty and reset permission on the ./var folder"
	@echo "    test:                  run phpunit test"
	@echo "                           you should use your IDE instead but it's a good way to test your setup first"
	@echo "    test-coverage:         run phpunit test with codecoverage enabled"
	@echo "                           this will take a couple of hours to run"
	@echo "    dump-assert-methods:   this will dump the assert methos for the tester trait"

.ONESHELL:
down:
	[ -f ./docker-compose.yml ] && docker-compose down --remove-orphans || echo docker-compose.yml does not exists

up:
	docker-compose up -d

build:
	docker-compose build --pull

install: clear copy-docker-compose-dist build provision

php:
	docker exec -it draw_php bash

clear: down
	sudo rm -Rf .docker/data ./vendor

test:
	docker-compose exec php php vendor/bin/phpunit

test-coverage:
	docker-compose exec php php vendor/bin/phpunit --coverage-html ./tmp/phpunit/report

provision: up
	docker-compose exec php composer install

copy-docker-compose-dist:
	cp ./docker-compose.yml.dist ./docker-compose.yml

subsplit:
	rm -rf .subsplit
	git subsplit init https://github.com/mpoiriert/draw
	git subsplit publish " \
	    src/Component/Tester:https://github.com/mpoiriert/tester.git \
	    src/Component/Profiling:https://github.com/mpoiriert/profiling.git \
	    src/Component/Messenger:https://github.com/mpoiriert/messenger.git \
	    src/Bundle/AwsToolKitBundle:https://github.com/mpoiriert/aws-tool-kit-bundle.git \
		src/Bundle/CommandBundle:https://github.com/mpoiriert/command-bundle.git \
		src/Bundle/CronBundle:https://github.com/mpoiriert/cron-bundle.git \
		src/Bundle/MessengerBundle:https://github.com/mpoiriert/messenger-bundle.git \
        src/Bundle/PostOfficeBundle:https://github.com/mpoiriert/post-office-bundle.git \
        src/Bundle/TesterBundle:https://github.com/mpoiriert/tester-bundle.git \
        src/Bundle/UserBundle:https://github.com/mpoiriert/user-bundle.git \
	"
	rm -rf .subsplit

migrations-diff:
	docker-compose exec php php bin/console doctrine:migrations:diff --formatted

migrations-migrate:
	docker-compose exec php php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

test-reset-db:
	docker-compose exec php php bin/console doctrine:database:drop --if-exists --no-interaction --force
	docker-compose exec php php bin/console doctrine:database:create --no-interaction
	docker-compose exec php php bin/console messenger:setup-transports --no-interaction
	docker-compose exec php php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration --quiet
	docker-compose exec php php bin/console doctrine:fixtures:load --no-interaction

tester-dump-assert-methods:
	docker-compose exec php bin/console draw:tester:dump-assert-methods ./src/Component/Tester/Resources/config/assert_methods.json

tester-generate-trait:
	docker-compose exec php bin/console draw:tester:generate-trait

tester-generate-doc:
	docker-compose exec php bin/console draw:tester:generate-asserts-documentation-page

tester-generate-all: tester-dump-assert-methods tester-generate-trait tester-generate-doc

monorepo-merge:
	docker-compose exec php vendor/bin/monorepo-builder merge

monorepo-split:
	docker-compose exec php vendor/bin/monorepo-builder split

monorepo-release:
	DRY_RUN=--dry-run
ifeq ($(run),1)
	DRY_RUN=
endif
	docker-compose exec php vendor/bin/monorepo-builder release $(version) $$DRY_RUN

monorepo-release-patch:
	docker-compose exec php vendor/bin/monorepo-builder release patch

composer-update:
	unlink composer.lock
	docker-compose exec php composer install
	sudo chown martin:martin -Rf .

cs-fix:
	docker-compose exec php php vendor/bin/php-cs-fixer fix -v