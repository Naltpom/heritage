# Les vidéos d'aide

Les vidéos de la page Aide et du lien d'invitation, en 1080 × 1920. Elles ont une voix off française, des sous-titres en karaoké, une musique composée ici et des bruitages. Toutes les données montrées sont fictives (famille Martin, Bernard, Dubois, Lefèvre).

## Fabriquer une vidéo

```sh
npm install                 # puppeteer-core (utilise le Chrome installé)
pip install edge-tts numpy scipy pillow
python voix.py <id>         # la voix et l'instant de chaque mot (voix/<id>.json)
node rendre.mjs <id>        # les images, puis sortie/<id>.muet.mp4 et sortie/<id>.piste.json
python mixer.py <id>        # musique, bruitages, voix, puis app/public/videos/<id>.{mp4,jpg,vtt}
```

Pour un aperçu de quelques images, sans tout rendre :

```sh
APERCU="1,5.5,12" node rendre.mjs <id>
python planche.py <id>
```

`sortie/apercu/` reçoit les images, et `sortie/planche_<id>.jpg` les met côte à côte.

## Les pièces

- `textes.json` : les phrases dites, une par plan. **C'est ce texte qui décide du rythme**, parce que l'image suit la voix.
- `commun.js` : le moteur.
  - `minuter()` place les phrases selon la voix, et `M(n, k)` donne l'instant où se dit le mot `k` de la phrase `n`.
  - Il fournit aussi le karaoké (`legende`), le doigt qui touche (`doigt`), les éléments d'interface, l'emblème de fin et les bruitages (`son`).
- `arbre.js` : l'arbre fictif des chapitres (Camille Martin et cinq générations).
- `scenes/<id>.js` : une scène par vidéo. `scenes/decouvrir.html` est la vidéo de présentation, autonome.
- `studio.html?v=<id>` : la page que `rendre.mjs` filme image par image.

## Les règles de lecture

- Une seule chose bouge à la fois : le geste, puis la phrase.
- La phrase entière s'affiche en gris, et chaque mot s'allume quand il est dit.
- Le plan suivant part 0,4 à 0,6 s après le dernier mot.
- En haut, environ 200 px restent libres pour la croix et le bouton du son du lecteur. En bas, environ 100 px restent libres pour la barre de progression.

La voix est générée par la synthèse Edge de Microsoft (`fr-FR-DeniseNeural`, et `fr-FR-RemyMultilingualNeural` pour « Un mot de Nathan »). Seul le texte des phrases leur est envoyé.
