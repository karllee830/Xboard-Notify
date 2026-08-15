VERSION := $(shell sed -n 's/^[[:space:]]*"version": "\([^"]*\)",/\1/p' config.json)
PACKAGE := dist/xboard-notify-v$(VERSION).zip

.PHONY: package clean

package:
	@mkdir -p dist
	git archive --format=zip --prefix=XboardNotify/ --output=$(PACKAGE) HEAD
	@echo "Created $(PACKAGE)"

clean:
	@rm -f dist/xboard-notify-v*.zip
