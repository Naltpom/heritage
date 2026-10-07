# La vidéo de présentation

Environ 1 min 50, 1920 × 1080, 30 i/s, H.264. La voix off est celle du créateur, générée avec ElevenLabs. Tout ce qu'on voit de l'application est **la vraie application** (`app/`), filmée image par image sur une famille fictive de 2 339 personnes réparties sur 14 générations.

## Fabriquer la vidéo

```sh
cd videos && npm install && cd presentation        # puppeteer-core (Chromium installé)
pip install numpy scipy pillow
(cd ../../app && npm ci)                            # le vrai front

python narration/voix.py              # 1. la voix (ElevenLabs), découpée et alignée mot à mot
node outils/fabrique.mjs              # 2. les images fabriquées (photos anciennes, actes)
sh outils/serveurs.sh                 # 3. la fausse API (:8000) et le vrai front (:5173)
node outils/rushes.mjs                # 4. les rushes : l'application filmée, calée sur la timeline
sh outils/serveurs.sh arret

APERCU_BD=1 node rendre.mjs && python mixer.py --bd   # 5a. aperçu basse définition (960×540, 15 i/s)
node rendre.mjs && python mixer.py                    # 5b. la version finale
```

Sorties : `sortie/heritage-presentation.mp4` (finale) et `sortie/heritage-presentation-bd.mp4` (aperçu).

Pour juger le rythme sans rien rendre, ouvrez `index.html` dans Chrome : c'est un lecteur avec la voix. Chrome doit être lancé avec `--allow-file-access-from-files`.

Pour vérifier quelques images : `IMAGES="12,40.5,88" node rendre.mjs`, qui écrit dans `sortie/images/`. Ensuite, `python outils/planche.py sortie/images 12 sortie/planche.jpg`.

## Modifier

- **Le rythme** se règle dans `timeline.js`, sans toucher aux scènes :
  - `PAUSES` fixe le silence après chaque phrase ;
  - `REPERES` accroche chaque mouvement à un mot de la voix.

  Si la voix change, les images se recalent d'elles-mêmes. Il faut alors refilmer les rushes (`node outils/rushes.mjs`), car ils sont calés sur la timeline.
- **Le texte** est dans `narration/texte.json`. `texte` contient ce qui s'affiche, et `dit` ce qu'on envoie à ElevenLabs quand c'est différent : les nombres en toutes lettres, et « Héritage » accentué pour une prononciation française.
  - `python narration/voix.py --decouper` redécoupe la prise en cache sans rien payer.
  - Les réglages de voix sont dans `REGLAGES` : stabilité 0,5, aucun style, vitesse 1,05.
- **Les gestes dans l'application** (caméra de l'arbre, frappe, clics) sont dans `outils/rushes.mjs`, un plan par entrée de `PLANS`.
- **La famille** est générée de façon déterministe par `donnees/famille.js`. Elle est servie au vrai front par `outils/fausse-api.mjs`, dans les formats de `docs/api-contract.md`.

## Les pièces

| Dossier, fichier | Rôle |
| --- | --- |
| `narration/` | Le texte de la voix off, la prise ElevenLabs (`prise/`, en cache), les segments et l'instant de chaque mot (`voix.json`, `voix.js`) |
| `timeline.js` | La timeline centrale : pauses et repères |
| `moteur.js` | Le canevas, les courbes, le texte, la caméra, le grain |
| `ui/composants.js` | Lecture des rushes, projecteur, répliques de l'application (fiche de l'arbre, logo), pastilles, onde |
| `scenes/*.js` | Une scène par partie : intro, heritage, arbre, exploration, participation, ia, fin |
| `composition.js` | L'assemblage, la bande des bruitages, le lecteur d'aperçu |
| `donnees/` | La famille fictive et son vocabulaire (prénoms par époque, métiers, communes) |
| `outils/fausse-api.mjs` | Une API Heritage fictive pour le vrai front |
| `outils/horloge-virtuelle.js` | L'horloge injectée dans l'application filmée : chaque image avance d'1/30 s exactement |
| `outils/rushes.mjs` | Le tournage des plans de l'application |
| `outils/fabrique.*` | Les images fabriquées : paysages anciens, dos de photos annotés, actes d'état civil |
| `rendre.mjs` | Le rendu image par image dans Chromium, en parallèle, puis l'encodage H.264 |
| `mixer.py` | La musique (composée ici, sans droits), les bruitages discrets, la voix, puis l'assemblage MP4 |
| `assets/` | Polices (OFL) et images fabriquées |

## Ce qui est montré

- **Les données :** toutes fictives. La famille Martin, Bernard, Dubois, Lefèvre, et Joséphine Perrin (1868–1949), fille de Mathurin Perrin et de Perrine Le Gall.
- **Les photos et les actes :** dessinés par `outils/fabrique.html`. Ce ne sont ni de vraies photos, ni de vrais documents.
- **Les données envoyées à ElevenLabs :** seul le texte de la voix off.
