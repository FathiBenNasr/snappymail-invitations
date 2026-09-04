#!/bin/sh
# Prépare et lance le banc navigateur des invitations, en une seule commande.
#
# ⚠️ **Ce script existe à cause d'un cas mesuré le 4 septembre 2026 sur
# `snappymail-servicedesk`** : son banc éprouvait depuis cinq jours une copie du
# greffon d'avant son garde `authentifie()`, parce que son README demandait de
# rafraîchir les copies **à la main**. Il restait vert. Ici deux fichiers du
# greffon et un knockout sont recopiés — même risque, même remède.
#
# ⚠️ **Le nom d'arrivée de knockout n'est pas son nom de départ** : `apercu.html`
# charge `knockout.js`. Déposé sous son nom d'origine, le banc s'arrête sur
# « Cannot read properties of undefined (reading 'r') » — knockout est absent, et
# c'est le greffon qui tombe, pas la page.
#
# Usage, depuis la racine du dépôt :   sh tests/browser/preparer.sh
set -eu

RACINE=$(cd "$(dirname "$0")/../.." && pwd)
CIBLE=${CIBLE:-/essai/invitations}
KNOCKOUT=${KNOCKOUT:-../snappymail/vendors/knockout/build/output/knockout-latest.js}
cd "$RACINE"

podman exec puppeteer-test mkdir -p "$CIBLE/tests/browser" "$CIBLE/plugin"
podman cp tests/browser/apercu.html "puppeteer-test:$CIBLE/tests/browser/"
podman cp tests/browser/journee.js  "puppeteer-test:$CIBLE/tests/browser/"
podman cp "$KNOCKOUT" "puppeteer-test:$CIBLE/tests/browser/knockout.js"
# Le greffon lui-même, à chaque passage.
podman cp plugin/invitations.js  "puppeteer-test:$CIBLE/plugin/"
podman cp plugin/invitations.css "puppeteer-test:$CIBLE/plugin/"
podman exec puppeteer-test ln -sf /app/scripts/node_modules "$CIBLE/tests/browser/node_modules"

podman exec puppeteer-test node "$CIBLE/tests/browser/journee.js"
