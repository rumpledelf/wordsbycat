#!/bin/sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname "$0")" && pwd)
REPO_ROOT=$(CDPATH= cd -- "$SCRIPT_DIR/.." && pwd)

ARCHIVE_ROOT=${ARCHIVE_ROOT:-"$HOME/Library/Mobile Documents/com~apple~CloudDocs/projects/wordsbycat"}
REMOTE_TARGET=${REMOTE_TARGET:-"deploy@wordsbycat.com:/var/www/wordsbycat"}

copy_tree() {
    src=$1
    dest=$2

    mkdir -p "$dest"

    if command -v rsync >/dev/null 2>&1; then
        rsync -a "$src"/ "$dest"/
    else
        cp -R "$src"/. "$dest"/
    fi
}

deploy_public() {
    src=$1
    dest=$2

    if command -v rsync >/dev/null 2>&1; then
        rsync -a --delete "$src"/ "$dest"/
    else
        rm -rf "$dest"
        mkdir -p "$dest"
        cp -R "$src"/. "$dest"/
    fi
}

echo "Compiling static site..."
"$SCRIPT_DIR/compile"

echo "Archiving source assets to $ARCHIVE_ROOT"
mkdir -p "$ARCHIVE_ROOT"

copy_tree "$REPO_ROOT/data/originals" "$ARCHIVE_ROOT/data/originals"
copy_tree "$REPO_ROOT/data/derived" "$ARCHIVE_ROOT/data/derived"
copy_tree "$REPO_ROOT/data/thumbs" "$ARCHIVE_ROOT/data/thumbs"
copy_tree "$REPO_ROOT/data/letters" "$ARCHIVE_ROOT/data/letters"
copy_tree "$REPO_ROOT/data/site" "$ARCHIVE_ROOT/data/site"
copy_tree "$REPO_ROOT/bin" "$ARCHIVE_ROOT/bin"
copy_tree "$REPO_ROOT/includes" "$ARCHIVE_ROOT/includes"

cp "$REPO_ROOT/README.md" "$ARCHIVE_ROOT/README.md"
cp "$REPO_ROOT/index.php" "$ARCHIVE_ROOT/index.php"
cp "$REPO_ROOT/generate_caption.php" "$ARCHIVE_ROOT/generate_caption.php"
cp "$REPO_ROOT/favicon.ico" "$ARCHIVE_ROOT/favicon.ico"
cp "$REPO_ROOT/CNAME" "$ARCHIVE_ROOT/CNAME"

if [ "${1:-}" = "--archive-only" ]; then
    echo "Archive complete."
    exit 0
fi

echo "Deploying public/ to $REMOTE_TARGET"

if command -v rsync >/dev/null 2>&1; then
    rsync -a --delete "$REPO_ROOT/public"/ "$REMOTE_TARGET"/
else
    scp -rp "$REPO_ROOT/public"/. "$REMOTE_TARGET"/
fi

echo "Deploy complete."
