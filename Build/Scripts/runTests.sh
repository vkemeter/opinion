#!/usr/bin/env bash

#
# Test runner of the extension, based on docker or podman.
# Every suite runs in a ghcr.io/typo3/core-testing-* container, no local PHP needed.
#

set -u

cleanUp() {
    local containers
    containers=$(${CONTAINER_BIN} ps --filter "network=${NETWORK}" --format='{{.Names}}')
    for container in ${containers}; do
        ${CONTAINER_BIN} kill "${container}" >/dev/null
    done
    ${CONTAINER_BIN} network rm "${NETWORK}" >/dev/null 2>&1
}

waitFor() {
    local host=${1}
    local port=${2}
    ${CONTAINER_BIN} run --rm --network "${NETWORK}" "${IMAGE_ALPINE}" /bin/sh -c "
        count=0
        while ! nc -z ${host} ${port}; do
            if [ \"\${count}\" -gt 30 ]; then
                echo 'Can not connect to ${host} port ${port}. Aborting.'
                exit 1
            fi
            sleep 1
            count=\$((count + 1))
        done
    " || { cleanUp; exit 1; }
}

loadHelp() {
    read -r -d '' HELP <<EOF
Test runner of the extension. Runs the suites in docker or podman containers.

Usage: $0 [options] [file]

Options:
    -s <suite>
        composer          Run composer, pass arguments after "--", e.g. -s composer -- install
        composerUpdate    Install the dependencies (composer update)
        unit (default)    Unit tests
        functional        Functional tests
        phpstan           PHPStan
        cgl               PHP coding guidelines (php-cs-fixer), add -n for a dry run
        lint              PHP syntax check
        clean             Remove .Build

    -d <sqlite|mariadb|mysql|postgres>
        Database for functional tests, default sqlite.

    -i <version>
        Database version, defaults: mariadb 10.11, mysql 8.0, postgres 16.

    -p <8.4|8.5>
        PHP version, default 8.4.

    -b <docker|podman>
        Container binary, default: podman if available, else docker.

    -x  Enable xdebug (port 9003).
    -n  Dry run for -s cgl.
    -h  Show this help.

Examples:
    Build/Scripts/runTests.sh -s composerUpdate
    Build/Scripts/runTests.sh -s unit
    Build/Scripts/runTests.sh -s functional -d mariadb
    Build/Scripts/runTests.sh -s functional Tests/Functional/Command/CleanupCommandTest.php
EOF
}

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "${ROOT_DIR}" || exit 1

TEST_SUITE="unit"
DBMS="sqlite"
DBMS_VERSION=""
PHP_VERSION="8.4"
CONTAINER_BIN=""
PHP_XDEBUG_ON=0
CGL_DRY_RUN=""

OPTIND=1
while getopts "s:d:i:p:b:xnh" OPT; do
    case ${OPT} in
        s) TEST_SUITE=${OPTARG} ;;
        d) DBMS=${OPTARG} ;;
        i) DBMS_VERSION=${OPTARG} ;;
        p) PHP_VERSION=${OPTARG} ;;
        b) CONTAINER_BIN=${OPTARG} ;;
        x) PHP_XDEBUG_ON=1 ;;
        n) CGL_DRY_RUN="--dry-run --diff" ;;
        h) loadHelp; echo "${HELP}"; exit 0 ;;
        *) loadHelp; echo "${HELP}" >&2; exit 1 ;;
    esac
done
shift $((OPTIND - 1))
[ "${1:-}" = "--" ] && shift

if ! [[ ${PHP_VERSION} =~ ^(8.4|8.5)$ ]]; then
    echo "Invalid PHP version ${PHP_VERSION}" >&2
    exit 1
fi

case ${DBMS} in
    sqlite) ;;
    mariadb) DBMS_VERSION=${DBMS_VERSION:-10.11} ;;
    mysql) DBMS_VERSION=${DBMS_VERSION:-8.0} ;;
    postgres) DBMS_VERSION=${DBMS_VERSION:-16} ;;
    *) echo "Invalid database ${DBMS}" >&2; exit 1 ;;
esac

if [ -z "${CONTAINER_BIN}" ]; then
    if type podman >/dev/null 2>&1; then
        CONTAINER_BIN="podman"
    elif type docker >/dev/null 2>&1; then
        CONTAINER_BIN="docker"
    else
        echo "Neither podman nor docker found" >&2
        exit 1
    fi
fi

IMAGE_PHP="ghcr.io/typo3/core-testing-php${PHP_VERSION//./}:1.6"
IMAGE_ALPINE="docker.io/alpine:3.20"
IMAGE_MARIADB="docker.io/mariadb:${DBMS_VERSION}"
IMAGE_MYSQL="docker.io/mysql:${DBMS_VERSION}"
IMAGE_POSTGRES="docker.io/postgres:${DBMS_VERSION}-alpine"

SUFFIX="${RANDOM}"
NETWORK="opinion-${SUFFIX}"
${CONTAINER_BIN} network create "${NETWORK}" >/dev/null
trap 'cleanUp; exit 2' SIGINT

CONTAINER_COMMON_PARAMS=(--rm --network "${NETWORK}" --add-host host.docker.internal:host-gateway
    -v "${ROOT_DIR}:${ROOT_DIR}" -w "${ROOT_DIR}"
    -e "HOME=${ROOT_DIR}/.Build/.cache" -e "COMPOSER_CACHE_DIR=${ROOT_DIR}/.Build/.cache/composer" -e TYPO3_CONTEXT=Testing)
[ -t 0 ] && [ -t 1 ] && CONTAINER_COMMON_PARAMS+=(-it)
[ "${CONTAINER_BIN}" = "docker" ] && CONTAINER_COMMON_PARAMS+=(--user "$(id -u):$(id -g)")
if [ ${PHP_XDEBUG_ON} -eq 1 ]; then
    CONTAINER_COMMON_PARAMS+=(-e XDEBUG_MODE=debug -e XDEBUG_TRIGGER=foo -e "XDEBUG_CONFIG=client_port=9003 client_host=host.docker.internal")
else
    CONTAINER_COMMON_PARAMS+=(-e XDEBUG_MODE=off)
fi

mkdir -p .Build/.cache

runPhp() {
    ${CONTAINER_BIN} run "${CONTAINER_COMMON_PARAMS[@]}" --name "${TEST_SUITE}-${SUFFIX}" "${IMAGE_PHP}" /bin/sh -c "$1"
}

case ${TEST_SUITE} in
    composer)
        runPhp "composer $*"
        SUITE_EXIT_CODE=$?
        ;;
    composerUpdate)
        runPhp "composer update --no-progress --no-interaction"
        SUITE_EXIT_CODE=$?
        ;;
    unit)
        runPhp ".Build/bin/phpunit -c Build/phpunit/UnitTests.xml $*"
        SUITE_EXIT_CODE=$?
        ;;
    functional)
        DB_ENV=""
        case ${DBMS} in
            sqlite)
                mkdir -p .Build/public/typo3temp/var/tests/functional-sqlite-dbs
                DB_ENV="-e typo3DatabaseDriver=pdo_sqlite"
                ;;
            mariadb)
                ${CONTAINER_BIN} run --rm -d --name "mariadb-${SUFFIX}" --network "${NETWORK}" --tmpfs /var/lib/mysql/:rw,noexec,nosuid \
                    -e MYSQL_ROOT_PASSWORD=funcp "${IMAGE_MARIADB}" >/dev/null
                waitFor "mariadb-${SUFFIX}" 3306
                DB_ENV="-e typo3DatabaseDriver=mysqli -e typo3DatabaseName=func_test -e typo3DatabaseUsername=root \
                    -e typo3DatabasePassword=funcp -e typo3DatabaseHost=mariadb-${SUFFIX}"
                ;;
            mysql)
                ${CONTAINER_BIN} run --rm -d --name "mysql-${SUFFIX}" --network "${NETWORK}" --tmpfs /var/lib/mysql/:rw,noexec,nosuid \
                    -e MYSQL_ROOT_PASSWORD=funcp "${IMAGE_MYSQL}" >/dev/null
                waitFor "mysql-${SUFFIX}" 3306
                DB_ENV="-e typo3DatabaseDriver=mysqli -e typo3DatabaseName=func_test -e typo3DatabaseUsername=root \
                    -e typo3DatabasePassword=funcp -e typo3DatabaseHost=mysql-${SUFFIX}"
                ;;
            postgres)
                ${CONTAINER_BIN} run --rm -d --name "postgres-${SUFFIX}" --network "${NETWORK}" --tmpfs /var/lib/postgresql/data:rw,noexec,nosuid \
                    -e POSTGRES_PASSWORD=funcp -e POSTGRES_USER=funcu "${IMAGE_POSTGRES}" >/dev/null
                waitFor "postgres-${SUFFIX}" 5432
                DB_ENV="-e typo3DatabaseDriver=pdo_pgsql -e typo3DatabaseName=bamboo -e typo3DatabaseUsername=funcu \
                    -e typo3DatabasePassword=funcp -e typo3DatabaseHost=postgres-${SUFFIX}"
                ;;
        esac
        ${CONTAINER_BIN} run "${CONTAINER_COMMON_PARAMS[@]}" ${DB_ENV} --name "functional-${SUFFIX}" "${IMAGE_PHP}" \
            /bin/sh -c ".Build/bin/phpunit -c Build/phpunit/FunctionalTests.xml $*"
        SUITE_EXIT_CODE=$?
        ;;
    phpstan)
        runPhp ".Build/bin/phpstan analyse -c Build/phpstan/phpstan.neon --no-progress --memory-limit=-1 $*"
        SUITE_EXIT_CODE=$?
        ;;
    cgl)
        runPhp "PHP_CS_FIXER_IGNORE_ENV=1 .Build/bin/php-cs-fixer fix -v ${CGL_DRY_RUN} $*"
        SUITE_EXIT_CODE=$?
        ;;
    lint)
        runPhp "find . -name '*.php' ! -path './.Build/*' ! -path './Documentation-GENERATED-temp/*' ! -path './Build/node_modules/*' -print0 | xargs -0 -n1 -P4 php -dxdebug.mode=off -l >/dev/null"
        SUITE_EXIT_CODE=$?
        ;;
    clean)
        rm -rf .Build
        SUITE_EXIT_CODE=0
        ;;
    *)
        loadHelp
        echo "Invalid suite ${TEST_SUITE}" >&2
        echo "${HELP}" >&2
        SUITE_EXIT_CODE=1
        ;;
esac

cleanUp

echo "" >&2
echo "###########################################################################" >&2
echo "Suite: ${TEST_SUITE}, PHP ${PHP_VERSION}, container ${CONTAINER_BIN}" >&2
[ "${TEST_SUITE}" = "functional" ] && echo "Database: ${DBMS} ${DBMS_VERSION}" >&2
if [ ${SUITE_EXIT_CODE} -eq 0 ]; then
    echo "SUCCESS" >&2
else
    echo "FAILURE" >&2
fi
echo "###########################################################################" >&2

exit ${SUITE_EXIT_CODE}
