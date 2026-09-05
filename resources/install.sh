#!/bin/bash

REQUESTED_PROGRESS_FILE="${1:-dependance}"
BASEDIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
PLUGIN=$(basename "$(realpath "$BASEDIR/..")")
PROGRESS_FILENAME=$(basename "$REQUESTED_PROGRESS_FILE")
NODE_DIR="$BASEDIR/node"
LIBRARY_URL="https://raw.githubusercontent.com/NebzHB/dependance.lib/master"

download_dependency_helper() {
    local name="$1"
    if command -v wget >/dev/null 2>&1; then
        wget -4 "$LIBRARY_URL/$name" --no-cache -O "$BASEDIR/$name" >/dev/null 2>&1
        return $?
    fi
    if command -v curl >/dev/null 2>&1; then
        curl -4fsSL "$LIBRARY_URL/$name" -o "$BASEDIR/$name"
        return $?
    fi
    return 1
}

if ! download_dependency_helper "dependance.lib" || ! download_dependency_helper "install_nodejs.sh"; then
    echo "Impossible de récupérer NebzHB/dependance.lib"
    exit 1
fi

# shellcheck source=/dev/null
. "$BASEDIR/dependance.lib"

pre
step 5 "Mise à jour des dépôts système"
try sudo apt-get -o Acquire::ForceIPv4=true update

step 10 "Installation des extensions PHP nécessaires"
try sudo DEBIAN_FRONTEND=noninteractive apt-get -o Acquire::ForceIPv4=true install -y php-zip php-xml php-curl ca-certificates

# La version minimale est lue dans resources/package.json par dependance.lib.
# shellcheck source=/dev/null
. "$BASEDIR/install_nodejs.sh" --firstSubStep 20 --lastSubStep 60

step 70 "Installation des modules Node.js du plugin"
try npm ci --prefix "$NODE_DIR" --omit=dev --no-audit --no-fund

step 90 "Vérification du démon LocalHomeConnect"
try env NODE_PATH="$NODE_DIR/node_modules" node -e "require.resolve('ws');require.resolve('fast-xml-parser');require.resolve('multicast-dns');"
try chmod 0755 "$BASEDIR/localhomeconnectd.js"

post
