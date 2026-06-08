DOCKER_RUN := docker run --rm -v $(CURDIR):/app porthole-dev


.PHONY: build harbor-setup harbor-up harbor-down install test cs stan check submodule-init submodule-update report

## Build the porthole-dev Docker image
build:
	docker build -t porthole-dev -f dev/Dockerfile .

## Run Harbor setup (generate certs and config — run once before harbor-up)
harbor-setup:
	bash dev/harbor/setup.sh

## Start the local Harbor stack
harbor-up:
	docker compose -f dev/harbor/docker-compose.yaml up -d

## Stop the local Harbor stack
harbor-down:
	docker compose -f dev/harbor/docker-compose.yaml down

## Seed Harbor with test projects, images, and pull events (requires harbor-up)
harbor-seed:
	bash dev/seed-harbor.sh

## Fetch the submodule after a fresh clone
submodule-init:
	git submodule update --init

## Pull latest changes from the submodule remote
submodule-update:
	git submodule update --remote agent-CTRS

## Install Composer dependencies
install:
	$(DOCKER_RUN) /usr/bin/composer install

## Run PHPUnit tests
test:
	$(DOCKER_RUN) vendor/bin/phpunit

## Run PHP CS Fixer
cs:
	$(DOCKER_RUN) vendor/bin/php-cs-fixer fix

## Run PHPStan static analysis
stan:
	$(DOCKER_RUN) vendor/bin/phpstan analyse src --memory-limit=512M

## Run all quality checks (cs + stan + test)
check: cs stan test

## Generate a pull activity report (reads credentials and options from .test.local)
## Note: bin/porthole is a single-command binary — do not pass "report" as argument
report:
	set -a && . ./.test.local && set +a && \
	docker run -it --rm -v $(CURDIR):/app \
	  --add-host harbor.local:host-gateway \
	  -e HARBOR_TOKEN \
	  -e HARBOR_USERNAME \
	  porthole-dev bin/porthole
