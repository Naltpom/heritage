# `monde-110m.geo.json` — le fond de carte par défaut

177 pays, 172 Ko sur disque, **≈ 54 Ko une fois gzippé par nginx**. C'est le fond
**servi par l'application elle-même**, et c'est ce qui permet à la carte de
fonctionner sans qu'aucune requête ne parte vers un tiers : la CSP reste
entièrement same-origin tant que personne n'appuie sur « Fond détaillé ».

## Provenance et licence

- **Natural Earth**, 1:110m Cultural Vectors — Admin 0 Countries, via le dépôt
  officiel `nvkelso/natural-earth-vector`.
- **Domaine public.** Natural Earth : « All versions … are in the public domain …
  No permission is needed to use Natural Earth. Crediting the authors is
  unnecessary. » Donc **aucune attribution obligatoire**, et aucune mention légale
  à afficher sur la carte — contrairement aux tuiles OSM, qui en exigent une.
- SHA-256 du GeoJSON amont : `6866c877d39cba9c357620878839b336d569f8c662d3cfab4cb1dbe2d39c977f`

## Comment il est fabriqué

C'est un **artefact de construction, commité tel quel** et jamais régénéré au build :
pas de réseau dans la CI, et un fichier reproductible qu'on peut relire.

```bash
curl -sSL -o ne110m.geojson   https://raw.githubusercontent.com/nvkelso/natural-earth-vector/master/geojson/ne_110m_admin_0_countries.geojson
node monde-110m.simplification.js
```

`monde-110m.simplification.js` ne garde que `nom` (le `NAME_FR` de Natural Earth) et
`iso`, et arrondit les coordonnées à **2 décimales** — environ 1,1 km à l'équateur,
invisible au zoom monde.

**Le point non évident** : un arrondi laisse des points consécutifs confondus, et un
anneau qui en garde deux identiques se dessine en pointe chez Leaflet. Le script
déduplique donc les points consécutifs, referme l'anneau, et **jette tout anneau
retombé sous 4 points**. Les 177 pays survivent tous à ce traitement — si un jour ce
nombre baisse, c'est que l'arrondi est devenu trop grossier.

## Comment il est chargé

Par `import urlMonde from '@/assets/monde-110m.geo.json?url'` puis `fetch()`, et
**surtout pas** par un import JSON direct : celui-ci inlinerait 172 Ko dans le chunk
JavaScript, qu'il faudrait analyser à chaque visite. En `?url`, Vite en fait un actif
empreinté sous `/assets/`, que `app/nginx.conf` sert en `immutable` pour un an —
téléchargé une fois, jamais deux. Le ranger dans `public/` le ferait au contraire
revalider à chaque visite, pour toujours.

## Couleurs

Aucune n'est ici. Le fond est peint par `app/src/styles/carte.css` à partir des
jetons du thème — mer `--color-fond`, terres `--color-panneau`, côtes
`--color-ligne` — donc il suit le thème sombre tout seul. C'est l'autre raison de
préférer ce fond aux tuiles : une tuile raster reste claire en thème sombre.
