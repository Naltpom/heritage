#!/bin/sh
# Démarre (ou arrête) la fausse API et le vrai front, pour filmer les rushes.
#   sh outils/serveurs.sh          → démarre (API :8000, front :5173)
#   sh outils/serveurs.sh arret     → arrête
cd "$(dirname "$0")/.."
ICI="$(pwd)"
mkdir -p sortie
arreter() { for f in sortie/api.pid sortie/front.pid; do [ -f "$f" ] && kill "$(cat "$f")" 2>/dev/null; rm -f "$f"; done; }
arreter
[ "$1" = "arret" ] && exit 0
setsid node outils/fausse-api.mjs > sortie/api.log 2>&1 &
echo $! > sortie/api.pid
cd ../../app
VITE_API_PROXY_TARGET=http://127.0.0.1:8000 setsid npx vite --port 5173 --strictPort > "$ICI/sortie/front.log" 2>&1 &
echo $! > "$ICI/sortie/front.pid"
cd "$ICI"
for i in $(seq 1 60); do curl -s -o /dev/null http://127.0.0.1:5173/api/session && break; sleep 1; done
head -1 sortie/api.log
