phpunit:
	docker pull prestashop/docker-internal-images:nightly
	@docker run --rm \
		--name phpunit \
		-e PS_DOMAIN=localhost \
		-e PS_ENABLE_SSL=0 \
		-e PS_DEV_MODE=1 \
		-e XDEBUG_MODE=coverage \
		-e XDEBUG_ENABLED=1 \
		-v ${PWD}/miguel:/var/www/html/modules/miguel \
		-w /var/www/html/modules/miguel \
		prestashop/docker-internal-images:nightly \
		sh -c " \
			service mariadb start && \
			service apache2 start && \
			docker-php-ext-enable xdebug && \
			../../bin/console prestashop:module install miguel && \
			echo \"Testing module v\`cat config.xml | grep '<version>' | sed 's/^.*\[CDATA\[\(.*\)\]\].*/\1/'\`\n\" && \
			chown -R www-data:www-data ../../var/logs && \
			chown -R www-data:www-data ../../var/cache && \
			./vendor/bin/phpunit -c ./tests/Unit/phpunit.xml \
		"
	@echo phpunit passed
