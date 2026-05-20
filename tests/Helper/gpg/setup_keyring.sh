#!/usr/bin/env bash
# Deterministically (re-)build the test GPG keyring used by Cerebrate's
# mailer test suite.
#
# Usage:
#   tests/Helper/gpg/setup_keyring.sh
#
# Idempotent: deletes any existing keyring/ and imports the committed
# fixture-public.asc and fixture-secret.asc into a fresh homedir.
#
# The fixture passphrase is documented in README.md next to this script.

set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
KEYRING="$HERE/keyring"
PASSPHRASE="cerebrate-test"

PUB="$HERE/fixture-public.asc"
SEC="$HERE/fixture-secret.asc"

if [ ! -f "$PUB" ] || [ ! -f "$SEC" ]; then
    echo "[setup_keyring.sh] Missing fixture-public.asc / fixture-secret.asc next to this script." >&2
    exit 1
fi

rm -rf "$KEYRING"
mkdir -p "$KEYRING"
chmod 700 "$KEYRING"

export GNUPGHOME="$KEYRING"

gpg --batch --import "$PUB" > /dev/null 2>&1
gpg --batch --pinentry-mode loopback --passphrase "$PASSPHRASE" --import "$SEC" > /dev/null 2>&1

# Mark the fixture key ultimately-trusted so encrypt operations don't
# prompt for an "untrusted_key.override" confirmation. Crypt_GPG does
# not surface a --trust-model option, so we set ownertrust here.
FPR=$(gpg --batch --with-colons --list-keys --fingerprint | awk -F: '/^fpr:/ {print $10; exit}')
if [ -n "$FPR" ]; then
    echo "$FPR:6:" | gpg --batch --import-ownertrust > /dev/null 2>&1
fi

echo "[setup_keyring.sh] Keyring ready at $KEYRING"
gpg --list-keys --with-colons | awk -F: '/^pub:/ {print "  pub " $5} /^uid:/ {print "  uid " $10}'
