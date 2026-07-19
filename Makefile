# Package development targets come from the laravel-dev image at
# /usr/local/share/package.mk. We copy it out during `make bootstrap` so the
# targets are available to host `make` too.

-include package.mk

bootstrap: ## Copy package.mk from the image if missing
	@[ -f package.mk ] || docker compose run --rm app cp /usr/local/share/package.mk /app/package.mk
