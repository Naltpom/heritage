#!/bin/sh
# Les versions claires : mêmes images en thème clair, même bande-son que la version sombre.
#   sh clair.sh bienvenue lire-l-arbre …
cd "$(dirname "$0")"
PUB=../app/public/videos
for v in "$@"; do
  THEME=clair node rendre.mjs "$v" || exit 1
  ffmpeg -v error -y -i "sortie/$v-clair.muet.mp4" -i "$PUB/$v.mp4" -map 0:v -map 1:a -c copy -shortest -movflags +faststart "$PUB/$v-clair.mp4" || exit 1
  ffmpeg -v error -y -i "sortie/$v-clair.jpg" -vf scale=540:-2 -q:v 4 "$PUB/$v-clair.jpg" || exit 1
  echo "$v-clair : ok"
done
