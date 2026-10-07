# La vidéo verticale de présentation

Format : 1080 × 1920 (9:16), 30 i/s, H.264, environ 1 min 41. La vidéo est pensée pour le téléphone (Reels, Shorts, TikTok). Rien n'est recadré depuis du 16:9.

- **La voix :** celle du créateur, générée par ElevenLabs (Eleven v4) en une seule prise, sur le script exact avec ses audio tags.
- **L'application :** tout ce qu'on voit d'elle est la vraie application (`app/`) en version smartphone, filmée image par image. Elle tourne sur une famille fictive de 2 307 personnes sur 14 générations.

## Fabriquer la vidéo

```sh
cd videos && npm install && cd verticale     # puppeteer-core (Chromium installé)
pip install numpy scipy pillow
(cd ../../app && npm ci)                     # le vrai front

python narration/voix.py      # 1. la voix (ElevenLabs v4, sinon v3) : narration/prise/ + voix.json
node outils/fabrique.mjs      # 2. les images fabriquées (photos anciennes, actes)
sh outils/serveurs.sh         # 3. la fausse API (:8000) et le vrai front (:5173)
node outils/rushes.mjs        # 4. les rushes : l'app mobile filmée, calée sur la voix
sh outils/serveurs.sh arret

APERCU_BD=1 node rendre.mjs && python mixer.py --bd   # 5a. aperçu 540×960 (15 i/s)
node rendre.mjs && python mixer.py                    # 5b. version finale 1080×1920
```

Sorties : `sortie/heritage-verticale.mp4` et `sortie/heritage-verticale-bd.mp4`.

- `python narration/voix.py --analyser` réanalyse la prise en cache sans rien payer.
- Pour régler le rythme sans rien rendre, ouvrir `index.html` dans Chrome, lancé avec `--allow-file-access-from-files`. C'est un lecteur 9:16 avec la voix.
- Pour vérifier quelques images : `IMAGES="12,40.5,88" node rendre.mjs`, puis `python outils/planche.py sortie/images 12 planche.jpg`.

## Le rythme

La vidéo est montée sur la prise de voix réelle. `timeline.js` est la timeline centrale :

- `T0` et `QUEUE` règlent le souffle avant la voix et la respiration après « Heritage. ».
- `REPERES` accroche chaque mouvement à un mot réel de la prise, sous la forme `[début de ligne du script, mot, décalage]`.
- `SCENE` donne la plage de chaque scène. Décaler une scène, c'est changer ses repères : les autres ne bougent pas.

Les rushes sont calés sur la même timeline. Après un changement de voix ou de repères, il faut les refilmer avec `node outils/rushes.mjs`.

## Les pièces

| Fichier | Rôle |
| --- | --- |
| `narration/` | Le script exact (`script.txt`), la prise ElevenLabs (`prise/`, en cache), les instants de chaque mot (`voix.json`) |
| `timeline.js` | La timeline centrale : repères et plages des scènes |
| `moteur.js` | Le canevas 1080 × 1920, les courbes, le texte, le grain |
| `ui/composants.js` | Lecture des rushes, projecteur, toucher du doigt, sous-titres, logo, objets de mémoire (photo, téléphone, dossier, note, boîte) |
| `scenes/` | Une scène par fichier : 1 mémoire, 2 dispersion, 3 création, 4 arbre et sources, 6 la famille participe, 7 questions, 8 explorer, 9 fin |
| `composition.js` | L'assemblage, les bruitages, le lecteur d'aperçu |
| `donnees/` | La famille fictive (2 307 personnes) et son vocabulaire |
| `outils/fausse-api.mjs` | Une API Heritage fictive pour le vrai front, dans les formats de `docs/api-contract.md` |
| `outils/horloge-virtuelle.js` | L'horloge injectée dans l'app filmée : chaque image avance d'1/30 s exactement |
| `outils/rushes.mjs` | Le tournage des 14 plans de l'app mobile (viewport 405 × 720 à ×2,667) |
| `outils/fabrique.*` | Les images fabriquées : paysages anciens, dos de photos, actes d'état civil |
| `rendre.mjs` | Le rendu image par image (avec contrôle du ratio 9:16), puis l'encodage H.264 |
| `mixer.py` | La musique d'ambiance (composée ici), les bruitages, la voix, puis le MP4 |

## Sous-titres

- **Placement :** dans la zone de sécurité verticale, centrés vers y = 1395, sur 860 px de large au plus. Ils restent au-dessus des légendes et des boutons des applications, et à l'écart des icônes de droite.
- **Taille :** 54 px, sur un voile sombre.
- **Contenu :** les audio tags ne sont pas sous-titrés. Le dernier « Heritage. » non plus, puisque le logo le dit.

## Ce qui est montré

- **Les données :** toutes fictives (Joséphine Perrin, Jean Martin, Mathurin Perrin, etc.).
- **Les photos et les actes :** dessinés par `outils/fabrique.html`. Ce ne sont ni de vraies photos, ni de vrais documents.
- **Les données envoyées à ElevenLabs :** seul le texte du script.
