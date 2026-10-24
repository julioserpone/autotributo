#!/usr/bin/env bash

set -euo pipefail

repo="${1:-julioserpone/autotributo}"
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ruleset="$root/.github/rulesets/main-protection.json"

if ! command -v gh >/dev/null 2>&1; then
    echo "GitHub CLI (gh) is required." >&2
    exit 1
fi

if ! gh auth status >/dev/null 2>&1; then
    echo "Authenticate with 'gh auth login' before applying repository settings." >&2
    exit 1
fi

echo "Applying merge settings to ${repo}..."
gh api --method PATCH "repos/${repo}" \
    -F allow_squash_merge=true \
    -F allow_merge_commit=false \
    -F allow_rebase_merge=false \
    -F delete_branch_on_merge=true \
    -F allow_update_branch=true >/dev/null

echo "Applying the main-protection ruleset..."
existing_id="$(gh api "repos/${repo}/rulesets" --jq '.[] | select(.name == "main-protection") | .id' 2>/dev/null || true)"

if [[ -n "${existing_id}" ]]; then
    if ! gh api --method PUT "repos/${repo}/rulesets/${existing_id}" --input "${ruleset}" >/dev/null; then
        echo "GitHub rejected the ruleset. Private repositories on GitHub Free require GitHub Pro or public visibility for branch protection/rulesets." >&2
        exit 1
    fi
else
    if ! gh api --method POST "repos/${repo}/rulesets" --input "${ruleset}" >/dev/null; then
        echo "GitHub rejected the ruleset. Private repositories on GitHub Free require GitHub Pro or public visibility for branch protection/rulesets." >&2
        exit 1
    fi
fi

echo "Done. main is protected by the main-protection ruleset."
