#!/usr/bin/env bash
# Committed env files must never contain real secrets.
#   .env, .env.prod, .env.example : secret-like variables are empty (or "change-me")
#   .env.dev, .env.test           : values are explicitly development/test values
# Real values live in .env.local (ignored), the environment or a secret manager.
set -euo pipefail
cd "$(dirname "$0")/../.."

status=0
secret_re='(SECRET|PASSWORD|PASSWD|TOKEN|API_KEY|PRIVATE_KEY|ENCRYPTION_KEY|_KEY)$'

for file in .env .env.prod .env.example .env.dev .env.test; do
    [ -f "$file" ] || continue
    while IFS= read -r line || [ -n "$line" ]; do
        case "$line" in ''|'#'*) continue ;; esac
        name="${line%%=*}"
        value="${line#*=}"
        value="${value%%#*}"                                # strip inline comments
        value="$(printf '%s' "$value" | sed -e 's/^[[:space:]"'\'']*//' -e 's/[[:space:]"'\'']*$//')"
        [[ "$name" =~ $secret_re ]] || continue
        [ -z "$value" ] && continue
        case "$file" in
            .env.dev|.env.test)
                decoded="$(printf '%s' "$value" | base64 -d 2>/dev/null || true)"
                if ! printf '%s %s' "$value" "$decoded" | grep -qiE 'dev|test'; then
                    echo "::error file=$file::$name must be an explicit development/test value (containing \"dev\" or \"test\")."
                    status=1
                fi
                ;;
            *)
                if [ "$value" != 'change-me' ]; then
                    echo "::error file=$file::$name must be empty in a committed file (set it in .env.local or the environment)."
                    status=1
                fi
                ;;
        esac
    done < "$file"
done

[ "$status" -eq 0 ] && echo "Committed env files contain no secret."
exit "$status"
