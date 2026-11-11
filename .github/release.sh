#!/usr/bin/env bash
# Publication of a version of the module: tag vX.Y.Z, GitHub release with the zip installable from Dolibarr
# (Setup > Modules > Deploy an external module), branch X.Y on the published commit.
#
# Branches:
#   develop  daily work, versions raised at each FIX / NEW, nothing published
#   stable   « I publish »: git push origin develop:stable, the release is marked Latest and the branch X.Y
#            is created or moved forward (fast-forward only) on this commit
#   X.Y      maintenance of a published series: a fix committed there with X.Y.Z raised gives a release
#            not marked Latest (unless it is the highest version), then merge X.Y into develop
#
# Run by .github/workflows/release.yml. Needs MODULE (dir of the module in the zip), DESCRIPTOR (path of the
# module descriptor), TITLE (name of the module in the release title) and GH_TOKEN.
# Same file in every module of THERSANE-DOLI that publishes its releases this way.
set -euo pipefail

: "${MODULE:?}" "${DESCRIPTOR:?}" "${TITLE:?}" "${GH_TOKEN:?}"
branch="${GITHUB_REF_NAME:?}"
sha="${GITHUB_SHA:?}"

fail() {
	echo "::error::$*"
	exit 1
}

# true when the version $1 is higher than the version $2
vgt() {
	[ "$1" != "$2" ] && [ "$(printf '%s\n%s\n' "$1" "$2" | sort -V | tail -n1)" = "$1" ]
}

v=$(grep -oP "\\\$this->version\s*=\s*'\K[0-9]+\.[0-9]+\.[0-9]+(?=')" "$DESCRIPTOR" || true)
[ -n "$v" ] || fail "No version X.Y.Z found in $DESCRIPTOR"
xy="${v%.*}"

if [ "$branch" != stable ] && [ "$branch" != "$xy" ]; then
	fail "Version $v cannot be published from the branch $branch (stable, or $xy for a fix of this series)"
fi

if git rev-parse -q --verify "refs/tags/v$v" >/dev/null; then
	echo "::notice::v$v already published, nothing to do (version of the descriptor not raised?)"
	exit 0
fi

# Versions already published, lowest first
tags=$(git tag -l 'v[0-9]*' | sed -n 's/^v\([0-9]\+\.[0-9]\+\.[0-9]\+\)$/\1/p' | sort -V)
last=$(printf '%s\n' "$tags" | tail -n1)
lastxy=$(printf '%s\n' "$tags" | grep "^${xy//./\\.}\." | tail -n1 || true)

if [ "$branch" = stable ]; then
	if [ -n "$last" ] && ! vgt "$v" "$last"; then
		fail "Version $v is not higher than the last one published ($last): stable only goes forward"
	fi
	from="$last"
	# The branch X.Y follows stable: refused when it holds a fix that develop does not have yet
	if git ls-remote --exit-code --heads origin "$xy" >/dev/null; then
		git fetch -q origin "refs/heads/$xy"
		git merge-base --is-ancestor FETCH_HEAD "$sha" \
			|| fail "Branch $xy holds commits that stable does not have: merge $xy into develop, then push develop on stable again"
	fi
	git push -q origin "$sha:refs/heads/$xy"
else
	if [ -n "$lastxy" ] && ! vgt "$v" "$lastxy"; then
		fail "Version $v is not higher than the last one published in $xy ($lastxy)"
	fi
	from="$lastxy"
fi

latest=false
if [ -z "$last" ] || vgt "$v" "$last"; then
	latest=true
fi

work=$(mktemp -d)

# Dolibarr wants the dir of the module at the root of the zip and a name module_xxx-x.y.z.zip
# (entries export-ignore of .gitattributes left out)
zip="$work/module_${MODULE}-$v.zip"
git archive --format=zip --prefix="$MODULE/" -o "$zip" "$sha"

# Notes: entries of the ChangeLog (« - NEW: ... :   X.Y.Z » and their sub-items) published since the previous
# release of this line, up to $v; without a previous release, the entries of the series X.Y.
# Works with or without sections « ## X.Y ».
awk -v hi="$v" -v lo="$from" -v first="$xy.0" '
	function cmp(a, b,    i, x, y) {
		split(a, x, ".")
		split(b, y, ".")
		for (i = 1; i <= 3; i++) {
			if (x[i] + 0 != y[i] + 0) {
				return (x[i] + 0 > y[i] + 0) ? 1 : -1
			}
		}
		return 0
	}
	function flush() {
		if (entry != "" && ver != "" && cmp(ver, hi) <= 0 && (lo == "" ? cmp(ver, first) >= 0 : cmp(ver, lo) > 0)) {
			out = out entry "\n"
		}
		entry = ""
		ver = ""
	}
	function version() {
		if (ver == "" && $0 ~ /:[ \t]+[0-9]+\.[0-9]+\.[0-9]+[ \t]*$/ && match($0, /[0-9]+\.[0-9]+\.[0-9]+[ \t]*$/)) {
			ver = substr($0, RSTART, RLENGTH)
			gsub(/[ \t]/, "", ver)
		}
	}
	/^#/ { flush(); next }
	/^- / { flush(); entry = $0 "\n"; version(); next }
	/^[ \t]*$/ { next }
	entry != "" { entry = entry $0 "\n"; version() }
	END { flush(); printf "%s", out }
' ChangeLog.md > "$work/notes.md"
[ -s "$work/notes.md" ] || echo "$TITLE $v" > "$work/notes.md"

gh release create "v$v" "$zip" --title "$TITLE $v" --notes-file "$work/notes.md" --target "$sha" --latest="$latest"
echo "::notice::Published v$v (latest: $latest)"
