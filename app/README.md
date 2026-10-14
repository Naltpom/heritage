# `app/` — front de « Heritage »

Vue 3 + TypeScript strict + Vite + Tailwind 4 + `vue-router` + Vitest. Node 22.

Le **socle** est posé : routeur et gardes d'accès, charte graphique, client
d'API, gestion de session, composants de base. Les **écrans** le sont aussi —
arbre, fiche, chronologie, carte, anniversaires, propositions, médias,
administration. Plus aucune vue ne rend d'`<EcranAPoser>` ; s'il en reparaît un,
c'est qu'un écran manque.

> **Si vous ouvrez ce fichier pour poser un écran**, allez directement à
> « [Conventions du socle](#conventions-du-socle) ». Les quatre questions y sont
> traitées dans l'ordre : où poser une vue, comment déclarer une route, comment
> appeler l'API, comment afficher une erreur.

---

## Développement

```bash
cd app
npm install
npm run dev        # http://127.0.0.1:5173
```

Le serveur de développement relaie `/api` vers l'API. Copier `.env.example` en
`.env` pour changer la cible :

| Variable | Défaut | À quoi ça sert |
|---|---|---|
| `VITE_API_PROXY_TARGET` | `http://127.0.0.1:8000` | Hôte de l'API. `http://api:8000` si le front tourne dans Docker. |
| `VITE_DEV_HOST` | `127.0.0.1` | `0.0.0.0` pour servir depuis un conteneur. |
| `VITE_DEV_PORT` | `5173` | Port d'écoute. |

## Vérification et construction

```bash
npm run typecheck  # vue-tsc --noEmit — doit rendre zéro erreur
npm run test       # vitest run — doit rendre zéro échec
npm run build      # produit dist/
npm run preview    # sert dist/ pour vérifier le rendu de production
```

`npm run typecheck` est une condition de passage, pas une indication : la CI
échoue au premier avertissement.

Les tests portent sur ce qui se casse en silence, pas sur les composants :

| Fichier | Ce qu'il protège |
|---|---|
| `src/lib/api/erreurs.test.ts` | la traduction d'une erreur d'API en message, et **la distinction des deux `409`** |
| `src/router/acces.test.ts` | les gardes pour les **trois** régimes d'accès |
| `src/lib/api/revalidation.test.ts` | l'`ETag` du graphe : en-tête envoyé, `304`, oubli |
| `src/router/rechargement.test.ts` | après une mise en production, un écran disparu **recharge une fois** — jamais en boucle |
| `src/composables/erreursGlobales.test.ts` | le filet global : il journalise et prévient, sans crier sur ce qui n'est pas une panne |
| `src/composables/useRecherchePersonnes.test.ts` | la recherche : chargement, **panne** (jamais « Personne ne correspond. »), vide, résultats, annulation, réponse périmée |
| `src/components/mise-en-page/recherche-entete.client.test.ts` | le motif *combobox* de l'en-tête, ses états, « Voir tous les résultats » |

## Production

`Dockerfile` construit le front avec Node puis le sert avec nginx.
`nginx.conf` sert les fichiers statiques **et relaie `/api` vers le service
d'API**. Ce n'est pas un détail d'exploitation : c'est ce qui donne au front et
à l'API une **origine unique**, donc un projet sans CORS ouvert et un cookie de
session `HttpOnly` + `SameSite=Lax` sans exception. Le fichier est commenté
directive par directive — le lire avant de le modifier.

**Une mise en production et les onglets déjà ouverts.** Les morceaux de Vite
sont empreintés et servis un an `immutable` ; `index.html` est en `no-cache`.
Un onglet ouvert la veille — ou l'application installée, qu'on ne ferme jamais —
nomme donc des morceaux que le serveur n'a plus, et chaque écran est paresseux.
`src/router/rechargement.ts` rattrape l'échec (`routeur.onError`,
`vite:preloadError`) par **un** rechargement complet vers la destination, gardé
par un drapeau horodaté dans `sessionStorage` ; si la page rechargée échoue
encore, il ne recharge plus et le dit, avec « Recharger ». Le service worker
(`public/sw.js`) ne met rien en cache : il n'y est pour rien, et doit le rester.

---

## Trois règles qui ne se devinent pas

### Les polices sont auto-hébergées, et ce n'est pas négociable

Elles sont installées comme dépendances `@fontsource` et importées depuis
`src/styles/main.css`. La CSP servie par `nginx.conf` est stricte et entièrement
*same-origin* : `font-src 'self'`, `style-src 'self' 'unsafe-inline'`,
`script-src 'self'`. **Aucun CDN n'est joignable**, Google Fonts compris.

Un `<link rel="stylesheet" href="https://fonts.googleapis.com/…">` ne produira
pas d'erreur visible : la feuille sera simplement bloquée, la police ne chargera
pas et le navigateur retombera sur la police système. On change de police en
ajoutant un paquet `@fontsource`, jamais en ajoutant une URL.

### Le canevas de l'arbre — § 7.2 du dossier de mission

> Ces deux règles sont tranchées par
> [l'ADR 0007](../docs/adr/0007-rendu-svg-performance.md) et rappelées dans le
> [`README.md` de la racine](../README.md) parce qu'elles ont coûté cher. Les
> enfreindre fera revenir la lenteur :
>
> 1. **Le zoom passe par le `viewBox`, jamais par `transform: scale()`.** Avec
>    `scale()`, Chrome rastérise les 7 816 px entiers à l'échelle demandée. Avec
>    le `viewBox`, seul le visible est dessiné.
> 2. **Jamais `opacity` sur un élément SVG** pour mettre en retrait : la
>    propriété crée un contexte d'empilement, donc **une couche de composition
>    par arête**. On utilise `fill-opacity` / `stroke-opacity`, qui ne coûtent
>    qu'un repaint.
>
> Troisième règle propre au portage Vue : le `<svg>` des 377 nœuds est piloté
> **impérativement** (`ref` + manipulation directe du DOM). Le faire rendre par
> la réactivité de Vue à chaque frappe dans la recherche détruirait la fluidité.
> Vue gère le cadre, les panneaux et les formulaires ; il ne gère pas le canevas.

Concrètement, pour l'agent qui portera le viewer : le composant du canevas
expose une `ref` sur le `<svg>`, construit ses nœuds une fois au montage, et
toutes les interactions ultérieures (zoom, pan, surlignage de recherche,
sélection) passent par des écritures directes dans le DOM — pas par des
`ref()`/`computed()` liés au template. Les 377 nœuds ne doivent **jamais**
figurer dans un `v-for` réactif.

**`/arbre` (`views/ArbreGroupesView.vue`)** tient ces règles depuis le 2026-10-14, avec
la révision de l'ADR qui lui est propre (des boîtes HTML, sans `viewBox`) :

- les traits, les rectangles des familles et leurs zones de clic sont **un** `<svg>`
  construit impérativement et zoomé par son `viewBox` (`arbre2/TracesGroupes.vue`) ;
  les boîtes, un seul conteneur transformé, construit impérativement avec **un**
  écouteur délégué (`arbre2/FichesGroupes.vue`) ;
- le cadrage vit **hors de la réactivité** (`arbre2/useCadrage.ts`) : pendant un
  geste, rien ne se rend — bandeau, gouttière, titres des rectangles et mini-carte se
  placent eux-mêmes (`placer()`) ; les textes, les anneaux et le « Zoom actuel » des
  filtres se recalculent d'après un **instantané** publié au plus toutes les 160 ms et
  à la fin du geste (`arbre2/cadence.ts`, `arbre2/libelles.ts`) ;
- rien n'est estompé par `opacity` : une couleur transparente (`arbre2/apparence.ts`).

`arbre2/rendu-adr0007.test.ts` lit ces sources et rougit si une règle revient. Le
même écran relit son graphe quand le lecteur change (« Quitter la vue empruntée ») et
au retour sur l'onglet, et abandonne ses requêtes au démontage (`arbre2/relecture.ts`).
`/arbre-2` (`HistoireView.vue`) n'a pas encore ce découpage.

### Le mobile s'écrit en `max-md:`, et jamais autrement

En deçà de `md` (48 rem), l'en-tête passe dans un tiroir à gauche et les barres
d'outils dans un tiroir à droite ; la fiche d'une personne couvre toute la
largeur ; les canevas acceptent le pincement à deux doigts. Au-delà, **rien ne
change** — et ce n'est pas une promesse, c'est une propriété :

> **Toute déclaration mobile s'écrit `max-md:*`, ou vit sur un élément portant
> `md:hidden`.** `max-md:` compile en `@media not all and (min-width: 48rem)` :
> elle *ne peut pas* s'appliquer à un écran large. Aucune classe non préfixée
> existante n'est modifiée pour obtenir un effet mobile.

La porte qui le vérifie : construire la feuille avant et après, retirer de la
nouvelle le bloc `@media not all and (min-width:48rem)` accolades appariées, et
vérifier que **chaque règle d'origine s'y retrouve, sélecteur et corps
identiques**. Au dernier passage : 508 règles sur 509, la 509ᵉ étant le bloc
`:root` qui n'avait fait que **gagner** un jeton, et 43 sélecteurs dans le bloc
mobile, tous `max-md:`.

Trois choses à savoir avant d'y toucher :

1. **`md:contents` est ce qui rend le grand écran immobile.** Au-delà du seuil,
   le conteneur d'un tiroir ne génère aucune boîte : ses enfants redeviennent
   enfants flex directs, dans le même ordre et avec les mêmes gouttières.
2. **Un tiroir est un `<div>`, jamais un `<aside>`.**
   `views/arbre-revalidation.test.ts` localise le bloc qui rogne le panneau de
   fiche par le **premier `<aside>` du document**, et `PanneauFiche` se trouve
   être le seul de toute l'application. Un second `<aside>` rendu avant lui
   repointerait trois de ces quatre cas sur le mauvais élément, sans rien dire.
3. **L'en-tête n'appelle pas `useRoute()`.** `entete-creation.test.ts` simule
   `vue-router` en n'exportant que `useRouter` ; la fermeture des tiroirs à la
   navigation vit donc dans `router/index.ts`. Et le burger est `v-if="connecte"`
   parce que ce même fichier vérifie qu'un visiteur anonyme ne reçoit **aucun**
   `<button>`.

---

## Arborescence

```
app/
  Dockerfile          construction multi-étapes (Node → nginx)
  nginx.conf          statique + relais /api + en-têtes de sécurité et CSP
  index.html
  vite.config.ts      proxy /api, alias @ → src/
  tsconfig.json       mode strict
  public/
    favicon.svg       remplaçable par la tâche « socle front »
  src/
    App.vue           le cadre : en-tête, bandeaux, <RouterView>
    main.ts           démarrage — ordre significatif, voir le fichier
    env.d.ts
    assets/           images et ressources importées par le bundler
    components/
      base/           bouton, champ, panneau, pastille, erreur, squelette
      mise-en-page/   en-tête (+ burger), bandeaux de régime, TiroirOutils,
                      AvisGlobal (le message non bloquant, voir § 5)
      personne/       la fiche : bandeau, onglets, gestes, PartageFiche
      evenements/     filtres de genre, partagés carte + anniversaires
      carte/          Leaflet, agrégation par lieu, les deux échelles
      anniversaires/  grille du mois, navigation, regroupement par jour
    composables/
      useSession.ts   état de session (singleton) + rattrapage 401
      useAppelApi.ts  triplet chargement / erreur / données
      tiroirs.ts      les deux tiroirs du mobile, en fonctions pures
      useTiroirs.ts   leur état partagé (portée module) + le seuil
      useGestesCanevas.ts  molette, déplacement et pincement à deux doigts
      avisGlobal.ts   l'avis global, une seule place (singleton)
      erreursGlobales.ts  errorHandler + unhandledrejection → journal + avis
      partage.ts      feuille de partage ou presse-papiers, adresse absolue
      useRecherchePersonnes.ts  LA recherche de personnes : états, délai, annulation
    lib/
      api/
        types.ts        types du contrat (§ 1 à § 16)
        erreurs.ts      ErreurApi + analyserErreur — LE traitement des erreurs
        client.ts       fetch, cookie de session, enveloppe d'erreur
        revalidation.ts ETag du graphe
        routes.ts       une fonction par route du socle
        index.ts        point d'entrée : on importe `@/lib/api`
      notifications.ts  linkRoute → route du front, sans casser sur l'inconnu
    router/
      acces.ts        les trois régimes + la garde, en fonctions pures
      routes.ts       la table des routes — pure donnée, sans `window`
      index.ts        l'instance, les deux gardes, la fermeture des tiroirs
      rechargement.ts écran disparu après une mise en production → un rechargement
    styles/
      main.css        palette, thème sombre, base, utilitaires
      canevas.css     styles SVG de l'arbre et de la chronologie
      carte.css       reprise des couleurs de Leaflet par le thème
    views/            écrans, un par route
      cadre.ts        la hauteur mesurée d'un écran à canevas — une seule fois
```

### Notes de configuration

- **Alias `@` → `src/`** : `import X from '@/lib/api'`. Déclaré à la fois dans
  `vite.config.ts` (résolution) et `tsconfig.json` (typage).
- **`exactOptionalPropertyTypes`** est activé. C'est volontaire et lié au fond
  du sujet : la donnée généalogique est pleine de champs absents (`an_deces`,
  `lieu_nais`, `quay`) et une fourchette de dates ne doit jamais être aplatie en
  date ferme (§ 4 du dossier de mission). On veut que le typeur distingue
  « absent » de « vide ».
- **`noUncheckedIndexedAccess`** est laissé désactivé : le canevas indexe des
  tableaux de 377 entrées en boucle, l'option y ajouterait un `undefined` à
  chaque accès sans rien attraper de réel.
- **Mode historique du routeur** : il va de pair avec le
  `try_files $uri $uri/ /index.html` de `nginx.conf`. Retirer l'un casse l'autre
  — un lien d'invitation collé dans un navigateur vierge est justement une route
  profonde. Le même repli rend la coque HTML à la place d'un morceau `.js`
  disparu : l'import échoue (type MIME), et c'est `router/rechargement.ts` qui
  le rattrape — voir « Production ».

---

## Conventions du socle

Cette section est écrite pour les agents qui poseront les écrans. Elle répond aux
quatre questions dans l'ordre : **où poser une vue**, **comment déclarer une
route**, **comment appeler l'API**, **comment afficher une erreur**. Ce qui suit
n'est pas une préférence de style : c'est ce qui permet à quatre écrans écrits
séparément de se comporter pareil.

### 1. Les trois régimes d'accès — et non deux

C'est le piège du produit. Le lire avant d'écrire une ligne d'écran.

| Régime | Ce que c'est | Ce qu'il voit |
|---|---|---|
| `anonyme` | aucune session | connexion, inscription |
| `en_attente` | session ouverte, **rattachement non validé** | les personnes **décédées uniquement** — 256 sur 274 — et **aucune** personne vivante, y compris celle à laquelle il prétend se rattacher |
| `membre` | rattaché, **avec** un cercle de visibilité | tout le décédé, plus les vivants de son cercle |
| `administrateur` | `ROLE_ADMIN` ; implique `membre` | il **traverse le cercle comme tout le monde** — le sien est simplement le cercle complet. Ce qu'il voit de plus est de l'**état** : les demandes et les médias `en_attente` de tous. Plus la bascule « voir comme un membre ». |

> **L'administrateur n'est pas un super-membre, et le front ne doit pas le
> traiter comme tel.** Il franchit les filtres d'**état** — la file de validation
> est la sienne — mais il passe par le **même** mécanisme de cercle que les
> autres : « le normaliseur n'a pas de mode *sans filtre*, l'administrateur passe
> par un cercle complet, jamais par un `if ($estAdmin) { return $tout; }` »
> (§ 10.4). Un `si administrateur, alors tout voir` a déjà été trouvé et retiré
> côté serveur ; ne le réintroduisez pas côté écran sous la forme d'un
> `v-if="administrateur"` qui afficherait un champ que la charge utile n'a pas
> envoyé. La règle pratique reste celle du § 4.1 : **ce qui est absent de la
> charge utile ne s'affiche pas**, quel que soit le rôle du lecteur.

> **`en_attente` n'est pas « un membre avec moins de droits », et surtout pas
> « un cercle de profondeur nulle ».** Côté serveur, cette dernière notion n'est
> même pas exprimable : le service de cercle rend « pas de cercle », un état à
> part de « cercle calculé, résultat vide ». Un `N = 0` resterait un cercle — il
> contiendrait encore la personne elle-même et ses conjoints, donc il laisserait
> sortir la fiche d'une personne vivante.

Dans le JSON, la distinction tient à **une clé** :

```jsonc
"cercle": { "regime": "aucun" }                                   // en attente
"cercle": { "regime": "cercle", "generations": 3, "taille": 34 }  // membre
```

**On branche sur `regime`, jamais sur `taille === 0`.** `CercleDeSession` est une
union discriminée exprès : `session.cercle.taille` ne compile pas tant qu'on n'a
pas discriminé. C'est délibéré, ne le contournez pas par un `as`.

Dans un écran, on ne relit pas la charge de session à la main :

```ts
import { useSession } from '@/composables/useSession'

const { regime, connecte, rattache, administrateur, vueEmpruntee } = useSession()
```

`rattache` est faux pour `en_attente` — c'est lui qui conditionne un bouton
« Proposer une modification », pas `connecte`.

### 2. Ajouter une route

Tout se passe dans `src/router/routes.ts` — la **table** des routes, séparée de
l'instance pour une raison mécanique : `createWebHistory()` lit
`window.location` au chargement du module, donc tout ce qui vit dans `index.ts`
est hors de portée d'un test, alors que la table est précisément ce qu'il faut
pouvoir vérifier. Une entrée porte **quatre** choses, et `acces` comme `titre`
sont obligatoires (le typage de `RouteMeta` les impose) :

```ts
{
  path: '/administration/invitations',
  name: 'administration.invitations',
  component: () => import('@/views/administration/InvitationsView.vue'),
  meta: { acces: 'administration', titre: 'Invitations' },
},
```

`meta.acces` prend une des cinq valeurs de `NiveauRequis` :

| Valeur | Qui passe | À employer pour |
|---|---|---|
| `public` | tout le monde | page introuvable, accès refusé |
| `anonyme` | **seulement** les visiteurs sans session | connexion, inscription |
| `attente` | **toute** session, `en_attente` comprise | arbre, fiche, chronologie, notifications |
| `membre` | rattachement validé exigé | déposer une demande, téléverser un média |
| `administration` | `ROLE_ADMIN` | tout `/administration/**` |

Ce qui se passe quand ça refuse, et pourquoi c'est écrit une fois pour toutes
dans `resoudreAcces()` plutôt que dans chaque écran :

- pas de session → `/connexion?suite=<destination>`, et l'écran de connexion doit
  y revenir après succès ;
- `en_attente` sur une route `membre` ou `administration` → **`inscription.etat`**,
  pas la connexion. Le renvoyer à la connexion lui ferait croire que sa session
  est tombée, alors qu'il lui manque un rattachement ;
- `membre` sur une route `administration` → `acces-refuse` ;
- déjà connecté sur une route `anonyme` → sa page d'accueil.

**Masquer une entrée de menu n'est pas une mesure de sécurité.** Les gardes la
doublent, et le serveur les double toutes les deux ; l'intérêt du menu est de ne
pas proposer un écran qui refusera.

**Les noms de route sont un contrat.** Une notification porte
`linkRoute: "demande.detail"` + `linkParams`, jamais une URL : renommer une route
rend muettes les notifications déjà écrites en base. **On ajoute, on ne renomme
pas.** Pour résoudre un lien de notification, passer par
`lienDeNotification(routeur, notification)` (`src/lib/notifications.ts`) : il rend
`null` sur une route inconnue, parce qu'une notification ancienne n'est pas une
erreur.

Les sous-écrans d'administration se déclarent en **routes enfants** de
`/administration`, chacune avec son propre `meta`.

### 3. Poser une vue

Une vue par route, dans `src/views/` — les sous-ensembles dans un sous-dossier
(`views/administration/`). Chaque vue existante contient déjà, en tête de
fichier, **ce qu'elle doit faire et les pièges du contrat qui la concernent** :
lisez ce commentaire avant de le remplacer.

Quand la vue est écrite, elle n'importe plus `EcranAPoser`. S'il reste un seul
`EcranAPoser` à la livraison, c'est qu'un écran manque.

Composants de base disponibles, tous dans `@/components/base/` :

| Composant | Notes |
|---|---|
| `BoutonBase` | `variante` : `principal`, `secondaire`, `discret`, `danger`. `type="button"` par défaut — le défaut HTML est `submit`, et un « Annuler » soumettrait le formulaire. |
| `ChampTexte` | `v-model`, `etiquette`, `aide`, `erreur`, `chasseFixe`. Brancher `erreur` sur `analyse.champs[nom]`. |
| `PanneauBase` | conteneur de la charte, créneaux `entete` et `pied`. |
| `PastilleEtat` | `etat` prend les valeurs **du modèle** (`en_attente`, `validee`, `refusee`, `caduque`…), jamais traduites. |
| `MessageErreur` | voir § 5 ci-dessous. |
| `SqueletteChargement` | occupe la place du contenu à venir. |

Et dans `@/components/mise-en-page/` : `EnTeteApplication` (créneau `actions` pour
y greffer un compteur de notifications sans modifier le fichier), `BandeauRegime`,
`AvisGlobal` (monté une fois par `App.vue` ; on ne le monte pas ailleurs, on
appelle `afficherAvis()` — voir § 5).

**Partager un lien** : `@/composables/partage` — `partagerOuCopier()` ouvre la
feuille de partage du système quand elle existe, copie sinon, et distingue
l'annulation (`AbortError`, on ne dit rien) d'un vrai refus (on affiche
l'adresse). L'adresse se construit par `adresseAbsolue(routeur, { name, params })`
— le `resolve()` du routeur posé sur l'origine —, jamais par concaténation à la
main, et ne porte **que** la route : le destinataire se connecte et ne voit que
ce que son cercle lui ouvre. Exemple : « Partager » sur la fiche
(`PartageFiche.vue`).

### 4. Appeler l'API

**La session est un cookie `HttpOnly`.** Le front ne manipule aucun jeton, n'en
lit aucun, n'en stocke aucun — ni en `localStorage`, ni en mémoire. Il n'y a
**aucun en-tête d'autorisation à poser nulle part** ; si vous en cherchez un,
c'est que l'architecture a changé.

Une fonction par route, dans `src/lib/api/<module>.ts`, chemin écrit **sans** le
préfixe `/api` :

```ts
// src/lib/api/propositions.ts
import { requeteApi } from './client'
import type { Demande } from './types'

export function lireDemande(id: number): Promise<Demande> {
  return requeteApi<Demande>(`/demandes/${id}`)
}

export function soumettreDemande(id: number): Promise<Demande> {
  return requeteApi<Demande>(`/demandes/${id}/soumettre`, { methode: 'POST' })
}
```

Puis, dans l'écran, le triplet chargement / erreur / données :

```ts
const { donnees: demande, erreur, chargement, executer } = useAppelApi(() => lireDemande(id))
onMounted(executer)
```

Trois règles du contrat qui changent la façon d'écrire un écran :

1. **Absent contre `null` (§ 1.3).** `'birth' in fiche === false` veut dire
   *interdit* : on n'affiche **pas la ligne du tout**, jamais un « — ».
   `fiche.birth === null` veut dire *inconnu* : la ligne existe, la valeur est
   vide. Les confondre efface une règle de confidentialité.
2. **Les clés ne se francisent pas (§ 1.2).** Une clé qui reflète une colonne
   porte le nom de la propriété Doctrine (`surname`, `displayName`, `birth`,
   `status`) ; le reste est en français (`erreur`, `elements`, `vivant`,
   `enAttente`). C'est ce qui fait que la clé du calque **est** la clé du corps,
   sans table de correspondance.
3. **Une fourchette de dates ne s'aplatit pas.** « entre 1901 et 1909 » s'affiche
   tel quel, jamais « 1901 ». Le serveur fournit `texte` : on l'affiche, on ne
   le recompose pas.

**Le graphe est la seule route qui porte un `ETag`** :

```ts
const resultat = await chargerGraphe()
if (resultat.statut === 'inchange') return      // le SVG reste EN PLACE
construireCanevas(resultat.donnees)             // sinon : reconstruction complète
```

Revalider au montage de la vue, au retour d'onglet, et après **toute** action qui
écrit — jamais en boucle. Après une écriture, appeler `invaliderGraphe()`.
L'`ETag` est aussi oublié à tout changement de session : il incorpore les
demandes en attente du lecteur et, sous la bascule « voir comme un membre », le
membre emprunté.

**La recherche de personnes** passe par `useRecherchePersonnes`
(`src/composables/`), partagée par l'en-tête, la barre du bas des deux arbres
(`components/arbre/ChampRechercheArbre.vue`), la page `recherche` et le sélecteur
des formulaires (`SelecteurPersonne`). Toujours par l'API, jamais par un index
local. Elle distingue cinq états — rien à chercher, chargement, **panne** (avec
« Réessayer »), vide, résultats — : une coupure de réseau ne s'affiche jamais
« Personne ne correspond. ». Les champs de l'en-tête et des arbres suivent le
motif *combobox* de WAI-ARIA (`aria-expanded`, `aria-controls`,
`aria-activedescendant`, options en `listbox`). L'en-tête s'arrête à huit
résultats ; tronquée, sa liste finit par « Voir tous les résultats », vers
`/recherche?q=…`, qui va jusqu'au plafond de 50 de l'API (§ 5.5, non paginé).

### 5. Afficher une erreur

**Un écran ne réinterprète jamais un code d'erreur.** Il passe ce que le `catch`
a attrapé — sans le trier, `analyserErreur` accepte `unknown` :

```vue
<MessageErreur v-if="erreur" :erreur="erreur" @action="executer" />
```

ou, s'il faut décider soi-même :

```ts
const analyse = analyserErreur(erreur)
// analyse.message  — texte du serveur, affichable tel quel
// analyse.champs   — { nomDeChamp: message }, sur les 422
// analyse.conduite — 'reessayer' | 'refaire' | 'recharger' | 'corriger'
//                  | 'se_connecter' | 'patienter' | 'aucune'
// analyse.rejouable, analyse.attenteSecondes, analyse.sessionPerdue
```

**Pourquoi ça vit à un seul endroit.** Le contrat a deux `409` qui donnent des
conseils opposés :

| | `verrou_concurrent` | `demande_caduque` |
|---|---|---|
| Écrit dans l'arbre | non | non |
| La demande | reste `en_attente` | passe **`caduque`**, l'auteur est notifié |
| `details.rejouable` | `true` | `false` |
| Ce qu'on propose | **« Réessayer »** | **« Refaire cette demande »** |

Un écran qui afficherait « Réessayer » sur les deux ferait **boucler
l'administrateur sur une demande morte**. Comme le libellé vient d'`erreurs.ts`
et non de l'écran, aucun écran ne peut se tromper — et le jour où le contrat
ajoute un code, il y a un seul fichier à modifier.

**Ce qui n'a pas d'écran pour le dire.** Deux cas seulement passent par l'avis
global (`afficherAvis()`, `composables/avisGlobal.ts`, une carte en bas d'écran,
non bloquante, une seule à la fois) :

- un écran **paresseux qui n'a pas pu se charger**, même après le rechargement
  automatique (`router/rechargement.ts`, voir « Production ») ;
- une erreur que **personne n'a attrapée** : `app.config.errorHandler` et
  `unhandledrejection` (`composables/erreursGlobales.ts`, posés par `main.ts`).
  Le filet journalise et prévient ; il ne dit rien d'une session tombée (le
  rattrapage `401` s'en charge), d'une requête annulée, ni d'un `AbortError` /
  `NotAllowedError` (partage annulé, lecture automatique refusée).

Ce n'est **pas** un canal de plus pour les écrans : une erreur qu'un écran sait
afficher s'affiche dans l'écran, par `MessageErreur`, à côté de ce qui l'a
causée. Le filet ne voit d'ailleurs que ce qui a échappé à tout `try/catch` et à
`useAppelApi`.

Deux autres pièges, déjà traités par le client :

- **une `401` n'est pas toujours une déconnexion.** `POST /api/connexion` rend des
  `401` métier (`identifiants_invalides`, `compte_suspendu`), et un mot de passe
  actuel erroné rend `422` et non `401` précisément pour cette raison. Le
  rattrapage global branche sur le **code** `authentification_requise` ;
- **`403 regime_en_attente`** ne propose rien : aucun geste du lecteur ne
  l'active, c'est l'administrateur qui valide.

### 6. La charte

Palette, teintes de sexe, couleurs de branche et mode sombre sont **repris de
`arbre.html`** — les valeurs hexadécimales sont des constantes du produit, pas
des choix à réexaminer. Le mode sombre passe par `prefers-color-scheme` ; il n'y
a pas de bascule manuelle.

Les couleurs sont des variables CSS brutes (`--h-*`) exposées à Tailwind par
`@theme inline`, ce qui donne des utilitaires qui suivent le thème :

```
bg-fond  bg-panneau  text-texte  text-attenue  border-ligne
text-accent  bg-accent-doux  text-alerte  bg-alerte-doux  text-ok  bg-ok-doux
bg-homme  bg-femme  bg-inconnu     (teintes de fiche)
fill-branche-pp … -pm -mp -mm -tr  (liserés de branche)
rounded-champ  rounded-panneau  rounded-pastille  shadow-panneau
tnum  lecteur-ecran                (utilitaires du projet)
```

En CSS ordinaire, on référence le jeton Tailwind : `var(--color-accent)`. Ne pas
écrire d'hexadécimal en dur dans un composant : le thème sombre ne le suivra pas.

`src/styles/canevas.css` porte les styles SVG (fiches, familles, arêtes, mises en
retrait). Il est **global et non *scoped*** : les 377 nœuds sont construits
impérativement et ne portent pas l'attribut `data-v-…` qu'un style *scoped*
exige. Ce fichier respecte déjà les deux règles de rendu, et il n'y a
volontairement **pas de `.ar.long`** — voir son en-tête.
