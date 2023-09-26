build-zip:
	bash .github/scripts/build-zip.sh

phpunit:
	./vendor/bin/phpunit -c ./tests/Unit/phpunit.xml
