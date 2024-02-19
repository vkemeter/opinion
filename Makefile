
fix: vendor/autoload.php
	php vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.php -vvv --diff

lint: vendor/autoload.php
	php vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.php -vvv --diff --dry-run

vendor/autoload.php: composer.json composer.lock
	composer install

