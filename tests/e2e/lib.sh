# shellcheck shell=bash
# Shared helpers for the E2E suites. Source right after `set -uo pipefail`:
#
#   . "$(dirname "$0")/lib.sh"        (from a subdirectory: "$(dirname "$0")/../lib.sh")
#
# Provides the env knobs, the PASS/FAIL bookkeeping (check/finish) and the
# cv, shell, database, HTTP and maildev plumbing every suite shares. The
# repository's own helpers go into tests/e2e/lib.local.sh, loaded last when it
# exists. Inside the image (CK_IN_IMAGE=1, the GitLab jobs) the helpers run
# directly instead of through compose.

CK_E2E_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# COMPOSE_DIR may point at a different stack (e.g. a sibling extension's
# full dev stack when this repo's own .docker is a minimal CI-only one).
COMPOSE_DIR="${COMPOSE_DIR:-$CK_E2E_DIR/../../.docker}"
# Evidence such as the bodies of hit(), next to the suite that wrote it; a
# caller without a script file (bash -c) writes next to lib.sh.
if [ -f "$0" ]; then
  OUT_DIR="${OUT_DIR:-$(cd "$(dirname "$0")" && pwd)/out}"
else
  OUT_DIR="${OUT_DIR:-$CK_E2E_DIR/out}"
fi
mkdir -p "$OUT_DIR"
export RUN_ID="${RUN_ID:-$(date +%s)}"

ck_published_url() { # ck_published_url <service> <port> — host URL of a published port, empty if none
  (cd "$COMPOSE_DIR" 2>/dev/null && docker compose port "$1" "$2" </dev/null 2>/dev/null \
    | sed -n 's/^.*:\([1-9][0-9]*\)$/http:\/\/localhost:\1/p') || true
}

# The site and maildev as the host reaches them: their published ports. Empty
# while the stack is down; BASE_URL and MAILDEV_URL override them.
if [ "${CK_IN_IMAGE:-}" = 1 ]; then
  BASE_URL="${BASE_URL:-${CIVIKITCHEN_SITE_URL:-http://localhost:8080}}"
  MAILDEV_URL="${MAILDEV_URL:-http://localhost:1080}"
else
  BASE_URL="${BASE_URL:-$(ck_published_url app 80)}"
  MAILDEV_URL="${MAILDEV_URL:-$(ck_published_url maildev 1080)}"
fi

PASS=0
FAIL=0

check() { # check <name> <condition-result(0/1)> [detail]
  if [ "$2" = "0" ]; then
    echo "  ✔ $1"; PASS=$((PASS + 1))
  else
    echo "  ✘ $1  — ${3:-}"; FAIL=$((FAIL + 1))
  fi
}

note() { echo "  · $*"; }

finish() { # summary line; exit status = suite verdict
  echo
  echo "Result: $PASS passed, $FAIL failed"
  [ "$FAIL" = 0 ]
}

# ── the app container ────────────────────────────────────────────────────
# Standalone runs as www-data from /var/www/html, the buildkit flavors as
# buildkit from their webroot. Set CK_APP_USER and CK_APP_CWD to skip the
# probe; inside the image the job already is the web user.
ck_app_flavor() {
  [ -n "${CK_APP_USER:-}" ] && [ -n "${CK_APP_CWD:-}" ] && return
  local probe='if [ -f /var/www/html/civicrm.standalone.php ]; then echo standalone; else echo buildkit; fi'
  local flavor
  if [ "${CK_IN_IMAGE:-}" = 1 ]; then
    flavor="$(sh -c "$probe")"
  else
    flavor="$(cd "$COMPOSE_DIR" 2>/dev/null && docker compose exec -T app sh -c "$probe" </dev/null 2>/dev/null)"
  fi
  case "$flavor" in
    standalone) CK_APP_USER=www-data CK_APP_CWD=/var/www/html ;;
    buildkit) CK_APP_USER=buildkit CK_APP_CWD=/home/buildkit/buildkit/build/site/web ;;
    *) echo "lib.sh: the app container of $COMPOSE_DIR does not answer" >&2; return 1 ;;
  esac
}
# Probed once here so command substitutions do not repeat it; a stack that is
# still down is probed again on first use.
ck_app_flavor 2>/dev/null || true

app() { # app <shell code> — bash as the web user from the site root; stdin passes through
  ck_app_flavor || return 1
  if [ "${CK_IN_IMAGE:-}" = 1 ]; then
    (cd "$CK_APP_CWD" && bash -c "$1")
  else
    (cd "$COMPOSE_DIR" && docker compose exec -T -u "$CK_APP_USER" -w "$CK_APP_CWD" app bash -c "$1")
  fi
}

cv() { # cv <args...> — as the web user from the site root; stderr dropped, stdin passes through
  ck_app_flavor || return 1
  if [ "${CK_IN_IMAGE:-}" = 1 ]; then
    (cd "$CK_APP_CWD" && command cv "$@" 2>/dev/null)
  else
    (cd "$COMPOSE_DIR" && docker compose exec -T -u "$CK_APP_USER" -w "$CK_APP_CWD" app cv "$@" 2>/dev/null)
  fi
}

# cv_scr [--user=<name>] <php file on the host> — runs it with cv scr, as that
# CiviCRM user when given; inline code: cv_scr /dev/stdin <<< "$code"
cv_scr() {
  local user=''
  case "${1:-}" in --user=*) user=" $(printf '%q' "$1")"; shift ;; esac
  app "f=\$(mktemp --suffix=.php) && cat > \"\$f\" && cv scr$user \"\$f\"; rc=\$?; rm -f \"\$f\"; exit \$rc" < "$1"
}

site_key() { cv ev 'echo CIVICRM_SITE_KEY;'; }

sql() { # sql <query> — tab-separated rows; PII-free queries only (ids, counts, metadata)
  if [ "${CK_IN_IMAGE:-}" = 1 ]; then
    command mariadb -h db -N -B -ucivicrm -pcivicrm civicrm -e "$1" 2>/dev/null
  else
    (cd "$COMPOSE_DIR" && docker compose exec -T db mariadb -N -B -ucivicrm -pcivicrm civicrm -e "$1" </dev/null 2>/dev/null)
  fi
}

# ── HTTP ─────────────────────────────────────────────────────────────────
# hit <label> <url> [curl config lines...] — prints the status code and keeps
# the body in $OUT_DIR/<label>.body. curl reads its configuration from stdin,
# so a credential in a header line appears in no process list.
hit() {
  local label="$1" url="$2"; shift 2
  rm -f "$OUT_DIR/$label.body"
  {
    printf 'url = "%s"\n' "$url"
    printf 'output = "%s"\n' "$OUT_DIR/$label.body"
    printf 'write-out = "%%{http_code}"\n'
    printf 'silent\ngloboff\n'
    printf '%s\n' "$@"
  } | curl -K -
}

# body_lacks <label> <extended-regex> — 0 when the body exists, is non-empty
# and does not match. A missing body fails instead of passing silently.
body_lacks() {
  local f="$OUT_DIR/$1.body"
  [ -s "$f" ] || return 1
  ! grep -qE "$2" "$f"
}

# cfg_value <string> — escaped for a double-quoted curl config value, so the
# quotes of a JSON payload do not end the value early.
cfg_value() {
  printf '%s' "$1" | sed -e 's/\\/\\\\/g' -e 's/"/\\"/g'
}

auth_header() { # auth_header [header] — a curl config line sending $TOKEN as a bearer credential
  printf 'header = "%s: Bearer %s"' "${1:-X-Civi-Auth}" "$TOKEN"
}

# ── maildev ──────────────────────────────────────────────────────────────
# Filtered by the app container's jq, so the host needs no JSON tool. An empty
# or broken answer from maildev is an error, never zero mails.
ck_mails_to() { # ck_mails_to <recipient> <jq filter> — the filter over the mails addressed To: the recipient
  curl -s "$MAILDEV_URL/email" | app "jq -nr --arg to $(printf %q "$1") \
    '[(try input catch error(\"maildev sent no mail list\"))[] | select(any(.to[]; .address == \$to))] | $2'"
}

mail_count() { # mail_count <recipient> — mails addressed To: the recipient
  ck_mails_to "$1" length
}

newest_mail_text() { # newest_mail_text <recipient> — body text of the newest mail
  ck_mails_to "$1" 'last | .text // ""'
}

mail_clear() { curl -s -X DELETE "$MAILDEV_URL/email/all" >/dev/null; }

if [ -f "$CK_E2E_DIR/lib.local.sh" ]; then
  # shellcheck source=/dev/null
  . "$CK_E2E_DIR/lib.local.sh"
fi
