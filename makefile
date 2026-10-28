# Makefile for mod_sideline
# Mirrors the moodle-plugin-ci check suite used in GitHub Actions.
#
# Targets:
#   make all          — fix + full check suite (default)
#   make fix          — auto-fix PHP style + PHPDoc + rebuild AMD
#   make check        — check-only (no auto-fix)
#   make clear        — clear terminal
#
# Individual checks:
#   make lint-php     — PHPCS Moodle coding standard
#   make lint-phpdoc  — Moodle PHPDoc checker
#   make lint-js      — ESLint on AMD source files (skipped when amd/src/ is empty)
#   make lint-mustache — Mustache template syntax
#   make lint-cpd     — PHP Copy/Paste Detector (informational)
#   make lint-md      — PHP Mess Detector (informational)
#
# Auto-fixers:
#   make fix-lint-php — phpcbf PHP code-style auto-fix
#   make fix-phpdoc   — moodlecheck PHPDoc report
#   make amd          — rebuild AMD minified files
#   make build        — build BOTH the React bundle (esbuild) and AMD (grunt)
#   make react        — bundle the React editor (Moodle grunt esm)
#   make lint-react   — TypeScript type-check (tsc --noEmit)
#   make test-react   — Jest unit tests for the React/TS sources
#
# Tests:
#   make phpunit      — PHPUnit testsuite for this plugin
#   make test-amd     — Jest tests of the AMD panel (Moodle 4.5 to 5.2)
#   make load-seed    — seed a load-test course with learners; stores the exports
#   make k6           — HTTP load test with k6 against a running site (see tests/load/README.md)
#
# Paths are auto-detected from the makefile's own location.
# The plugin lives at <MOODLE_ROOT>/public/mod/sideline/ —
# levels below the Moodle root — so both PLUGIN_DIR and MOODLE_ROOT are
# derived automatically and work on any installation.
# Override on the command line if necessary:
#   make lint-php MOODLE_ROOT=/opt/moodle

THIS_DIR      := $(patsubst %/,%,$(dir $(abspath $(lastword $(MAKEFILE_LIST)))))
PLUGIN_DIR    ?= $(THIS_DIR)
# Moodle 5.1+ keeps plugins below public/ (plugin at <root>/public/mod/sideline), 4.5 and 5.0 in the root.
PUBLIC        := $(if $(wildcard $(PLUGIN_DIR)/../../../public/version.php),public/,)
MOODLE_ROOT   ?= $(if $(PUBLIC),$(abspath $(PLUGIN_DIR)/../../..),$(abspath $(PLUGIN_DIR)/../..))
PLUGIN_NAME   ?= mod_sideline
PLUGIN_REL    ?= $(PUBLIC)mod/sideline
PHP           ?= $(shell which php 2>/dev/null || echo /usr/bin/php)
PHPCS         ?= phpcs
PHPCBF        ?= phpcbf
NPX           ?= npx
# Refresh the browserslist database before a Grunt/ESLint run. Off by default:
# it writes to the lockfile and reaches the network, which a plain `make check`
# should not do. caniuse-lite going stale only produces a warning, never a wrong
# build, so this is a maintenance step rather than part of every run.
#   make amd BROWSERSLIST_UPDATE=1
BROWSERSLIST_UPDATE ?= 0
NPM           ?= npm
# Refresh frontend dependencies before running Jest: `npm update` plus
# `npm audit fix --force`. Off by default because --force accepts breaking major
# versions, which changes the bundled output and therefore the committed
# amd/build artefacts; run it deliberately, then rebuild and re-commit them.
#   make test-react NPM_REFRESH=1
NPM_REFRESH   ?= 0

# --- Load-test tooling -------------------------------------------------------
LOAD_DIR       ?= $(PLUGIN_DIR)/tests/load
K6             ?= k6
VUS            ?= 20
DURATION       ?= 60s
LOAD_USERS     ?= 50
# Values written by `make load-seed` (BASE_URL, CMID, USER_PREFIX, ...). Ignored while the file does not exist.
-include $(LOAD_DIR)/.load-env

.PHONY: all fix check clear \
        lint-php lint-phpdoc lint-js lint-mustache lint-cpd lint-md \
        lint-react test-react react build \
        fix-lint-php fix-phpdoc amd phpunit test-amd load-seed k6

all: clear fix check
	@echo ""
	@echo "=== All checks complete. Review output above for errors. ==="

fix: clear fix-phpdoc fix-lint-php build
	@echo ""
	@echo "=== All fixes complete. ==="

check: clear lint-php lint-phpdoc lint-mustache lint-cpd lint-js lint-react build test-react phpunit
	@echo ""
	@echo "=== All checks complete. Review output above for errors. ==="

clear:
	clear

lint-php:
	@echo "=== phpcs (Moodle standard, excludes tools/) ==="
	-cd $(PLUGIN_DIR) && $(PHPCS) \
		--standard=moodle \
		--extensions=php \
		--severity=1 \
		--no-cache \
		--ignore=tools/ \
		.

fix-lint-php:
	@echo ""
	@echo "=== phpcbf (auto-fix) ==="
	-cd $(PLUGIN_DIR) && $(PHPCBF) \
		--standard=moodle \
		--extensions=php \
		.

lint-phpdoc:
	@echo ""
	@echo "=== PHPDoc (local_moodlecheck, excludes tools/) ==="
	-cd $(MOODLE_ROOT) && $(PHP) $(PUBLIC)local/moodlecheck/cli/moodlecheck.php \
		--path=$(PLUGIN_REL) \
		--exclude=$(PLUGIN_REL)/tools \
		--format=text 2>&1 | grep -B1 '    Line' | grep -v '^--$$' || true

fix-phpdoc:
	@echo ""
	@echo "=== fix_phpdoc (tools/fix_phpdoc.php) ==="
	-$(PHP) $(PLUGIN_DIR)/tools/fix_phpdoc.php $(PLUGIN_DIR)

lint-mustache:
	@echo ""
	@echo "=== Mustache syntax check ==="
	@if [ -d $(PLUGIN_DIR)/templates ]; then \
		$(PHP) $(PLUGIN_DIR)/tools/mustache_check.php \
			$(PLUGIN_DIR)/templates 2>&1 | grep -v '^OK:' || true; \
	else \
		echo "No templates/ directory — Mustache check skipped."; \
	fi

lint-cpd:
	@echo ""
	@echo "=== PHP Copy/Paste Detector ==="
	-cd $(PLUGIN_DIR) && phpcpd --min-lines 5 --min-tokens 70 . || true

lint-md:
	@echo ""
	@echo "=== PHP Mess Detector ==="
	@echo "Rules: phpmd.xml (Moodle-incompatible rules excluded, each with a reason)."
	@echo "db/upgrade.php is exempt: its shape is prescribed by Moodle."
	-cd $(PLUGIN_DIR) && phpmd . text phpmd.xml \
		--exclude tests,tools,tests/load,db/upgrade.php || true

lint-js:
	@echo ""
	@echo "=== ESLint (skipped when amd/src/ is empty) ==="
	@if ls $(PLUGIN_DIR)/amd/src/*.js 2>/dev/null | grep -q .; then \
		cd $(MOODLE_ROOT) && $(NPX) grunt eslint --root=. \
			--files=$(PLUGIN_REL)/amd/src/ --show-lint-warnings; \
	else \
		echo "No AMD source files — ESLint skipped."; \
	fi

amd:
	@echo ""
	@echo "=== AMD rebuild (skipped when amd/src/ is empty) ==="
	@if [ "$(BROWSERSLIST_UPDATE)" = "1" ]; then \
		echo "Updating the browserslist database (BROWSERSLIST_UPDATE=1)..."; \
		cd $(PLUGIN_DIR) && $(NPX) browserslist@latest --update-db || true; \
	fi
	@if ls $(PLUGIN_DIR)/amd/src/*.js 2>/dev/null | grep -q .; then \
		cd $(PLUGIN_DIR) && $(NPX) grunt amd --force; \
	else \
		echo "No AMD source files — skipped."; \
	fi

react:
	@echo ""
	@echo "=== ESM bundle (Moodle grunt esm) ==="
	@cd $(MOODLE_ROOT) && npx grunt esm

build: react amd
	@echo ""
	@echo "=== Front-end build complete (React bundle + AMD). ==="

lint-react:
	@echo ""
	@echo "=== TypeScript type-check (Moodle root tsc --noEmit) ==="
	@cd $(MOODLE_ROOT) && npx tsc --noEmit -p tsconfig.json

test-react:
	@echo ""
	@echo "=== Jest (run from the Moodle root) ==="
	@cd $(MOODLE_ROOT) && npx jest $(PLUGIN_REL)/js/esm/tests

phpunit:
	@echo ""
	@echo "=== PHPUnit ==="
	@if ! $(PHP) -r \
		"define('CLI_SCRIPT',1); require '$(MOODLE_ROOT)/config.php'; \
		exit(empty(\$$CFG->phpunit_dataroot) ? 1 : 0);" 2>/dev/null; then \
		echo "SKIP: phpunit_dataroot not configured."; \
		echo "      Add to config.php: \$$CFG->phpunit_dataroot = '...';"; \
	else \
		reinit_check=$$(cd $(MOODLE_ROOT) && $(PHP) vendor/bin/phpunit \
			--testsuite $(PLUGIN_NAME)_testsuite \
			--testdox 2>&1 | head -5); \
		if printf '%s\n' "$$reinit_check" | grep -q "initialised for different version"; then \
			echo "PHPUnit environment outdated — reinitialising..."; \
			cd $(MOODLE_ROOT) && $(PHP) $(PUBLIC)admin/tool/phpunit/cli/init.php; \
		fi; \
		tmpout=$$(mktemp); \
		cd $(MOODLE_ROOT) && $(PHP) vendor/bin/phpunit \
			--testsuite $(PLUGIN_NAME)_testsuite \
			--testdox > "$$tmpout" 2>&1; \
		phpunit_exit=$$?; \
		grep -v "^ ✔\|^ ✓\|^ ↩" "$$tmpout" || true; \
		rm -f "$$tmpout"; \
		exit $$phpunit_exit; \
	fi

test-amd:
	@echo ""
	@echo "=== Jest (AMD panel) ==="
	@cd $(MOODLE_ROOT) && npx jest --config $(PLUGIN_REL)/tests/js/jest.config.js

# --- HTTP load test (k6), see tests/load/README.md -------------------------------------
# Needs an installed development site served on its wwwroot. Disposable sites only.
load-seed:
	@echo ""
	@echo "=== Seed a load-test course with $(LOAD_USERS) learners ==="
	@$(PHP) $(PLUGIN_DIR)/tests/load/seed_load.php --users=$(LOAD_USERS) | tee $(LOAD_DIR)/.load-seed.out
	@sed -n "s/^export \([A-Z_][A-Z_]*\)='\(.*\)'$$/\1=\2/p" $(LOAD_DIR)/.load-seed.out > $(LOAD_DIR)/.load-env
	@rm -f $(LOAD_DIR)/.load-seed.out
	@echo "Saved to $(LOAD_DIR)/.load-env"

k6:
	@echo ""
	@echo "=== k6 load test against $(BASE_URL) ==="
	@if [ -z "$(CMID)" ]; then echo "Run 'make load-seed' first."; exit 1; fi
	$(K6) run -e BASE_URL='$(BASE_URL)' -e CMID='$(CMID)' -e USER_PREFIX='$(USER_PREFIX)' -e USERS='$(USERS)' \
		-e PASSWORD='$(PASSWORD)' -e PAGE_CMIDS='$(PAGE_CMIDS)' -e VUS='$(VUS)' -e DURATION='$(DURATION)' \
		$(LOAD_DIR)/sideline-commentary.js
	$(PHP) $(PLUGIN_DIR)/tests/load/verify_load.php --cmid=$(CMID) --prefix=$(USER_PREFIX)
