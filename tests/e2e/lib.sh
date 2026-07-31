# shellcheck shell=bash
# Shared helpers for the E2E suites. Source right after `set -uo pipefail`:
#
#   . "$(dirname "$0")/lib.sh"
#
# Provides the common env knobs (override via environment), the PASS/FAIL
# bookkeeping (check/finish) and the cv/maildev plumbing every suite shares.
# Suite-specific helpers (seed data, API calls, link extraction, …) stay in
# their suite — keep this file generic.

MAILDEV_URL="${MAILDEV_URL:-http://localhost:1080}"
# COMPOSE_DIR may point at a different stack (e.g. a sibling extension's
# full dev stack when this repo's own .docker is a minimal CI-only one).
COMPOSE_DIR="${COMPOSE_DIR:-$(dirname "$0")/../../.docker}"
export RUN_ID="${RUN_ID:-$(date +%s)}"

PASS=0
FAIL=0

check() { # check <name> <condition-result(0/1)> [detail]
  if [ "$2" = "0" ]; then
    echo "  ✔ $1"; PASS=$((PASS + 1))
  else
    echo "  ✘ $1  — ${3:-}"; FAIL=$((FAIL + 1))
  fi
}

finish() { # summary line; exit status = suite verdict
  echo
  echo "Result: $PASS passed, $FAIL failed"
  [ "$FAIL" = 0 ]
}

cv() {
  (cd "$COMPOSE_DIR" && docker compose exec -T app cv "$@" 2>/dev/null)
}

mail_count() { # mail_count <recipient> — mails addressed To: the recipient
  curl -s "$MAILDEV_URL/email" | python3 -c "
import json, sys
print(sum(1 for m in json.load(sys.stdin) if any(t['address'] == sys.argv[1] for t in m['to'])))" "$1"
}

newest_mail_text() { # newest_mail_text <recipient> — body text of the newest mail
  curl -s "$MAILDEV_URL/email" | python3 -c "
import json, sys
mails = [m for m in json.load(sys.stdin) if any(t['address'] == sys.argv[1] for t in m['to'])]
print(mails[-1].get('text') or '' if mails else '')" "$1"
}
