#!/usr/bin/env bash
set -Eeuo pipefail

ROOT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
cd "$ROOT_DIR"

OUT_DIR="$ROOT_DIR/reports/newman"
REPORTER_DIR="$(mktemp -d "${TMPDIR:-/tmp}/porsca-newman-report.XXXXXX")"
trap 'rm -rf -- "$REPORTER_DIR"' EXIT

for name in ADMIN_PASSWORD QA_CASHIER_PASSWORD PAYMONGO_WEBHOOK_SECRET; do
    if [[ -z "${!name:-}" ]]; then
        printf 'Required environment variable %s is not set.\n' "$name" >&2
        exit 2
    fi
done

if ! command -v npx >/dev/null 2>&1 || ! command -v php >/dev/null 2>&1; then
    printf 'Both npx and PHP are required to generate sanitized Newman reports.\n' >&2
    exit 2
fi

if [[ -e "$OUT_DIR" && ! -d "$OUT_DIR" ]]; then
    printf 'The report output path exists and is not a directory.\n' >&2
    exit 2
fi

rm -rf -- "$OUT_DIR"
newman_status=0
if npx --yes \
    --package newman@6.2.3 \
    --package newman-reporter-htmlextra@1.23.1 \
    newman run postman/PorSca-API.postman_collection.json \
    -e postman/PorSca-API.postman_environment.json \
    --env-var "admin_password=$ADMIN_PASSWORD" \
    --env-var cashier_email=qa-cashier@porsca.test \
    --env-var "cashier_password=$QA_CASHIER_PASSWORD" \
    --env-var "webhook_secret=$PAYMONGO_WEBHOOK_SECRET" \
    --reporters cli,json,junit,htmlextra \
    --reporter-json-export "$REPORTER_DIR/newman.json" \
    --reporter-junit-export "$REPORTER_DIR/newman.xml" \
    --reporter-htmlextra-export "$REPORTER_DIR/newman.html" \
    --reporter-htmlextra-skipSensitiveData \
    >"$REPORTER_DIR/newman-cli.log" 2>&1; then
    newman_status=0
else
    newman_status=$?
fi

if ! php scripts/newman-report-summary.php \
    "$REPORTER_DIR/newman.json" \
    "$REPORTER_DIR/summary.md" \
    "$REPORTER_DIR"; then
    printf 'Sanitization failed; raw reports were discarded.\n' >&2
    exit 1
fi

if ! php scripts/newman-report-summary.php --check "$REPORTER_DIR"; then
    printf 'Leak check failed; reports were not published.\n' >&2
    exit 1
fi

mkdir -p -- "$OUT_DIR"
cp -- "$REPORTER_DIR"/* "$OUT_DIR"/

cat "$OUT_DIR/newman-cli.log"
printf '\nSanitized Newman reports are in reports/newman/.\n'
exit "$newman_status"
