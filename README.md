# Heritage

Application généalogique privée : un arbre familial de 274 personnes sur 12 générations,
consultable et **modifiable par les membres de la famille**, avec une règle de
confidentialité qui décide pour chaque lecteur ce qu'il a le droit de voir.

Ce n'est pas un logiciel de généalogie de plus. Ce qui le définit tient en trois
contraintes, et tout le reste en découle :

1. **L'arbre porte l'état civil de 18 personnes vivantes.** Noms, dates et lieux de
   naissance, filiations, actes numérisés. La confidentialité n'est donc pas une option de
   configuration : c'est le **cercle de visibilité**, calculé par lecteur, appliqué dans la
   sérialisation, que même un administrateur traverse — [ADR 0003](docs/adr/0003-cercle-de-visibilite.md).
2. **Personne n'écrit directement dans l'arbre.** Toute modification est une
   **proposition**, diffée champ par champ, validée ou refusée avec motif —
   [ADR 0004](docs/adr/0004-ecriture-par-proposition.md).
3. **Les données réelles ne sont pas dans ce dépôt**, et n'y entreront pas —
   [ADR 0006](docs/adr/0006-donnees-reelles-non-versionnees.md). Ce que vous clonez
   démarre sur des fixtures anonymisées.

| | |
|---|---|
| API | Symfony 8.1 · PHP 8.4 · Doctrine · PostgreSQL 17 · FrankenPHP · 7 modules |
| Front | Vue 3 · TypeScript strict · Vite · Tailwind 4 · `vue-router` · Vitest · Node 22 |
| Servi par | nginx, qui sert la SPA **et** relaie `/api` — une seule origine, donc pas de CORS |
| Courriel | Mailpit en développement (il capte tout et n'envoie rien) |

---

## Lisez ceci avant de taper quoi que ce soit

Ce dépôt contient un arbre **réel**. Trois règles, dans cet ordre.

**Le GEDCOM n'entre jamais dans le dépôt.** `*.ged` est exclu par `.gitignore`, ainsi que
`recherche/`, `vignettes/`, `data/`, `graphe.json`, `arbre*.html`, `uploads/`, `*.sql`. Ces
exclusions ne sont pas du rangement : un fichier commité une fois **reste dans
l'historique**, même supprimé au commit suivant. On n'utilise donc ni `git add -A` ni
`git add .` tant que la racine peut héberger ces fichiers, et on ne commite pas un chemin
sans l'avoir passé à `git check-ignore -v`.

**Aucun secret n'entre dans le dépôt.** `.env`, `.env.local`, `api/.env.local` sont ignorés.
Ce qui est versionné — `.env.example`, `api/.env`, `api/.env.test` — ne porte que des
valeurs de développement, jetables et publiques, et le dit.

**Un `docker compose down` lancé depuis ce répertoire détruit la pile de travail.** Le
15 septembre 2026 à 21 h 03, c'est exactement ce qui est arrivé : la commande visait une
pile jetable, un nom de projet tu est un nom de projet déduit du répertoire, et les quatre
conteneurs sont partis. Le `-p` posé sur le `up` ne protège pas le `down`. Si vous montez
une pile jetable, **posez le nom dans le shell, pas en drapeau** — la marche à suivre
complète est en tête de [`compose.yaml`](compose.yaml), section « AVANT DE MONTER UNE PILE
JETABLE ».

---

## Monter la pile

Prérequis : Docker avec Compose v2. Rien d'autre — ni PHP, ni Node, ni PostgreSQL sur le
poste pour un simple démarrage.

```bash
git clone https://github.com/Naltpom/heritage.git
cd heritage
docker compose up --build
```

Il n'y a **rien à configurer** : `compose.yaml` embarque comme replis exactement les valeurs
de `.env.example`. Copiez `.env.example` en `.env` seulement si vous voulez changer un port
ou l'adresse d'écoute.

| | |
|---|---|
| Application | <http://127.0.0.1:8080> |
| API seule (mise au point) | <http://127.0.0.1:8000> |
| Boîte aux lettres | <http://127.0.0.1:8025> |
| PostgreSQL | `127.0.0.1:5432`, base `heritage`, utilisateur `heritage` |

Tout est publié sur `127.0.0.1` et pas ailleurs : l'application porte l'état civil de
18 personnes vivantes, elle n'a rien à faire sur le réseau d'un logement ou d'un café.
`BIND_ADDR` permet d'y déroger, mais c'est alors une décision prise, pas un défaut subi.

Au premier démarrage, l'entrée du conteneur `api` attend PostgreSQL, **joue les migrations**,
puis démarre le serveur. Le service `app` n'ouvre qu'une fois l'API répondante : sans cela
l'écran est servi pendant que les migrations tournent et le premier appel `/api` rend un 502
que rien ne distingue d'une panne réelle.

La base est alors **vide d'arbre et vide de comptes**. Les deux sections suivantes s'en
occupent, dans cet ordre.

---

## Le premier compte

Une base neuve n'a aucun compte, et **il n'existe aucune route d'inscription libre** : on
entre par la console, une fois, puis par le parcours d'invitation.

```bash
docker compose exec -T api sh -c \
  'printf "%s\n" "<mot de passe, 12 caractères minimum>" | \
   php bin/console heritage:creer-administrateur vous@exemple.fr "Votre Nom"'
```

Un tube suffit, la commande n'exige pas de terminal. Ouvrez ensuite
<http://127.0.0.1:8080> et connectez-vous.

Les membres suivants ne se créent pas en console : l'administrateur envoie une invitation
depuis `/administration`, le courriel arrive dans **Mailpit** (<http://127.0.0.1:8025>), et
l'invité suit le lien — rattachement, puis création de compte. C'est le seul chemin, et il
est vérifié par les tests.

> **Ne créez jamais une ligne `mbr_member` à la main.** Une seule a déjà mis
> `GET /api/administration/membres` en `500` pour tout le monde, avec un `match_kind` que
> l'énumération ne connaissait pas.

> **Il n'existe aucune route pour réinitialiser le mot de passe d'un autre compte.**
> `PATCH /api/administration/utilisateurs/{id}` ne porte que `roles`, `status` et
> `displayName`. Toute réinitialisation passe donc par un `UPDATE` SQL, qui contourne le
> cycle de vie Doctrine et le souscripteur d'audit : ni `updated_at` ni `core_audit_log`
> n'en gardent trace. C'est la raison pour laquelle **on ne partage pas un compte** — voir
> [`docs/securite-comptes.md`](docs/securite-comptes.md) et l'en-tête de `compose.yaml`.

Bloqué par le limiteur — « Trop d'essais successifs, réessayez dans N minutes » ? Cinq
essais ratés par quart d'heure. Pour remettre **ce** compteur à zéro, et lui seul :

```bash
docker compose exec -T api php bin/console cache:pool:clear cache.rate_limiter
```

Ce pool-là, jamais `cache:clear` : un vidage global recompilerait le conteneur de services
de toute la pile.

---

## Peupler l'arbre

Deux chemins, qui ne se mélangent pas.

### Fixtures anonymisées — le chemin normal pour développer

Elles reproduisent la **topologie et les pathologies** du vrai arbre : 12 générations,
branches déséquilibrées, fratries jusqu'à 9, remariages, divorces, dates en fourchette,
ancêtres sans nom, un tiers de personnes sans parents connus. Le cahier des charges chiffré
est dans [l'ADR 0006](docs/adr/0006-donnees-reelles-non-versionnees.md), et il est **vérifié
par un test** : des fixtures plates feraient découvrir chaque bug à l'import des vraies
données, c'est-à-dire chez le commanditaire, en production, sans filet.

```bash
docker compose exec -T api php bin/console doctrine:fixtures:load --append
```

> ⚠ **`--append` n'est pas une commodité.** Sans lui, `doctrine:fixtures:load` **purge la
> base** avant de charger : comptes, rattachements, propositions, médias, tout. Si vous
> l'oubliez sur une base qui portait déjà l'arbre réel et des comptes, vous ne perdez pas
> des fixtures, vous perdez le travail.

Pour rejouer le seul amorçage du compte administrateur, sans entraîner le reste :

```bash
docker compose exec -T api php bin/console doctrine:fixtures:load --append --group=amorcage
```

### Import GEDCOM — les données réelles

C'est une **commande locale**, jamais une fixture, jamais une étape de démarrage. Le fichier
ne vient pas du dépôt : il vit hors de lui.

```bash
# 1. Déposer le fichier dans le conteneur (il n'y a aucun montage du GEDCOM par défaut).
docker compose cp arbre.ged api:/tmp/arbre.ged

# 2. LIRE D'ABORD, ÉCRIRE ENSUITE. --dry-run compte et signale sans rien toucher :
#    c'est le mode dans lequel on découvre qu'un fichier est mauvais avant la base.
docker compose exec -T api php bin/console heritage:import-gedcom /tmp/arbre.ged --dry-run

# 3. L'import réel. --ego désigne la racine par sa RÉFÉRENCE GEDCOM, jamais par un id.
docker compose exec -T api php bin/console heritage:import-gedcom /tmp/arbre.ged --ego '@I500001@'

# 4. Retirer le fichier du conteneur.
```

Sur une base neuve où des comptes existent déjà, ajoutez `--premier-import` : la commande
refuse sinon de s'exécuter, et elle a raison — un arbre vide sous des comptes vivants est la
signature d'un schéma détruit, et repeupler réattribuerait les identifiants que les
rattachements désignent.

L'import est **idempotent** : personnes, familles et sources sont retrouvées par leur
identifiant GEDCOM, un second passage ne crée pas de doublon. En alternative au `cp`, le
montage d'un atelier en lecture seule est préparé (commenté) dans `compose.yaml`, service
`api` ; le `:ro` n'y est pas négociable.

---

## Vérifier

**Les tests ne tournent que sur l'hôte.** Le `.dockerignore` de l'API exclut `tests/` du
contexte de construction : les binaires sont dans l'image, les fichiers de test n'y sont pas.

```bash
cd api && composer install          # une fois
php vendor/bin/phpstan analyse --level=6      # l'existence et les types
php vendor/bin/phpunit                        # le tout, et seul lui a le dernier mot
php vendor/bin/paratest                       # le même, un processus par cœur (comme la CI)
php bin/console lint:container                # la cohérence des définitions de services
```

```bash
cd app && npm install               # une fois
npm run typecheck                   # vue-tsc --noEmit — zéro erreur exigé
npm run test                        # vitest run
npm run build                       # vite build
```

**Trois outils, aucun n'est un sur-ensemble de l'autre** — et cette phrase a été payée :

| | Ce qu'il prouve | Ce qu'il ne voit pas |
|---|---|---|
| `lint:container` | la cohérence des définitions de services | l'existence des classes |
| `phpstan` | l'existence et les types | le câblage |
| `phpunit` | le tout | — |

> `lint:container --env=test` est **rouge par construction dans le conteneur**, puisque
> `tests/` n'y est pas. Ce n'est pas une panne. Et mesurez les codes de sortie sur la
> **commande nue** : à travers un tuyau, vous lisez celui du dernier maillon.

La CI ([`.github/workflows/ci.yml`](.github/workflows/ci.yml)) rejoue ces portes à chaque
poussée sur `main` et à chaque demande de fusion : phpstan niveau 6, phpunit, `vue-tsc`,
`vite build`, puis la construction des deux images. Le style (`php-cs-fixer`) n'est
délibérément pas une porte — un échec de CI sur un espace manquant apprend à ignorer la CI.

### Un fichier du dépôt n'est pas ce que le conteneur sert

Il n'y a **aucun montage du code** dans les images : elles embarquent une copie, figée à la
construction. On lit un correctif dans l'éditeur et l'API répond avec le code d'avant, sans
que rien ne le dise. L'image annonce donc son empreinte en première ligne de journal :

```bash
docker compose logs api | head -2          # empreinte servie
cd api && sh docker/empreinte.sh           # empreinte du dépôt
```

Si les deux diffèrent, l'image est périmée. **Hiérarchie des preuves**, pour toute question
sur ce que l'API envoie : la réponse HTTP observée, puis le code **dans** le conteneur
(`docker compose exec`), puis le dépôt, puis la documentation.

---

## Où sont les décisions

Les ADR sont écrits **avant** le code et font autorité : qui trouve une meilleure idée ne
l'applique pas, il propose un nouvel ADR.

| | |
|---|---|
| [`docs/adr/`](docs/adr/README.md) | les 7 décisions d'architecture, avec leur mécanisme |
| [`docs/modele-donnees.md`](docs/modele-donnees.md) | tables, colonnes, index, encodage des dates incertaines |
| [`docs/api-contract.md`](docs/api-contract.md) | le contrat HTTP, route par route, code d'erreur par code d'erreur |
| [`docs/arbitrages.md`](docs/arbitrages.md) | les 35 points sur lesquels les deux corpus divergeaient, et ce qui a été retenu |
| [`docs/securite-comptes.md`](docs/securite-comptes.md) | comptes, rôles, amorçage, journal d'audit |
| [`docs/moteur-placement-portage.md`](docs/moteur-placement-portage.md) | le portage en PHP du moteur de placement |
| [`app/README.md`](app/README.md) | **le front** : conventions du socle, routage, appels d'API, traitement des erreurs, charte |
| [`compose.yaml`](compose.yaml) | l'exploitation de la pile de développement, piège par piège |
| [`compose.prod.yaml`](compose.prod.yaml) | la production, et les 7 étapes qui ne s'automatisent pas |

### « § 7.2 du dossier de mission » — ce que c'est, et pourquoi vous ne le trouvez pas

Le code et la documentation renvoient **134 fois** à un « dossier de mission » numéroté par
paragraphes. C'est le cahier des charges d'origine, et **il n'est pas versionné** : un
`grep -rn "dossier de mission"` trouve les renvois, pas la cible.

Ce qui en tient lieu dans le dépôt, et qui fait autorité au même titre :
[`docs/api-contract.md`](docs/api-contract.md) pour tout ce qui touche au contrat HTTP (il
porte sa **propre** numérotation en `§`, qui ne coïncide pas systématiquement avec celle du
dossier — ne les confondez pas), [`docs/modele-donnees.md`](docs/modele-donnees.md) pour le
schéma, et les ADR pour les décisions. Un renvoi orphelin n'est donc jamais bloquant, mais
sachez que c'en est un.

Deux règles de rendu SVG, écrites en [ADR 0007](docs/adr/0007-rendu-svg-performance.md) et
reprises dans `app/README.md`, décident à elles seules de la fluidité du canevas : **le zoom
passe par le `viewBox`, jamais par `transform: scale()`** ; **jamais `opacity` sur un élément
SVG**, mais `fill-opacity` / `stroke-opacity`. La troisième, propre au portage Vue : les
377 nœuds sont pilotés impérativement, hors de la réactivité.

---

## Ce qu'il ne faut pas faire

Chacune de ces lignes a coûté une panne réelle.

**Ne retouchez jamais une migration déjà jouée.** Doctrine retient les versions exécutées,
pas leur contenu : un fichier modifié après coup ne sera pas rejoué, et votre schéma diverge
en silence de celui de tout le monde. On corrige par une **nouvelle** migration.

**Ne faites jamais `TRUNCATE tree_person`.** Les identifiants sont une identité, réattribuée
au réimport suivant : mesuré le 15 septembre 2026, ils ont glissé de 1–274 à 275–548. Tout
rattachement de membre enregistré par son numéro désigne alors **quelqu'un d'autre**, sans
qu'aucune erreur ne se déclenche. C'est pourquoi l'ego s'écrit `@I500001@` et non `3` :
`gedcom_xref` porte une contrainte d'unicité et suit la personne.

**`doctrine:fixtures:load` sans `--append` purge la base.** Répété ici parce que c'est la
faute la plus facile à commettre et la plus coûteuse.

**Ne réinitialisez pas le mot de passe d'un autre par SQL.** Ça marche, et c'est précisément
le problème : le cycle de vie Doctrine et le souscripteur d'audit sont contournés, rien n'en
garde trace, et deux personnes diagnostiquent une panne d'authentification qui n'existe pas.

**Ne partagez pas un compte.** `core_audit_log` ne distingue pas trois agents derrière une
même adresse : le jour où il faut dater qui a fait quoi, les lignes `connexion` sont
inexploitables.

**Ne renommez pas le service `api`.** `app/nginx.conf` écrit `proxy_pass http://api:8000` en
dur ; dans un réseau Compose, le nom du service **est** le nom d'hôte DNS. Le renommer coupe
le relais `/api`, donc l'origine unique, donc le cookie `SameSite=Lax`, la CSP
`connect-src 'self'` et l'absence de configuration CORS.

**Ne renommez pas une route du front.** Les notifications stockent `linkRoute` en base : un
renommage rend muettes celles qui sont déjà écrites. **On ajoute, on ne renomme pas.**

**N'ajoutez pas de CDN au front.** La CSP servie par `nginx.conf` est entièrement
*same-origin*. Une feuille externe ne produit **aucune erreur visible** : elle est bloquée,
la police ne charge pas, et le navigateur retombe sur celle du système. Les polices sont des
paquets `@fontsource`.

**Ne faites pas `docker compose down` sans nom de projet.** Voir le premier avertissement de
ce fichier.

---

## Un premier appel, pour vérifier que tout est debout

```bash
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:8080/        # 200
curl -s -X POST http://127.0.0.1:8000/api/connexion \
  -H 'Content-Type: application/json' \
  -d '{"email":"vous@exemple.fr","password":"<votre mot de passe>"}'
```

> Le corps attend **`password`**, jamais `motDePasse`. Mesuré : `password` rend `200`,
> `motDePasse` rend `422 donnees_invalides`. La règle générale du contrat est au § 1.2 —
> une clé qui reflète une colonne porte le nom de la propriété Doctrine (`surname`,
> `displayName`, `birth`, `status`), le reste est en français (`erreur`, `elements`,
> `vivant`). Ce n'est pas une incohérence, c'est ce qui fait que la clé du calque **est** la
> clé du corps.

La session est un cookie `HttpOnly` : le front ne manipule aucun jeton, n'en stocke aucun,
et il n'y a **aucun en-tête d'autorisation à poser nulle part**.

---

## Production

Tout est dans [`compose.prod.yaml`](compose.prod.yaml), y compris les **sept étapes qui ne
s'automatisent pas** et qui ne sont pas facultatives : secrets, domaine, TLS et proxy
amont, SPF/DKIM/DMARC, sauvegardes hors site, import des données réelles, épinglage de
`IMAGE_TAG`.

```bash
cp .env.example .env     # puis remplacer les quatre variables « ⚠ À REMPLACER »
docker compose -f compose.yaml -f compose.prod.yaml up -d --build
```

Un service `garde-secrets` interrompt le démarrage tant qu'`APP_SECRET`,
`POSTGRES_PASSWORD`, `MAILER_DSN` et `DEFAULT_URI` portent encore les valeurs de
développement. **Ne le désactivez pas « pour voir si ça marche »** : c'est précisément le
moment où ça marche mal — une pile qui démarre avec les identifiants de développement a
l'air parfaitement saine, et la base de l'arbre familial est ouverte avec un mot de passe
écrit dans un dépôt Git.

Certaines livraisons demandent **une reprise en console après leur déploiement** — une
colonne calculée par une formule qui a changé, que le déploiement ne réécrit pas tout seul.
Elles sont listées, avec la commande exacte, dans
[`docs/deploiement/premiere-mise-en-ligne.md`](docs/deploiement/premiere-mise-en-ligne.md)
(§ 13, « Reprises ponctuelles ») ; la dernière en date est
`heritage:recalculer-les-cles-de-doublon`.

Le TLS n'est pas géré par la pile : elle ne publie qu'un port HTTP sur la boucle locale, et
c'est un nginx, un Caddy ou un Traefik de l'hôte qui termine le TLS. Ce proxy **doit** poser
`X-Forwarded-Proto: https` — sans cet en-tête, Symfony se croit en clair, le cookie de
session sort sans son drapeau `Secure` et les URL absolues sortent en `http`.

Le flux de déploiement (`.github/workflows/deploy.yml`) **vide la base avant chaque mise en
production**, après la construction et avant le démarrage — donc avant toute migration :
`backup/deploiement-<horodatage UTC>-avant-<sha court>.dump` à la racine du clone du VPS
(`pg_dump -Fc`, les 10 derniers gardés, ignoré par Git). Si le vidage échoue, le déploiement
s'arrête sans toucher la pile en service. La restauration est **manuelle** et efface tout ce
qui a été écrit depuis : base recréée vide, `pg_restore`, puis redémarrage de la version
d'avant — pas à pas au § 11.6 de
[`docs/deploiement/premiere-mise-en-ligne.md`](docs/deploiement/premiere-mise-en-ligne.md).
Ces vidages restent sur la machine : ils ne remplacent pas la copie hors site des
sauvegardes quotidiennes.

Pour la surveillance, `GET /api/sante` rend `{"statut":"ok"}` (ou `503` sans détail) sans
compte ni session, base comprise : c'est la cible d'une sonde extérieure, à doubler d'une
alerte d'expiration du certificat (même document, § 13).

---

## Arborescence

```
heritage/
  compose.yaml            pile de développement — et 25 Ko de pièges documentés
  compose.prod.yaml       surcouche de production (ne se suffit pas à elle-même)
  .env.example            modèle versionné des variables d'orchestration
  .github/workflows/      CI : 5 portes bloquantes
  api/                    Symfony 8 — 8 modules sous src/Module/
    Almanach/             carte des lieux et anniversaires — lecture seule
    Core/                 comptes, audit, erreurs, URL publiques
    Gedcom/               analyse et import GEDCOM
    Media/                portraits, preuves, vignettes, purge EXIF
    Membership/           invitations, inscription, cercle de visibilité
    Proposal/             propositions, diff, file de validation
    Timeline/             chronologie
    Tree/                 l'arbre, le placement, la sérialisation filtrée
  app/                    Vue 3 — voir app/README.md
  docs/
    adr/                  les décisions d'architecture
    deploiement/          la mise en ligne, pas à pas
    modele-donnees.md     tables, colonnes, index
    api-contract.md       le contrat HTTP
    arbitrages.md         les 35 arbitrages
    securite-comptes.md   comptes, rôles, audit
    moteur-retrouve/      le moteur de placement d'origine, en Python : la source
                          de vérité de l'algorithme porté en PHP
```

---

Dépôt privé : <https://github.com/Naltpom/heritage.git>
