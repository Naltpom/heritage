# Contrat d'API — Heritage

> **Ce document est le contrat.** Un agent qui n'aurait lu que lui doit pouvoir écrire **soit**
> le contrôleur **soit** l'écran, sans poser de question et sans que les deux divergent. Tout
> ce qu'il ne dit pas sera inventé deux fois, différemment.
>
> **Autorité.** En cas de contradiction : `docs/arbitrages.md` tranche, puis les ADR, puis
> `docs/modele-donnees.md`, puis ce document. Ce document ne décide que du **contrat HTTP** —
> ce que `docs/modele-donnees.md` § 12 lui délègue explicitement (« nom des routes et forme
> des charges utiles JSON »). Là où il a fallu trancher un point qu'aucun des trois ne
> couvrait, c'est écrit en toutes lettres et repris au **§ 11**.
>
> **Convention de renvoi.** « § 9.3 » renvoie à `docs/modele-donnees.md`. « § 6.1 du
> dossier » renvoie à `brief-mission.md`. « ADR 0003 » renvoie à `docs/adr/`. La mention
> « du dossier » n'est jamais omise.
>
> **58 routes**, huit modules.
>
> **Les exemples de ce fichier sont tenus par un test.**
> `api/tests/Contrat/InvariantsDuContratTest.php` relit ce document, extrait ses blocs
> ```` ```json ```` et vérifie quatre invariants : la largeur d'un nœud se recalcule depuis
> ses deux étiquettes (§ 5.2) ; toute étiquette de date respecte la grammaire compacte
> (§ 14.4) ; un nœud ne porte aucune clé de fiche ; un bloc date porte ses cinq clés et
> jamais `texte` dans une valeur d'op (§ 14.1). Il ne dépend ni du noyau, ni de la base, ni
> d'une fixture — seulement de ce fichier.
>
> Il existe parce que trois fautes ont été trouvées ici le même soir, **toutes de la même
> forme** : une valeur d'exemple recopiée d'un producteur qui n'est pas celui qui produira la
> valeur réelle. Aucune n'était visible à la relecture. **Si vous modifiez un exemple,
> lancez-le** — et s'il rougit sur une valeur que vous croyez juste, c'est probablement le
> contrat qui se contredit ailleurs, pas le test qui se trompe.
>
> **Ce qu'il ne voit pas, et qu'il faut savoir avant de s'en remettre à lui : il vérifie des
> formes, jamais des données.** Deux valeurs de ce document étaient fausses et il les a
> laissées passer — la génération de la personne 17, `-3` au lieu de `-4`, et la borne
> inférieure de l'arbre, 1657 au lieu de 1655 ; toutes deux trouvées en interrogeant la vraie
> base. Ce qu'il garantit, c'est qu'une valeur corrigée **n'abandonne pas derrière elle les
> valeurs qui en dérivent** : corriger une génération sans corriger `y`, ou une borne sans
> recalculer les bandes, le fait rougir.

---

## Table des matières

| § | Contenu |
|---|---|
| 1 | Conventions — ce qui vaut pour toutes les routes |
| 2 | Authentification et session |
| 3 | Invitations |
| 4 | Inscription en trois étapes |
| 5 | Arbre |
| 6 | Propositions |
| 7 | Médias |
| 8 | Notifications |
| 9 | Chronologie |
| 10 | Administration et GEDCOM |
| 11 | Le cercle de visibilité dans les réponses — la même fiche deux fois |
| 12 | Le régime du compte en attente de validation |
| 13 | Le calque « en attente » |
| 14 | Les dates incertaines |
| 15 | Les erreurs |
| 16 | Ce qui n'est jamais renvoyé |
| 17 | Le test des trois routes — ce qu'il a révélé |
| 18 | Ce que le modèle ne tranche pas — points signalés |
| 19 | Carte des lieux |
| 20 | Anniversaires |

---

## 1. Conventions

### 1.1 Origine, préfixe, format

- **Même origine** front et API (§ 2 du dossier). Aucun CORS n'est ouvert : c'est le proxy
  Vite en développement et nginx en production qui unifient l'origine. Une route API n'émet
  jamais d'en-tête `Access-Control-*`.
- Toutes les routes sont préfixées **`/api`**. Tout ce qui est sous `/api` est derrière
  `access_control`, à l'exception nommée du § 1.6.
- **Requête** : `Content-Type: application/json; charset=utf-8`, sauf le téléversement
  (§ 7.1) qui est en `multipart/form-data`.
- **Réponse** : `application/json; charset=utf-8`, sauf les deux routes de fichier (§ 7.4,
  § 7.5) qui rendent le type réel du média.
- Une réponse sans corps (204) n'est utilisée **nulle part**. Une action qui réussit rend
  toujours l'état résultant : le front n'a jamais à refaire un `GET` pour savoir ce qu'il
  vient de faire.

### 1.2 Langue des clés — la règle mécanique

Le projet est en français, mais les entités sont en anglais au singulier (§ 0.1), et le
modèle impose que `prp_change_op.field` porte le **nom de propriété Doctrine** (§ 5.2). La
règle est donc mécanique, et il n'y a pas à l'interpréter :

> **Une clé qui correspond à une colonne porte le nom de la propriété Doctrine**
> (`surname`, `givenNames`, `displayName`, `birth`, `deceased`, `personId`, `submittedAt`).
> **Toute autre clé est en français** (`erreur`, `elements`, `vivant`, `enAttente`,
> `visibilite`, `resultat`, `candidats`, `lien`, `parente`, `ligneDates`, `etiquetteNom`,
> `etiquetteDates`).

**Pourquoi, et pourquoi il ne faut pas « harmoniser en français ».** Le calque « en attente »
(§ 13) désigne les champs modifiés par leur nom : `enAttente["surname"]`. Si la fiche
exposait `nom` pendant que l'op dit `surname`, il faudrait une table de correspondance entre
deux jeux de noms — exactement ce que le § 5.3 désigne comme « l'endroit où les champs se
perdent en silence quand on en ajoute un ». Avec la règle ci-dessus, **la clé de `enAttente`
est littéralement la clé du corps principal**, et le front pose sa pastille sans aucune
logique de traduction.

Conséquences à accepter :

- `displayName`, pas `nomAffichage`. `birth`, pas `naissance`. `status`, pas `statut` —
  **dans un objet qui reflète une ligne**.
- `{"utilisateur": …, "membre": …}` en revanche : ce sont des **emplacements d'enveloppe**,
  ils n'ont pas de colonne, ils sont en français.
- Les **valeurs** d'énumération sont celles du modèle, jamais traduites, jamais recasées :
  `actif`, `en_attente`, `feminin`, `entre`, `modification_personne`, `pm`. Une valeur
  d'énumération est une chaîne qui part en base (§ 5.7) ; l'API la rend telle quelle.
- Casse : `camelCase` partout. Les clés de réglage (`cercle.generations`) gardent leur forme
  pointée, elles sont des données et non des identifiants de champ.

### 1.2 bis Ce qu'est un exemple, et ce qu'il n'est pas

> **Un exemple illustre, il n'énumère pas. Les cas font foi dans les sections de vocabulaire,
> le § 14.1 en tête.**

Il faut l'écrire parce que l'inverse s'est produit quatre fois : **les exemples de ce document
sont lus comme des spécifications.** Quand un exemple ne montre qu'un cas, le lecteur en
déduit qu'il n'y en a qu'un — et il écrit un type qui ment. Un exemple de barre de
chronologie sans nullité a produit une union discriminée qui plantait sur 64 barres sur 235 ;
un autre, avec des bornes entières, a fait partir quatre barres 1 655 ans avant l'origine de
l'axe, **sans lever d'exception**.

Deux conséquences pratiques, et elles vont dans les deux sens :

- **Pour qui lit** : avant de typer un champ, cherchez sa définition — § 14.1 pour les dates,
  § 5.7 du modèle pour les énumérations, la section du champ pour le reste. L'exemple dit à
  quoi ça ressemble ; la définition dit ce que c'est.
- **Pour qui écrit** : là où un champ est nullable, **montrez-le nullable au moins une fois**,
  ou renvoyez à la section qui le dit. Un cas qu'aucun exemple ne montre est un cas que
  personne n'implémente. C'est pourquoi le § 9.1 illustre désormais ses trois nullités au lieu
  de les mentionner.

### 1.3 Absent contre `null` — la règle qui porte la confidentialité

> **Un champ que le lecteur n'a pas le droit de voir est absent de la charge utile.
> Un champ qu'il a le droit de voir et qui n'est pas renseigné est présent, à `null`.**

C'est la traduction en JSON de « un champ masqué n'est pas envoyé puis caché : il n'est pas
envoyé » (§ 8 du dossier, ADR 0003). Elle donne au front une distinction gratuite et sans
drapeau : `"birth" in fiche === false` veut dire *interdit*, `fiche.birth === null` veut
dire *inconnu*. Le front n'invente jamais un « — » pour un champ interdit : il n'affiche pas
la ligne du tout.

Corollaire pour le back : **on ne construit pas la charge utile complète pour en retirer
ensuite les clés interdites.** Le normaliseur décide, champ par champ, s'il l'ajoute. Une
implémentation qui remplit puis `unset()` laisse passer le premier champ ajouté après coup —
c'est le mode de défaillance que la liste blanche (§ 11.4) existe pour attraper.

### 1.4 Types scalaires

| Type | Forme JSON | Exemple |
|---|---|---|
| Identifiant technique | nombre entier | `17` — **valeur d'illustration**, voir ci-dessous |
| Identifiant de nœud de graphe | chaîne préfixée (§ 5.2) | `"p17"`, `"f28"`, `"#12/p1"` |
| `TIMESTAMPTZ` | ISO 8601 avec décalage | `"2026-09-15T18:42:11+02:00"` |
| `DATE` généalogique | `YYYY-MM-DD`, jamais d'heure | `"1901-01-01"` |
| Bloc date | objet à 5 clés (§ 14) | voir § 14 |
| Booléen | `true` / `false`, jamais `0`/`1` | `false` |
| Énumération | chaîne, valeur du modèle | `"feminin"` |
| Montant, coordonnée | nombre, décimales non tronquées | `-412.5` |

> **Les identifiants des exemples n'ont aucune stabilité, et ne servent qu'à relier les
> exemples entre eux.** `tree_person.id` est une colonne d'identité que le réimport
> réattribue : mesuré, les identifiants 1–274 sont devenus 275–548 après neuf imports, et
> Marne Dolomite Sureau, `17` dans ce document, porte aujourd'hui `291`. **Ne codez jamais un
> identifiant en dur**, ni dans un test, ni dans une fixture, ni dans un lien : appariez sur
> **`gedcomXref`** (`@I500017@`), qui est l'identité externe de l'enregistrement et survit à
> l'aller-retour (§ 9.8). Les identifiants d'URL restent des entiers — courts et lisibles
> (§ 0.2) — mais ce sont des poignées, pas des clés.

Les coordonnées `x` et `y` du graphe sont des flottants et **ne sont jamais arrondies à
l'émission** : le test de non-régression compare au centième (ADR 0005), un arrondi de
sérialisation rendrait ce test ininterprétable. `w` et `h`, eux, arrivent **déjà entiers** du
moteur, qui les **tronque** — la sérialisation ne fait ni l'un ni l'autre, elle recopie
(§ 5.2).

Règle générale, tirée d'un pixel d'écart trouvé en relecture : **une opération numérique se
décrit en formule, jamais en français.** « Arrondi », « au plus proche », « centré » ont
chacun deux ou trois implémentations, et la mauvaise ne se voit qu'en superposant deux
rendus. Partout où ce document donne un calcul, il donne l'opération exacte.

### 1.5 Enveloppe de liste et pagination

Le dépôt de référence n'a pas de convention de pagination — il plafonne en dur
(`setMaxResults`) et rend un tableau nu. Ce n'est pas tenable ici : le journal d'audit et les
notifications croissent sans borne. Forme unique, pour **toutes** les listes paginées :

```json
{
  "elements": [],
  "total": 128,
  "page": 1,
  "parPage": 25
}
```

- Paramètres de requête : `?page=1&parPage=25`. `page` commence à **1**.
- `parPage` par défaut **25**, maximum **100**. Au-delà, la valeur est **ramenée à 100**
  sans erreur — un plafond qui lève une 422 apprend à l'appelant où est la limite, un plafond
  qui rabote ne lui apprend rien et sert le même résultat.
- `total` est le nombre d'éléments **après** application du cercle de visibilité, pas avant.
  Sans cela, un lecteur déduit du compte ce que la liste lui cache.
- Une liste **non** paginée (elle est bornée par nature : les réglages, les branches, les
  ops d'une demande) rend un tableau nu, sans enveloppe. Le doute ne se pose pas : le tableau
  de routes dit laquelle est laquelle.

### 1.5 bis `avertissements` — toujours un tableau

Plusieurs routes rendent des avertissements à côté d'un succès : un doublon de fichier au
téléversement (§ 7.2), un envoi de courriel impossible (§ 3.1), une référence disparue d'une
reprise (§ 6.10). Trois formes circulaient dans les charges capturées — clé **absente**, clé à
**`null`**, et tableau — et un `.map()` direct tombe sur deux des trois.

> **`avertissements` est toujours présent et toujours un tableau, vide quand il n'y a rien.
> Jamais `null`, jamais absent.**

C'est la même exception assumée que `champs` et `details` dans l'enveloppe d'erreur (§ 15.1) :
une clé de **protocole** n'obéit pas à la règle « absent contre `null` » du § 1.3, qui vise les
**données**. La règle du § 1.3 existe pour distinguer *interdit* de *inconnu* ; un
avertissement n'est ni l'un ni l'autre, il y en a zéro ou plusieurs. Un consommateur doit
pouvoir écrire `reponse.avertissements.map(...)` sans garde.

Chaque entrée porte `{code, message}`, plus les clés propres au code — `mediaId` pour
`doublon_probable`, `lien` pour `reference_disparue`.

### 1.6 Authentification, rôles et régimes

Session `json_login`, cookie `HttpOnly` + `SameSite=Lax` + `Secure` en production (déjà posé
dans `api/config/packages/framework.yaml`). **Aucun jeton dans un en-tête, aucun jeton en
`localStorage`** : le cookie est le seul porteur de session.

`access_control` :

```yaml
- { path: ^/api/connexion,   roles: PUBLIC_ACCESS }
- { path: ^/api/inscription, roles: PUBLIC_ACCESS }   # § 4 — 404 sur jeton invalide
- { path: ^/api/administration, roles: ROLE_ADMIN }
- { path: ^/api,             roles: ROLE_USER }
```

Deux rôles, et **deux régimes qui ne sont pas des rôles** :

| Code d'accès employé dans les tableaux | Ce que c'est |
|---|---|
| `PUBLIC` | Aucune session. Trois routes seulement (§ 4). |
| `ATTENTE` | `ROLE_USER` + `core_user.status = 'en_attente'`. **Pas de cercle** — régime distinct, jamais « un cercle avec N = 0 » (§ 4.5, ADR 0003). **Ne lit rien de l'arbre** depuis le 2026-10-01 : ce code ne figure plus que sur les routes **du compte** (session, notifications, push, inscription). Voir § 12. |
| `MEMBRE` | `ROLE_USER` + `status = 'actif'` + un `mbr_member` actif. Implique `ATTENTE`. |
| `ADMIN` | `ROLE_ADMIN`. Implique `MEMBRE`. |

- `role_hierarchy: ROLE_ADMIN: [ROLE_USER]`.
- Un compte `suspendu` **ne s'authentifie pas** : `POST /api/connexion` rend `401`
  `compte_suspendu`. Il n'obtient pas de session qu'il faudrait ensuite filtrer.
- Toute route de **lecture de l'arbre** porte `MEMBRE` et répond `403` `regime_en_attente`
  à un compte en attente — et non `acces_refuse` : le front y reconnaît l'attente et mène à
  l'écran d'attente (révision du 2026-10-01, § 12). Côté code : l'attribut
  `Core\Security\ReserveAuxMembres`, qui consulte le voter avant le contrôleur. Le voter
  lit le statut du compte **puis le régime du cercle** (`cercle.regime` de la session) :
  un compte actif sans `mbr_member` actif — ancien administrateur rétrogradé, compte créé
  en ligne de commande — reçoit le même `403` (correction du 2026-10-01). Son
  `GET /api/inscription/etat` rend `404` ; l'écran d'attente le dit « compte sans fiche ».
- La distinction `ATTENTE` / `MEMBRE` n'est **pas** portée par un rôle. Un `ROLE_MEMBRE`
  qu'on retire à l'activation serait une seconde source de vérité à côté de
  `core_user.status` et de `mbr_member.status`. Elle est portée par un **voter**, appelé
  par chaque route qui l'exige (§ 8 du dossier : « anti-IDOR systématique : toute route qui
  prend un identifiant passe par un voter »).

### 1.7 Découpage des chemins par module

Chaque module déclare ses routes dans **son** fichier `config/routes/<module>.yaml`
(ADR 0002, règle 1). Deux modules ne déclarent jamais le même chemin. La carte :

| Préfixe | Module propriétaire |
|---|---|
| `/api/connexion`, `/api/deconnexion`, `/api/session`, `/api/notifications` | `Core` |
| `/api/inscription` | `Membership` |
| `/api/arbre` | `Tree` |
| `/api/demandes` | `Proposal` |
| `/api/medias` | `Media` |
| `/api/chronologie` | `Timeline` |
| `/api/carte`, `/api/anniversaires` | `Almanach` |
| `/api/administration/**` | **le troisième segment désigne le propriétaire** — voir ci-dessous |

`/api/administration` est le seul préfixe partagé, parce que « administration » est un
public, pas un domaine. Le troisième segment tranche, sans ambiguïté possible :

| Chemin | Module |
|---|---|
| `/api/administration/utilisateurs`, `/reglages`, `/voir-comme`, `/journal` | `Core` |
| `/api/administration/invitations`, `/membres` | `Membership` |
| `/api/administration/demandes` | `Proposal` |
| `/api/administration/gedcom` | `Gedcom` |
| `/api/administration/sources` | `Tree` (ADR 0012) |

### 1.8 En-têtes

**Trois régimes de cache, et trois seulement.** La chaîne exacte, à recopier :

| Régime | Routes | `Cache-Control` | `ETag` | `Vary` |
|---|---|---|---|---|
| **A — rien n'est gardé** | toutes les routes JSON, **sauf le graphe** | `private, no-store` | aucun | `Cookie` |
| **B — revalidation** | `GET /api/arbre/graphe` | `private, no-cache` | `"<64 hex>"` **fort** (§ 5.2) | `Cookie` |
| **C — revalidation d'octets** | `GET /api/medias/{id}/fichier` et `/vignette` | `private, no-cache` | `"<sha256 du fichier>"` **fort** | `Cookie` |

| Autre en-tête | Où | Valeur |
|---|---|---|
| `Retry-After` | toute `429` **sauf** `POST /api/inscription/rattachement` (§ 4.2) | secondes |
| `Content-Disposition` | `GET /api/medias/{id}/fichier` | `inline; filename="<original_name assaini>"` |

> **Corrigé le 17/09/2026 : le régime A porte `private, no-store` et `Vary: Cookie`.**
> Cette table disait `no-store` seul et « `Vary` : aucun » ; le code écrit
> `private, no-store` + `Vary: Cookie` depuis `ChronologieController`, et c'est
> lui qui a raison. Ces charges dépendent du **lecteur** — le cercle de
> visibilité s'applique à la sérialisation —, et une réponse qui varie selon le
> cookie sans le déclarer est une réponse qu'un cache partagé peut servir à
> quelqu'un d'autre. `private` le dit au cache, `Vary` dit sur quoi.
>
> La divergence était sans conséquence tant qu'aucun cache partagé ne se trouvait
> sur le chemin. Elle est réconciliée plutôt que propagée aux deux routes
> d'`Almanach`.

**`no-store` et `no-cache` ne sont pas deux intensités de la même chose**, et les confondre
donne soit une fuite, soit une application lente :

- **`no-store`** interdit d'écrire la réponse où que ce soit — disque, mémoire, historique.
  C'est le régime A, et c'est le bon défaut : ces charges utiles portent des données de
  personnes vivantes, elles n'ont rien à faire dans le cache disque d'un navigateur familial
  partagé.
- **`no-cache`** autorise à stocker mais **impose de revalider avant chaque réutilisation**.
  C'est ce qu'il faut pour B et C : le client garde les octets, redemande à chaque fois, et
  reçoit `304` sans corps tant que rien n'a bougé.
- **`private`** interdit à tout cache partagé — proxy, passerelle — de garder la réponse. Sans
  lui, un intermédiaire peut servir le graphe d'un membre du cercle à un membre hors cercle.
  `Vary: Cookie` est la seconde moitié de la même précaution.

**Pourquoi les médias sont en C et non en A.** Un rendu de l'arbre demande des dizaines de
vignettes ; en `no-store`, elles se retéléchargent à chaque navigation. Or les octets d'un
média sont **immuables** — nom de stockage aléatoire, `sha256` stable (§ 6.1) — donc leur
`ETag` ne change jamais et la revalidation coûte un aller-retour vide. Ce qui peut changer,
c'est **l'autorisation** : le cercle du lecteur bouge, la personne décède, le média est
refusé. D'où la règle qui rend ce régime sûr, et qu'il faut écrire parce qu'elle s'optimise
mal :

> **Le voter s'exécute avant la comparaison d'`ETag`, toujours.** Un `304` est une réponse :
> la servir à quelqu'un qui vient de perdre l'accès lui apprend que la ressource existe
> toujours et n'a pas changé. Un lecteur qui n'a plus le droit reçoit **`404`**, quel que soit
> son `If-None-Match` (§ 7.4). Court-circuiter sur l'`ETag` « puisque c'est moins cher » est
> exactement l'optimisation à ne pas faire.

### 1.9 Limitation de débit

Quatre limiteurs, comme l'exige le § 8 du dossier. Un seul est piloté par `core_setting`
(§ 2.2) parce que le modèle le nomme ; les trois autres vivent dans `framework.yaml`, le
composant `RateLimiter` stockant ses compteurs dans le cache (§ 0.6 — pas de table).

| Route | Clé de comptage | Plafond | Réponse au dépassement |
|---|---|---|---|
| `POST /api/connexion` | courriel + IP | 5 / 15 min (`login_throttling`) | `429` `trop_de_tentatives`, `Retry-After` |
| `POST /api/inscription/verifier` | IP | 20 / heure | `429` `trop_de_tentatives` |
| `POST /api/inscription/rattachement` | **jeton** | `invitation.tentatives_par_heure`, défaut **5** | **`200` indistinguable d'une recherche infructueuse** — voir § 4.2 |
| `POST /api/inscription/rattachement` | IP | 30 / heure | `429` `trop_de_tentatives` |
| `POST /api/medias` | compte (administrateur exempté) | 20 / heure **par défaut**, réglable par compte (§ 10.1) | `429` `trop_de_tentatives` |
| `POST /api/demandes` | compte | 30 / heure | `429` `trop_de_tentatives` |

Le plafond par jeton est le garde-fou du § 6.1 du dossier ; le plafond par IP est là pour le
balayage distribué sur plusieurs jetons, que le premier ne voit pas. Les deux coexistent et
ne se répondent **pas** de la même façon : c'est le point le plus délicat du document, il est
traité au § 4.2 et au § 17.1.

**Les quotas d'usage réglables** (depuis le 1er octobre 2026) — téléversements, analyses et
questions de l'assistant, rattachement assisté — ont un plafond **par défaut** en
configuration (`media.yaml`, `assistant.yaml`) et, pour chaque compte, une limite
**personnelle** facultative posée par l'administrateur (§ 10.1, « Quotas d'usage »). La
limite effective est la personnelle, sinon le défaut ; le message du `429` cite la limite
effective. Une limite à `0` ferme l'usage (`429` aussitôt, rien n'est décompté).

**Symfony ne rend pas spontanément l'enveloppe d'erreur du § 15.** `login_throttling` émet sa
propre réponse : il faut un `AuthenticationFailureHandler` qui la normalise. Sans lui, la
seule route que tout le monde appelle en premier est la seule à ne pas respecter le contrat.

---

## 2. Authentification et session

Module `Core`. Session `json_login`, cookie `HttpOnly`, 401 JSON (§ 2 du dossier).

| Méthode | Chemin | Accès | Corps | Succès | Erreurs | Débit |
|---|---|---|---|---|---|---|
| `POST` | `/api/connexion` | `PUBLIC` | `{email, password}` | `200` charge de session (§ 2.2) | `401` `identifiants_invalides` · `401` `compte_suspendu` · `422` `donnees_invalides` | 5 / 15 min |
| `POST` | `/api/deconnexion` | `ATTENTE` | *(vide)* | `200` `{"deconnecte": true}` | `401` | — |
| `GET` | `/api/session` | `ATTENTE` | — | `200` charge de session | `401` | — |
| `POST` | `/api/session/mot-de-passe` | `ATTENTE` | `{motDePasseActuel, nouveauMotDePasse}` | `200` `{"motDePasseChange": true, "clesDAccesRetirees": n}` — **toutes les clés d'accès du compte sont retirées** (§ 2.4) | `401` · `422` `donnees_invalides` | — |
| `POST` | `/api/session/courriel` | `ATTENTE` | `{motDePasseActuel, nouveauCourriel}` | `200` `{"courrielEnvoye": true, "expiresAt"}` | `401` · `422` `donnees_invalides` · `409` `courriel_deja_pris` · `409` `courriel_non_envoyable` | — |
| `POST` | `/api/session/courriel/confirmer` | `PUBLIC` | `{jeton}` | `200` `{"courrielChange": true}` | `404` · `409` `courriel_deja_pris` | — |
| `POST` | `/api/connexion/cle-d-acces/options` | `PUBLIC` | *(vide)* | `200` `{options}` (§ 2.4) | — | — |
| `POST` | `/api/connexion/cle-d-acces` | `PUBLIC` | `{reponse}` | `200` charge de session (§ 2.2) | `401` `identifiants_invalides` · `401` `compte_suspendu` · `429` `trop_de_tentatives` | 5 / 15 min, **partagé** avec `/api/connexion` |
| `POST` | `/api/cles-d-acces/options-d-enregistrement` | `ATTENTE` | *(vide)* ou `{motDePasseActuel}` | `200` `{options}` | `401` · `409` `incarnation_active` · `422` `donnees_invalides` sur `champs.motDePasseActuel` (preuve récente exigée, § 2.4) | — |
| `POST` | `/api/cles-d-acces` | `ATTENTE` | `{reponse, nom?}` | `201` clé (§ 2.4) | `401` · `409` `incarnation_active` · `409` `cle_deja_enregistree` · `422` `donnees_invalides` | — |
| `GET` | `/api/cles-d-acces` | `ATTENTE` | — | `200` `[clé]` (les siennes) | `401` | — |
| `PATCH` | `/api/cles-d-acces/{id}` | `ATTENTE` | `{nom}` | `200` clé | `401` · `404` · `409` `incarnation_active` · `422` `donnees_invalides` | — |
| `DELETE` | `/api/cles-d-acces/{id}` | `ATTENTE` | — | `204` | `401` · `404` · `409` `incarnation_active` | — |

`POST /api/connexion` est le `check_path` de `json_login` : les clés du corps sont fixées par
`username_path: email` et `password_path: password`. Elles se trouvent être aussi les noms de
propriété (§ 1.2) — la cohérence est heureuse, pas fortuite, et il ne faut pas la « franciser ».

`POST /api/deconnexion` est interceptée par le listener `logout` du pare-feu ; le contrôleur
n'est jamais exécuté. Le listener doit rendre le corps ci-dessus et non une redirection.

### 2.1 Changement de mot de passe

```json
{ "motDePasseActuel": "…", "nouveauMotDePasse": "…" }
```

- 12 caractères minimum (§ 6.1 du dossier). Refus en `422` avec
  `champs: {"nouveauMotDePasse": "12 caractères minimum."}`.
- `motDePasseActuel` faux → `422` sur `champs.motDePasseActuel`, **pas** `401` : le compte
  est authentifié, c'est la donnée soumise qui est mauvaise. Une `401` ferait déconnecter le
  front par sa logique de rattrapage globale.
- **La session en cours survit** au changement ; les autres sessions du même compte ne sont
  pas invalidées (Symfony ne le fait pas par défaut, et il n'y a pas de table de session —
  § 0.6). À noter en feuille de route, pas à improviser ici.
- Journalisé en `modification` sur `Core\User`, avec `password` remplacé par `"***"` dans
  `changes` (§ 2.4).

### 2.2 La charge de session

C'est la charge utile que le front lit au démarrage et après chaque connexion. Elle porte
tout ce qui conditionne l'affichage : le régime, le cercle, la bascule d'administration.

Membre actif :

```json
{
  "utilisateur": {
    "id": 3,
    "email": "cobalt.romarin@example.net",
    "displayName": "Cobalt Romarin",
    "roles": ["ROLE_USER"],
    "status": "actif",
    "totpEnabledAt": null,
    "lastLoginAt": "2026-09-15T18:42:11+02:00"
  },
  "membre": {
    "id": 7,
    "status": "actif",
    "personId": 129,
    "personLabel": "Cobalt Romarin",
    "matchKind": "pre_lie",
    "activatedAt": "2026-03-02T09:14:00+01:00"
  },
  "cercle": {
    "regime": "cercle",
    "generations": 3,
    "taille": 34
  },
  "voirComme": null,
  "incarnation": null,
  "demandesEnAttente": [12]
}
```

`cercle.taille` est la taille du cercle **généalogique** `C(u)` (§ 11). Depuis la révision du
2026-10-01 de l'ADR 0003, ce n'est plus le nombre de vivants que le membre voit — il les voit
tous —, mais l'étendue sur laquelle il voit aussi les fiches à confidentialité forcée.

Compte en attente de validation — **noter les trois différences**, elles ne sont pas
cosmétiques :

```json
{
  "utilisateur": {
    "id": 9,
    "email": "cousin.saule@example.net",
    "displayName": "cousin.saule@example.net",
    "roles": ["ROLE_USER"],
    "status": "en_attente",
    "totpEnabledAt": null,
    "lastLoginAt": "2026-09-15T20:01:44+02:00"
  },
  "membre": {
    "id": 11,
    "status": "en_attente",
    "matchKind": "recherche_confirmee",
    "activatedAt": null
  },
  "cercle": { "regime": "aucun" },
  "voirComme": null,
  "incarnation": null,
  "demandesEnAttente": []
}
```

1. **`cercle.regime` vaut `"aucun"`, pas `"cercle"` avec une liste vide.** C'est la
   traduction JSON de « ce n'est pas un cercle réduit, c'est un régime distinct » (§ 4.5,
   ADR 0003). Le front branche sur `regime`, jamais sur `taille === 0`. `generations` et
   `taille` sont **absents** — il n'y a pas de N pour ce régime.
2. **`membre.personId` et `membre.personLabel` sont absents.** Le compte prétend se
   rattacher à une personne, éventuellement déjà pré-liée sur l'invitation — mais « confirmer
   une date n'est pas la lire », et le pré-lien n'ouvre aucun accès (§ 4.5). Tant que
   l'administrateur n'a pas activé, **cette fiche lui est aussi fermée qu'aux autres**, y
   compris son libellé. Un back qui renvoie `personLabel` « puisque c'est lui » enfreint la
   règle sur la porte de sortie n° 8 (§ 0.4).
3. `utilisateur.displayName` retombe sur le courriel tant qu'il n'y a pas de rattachement
   validé. Il n'est **jamais** renseigné depuis `mbr_member.person_label` avant activation.

`demandesEnAttente` liste les identifiants des demandes `en_attente` dont le lecteur est
l'auteur. Le front s'en sert pour savoir s'il doit attendre un calque (§ 13) et pour afficher
un compteur ; il conditionne aussi l'`ETag` du graphe (§ 5.2), ce qui rend le lien explicite
plutôt que magique.

`voirComme` est `null` sauf pour un administrateur ayant activé la bascule (§ 10.4).
`incarnation` est `null` sauf quand un administrateur est connecté en tant que ce compte
(§ 23) : elle vaut alors `{par: {id, displayName}}`.


---

### 2.3 Changement de courriel

Le courriel est l'**identifiant** du compte. Il ne se remplace pas sans deux preuves, et
aucune des deux ne suffit seule :

1. **le mot de passe actuel**, à la demande — une session volée ne le connaît pas ;
2. **un clic sur un lien reçu à la nouvelle adresse**, à la confirmation — sans lui, une
   faute de frappe enferme le titulaire dehors, et la réinitialisation partirait vers une
   boîte qui n'existe pas.

**La demande** — `POST /api/session/courriel`, accès `ATTENTE` :

```json
{ "motDePasseActuel": "…", "nouveauCourriel": "ada.nouvelle@exemple.test" }
```

- Rien ne change à cet appel. Un courriel part vers la **nouvelle** adresse avec un lien
  `/compte/courriel/{jeton}`, valable **24 h**, à usage unique. Réponse :
  `{"courrielEnvoye": true, "expiresAt": "…"}`.
- `motDePasseActuel` faux → `422` sur `champs.motDePasseActuel`, **pas** `401` — même
  arbitrage qu'au § 2.1. Adresse invalide ou identique à l'actuelle → `422` sur
  `champs.nouveauCourriel`.
- `409 courriel_deja_pris` : un compte existe pour cette adresse. C'est la seconde route de
  l'API qui admet de le révéler (avec § 4.3), et pour la même raison : l'index unique
  refuserait de toute façon. Le risque est borné — il faut une session **et** le mot de
  passe pour poser la question.
- `409 courriel_non_envoyable` : aucun SMTP n'est configuré. Contrairement à l'invitation,
  dont le lien est aussi affiché à l'administrateur, il n'y a personne ici à qui montrer le
  lien — le montrer au demandeur annulerait la seconde preuve. Sans SMTP, la fonctionnalité
  n'existe pas, et la réponse le dit plutôt que de réussir en silence.
- Une nouvelle demande **périme la précédente** : un compte n'a jamais qu'un lien valable.
- Le serveur ne stocke que `hash(jeton)` (`core_email_change`), jamais le jeton.

**La confirmation** — `POST /api/session/courriel/confirmer`, accès `PUBLIC` :

```json
{ "jeton": "…" }
```

- Publique pour la même raison que l'inscription : on clique depuis le courriel, souvent
  sur un autre appareil, sans session.
- Réponse `200 {"courrielChange": true}`. Le courriel devient l'identifiant du compte à cet
  instant. **La session qui confirme continue**, sous la nouvelle adresse — le jeton de
  sécurité porte l'entité, modifiée pendant la requête et resérialisée avec la nouvelle
  adresse. **Toute autre session**, ouverte ailleurs sous l'ancienne, tombe à sa requête
  suivante (`User::isEqualTo` compare l'identifiant). C'est le cas nominal d'un compte
  repris : le titulaire légitime a la nouvelle adresse et son mot de passe, il se
  reconnecte. Mesuré, pas déduit — la première rédaction disait « toute session tombe ».
- **Tout refus rend `404`, avec le même corps** : jeton inconnu, mal formé, absent, périmé
  ou déjà consommé. Un seul cas observable — un `403` sur « périmé » dirait qu'un jeton a
  existé, et la route est publique.
- `409 courriel_deja_pris` si l'adresse a été prise entre la demande et le clic.
- Journalisé par le souscripteur d'audit comme toute modification de `Core\User` :
  `changes.email` porte l'ancienne et la nouvelle adresse (§ 2.4). Aucune entrée à écrire à
  la main.

**Ce qui n'est pas ici** : le nom et le prénom. Ce sont ceux de la fiche rattachée, pas du
compte, et ils se corrigent par une demande (§ 6) — jamais depuis la page du compte.

### 2.4 Clés d'accès (passkeys, WebAuthn)

Se connecter avec **Face ID, Touch ID, l'empreinte d'un téléphone Android ou Windows
Hello**, comme dans les autres applications. **Le mot de passe reste**, en secours : rien
ne le désactive. Bibliothèque serveur `web-auth/webauthn-lib` 5.3 ;
`Core\Service\ClesDAcces`, `Core\Security\CleDAccesAuthenticator`,
`Core\Controller\CleDAccesController`. Le pourquoi est dans `docs/securite-comptes.md`
§ 2 bis.

**La politique**, fixée côté serveur et lisible dans les options :

| Réglage | Valeur |
|---|---|
| Partie de confiance | `rp.id` = l'hôte de `HERITAGE_URL_PUBLIQUE` (`heritage.naltpom.fr` en production, `localhost` en développement), `rp.name` = `Heritage` |
| Vérification de l'utilisateur | `userVerification: "required"` — biométrie ou code de l'appareil, à chaque fois |
| Clé découvrable | `residentKey: "required"` — on se connecte **sans taper son courriel** |
| Attestation | `"none"` |
| Algorithmes | ES256 (`-7`), RS256 (`-257`) |
| Défi | 32 octets, **en session**, **à usage unique** (consommé à la première réponse, bonne ou mauvaise), **5 minutes** ; `timeout` = 300 000 ms |
| Origine | même hôte que `rp.id`, sous-domaines refusés ; HTTPS exigé sauf `localhost` / `127.0.0.1` |

Tous les champs binaires des options et des réponses sont en **base64url sans
remplissage** — la forme de `PublicKeyCredential.toJSON()` et de
`PublicKeyCredential.parseCreationOptionsFromJSON()` / `parseRequestOptionsFromJSON()`.
Les options sont rendues **telles que WebAuthn les nomme** (`challenge`, `rp`, `user`,
`pubKeyCredParams`, `excludeCredentials`, `allowCredentials`…) : ce sont des noms d'une
norme, pas des noms de propriété du domaine, et la règle du § 1.2 ne s'y applique pas. Il
en va de même de `reponse`, qui est l'objet `toJSON()` du navigateur tel quel.

Une session peut détenir **jusqu'à cinq défis** d'un même type ; la réponse désigne le sien
(`clientDataJSON.challenge`). L'écran de connexion peut donc demander un défi pour
l'autoremplissage à l'affichage, puis un second au clic sur le bouton, sans que le premier
devienne invalide.

#### Enregistrer une clé — compte connecté

Accès `ATTENTE` : un compte en attente se connecte, il peut se connecter plus vite.
**Refusé pendant une incarnation** (`409 incarnation_active`, ADR 0010) : une clé ouvre le
compte, c'est un secret du compte comme le mot de passe. Même refus sur `PATCH` et
`DELETE` ; la lecture reste permise.

**Preuve d'identité récente.** Une session ne suffit pas : une clé d'accès est un moyen
de connexion **permanent**, qui survivrait à la session volée (onglet oublié, poste
partagé) qui l'aurait ajoutée. Le défi d'enregistrement n'est donc délivré que si :

- la session s'est **connectée il y a moins de 10 minutes** (mot de passe ou clé
  d'accès) — le cas de l'invitation qui suit la connexion ; ou
- le corps porte **`motDePasseActuel`**, juste. Il vaut alors preuve fraîche pour les
  10 minutes suivantes (un Face ID annulé se réessaie sans le retaper).

Sinon : `422 donnees_invalides` sur `champs.motDePasseActuel` — « Confirmez votre mot de
passe pour ajouter une clé d'accès. » s'il manque, « Le mot de passe actuel est
incorrect. » s'il est faux. `422` et non `401`, comme au § 2.1 : le front ne doit pas
déconnecter. Une session ouverte avant le 1er octobre 2026 ne porte pas d'heure de
connexion : elle donne le mot de passe. Changer son mot de passe vaut aussi preuve
fraîche.

**Avis par courriel.** Chaque ajout envoie au titulaire « Une clé d'accès a été ajoutée
à votre compte Heritage » (nom, date, quoi faire si ce n'est pas lui). Au mieux : sans
SMTP configuré, rien ne part ; une panne du relais est journalisée et l'ajout réussit.

**Révocation.** Changer son mot de passe (`POST /api/session/mot-de-passe`) et la
réinitialisation par l'administrateur
(`POST /api/administration/utilisateurs/{id}/mot-de-passe`) **retirent toutes les clés d'accès du
compte**, dans la même écriture. Le titulaire réenregistre les siennes.

1. `POST /api/cles-d-acces/options-d-enregistrement` (corps vide, ou
   `{ "motDePasseActuel": "…" }`) → `200` :

   ```json
   { "options": {
       "challenge": "…43 signes…", "timeout": 300000,
       "rp": { "id": "heritage.naltpom.fr", "name": "Heritage" },
       "user": { "id": "…opaque…", "name": "ada@exemple.test", "displayName": "Ada Romarin" },
       "pubKeyCredParams": [ { "type": "public-key", "alg": -7 }, { "type": "public-key", "alg": -257 } ],
       "authenticatorSelection": { "userVerification": "required", "residentKey": "required", "requireResidentKey": true },
       "attestation": "none",
       "excludeCredentials": [ { "type": "public-key", "id": "…", "transports": ["internal", "hybrid"] } ]
   } }
   ```

   `excludeCredentials` liste **les clés existantes du compte** : l'appareil qui en porte
   déjà une refuse d'en créer une seconde. `user.id` est **opaque** (empreinte stable du
   compte) : ni courriel ni identifiant lisible — l'appareil le garde et peut l'afficher.

2. `navigator.credentials.create({ publicKey })`, puis
   `POST /api/cles-d-acces` `{ "reponse": <credential.toJSON()>, "nom": "iPhone d'Ada" }`
   → `201` :

   ```json
   { "id": 7, "nom": "iPhone d'Ada", "creeeLe": "2026-10-01T15:00:00+02:00", "derniereUtilisation": null, "appareil": "Trousseau iCloud" }
   ```

   - `nom` est facultatif (80 signes au plus, espaces resserrés). Absent ou vide : le nom
     de l'authentificateur s'il est reconnu (`Trousseau iCloud`, `Gestionnaire de mots de
     passe Google`, `Windows Hello`…), sinon l'appareil lu dans le `User-Agent`
     (`iPhone`, `Android`, `Windows`…), sinon `Clé d'accès`.
   - `appareil` : le nom de l'authentificateur reconnu par son AAGUID, ou `null`.
   - Toute réponse refusée — défi absent, expiré ou déjà utilisé, origine étrangère,
     vérification de l'utilisateur absente, attestation illisible — rend
     `422 donnees_invalides` sur `champs.reponse`, **sans détail** : il n'aide pas la
     personne et renseignerait celui qui fabrique des réponses.

**La forme d'une clé** — `{id, nom, creeeLe, derniereUtilisation, appareil}`. Ni
l'identifiant de la clé, ni la clé publique : le front n'en a pas l'usage.

- `GET /api/cles-d-acces` → `200 [clé, …]`, les siennes, de la plus ancienne à la plus
  récente.
- `PATCH /api/cles-d-acces/{id}` `{nom}` → `200 clé`. Nom absent, vide ou trop long :
  `422` sur `champs.nom`.
- `DELETE /api/cles-d-acces/{id}` → `204`. La clé reste dans l'appareil, orpheline : le
  navigateur la proposera encore, la connexion la refusera.
- **La clé d'un autre compte rend `404`**, comme une clé inexistante.

#### Se connecter — publique

1. `POST /api/connexion/cle-d-acces/options` → `200` :

   ```json
   { "options": { "challenge": "…", "timeout": 300000, "rpId": "heritage.naltpom.fr", "allowCredentials": [], "userVerification": "required" } }
   ```

   `allowCredentials` est **vide** : la clé est découvrable, c'est l'appareil qui propose
   les comptes qu'il connaît. Utilisable avec `mediation: "conditional"` (autoremplissage
   sur un champ `autocomplete="username webauthn"`) comme avec le bouton.

2. `navigator.credentials.get({ publicKey })`, puis
   `POST /api/connexion/cle-d-acces` `{ "reponse": <credential.toJSON()> }`.

**Exactement les réponses de `POST /api/connexion`**, au caractère près, parce que ce sont
ses gestionnaires qui répondent :

- succès → `200` charge de session (§ 2.2), **session ouverte** (cookie posé, identifiant
  de session renouvelé) ;
- clé inconnue, signature fausse, défi absent, expiré ou déjà utilisé, vérification de
  l'utilisateur absente, **compteur de signatures en recul** (clone), corps inexploitable →
  `401 identifiants_invalides`, message « Courriel ou mot de passe incorrect. » — **un
  seul cas observable** ;
- compte suspendu → `401 compte_suspendu`, après vérification de la signature, comme au
  mot de passe ;
- **le limiteur est le même** (§ 1.9) : quand la clé est connue, les essais comptent sous
  le courriel du compte, cumulés avec ceux du mot de passe — cinq mots de passe faux
  bloquent aussi la clé. Les clés inconnues comptent sous un identifiant fixe, par IP.
  Dépassement : `429 trop_de_tentatives` + `Retry-After`.

**Journal** : une ligne `connexion` (ou `connexion_echouee`) comme au mot de passe, avec
`changes.methode = "cle_d_acces"` — et `"mot_de_passe"` désormais sur les connexions par
mot de passe. Un échec par clé inconnue porte `actorEmail = "cle_d_acces_inconnue"`.
Enregistrement et suppression d'une clé : lignes `creation` / `suppression` sur
`Core\Passkey`, par le souscripteur Doctrine ; le compteur et la date d'usage n'en laissent
pas, et `publicKey` / `credentialId` sont masqués s'ils devaient apparaître dans un diff.

## 3. Invitations

Module `Membership`, côté administrateur. **Aucune inscription ouverte** : on n'entre que par
un lien d'invitation (§ 6.1 du dossier).

| Méthode | Chemin | Accès | Corps | Succès | Erreurs | Débit |
|---|---|---|---|---|---|---|
| `POST` | `/api/administration/invitations` | `ADMIN` | § 3.1 | `201` § 3.2 | `422` `donnees_invalides` · `409` `personne_deja_rattachee` · `409` `invitation_en_cours` · `409` `vue_comme_membre_active` | — |
| `GET` | `/api/administration/invitations` | `ADMIN` | `?status=&revoquees=&page=&parPage=` | `200` liste paginée | — | — |
| `POST` | `/api/administration/invitations/{id}/revoquer` | `ADMIN` | *(vide)* | `200` invitation | `404` `introuvable` · `409` `invitation_deja_consommee` | — |

### 3.1 Créer une invitation

```json
{
  "personId": 202,
  "email": "cousin.saule@example.net",
  "envoyerCourriel": true,
  "note": "pour la cousine de Saule",
  "maxUses": 1,
  "expiresInDays": 7
}
```

| Champ | Obligatoire | Rôle |
|---|---|---|
| `note` | **oui** | Description du lien, 1 à 255 caractères après `trim`. Absente ou vide ⇒ `422` `champs.note`. |
| `maxUses` | non | Plafond de comptes créés par le lien : entier ≥ 1. `null` ou absent ⇒ **illimité**. |
| `expiresInDays` | non | Durée de validité en jours, entier de 1 à 36 500. `null` ⇒ **n'expire jamais** ; clé **absente** ⇒ la durée réglée (`invitation.ttl_jours`). |
| `personId` | non | Le **pré-lien** (§ 4.1). Le candidat n'aura qu'à confirmer une date de naissance. `null` ou absent ⇒ recherche floue à l'inscription. **Seulement si `maxUses` vaut 1** — sinon `422` `champs.personId` : une fiche ne se réclame qu'une fois. |
| `email` | oui si `envoyerCourriel` | Destinataire. `null` quand l'administrateur copie le lien à la main. |
| `envoyerCourriel` | non, défaut `false` | Déclenche l'envoi SMTP synchrone. |

Contrôles :

- `personId` est résolu par un service de `Tree` **avant** écriture (ADR 0002,
  compensation 1). Inexistant ⇒ `422` `champs.personId`.
- Personne déjà rattachée à un compte (`uniq_mbr_member_person`) ⇒ `409`
  `personne_deja_rattachee`.
- Invitation `active` déjà en cours sur cette personne (`idx_mbr_invitation_person`) ⇒ `409`
  `invitation_en_cours`, avec `erreur.details.invitationId`. Deux liens concurrents vers la
  même fiche sont une confusion garantie, pas une commodité.
- `envoyerCourriel: true` sans SMTP configuré ⇒ l'invitation est **créée quand même**, la
  réponse porte `courrielEnvoye: false` et un avertissement. Perdre l'invitation parce que
  Mailpit est éteint serait absurde : le lien est affiché de toute façon.

### 3.2 La réponse de création — le seul moment où le jeton existe

```json
{
  "id": 4,
  "tokenPrefix": "a3f91c2d",
  "lien": "https://heritage.local/inscription/kJ8s-2Qb1nR7xTmA0vL5wYcE9hP3dZfG6uIoN4jXqSs",
  "courrielEnvoye": true,
  "personId": 202,
  "personLabel": "Pyroxène Tilleul",
  "email": "cousin.saule@example.net",
  "status": "active",
  "maxUses": 1,
  "useCount": 0,
  "expiresAt": "2026-09-22T20:15:00+02:00",
  "createdByEmail": "natron@example.net",
  "createdAt": "2026-09-15T20:15:00+02:00",
  "note": "pour la cousine de Saule",
  "inscrits": [],
  "avertissements": []
}
```

- **`lien` n'apparaît que dans cette réponse, une seule fois, et jamais ailleurs.** Le
  serveur ne stocke que `hash(jeton)` (§ 4.1). `GET /api/administration/invitations` ne porte
  **pas** de clé `lien` — pas `null`, absente (§ 1.3).
- Il n'existe donc **aucune route « renvoyer l'invitation »**, et le front ne doit pas
  proposer ce bouton : le jeton en clair n'existe plus. L'administrateur qui a perdu le lien
  **révoque et recrée** (§ 4.1, explicitement). C'est le piège le plus probable de ce module :
  la route paraît manquante alors qu'elle est impossible.
- Le jeton est **32 octets aléatoires en base64url sans remplissage**, soit 43 caractères
  `[A-Za-z0-9_-]`. `tokenPrefix` en est les 8 premiers caractères, et sert uniquement à
  distinguer deux lignes dans la liste d'administration — 8 caractères sur 43 ne reconstruisent
  rien.
- `lien` est une URL **absolue**, bâtie sur le `Host` de la requête. C'est pour cela que le
  proxy Vite pose `changeOrigin: false`. Le chemin `/inscription/{jeton}` est une **route
  front**, pas une route API : la SPA la lit et appelle `POST /api/inscription/verifier`.
- `personLabel` est présent ici parce que le lecteur est administrateur. Il reste soumis à la
  règle de la porte de sortie n° 8 (§ 0.4, § 16) : c'est un libellé de personne
  potentiellement vivante, filtré comme la personne.

### 3.3 Lister et révoquer

`GET /api/administration/invitations?status=active` — `status` accepte `active`, `utilisee`,
`expiree`, `revoquee`, ou est omis pour tout. `revoquees=0` écarte les liens révoqués (absent
ou `1` : inclus) ; les deux filtres se combinent. Liste paginée du § 1.5, triée par
`createdAt DESC`.

Un lien sert jusqu'à `maxUses` comptes (`null` = illimité). `status` vaut `utilisee` quand le
plafond est atteint (`useCount = maxUses`) — un lien illimité reste `active` tant qu'il n'est
ni révoqué ni échu ; `expiree` n'arrive que si `expiresAt` n'est pas `null`.

```json
{
  "elements": [
    {
      "id": 4,
      "tokenPrefix": "a3f91c2d",
      "personId": 202,
      "personLabel": "Pyroxène Tilleul",
      "email": "cousin.saule@example.net",
      "status": "active",
      "maxUses": 10,
      "useCount": 1,
      "expiresAt": null,
      "usedAt": "2026-09-16T09:02:00+02:00",
      "usedByEmail": "pyroxene@example.net",
      "revokedAt": null,
      "revokedByEmail": null,
      "createdByEmail": "natron@example.net",
      "createdAt": "2026-09-15T20:15:00+02:00",
      "note": "groupe de la famille",
      "tentatives": 2,
      "inscrits": [
        {
          "userId": 12,
          "email": "pyroxene@example.net",
          "memberStatus": "en_attente",
          "createdAt": "2026-09-16T09:02:00+02:00",
          "personId": 202,
          "personLabel": "Pyroxène Tilleul"
        }
      ]
    }
  ],
  "total": 1,
  "page": 1,
  "parPage": 25
}
```

`tentatives` est le décompte des `mbr_claim_attempt` de l'heure écoulée : c'est ce qui permet
à l'administrateur de voir qu'un lien est en train d'être sondé. Les **critères** soumis ne
sont jamais rendus — `query_hash` est une empreinte, pas un texte (§ 4.3, § 16).

`inscrits` liste les comptes nés du lien (`mbr_member.invitation_id`), du plus ancien au plus
récent ; `usedAt` / `usedByEmail` désignent le **dernier**. Dans chaque inscrit, `personId` et
`personLabel` suivent la règle du pré-lien : les deux, ou aucun (§ 0.4, § 16).

`POST .../revoquer` pose `revoked_at` et `status = revoquee`, rend l'invitation à jour, et
journalise `invitation_revoquee`. Un lien déjà utilisé mais pas épuisé se révoque : cela
arrête les inscriptions à venir, les comptes créés restent. Sur un lien **épuisé** : `409`
`invitation_deja_consommee` — révoquer après coup ne défait pas le compte créé, et laisser
croire le contraire serait pire que refuser.

---

## 4. Inscription en trois étapes

Module `Membership`, **`PUBLIC`**. C'est le point le plus délicat du produit : un lien
d'invitation ne doit jamais devenir un moyen d'énumérer les personnes vivantes de l'arbre
(§ 6.1 du dossier, ADR 0003).

| Méthode | Chemin | Accès | Corps | Succès | Erreurs | Débit |
|---|---|---|---|---|---|---|
| `POST` | `/api/inscription/verifier` | `PUBLIC` | `{jeton}` | `200` § 4.1 | **`404` `introuvable`** · `429` | 20 / h / IP |
| `POST` | `/api/inscription/rattachement` | `PUBLIC` | `{jeton, surname, givenNames, birthDate}` | `200` § 4.2 | **`404` `introuvable`** · `429` (IP seulement) | 5 / h / **jeton** + 30 / h / IP |
| `POST` | `/api/inscription/compte` | `PUBLIC` | `{jeton, choix, email, password}` **ou** `{jeton, declaration, email, password}` | `201` § 4.3 | **`404`** · `409` `courriel_deja_pris` · `409` `personne_deja_rattachee` · `422` · `429` (avec `declaration`) | 5 / h / IP **avec `declaration`** |
| `GET` | `/api/inscription/etat` | `ATTENTE` | — | `200` § 4.4 | `401` | — |

> **Depuis le 1er octobre 2026 : l'inscription sans fiche (ADR 0013).** Un candidat qui ne se
> trouve pas — ou pas avec ses données exactes — décrit sa famille en texte libre et crée
> quand même son compte : `en_attente`, **sans fiche** (`matchKind: "a_rattacher"`), régime
> `aucun`. Un administrateur le rattache ensuite depuis l'onglet « À rattacher » (§ 10.1).
> Les quatre règles de cette section ne bougent pas ; la recherche, elle, **ne propose plus
> de défunts** (§ 4.2).

> **404, jamais 403 — sur les trois routes publiques.** Jeton inconnu, mal formé, expiré,
> révoqué ou déjà consommé : **`404`**, corps d'erreur identique dans les cinq cas, même
> message. Un `403` confirmerait que la route existe et que le jeton a une forme valide
> (§ 6.1 du dossier, § 4.1). Le message est **`"Ressource introuvable."`** — jamais « jeton
> expiré », qui distinguerait un jeton ayant existé d'un jeton inventé.

### 4.1 Étape 1a — vérifier le jeton

```json
{ "jeton": "kJ8s-2Qb1nR7xTmA0vL5wYcE9hP3dZfG6uIoN4jXqSs" }
```

Réponse, invitation **pré-liée** :

```json
{
  "mode": "confirmation",
  "expiresAt": "2026-09-22T20:15:00+02:00",
  "candidatsMax": 3,
  "champsDemandes": ["surname", "givenNames", "birthDate"]
}
```

Réponse, invitation **sans pré-lien** :

```json
{
  "mode": "recherche",
  "expiresAt": "2026-09-22T20:15:00+02:00",
  "candidatsMax": 3,
  "champsDemandes": ["surname", "givenNames", "birthDate"]
}
```

`expiresAt` vaut `null` pour un lien sans échéance (§ 3.1).

**Ce que cette réponse ne contient pas, et pourquoi.** Ni `personId`, ni `personLabel`, ni
aucun indice sur la personne pré-liée — pas même une initiale. `mode` dit seulement quel
formulaire afficher. On peut objecter que `mode: "confirmation"` révèle qu'un administrateur
a pré-lié quelqu'un : c'est exact, et c'est le minimum indispensable pour que le formulaire
soit le bon. Le porteur du jeton l'a reçu nominativement ; il apprend qu'on l'attendait, pas
**qui** on attendait.

Les deux modes demandent **les mêmes trois champs**. C'est délibéré : un formulaire qui ne
demanderait que la date en mode confirmation dirait au porteur « nous savons déjà ton nom ».

### 4.2 Étape 1b — la recherche de rattachement

**C'est le principal risque du produit.** Tout ce qui suit est non négociable.

```json
{
  "jeton": "kJ8s-2Qb1nR7xTmA0vL5wYcE9hP3dZfG6uIoN4jXqSs",
  "surname": "Romarin",
  "givenNames": "Pyrite",
  "birthDate": "1980-07-27"
}
```

`birthDate` est une date **ferme** (`YYYY-MM-DD`) : c'est une preuve de connaissance fournie
par le candidat, pas une donnée généalogique. C'est la seule date ferme de toute l'API.

Réponse — **la même forme dans tous les cas**, quel que soit le mode :

```json
{
  "candidats": [
    { "ref": "c1", "libelle": "P. Romarin, 1980" },
    { "ref": "c2", "libelle": "P. Romarin, 1982" }
  ],
  "tentativesRestantes": null
}
```

Aucun candidat, ou plafond horaire atteint, ou date non confirmée :

```json
{
  "candidats": [],
  "tentativesRestantes": null
}
```

#### Ce que la réponse contient — exhaustivement

- `candidats` : **au plus 3** (`invitation.candidats_max`, § 2.2), et **au plus 1** en mode
  confirmation.
- `ref` : un jeton **opaque, aléatoire, à usage unique, lié à la session de cette tentative**,
  valable 15 minutes. Ce n'est **pas** un `tree_person.id` et il ne doit jamais l'être.
  C'est ce `ref` que l'étape 2 renvoie dans `choix`. Un `personId` ici donnerait au candidat
  l'identifiant réel d'une personne vivante — c'est-à-dire le début d'une énumération par
  incrémentation.
- `libelle` : **l'affichage minimal du § 6.1 du dossier** — initiale du premier prénom, point,
  espace, nom, virgule, espace, année de naissance : `"P. Romarin, 1980"`. Rien d'autre.
  L'année vient de `birth_max` ; si elle est inconnue, le libellé s'arrête au nom :
  `"P. Romarin"`. La forme est fixée ici au caractère près pour qu'aucune implémentation ne
  « complète utilement ».

#### Ce que la réponse ne contient jamais

Ni `personId`, ni `displayName` complet, ni prénoms entiers, ni `birthDate` exacte, ni
`birth`, ni lieu, ni sexe, ni génération, ni branche, ni parents, ni conjoint, ni portrait,
ni le nombre total de correspondances trouvées avant plafonnement. **Ni un décompte, ni un
« et 7 autres »** : ce serait un oracle de cardinalité, qui permet de tester une hypothèse
sans même lire un nom.

#### Le plafond ne se voit pas — et c'est une exception explicite au § 1.9

`ClaimResult.aucun_candidat` porte, dans le modèle, la mention « réponse indistinguable, côté
client, d'un plafond atteint » (§ 4.5). Une `429` rendrait les deux parfaitement
distinguables. Donc :

> **La 6ᵉ tentative dans l'heure sur un même jeton rend `200` avec `candidats: []`.**
> Pas de `429`, pas de `Retry-After`, pas d'en-tête de quota, et `tentativesRestantes` reste
> **`null`** pour tout le monde. Le serveur enregistre `result = limite_atteinte` dans
> `mbr_claim_attempt` ; le client, lui, voit exactement ce qu'il aurait vu en cherchant une
> personne inexistante.

`tentativesRestantes` existe dans le contrat **uniquement pour être toujours `null`** : sans
cette clé, un back l'ajouterait un jour « pour aider l'utilisateur ». Elle est là, à `null`,
avec ce commentaire.

Le limiteur **par IP** (30 / h), lui, rend bien `429` : il ne dit rien de la validité d'un
jeton ni de l'existence d'une personne, seulement qu'une adresse s'acharne. Les deux
limiteurs coexistent et ne se répondent pas de la même façon — c'est voulu.

Deux conséquences d'implémentation, à écrire dans le contrôleur et à tester :

1. **Le temps de réponse ne doit pas trahir.** Un jeton valide qui court-circuite au plafond
   répond plus vite qu'une vraie recherche. Le service applique donc la recherche **puis**
   jette le résultat au plafond, plutôt que de sortir avant. Sur 274 lignes, le surcoût est
   nul ; le canal auxiliaire, lui, serait permanent.
2. **Chaque appel écrit une ligne `mbr_claim_attempt`**, y compris au plafond, y compris en
   échec (`rattachement_tente` au journal d'audit, § 2.7). Cette table est le journal, pas le
   compteur : le comptage reste au `RateLimiter` (§ 4.3).

En mode confirmation, une date de naissance qui ne correspond pas à la personne pré-liée
rend `candidats: []` — et **surtout pas** un message du genre « la date attendue est en
1934 ». `result = echec_date` côté journal.

#### Les défunts ne sont pas proposés — depuis le 1er octobre 2026

Les personnes **non vivantes** (la propriété calculée du § 9.2 du modèle) sont exclues des
candidats, **dans les deux modes**. En confirmation, un pré-lien vers un défunt rend
`candidats: []` et consomme sa tentative, exactement comme une date fausse.

Elles étaient cherchables au nom d'un « oracle inverse » : les exclure faisait dire à une
réponse vide « cette personne est morte » plutôt que « elle n'existe pas ». L'argument est
tombé avec l'inscription sans fiche (ADR 0013) : tout porteur du lien peut désormais créer
un compte en attente, et un compte en attente **voit tous les défunts** (§ 12). Ce qui reste
vrai, et qui décide : un compte ancré sur un mort n'a pas de sens.

### 4.3 Étape 2 — créer le compte

```json
{
  "jeton": "kJ8s-2Qb1nR7xTmA0vL5wYcE9hP3dZfG6uIoN4jXqSs",
  "choix": "c1",
  "email": "cousin.saule@example.net",
  "password": "un-mot-de-passe-de-douze-signes-au-moins"
}
```

- `choix` est un `ref` rendu par l'étape 1b, encore valide et issu de **la même** tentative.
  Expiré, inconnu, ou appartenant à une autre session ⇒ `404` (pas `422` : on ne confirme rien
  sur le contenu de l'étape précédente).
- `password` : 12 caractères minimum, haché par le composant Security (`auto`).
- Courriel déjà pris ⇒ `409` `courriel_deja_pris`. C'est le seul endroit de l'API qui admet de
  révéler l'existence d'un compte, et c'est inévitable : `uniq_core_user_email` refusera de
  toute façon. Le risque est borné — il faut un jeton d'invitation valide pour poser la
  question.
- Personne déjà réclamée entre l'étape 1b et maintenant ⇒ `409` `personne_deja_rattachee`.
- Succès : le jeton est **consommé** (`used_at`, usage unique), `mbr_member` est créé, **et la
  session est ouverte** — le front n'a pas à enchaîner sur `POST /api/connexion`.

`201` :

```json
{
  "utilisateur": {
    "id": 9,
    "email": "cousin.saule@example.net",
    "displayName": "cousin.saule@example.net",
    "roles": ["ROLE_USER"],
    "status": "en_attente",
    "totpEnabledAt": null,
    "lastLoginAt": "2026-09-15T20:31:02+02:00"
  },
  "membre": {
    "id": 11,
    "status": "en_attente",
    "matchKind": "recherche_confirmee",
    "activatedAt": null
  },
  "cercle": { "regime": "aucun" },
  "voirComme": null,
  "incarnation": null,
  "demandesEnAttente": []
}
```

C'est **exactement** la charge de session du § 2.2 : une seule forme à implémenter côté
front, un seul magasin à alimenter.

L'activation est automatique dans **un seul cas** : `match_kind = pre_lie` avec date
confirmée (§ 4.5). La charge porte alors `status: "actif"`, `matchKind: "pre_lie"`, et un
`cercle` complet. Dans l'autre cas (`recherche_confirmee`), le compte reste `en_attente` et
une notification `compte_en_attente` part vers l'administrateur.

#### Seconde forme : « Je ne me trouve pas » (ADR 0013)

Quand l'étape 1b ne rend rien — ou rien qui lui ressemble —, le candidat décrit sa famille :

```json
{
  "jeton": "kJ8s-2Qb1nR7xTmA0vL5wYcE9hP3dZfG6uIoN4jXqSs",
  "declaration": {
    "surname": "Dupont",
    "givenNames": "Léa Marie",
    "birthDate": "1998-04-12",
    "description": "Mon père est Jean Dupont, né vers 1960 à Lille ; ma mère Marie Martin ; ma grand-mère paternelle Jeanne Dupont."
  },
  "email": "lea@example.net",
  "password": "douze-caracteres-min"
}
```

Les deux formes sont **exclusives**. Ordre des contrôles :

1. avec `declaration` : **limiteur par IP** `inscription_sans_fiche`, 5 / h — au-delà `429`
   avec `Retry-After`. Il passe **avant** le jeton : il ne dit rien du jeton ;
2. jeton ⇒ `404` uniforme ;
3. ni `choix` ni `declaration` ⇒ `404`, comme avant ;
4. les deux ⇒ `422` `{champs: {choix: "Choisissez une fiche ou décrivez votre famille, pas les deux."}}` ;
5. **un seul `422`** regroupe toutes les erreurs de champ : `email`, `password` (règles
   ci-dessus), `declaration.surname` (1 à 120 caractères après nettoyage des blancs),
   `declaration.givenNames` (1 à 160), `declaration.birthDate` (`YYYY-MM-DD` d'une date qui
   existe, de 1900 à aujourd'hui), `declaration.description` (60 à 4 000 caractères après
   nettoyage — **0 à 4 000 avec un message vocal valide**, voir ci-dessous),
   `declaration.messageVocal` ;
6. `409` `courriel_deja_pris`, inchangé.

##### Le message vocal (2 octobre 2026)

La déclaration peut porter un **message vocal**, à la place du texte ou en plus. Le corps
part alors en `multipart/form-data` : le JSON ci-dessus, **inchangé**, dans le champ
`donnees`, et l'enregistrement dans le champ `messageVocal`. La durée mesurée par le
navigateur voyage dans la déclaration, `declaration.dureeMessageVocal` (secondes, 1 à 310,
facultative) : un WebM de `MediaRecorder` n'annonce pas la sienne.

- **Texte OU vocal OU les deux** : un vocal valide rend `description` facultative (vide, elle
  est rangée à `null`) ; sans vocal, les 60 caractères tiennent. Un vocal **refusé** ne rend
  pas le texte facultatif : les deux refus viennent dans le même `422`.
- Contrôles, tous sous `declaration.messageVocal` dans le seul `422` : transfert réussi,
  **10 Mio** au plus, type **lu sur les octets** — WebM (`1A 45 DF A3`), Ogg (`OggS`), MP4
  (`ftyp`, sauf les marques d'images HEIF/AVIF) —, durée annoncée lisible et ≤ 310 s.
- Le jeton est contrôlé **avant** : sans jeton valide, `404`, et rien n'est écrit. Le
  limiteur `inscription_sans_fiche` (5 / h / IP) couvre le vocal comme le reste.
- Un `messageVocal` sans `declaration` ⇒ `422` `{champs: {messageVocal: …}}`.
- Stockage **hors racine web** (`<MEDIA_RACINE>/messages-vocaux/`), nom aléatoire ; colonnes
  `voice_message_*` de `mbr_member`. Purgé — ligne **et fichier** — au rattachement comme au
  refus.
- **Transcrit automatiquement** après coup (2 octobre 2026, soir) par Gemini, à la demande de
  l'écran « À rattacher » (§ 10.1, « La transcription du message vocal ») : rien n'est appelé
  à l'inscription, et un échec de transcription n'a aucun effet sur le compte. La mention
  sous le micro le dit : « Votre message est écouté par l'administrateur et transcrit
  automatiquement (Google Gemini) pour retrouver votre fiche. »

Succès : `core_user` `en_attente`, `mbr_member` **sans fiche** (`person_id` nul,
`match_kind = a_rattacher`), un usage d'invitation compté sous le même verrou que l'autre
forme. L'audit `invitation_utilisee` porte `matchKind: "a_rattacher"` et **aucun contenu de
la déclaration** ; la notification `compte_en_attente` des administrateurs nomme le courriel,
jamais la description, et mène à l'onglet « À rattacher » (`linkRoute:
"administration.rattachements"`).

`201` : la charge de session du § 2.2, avec
`membre: {id, status: "en_attente", matchKind: "a_rattacher", activatedAt: null}` et
`cercle: {regime: "aucun"}`. Le compte voit les défunts, comme tout compte en attente, et
n'écrit rien — sauf **sa propre photo** (§ 7.1, « le portrait du compte »).

La déclaration parle de personnes **vivantes** : elle n'est lisible que des administrateurs,
jamais journalisée, et **purgée** au rattachement comme au refus.

### 4.4 Étape 3 — l'état d'activation

`GET /api/inscription/etat`, accessible dès que la session existe.

```json
{
  "status": "en_attente",
  "depuis": "2026-09-15T20:31:02+02:00",
  "matchKind": "recherche_confirmee",
  "regime": "aucun"
}
```

- **Aucune clé `personId` ni `personLabel`** tant que `status` vaut `en_attente` (§ 4.5, et
  § 2.2 point 2). Le candidat a vu `"P. Romarin, 1980"` à l'étape 1b ; il ne reverra pas
  ce libellé ici, et surtout pas le `display_name` réel de la fiche, qui peut porter un nom
  d'usage ou un nom d'épouse qu'il ignore.
- **Aucun chiffre de l'arbre** (révision du 2026-10-01) : la clé `cequejevois` (nombre de
  défunts, nombre de fiches) est retirée — un compte en attente ne lit plus rien de l'arbre
  (ADR 0003).
- Une fois activé : `status: "actif"`, `regime: "cercle"`. Le
  front bascule alors sur `GET /api/session`, qui porte désormais `membre.personId`.
- `matchKind` peut valoir `"a_rattacher"` : le compte n'a pas encore de fiche (§ 4.3,
  seconde forme), et l'écran le dit.
- `portraitEnAttente` (booléen) : le compte a-t-il déposé sa photo (§ 7.1) ? Présente
  **seulement tant que `status` vaut `en_attente`** — le front propose « Ajouter votre
  photo » à qui a passé l'étape.

```json
{
  "status": "en_attente",
  "depuis": "2026-10-01T09:12:00+02:00",
  "matchKind": "a_rattacher",
  "regime": "aucun",
  "portraitEnAttente": true
}
```

---

## 5. Arbre

Module `Tree`.

| Méthode | Chemin | Accès | Corps | Succès | Erreurs | Débit |
|---|---|---|---|---|---|---|
| `GET` | `/api/arbre/graphe` | `MEMBRE` | — | `200` § 5.2 · **`304`** | `401` · `403` `regime_en_attente` | — |
| `GET` | `/api/arbre/personnes/{id}` | `MEMBRE` | — | `200` § 5.3 | `401` · `403` `regime_en_attente` · `404` `introuvable` | — |
| `GET` | `/api/arbre/familles/{id}` | `MEMBRE` | — | `200` § 5.4 | `401` · `403` `regime_en_attente` · `404` | — |
| `GET` | `/api/arbre/recherche` | `MEMBRE` | `?q=&limite=&vivants=` | `200` § 5.5 | `401` · `403` `regime_en_attente` · `422` `donnees_invalides` | — |

`{id}` porte `requirements: ['id' => '\d+']`. Conséquence utile et voulue : un identifiant
provisoire (`#12/p1`, § 5.2) **ne correspond tout simplement pas à la route** et produit une
`404` du routeur, sans qu'aucun code n'ait à s'en soucier.

### 5.1 Ce que « le cercle est appliqué ici » veut dire, route par route

Depuis le 2026-10-01, pour un membre validé, la quatrième colonne ne concerne plus que les
fiches **confidentielles** (`forcePrivacy`) hors de son cercle ; tout autre vivant suit la
troisième (§ 11, révision du 2026-10-01).

| Route | Personne décédée | Personne vivante **dans** le cercle | Personne vivante **hors** cercle |
|---|---|---|---|
| `/api/arbre/graphe` | nœud complet | nœud complet | **nœud présent, réduit** (§ 11.2) |
| `/api/arbre/personnes/{id}` | fiche complète | fiche complète | **`200`, fiche réduite** — pas `404` |
| `/api/arbre/recherche` | présente | présente | **absente** |
| `/api/chronologie` | barre présente | barre présente | **absente** (§ 9.1) |

La quatrième colonne est toute la règle de l'ADR 0003 : **le graphe montre, il n'énumère
pas.** Un nœud muet dans le graphe est acceptable — l'arbre amputé serait mensonger. Une
ligne de résultat de recherche ne l'est pas : elle *est* une énumération.

Et la fiche réduite rend `200`, pas `404`, parce que le nœud est visible dans le graphe :
cliquer dessus doit donner quelque chose de cohérent (un nom, et l'impossibilité d'aller plus
loin), pas une erreur qui ferait croire à une panne.

### 5.2 Le graphe

`GET /api/arbre/graphe`. C'est l'étage 2 de l'ADR 0005 : le placement partagé (étage 1, en
cache sous `placement.{empreinte}`) projeté pour ce lecteur. **L'objet de l'étage 1 ne sort
jamais tel quel.**

**Borné au périmètre du lecteur** (ADR 0003, révision du 2026-10-04) : l'arbre de sa fiche,
plus les arbres qu'aucun compte ne tient (« sans lien connu »). Les arbres des autres
comptes ne sortent pas du tout — ni nœuds, même masqués, ni familles, ni arêtes. Le front
reçoit les arbres sans lien comme des îlots détachés de l'ego, et les masque par défaut
(case « Personnes sans lien connu », `app/src/histoire/sansLien.ts`).

#### Identifiants de nœuds

Le graphe mélange 274 personnes et 103 familles dans une même liste. Les identifiants sont
donc des **chaînes préfixées**, jamais des entiers :

| Forme | Ce que c'est | Peut apparaître dans une URL ? |
|---|---|---|
| `"p17"` | `tree_person.id = 17` | via `personId`, pas via `id` |
| `"f28"` | `tree_family.id = 28` | via `familyId` |
| `"#12/p1"` | `prp_draft_person.local_ref = '#p1'` de la demande **12** | **non** — voir ci-dessous |
| `"#12/f1"` | famille provisoire déduite à la projection, demande 12 | **non** |

> **Un identifiant de nœud qui commence par `#` est provisoire.** Il n'existe dans aucune
> table, il ne survit pas à la validation, et il n'est accepté par aucune route.

Deux précisions qui ne sont ni dans le modèle ni dans les ADR, et sans lesquelles deux
agents divergent (reprises au § 18) :

1. **`local_ref` seul ne suffit pas comme identifiant de nœud.** Le § 9.5 écrit « le nœud
   provisoire porte son `local_ref` comme identifiant de nœud », mais `local_ref` n'est unique
   **que dans sa demande** — « `#p1` de la demande 12 et `#p1` de la demande 13 n'ont aucun
   rapport » (§ 9.5). Or un lecteur peut avoir plusieurs demandes en attente, et un
   administrateur les a **toutes**. Deux nœuds `#p1` dans la même liste se recouvriraient.
   D'où la forme **`#<demandeId>/<localRef sans dièse>`**.
2. **Le `#` ne passe pas dans un chemin d'URL** — il y est le séparateur de fragment. La route
   de détail d'un brouillon prend donc la référence **sans dièse** :
   `GET /api/demandes/12/brouillons/p1` (§ 6.6). Le front ne fabrique jamais cette URL en
   concaténant l'identifiant de nœud : il lit `demandeId` et `brouillonRef`, qui sont dans le
   nœud pour cette raison précise.

**Familles provisoires.** Il n'existe pas de `prp_draft_family` (§ 9.5 : « la demande décrit
l'intention, le service en déduit les familles à la validation »). Mais le rendu a besoin
d'un losange entre un couple proposé et ses enfants. La projection en alloue donc un **en
mémoire seulement**, numéroté dans l'ordre des liens de la demande. Rien n'est persisté, et
`#12/f1` de ce matin n'est pas garanti d'être `#12/f1` cet après-midi — le front ne le stocke
pas.

#### La charge utile

Ci-dessous, `noeuds` et `aretes` sont **des extraits** : la réponse réelle porte les 377 nœuds
et les 377 arêtes de l'arbre, plus les nœuds en attente du lecteur. Les sept nœuds retenus
couvrent les sept formes possibles — personne complète, personne restreinte, personne d'une
famille par alliance, famille, personne proposée, famille provisoire, et l'ego.

```json
{
  "empreinte": "9f2c4a1e7b0d5c83a6f1e2d9c4b7a0e5f3d8c1b6a9e4f7d2c5b8a1e6f3d0c9b2",
  "ego": "p1",
  "boite": { "x": -7770, "y": -1730, "w": 15824, "h": 1810 },
  "calque": {
    "actif": true,
    "demandes": [12],
    "proposes": 4,
    "places": 1,
    "nonPlaces": [
      { "noeudId": "#12/p2", "demandeId": 12, "brouillonRef": "p2", "displayName": "Morille Chanterelle", "raison": "enchainement" },
      { "noeudId": "#12/p3", "demandeId": 12, "brouillonRef": "p3", "displayName": "Cèpe Chanterelle", "raison": "enchainement" },
      { "noeudId": "#12/p4", "demandeId": 12, "brouillonRef": "p4", "displayName": "Russule Chanterelle", "raison": "enchainement" }
    ]
  },
  "noeuds": [
    {
      "id": "p1",
      "type": "personne",
      "personId": 1,
      "x": 0,
      "y": 0,
      "w": 120,
      "h": 40,
      "displayName": "Natron Romarin",
      "etiquetteNom": "Natron Romarin",
      "sex": "masculin",
      "generation": 0,
      "branch": "tr",
      "vivant": true,
      "parente": "famille",
      "visibilite": "complete",
      "bestQuay": 2,
      "citationCount": 3,
      "avecImage": true,
      "etiquetteDates": "vivant·e"
    },
    {
      "id": "p17",
      "type": "personne",
      "personId": 17,
      "x": -412.5,
      "y": -600,
      "w": 120,
      "h": 40,
      "displayName": "Marne Dolomite Sureau",
      "etiquetteNom": "Marne Sureau",
      "sex": "feminin",
      "generation": -4,
      "branch": "pm",
      "vivant": false,
      "parente": "famille",
      "visibilite": "complete",
      "bestQuay": 2,
      "citationCount": 6,
      "avecImage": true,
      "etiquetteDates": "1901-1909 – 1987"
    },
    {
      "id": "p202",
      "type": "personne",
      "personId": 202,
      "x": 1840,
      "y": -300,
      "w": 133,
      "h": 40,
      "displayName": "Personne vivante",
      "etiquetteNom": "Personne vivante",
      "sex": "masculin",
      "generation": -2,
      "branch": "mm",
      "vivant": true,
      "parente": "famille",
      "visibilite": "restreinte"
    },
    {
      "id": "p311",
      "type": "personne",
      "personId": 311,
      "x": 2410,
      "y": -450,
      "w": 107,
      "h": 40,
      "displayName": "Orpin Basalte Lichen",
      "etiquetteNom": "Orpin Lichen",
      "sex": "masculin",
      "generation": -3,
      "branch": null,
      "vivant": false,
      "parente": "alliance",
      "pont": "p64",
      "visibilite": "complete",
      "bestQuay": 1,
      "citationCount": 1,
      "avecImage": false,
      "etiquetteDates": "1868 – 1931"
    },
    {
      "id": "f28",
      "type": "famille",
      "familyId": 28,
      "x": -337.25,
      "y": -554,
      "w": 22,
      "h": 14,
      "divorced": false
    },
    {
      "id": "#12/p1",
      "type": "personne",
      "personId": null,
      "demandeId": 12,
      "brouillonRef": "p1",
      "enAttente": true,
      "x": 268,
      "y": -750,
      "w": 140,
      "h": 40,
      "displayName": "Tourmaline Muguet",
      "etiquetteNom": "Tourmaline Muguet",
      "sex": "feminin",
      "generation": -5,
      "branch": "pm",
      "vivant": false,
      "parente": "famille",
      "visibilite": "complete",
      "bestQuay": null,
      "citationCount": 0,
      "avecImage": true,
      "etiquetteDates": "~1889 – 1972"
    },
    {
      "id": "#12/f1",
      "type": "famille",
      "familyId": null,
      "demandeId": 12,
      "enAttente": true,
      "x": 216.5,
      "y": -704,
      "w": 22,
      "h": 14,
      "divorced": false
    }
  ],
  "aretes": [
    { "de": "p17", "vers": "f28", "genre": "union", "divorce": false },
    { "de": "f28", "vers": "p63", "genre": "filiation" },
    { "de": "#12/p1", "vers": "#12/f1", "genre": "union", "divorce": false, "enAttente": true },
    { "de": "#12/f1", "vers": "p61", "genre": "filiation", "enAttente": true }
  ]
}
```

Lecture, point par point :

- **`empreinte`** est celle du § 2.3, en 64 hexadécimaux. Elle est rendue dans le corps en
  plus de l'`ETag` parce qu'elle sert au diagnostic : elle se compare à
  `prp_revision.tree_fingerprint_after` pour situer un rendu dans l'historique (§ 5.5 du
  modèle). Elle n'est **pas** l'`ETag` — voir plus bas.
- **`boite`** alimente directement le `viewBox` initial. Elle n'est **jamais recalculée côté
  client** (ADR 0005, ADR 0007).
- **`ego`** est le nœud de `core_setting['tree.personne_racine_id']`, à `x = 0` par
  construction.
- **`calque.actif`** dit au front s'il doit afficher la légende « en attente ».
  `calque.demandes` liste les demandes incorporées : celles du lecteur, ou **toutes** pour un
  administrateur (§ 9.5).
- **`calque.proposes`, `calque.places` et `calque.nonPlaces`** disent ce que `actif` taisait.

  > **`actif` ne dit pas « complet ».** Il dit qu'il y a quelque chose en attente, et rien
  > de plus.

  Mesuré sur un cas réel : cinq demandes portant vingt-deux personnes ont rendu
  `actif: true`, `demandes: [1,2,3,4,5]` et **cinq nœuds sur vingt-deux**. Rien dans la
  charge ne signalait l'écart ; le lecteur avait sous les yeux un calque qui se déclarait
  actif sur cinq demandes en ayant écarté les trois quarts de ce qu'elles proposaient.

  **L'omission reste le bon comportement** et ne change pas : le placement partagé ne
  contient que l'arbre **validé**, une fiche rattachée à une autre fiche de la même demande
  n'a nulle part où s'accrocher, et la poser faute de mieux la mettrait à `x = 0`,
  c'est-à-dire sur l'ego. Dix-sept fiches empilées là seraient pires qu'absentes. Ce qui
  change, c'est que l'écart se lise.

  Les trois clés sont **toujours présentes**, à zéro quand il n'y a rien : un compteur
  absent se lit `undefined` côté client, et `undefined > 0` est faux — l'avertissement ne
  s'afficherait jamais, exactement comme avant.

  `proposes === places + nonPlaces.length`, **par construction et non par accord** : le
  serveur mesure les deux sur la même sortie, en confrontant ce que `Proposal` publie au
  placement final. Additionner les écarts que chaque étage déclare laisserait passer ceux
  qu'aucun ne déclare — et il y a deux endroits qui écartent, la traduction et la pose.

  `raison` prend l'une des valeurs suivantes. Elle est en français comme toute clé qui n'est
  pas une colonne (§ 1.2), et sa valeur l'est aussi :

  | `raison` | ce qu'elle dit |
  |---|---|
  | `enchainement` | l'ancre est une **autre fiche proposée**, qui n'est dans aucun placement partagé. C'est la forme normale d'une branche déposée en bloc, et le cas qui domine. |
  | `ancre_absente` | l'ancre désigne une personne ou une famille **validée** qui n'est pas dans le graphe — une cible disparue depuis le dépôt. |
  | `sans_couple` | « enfant de » avec un seul parent nommé, et ce parent n'a aucun couple dans l'arbre. La validation en créerait un ; le rendu ne devine pas. |
  | `sans_lien` | aucun lien ne porte cette fiche. Le § 6.4 du dossier l'interdit à la saisie. |
  | `generation_indeterminee` | l'ancre est là, mais sa rangée ne se lit pas. |
  | `placement_refuse` | traduite, puis refusée par le moteur de placement. |

  `demandeId` et `brouillonRef` sont servis pour que l'écran puisse **ouvrir la fiche** : elle
  reste consultable par `GET /api/demandes/{id}/brouillons/{ref}` (§ 6.6) même quand le
  canevas ne sait pas la dessiner. Un avertissement qui annonce un manque sans offrir le
  moindre geste transforme une information en frustration.

  Ce n'est pas une fuite : la projection est **déjà** bornée aux demandes que ce lecteur a le
  droit de voir (§ 13.3), et `displayName` serait sur le nœud si le nœud avait été placé.
- **`visibilite`** vaut `"complete"` ou `"restreinte"`. Sur un nœud `"restreinte"`,
  **quatre clés sont absentes** — pas à `null` (§ 1.3) : `etiquetteDates`, `bestQuay`,
  `citationCount`, `avecImage`. `displayName` et `etiquetteNom` restent, mais **valent
  toutes deux `"Personne vivante"`** : depuis la révision du 2026-10-01 de l'ADR 0003, le
  vrai nom d'un vivant hors cercle ne quitte pas le serveur (§ 11.2). Le canevas dessine
  `etiquetteNom` tel quel ; il ne cherche jamais le nom ailleurs.
- **`parente`** — *ajouté le 2026-10-01* — dit, **vu du lecteur**, à quelle famille la
  personne appartient. Présent sur **tout** nœud de personne, restreint compris :

  | `parente` | qui |
  |---|---|
  | `"famille"` | les ancêtres du lecteur, **les descendants de ses ancêtres** (lui-même, sa descendance, sa fratrie, ses cousins à tout degré), et **les conjoints de toutes ces personnes** — le conjoint lui-même, pas sa famille. Le mari d'une tante est `famille` : c'est le pont. |
  | `"conjoint"` | la famille du conjoint **du lecteur** : tout ce qu'on atteint depuis ses conjoints, par n'importe quel lien, sans repasser par la famille — ce que le filtre « Famille du conjoint » couvrait déjà. |
  | `"alliance"` | **tout le reste** : l'ascendance et la parenté d'un conjoint de collatéral, de proche en proche. L'îlot qu'aucun chemin ne relie au lecteur n'en est **pas** : il n'est relié à rien, et il est servi `"famille"` (sans branche, comme avant). |

  Un nœud proposé (`enAttente`) vaut toujours `"famille"`. Sans racine ni rattachement — aucun
  point de vue —, tous les nœuds valent `"famille"`. La clé se déduit des arêtes, que tout
  lecteur reçoit : elle n'apprend rien de plus que `branch` (ADR 0003, révision du
  2026-10-01). **Sur une fiche, `parente` désigne autre chose** — la phrase « arrière-grand-
  mère » (§ 5.3) ; sur un nœud, c'est l'une des trois valeurs ci-dessus, jamais une phrase.
- **`pont`** — *ajouté le 2026-10-01* — **présent seulement quand `parente` vaut
  `"alliance"`**, absent sinon (§ 1.3). C'est l'identifiant de nœud (`"p64"`) de la personne
  **non-alliance** la plus proche par les liens, celle par laquelle ce groupe se rattache au
  lecteur : pour toute l'ascendance du mari d'une tante, c'est le mari de la tante. Il sert de
  **clé de bloc** au canevas : une famille par alliance = l'ensemble des nœuds qui partagent
  un `pont`. Le pont lui-même est `famille` (ou `conjoint`) et reste dans les branches du
  lecteur. Plus proche au sens du nombre de liens (parent, enfant, conjoint) ; à égalité, le
  premier dans l'ordre des personnes de l'arbre — stable d'un appel à l'autre tant que l'arbre
  ne change pas. Jamais `null` : un îlot détaché n'est pas `"alliance"`.
- **`branch` d'un nœud `alliance` vaut `null`.** Les branches sont recalculées pour le lecteur,
  et une famille par alliance n'appartient à aucune d'elles : l'ascendance du mari d'une tante
  ne prend plus la branche de la tante. Le mari, lui, garde la branche de sa femme. Quand un
  point de vue est calculé, `branch` ne retombe **jamais** sur la colonne `tree_person.branch`
  (la vue de la racine) : absente de la vue, elle vaut `null`. La colonne persistée garde
  l'ancienne règle (la chronologie en fait ses colonnes).
- **`bestQuay`, `citationCount` et `avecImage`** servent les filtres du visualiseur —
  « Non vérifiés » (`bestQuay` à 0 ou `null`), « Sans source » (`citationCount` à 0),
  « Avec portrait ». Ils sont sur le **nœud** et non chargés fiche par fiche : un filtre
  qui demanderait 377 fiches pour griser des nœuds annulerait le bénéfice du canevas.
  `bestQuay` vaut `null` quand la personne n'a aucune citation — ce qui n'est **pas** la
  même chose que `0`, « recopie non vérifiée » (§ 3.7).
- **`w`** et **`h`** viennent du moteur et sont **identiques pour tous les lecteurs** :
  `max(104, max(len(nom) × 6.6, len(ligneDates) × 5.8) + 2 × 14)`, **tronqué** à l'entier —
  `int()` en Python, `(int)` en PHP, troncature vers zéro, **jamais `round()`** — avec
  `H_FICHE = 40` et un losange 22 × 14 pour une famille. Deux précisions qui coûtent cher si
  on les devine : `nom` est **le premier prénom suivi du nom**, pas `displayName` entier
  (« Marne Sureau », non « Marne Dolomite Sureau ») ; et pour une personne **vivante**,
  `ligne_dates()` rend la **constante `"vivant·e"`**, de sorte que la mesure ne lit jamais une
  date masquée. Le client ne recalcule ni l'une ni l'autre : le test de non-régression porte
  sur ces valeurs au centième (ADR 0005). Voir § 11.2.

  **Ce que le graphe sert réellement** : la fiche **affichée**, remesurée par l'affinage —
  la formule ci-dessus sur `etiquetteNom` et `etiquetteDates`, **plus 48** (photo 40 et son
  espace 8), `h = 56`. Et depuis le 2026-10-01, **pour toute personne vivante, vue ou non,
  `w = 215`**, constante : ni son nom ni sa date n'entrent dans sa largeur, ni dans les `x` de
  personne (le moteur reçoit un jeu où les vivants n'ont ni nom ni date). C'est ce qui empêche
  `w` et l'écart entre deux fiches de redonner la longueur d'un nom qu'on ne sert plus. Un nom
  de vivant de plus de 21 signes est tronqué par le canevas.
#### Les deux chaînes mesurées

> **La largeur d'un nœud dépend de deux chaînes, et de deux seulement : `etiquetteNom` et
> `etiquetteDates`. Le canevas dessine celles-là, jamais `displayName`.**

La formule mesure **deux** textes — `max(104, max(len(nom) × 6.6, len(dates) × 5.8) + 2 × 14)`
— et chacun a désormais sa clé dans la charge utile, parce qu'aucun des deux n'est
reconstructible côté client :

| Clé du nœud | Ce que le moteur a mesuré | Producteur |
|---|---|---|
| **`etiquetteNom`** | `(nickname ?? givenNames)` réduit à son **premier mot**, espace, `surname` | `MesureDeFiche::etiquetteNom()` |
| **`etiquetteDates`** | la forme compacte, `"vivant·e"` si la personne est vivante (§ 14.4) | `MesureDeFiche::ligneDates()` |

**`displayName` n'est ni l'une ni l'autre**, et c'est ce qui rend le piège coûteux :
`displayName` est composé de **tous** les prénoms (§ 3.1) — « Ambre Célestin Jade Mica
Romarin », 32 signes, quand le moteur a mesuré « Ambre Romarin », 13. Mesuré sur les
274 fiches réelles : **220 débordent de leur boîte** si le canevas dessine `displayName`,
jusqu'à **169 unités de trop sur une boîte de 149**. Et le client ne peut pas la dériver :
`displayName` est `nickname ?? givenNames` **puis** `surname`, et un nom de famille peut
contenir des espaces — « de la Tour » n'est pas découpable par une règle.

`displayName` reste néanmoins dans le nœud : c'est le nom complet, celui qu'on affiche au
survol, dans le panneau latéral et dans la recherche. **Trois clés de nom, trois usages** —
`displayName` pour lire, `etiquetteNom` pour dessiner, `surname`/`givenNames` pour éditer
(§ 5.3).

**Les deux `MesureDeFiche::*` sont publiques** : le sérialiseur émet ces clés **en les
appelant**, jamais en les reconstruisant. C'est ce qui garantit que la largeur envoyée et les
textes envoyés ne peuvent pas diverger — et c'est la seule protection réelle, puisqu'un écart
ne se voit qu'en superposant deux rendus.

Sur un nœud **restreint**, `etiquetteDates` est **absente** (§ 11.2) : le canevas ne dessine
pas de ligne sous le nom et ne la **reconstitue jamais**, la boîte restant dimensionnée par le
moteur. `etiquetteNom` reste — c'est une troncature de `displayName`, déjà dans la liste
blanche, donc strictement moins d'information.
- **Aucune arête ne porte de marqueur « longue ».** `.ar.long` est supprimée (§ 7.3 du
  dossier, ADR 0007) : l'API n'émet ni `long`, ni `distance`, ni `portee`. Un back qui
  calculerait cette information donnerait au front de quoi réintroduire les pointillés.
- `genre` vaut `"union"` ou `"filiation"`, comme dans `graphe.json`. `divorce` n'existe que
  sur les arêtes d'union et porte `tree_family.divorced` : c'est ce qui allume `.ar.divorce`,
  conservée (ADR 0007).

#### L'`ETag` — comment le client s'en sert

```
ETag = "sha256(
      empreinte_de_l_arbre                          -- § 2.3, identique pour tout le monde
  || ':' || empreinte_du_cercle_effectif_du_lecteur -- ci-dessous
  || ':' || empreinte_des_demandes_du_lecteur       -- '' s'il n'en a aucune
  || ':' || point_de_vue_du_lecteur                 -- 'p<id>', '' s'il n'est rattaché à personne
  || ':' || empreinte_de_la_mise_en_page            -- l'image elle-même, ci-dessous
)"

empreinte_du_cercle_effectif =
    sha256( regime || ':' || implode(',', identifiants_du_cercle_triés) )

empreinte_de_la_mise_en_page =
    sha256( ego || les nœuds placés, dans l'ordre servi || les arêtes )
```

**Cinq composantes, et la règle qui les explique tient en une ligne :**

> **L'`ETag` doit distinguer tout ce qui distingue la représentation. Le cercle en fait
> partie.**

Et l'arbitrage qui la fonde, parce qu'il se reposera à chaque composante qu'on hésitera à
ajouter : **deux `ETag` différents pour deux charges identiques coûtent une revalidation —
quelques kilo-octets, une fois. Un `ETag` identique pour deux charges différentes est une
FUITE. Quand on hésite, on distingue.**

`empreinte_de_l_arbre` ne dépend pas du lecteur — c'est son intérêt, elle rend le placement
partageable (ADR 0005). `empreinte_des_demandes` porte le calque. **Aucune des deux ne porte
le cercle**, et le cercle est pourtant exactement ce qui fait différer les charges utiles.
Mesuré sur trois lecteurs réels du même arbre, sans aucune demande en cours :

| Lecteur | Charge | Régime |
|---|---|---|
| administrateur | 274 fiches complètes | `cercle`, 274 personnes |
| membre actif | 256 complètes + 18 restreintes | `cercle` |
| compte en attente | 256 complètes + 18 restreintes | **`aucun`** |

Trois régimes, des charges qui diffèrent, et — avec une formule à deux composantes — **un seul
`ETag`**. Un `304` servi au mauvais lecteur lui rend alors le graphe d'un autre.

##### Les deux composantes qui ne viennent pas de la base

`point_de_vue_du_lecteur` : depuis que la mise en page est calculée **par lecteur** (ADR
0005, § 9.9 du modèle), deux comptes de même cercle et sans demande reçoivent des charges
dont toutes les coordonnées diffèrent. Elles ne différaient par aucune des trois autres
composantes.

`empreinte_de_la_mise_en_page` : les quatre premières décrivent **l'état de la base et le
lecteur**, et aucune ne bouge quand c'est le *code* de placement qui change. Or la route
répond `Cache-Control: private, no-cache` : le navigateur garde la charge et revalide. Un
lecteur revenu après un déploiement qui change les règles de placement présentait donc son
ancien `ETag`, recevait `304`, et **redessinait l'ancienne image**, pendant que le serveur
en calculait une nouvelle que personne ne recevait. C'est le défaut de `cercle.generations`
décrit plus bas, sur une cinquième dimension.

Elle est **dérivée du placement** et non posée comme un numéro de version à bousculer à la
main : un compteur qu'on oublie d'incrémenter donne ici une page vieille mais plausible,
avec une suite de tests verte. C'est le même arbitrage qu'au § 2.3, qui a déjà écarté un
compteur d'invalidation au profit d'une empreinte.

**Conséquence à connaître** : le chemin du `304` lit désormais le placement en cache (§ 9.9)
en plus de résoudre le cercle. Une revalidation n'est pas gratuite ; c'est le prix des deux
corrections, et il est assumé ici plutôt que découvert en service.

**Le cercle effectif, c'est celui qui sert à la sérialisation**, pas celui du compte
authentifié : sous la bascule « voir comme un membre », c'est le cercle **emprunté** qui entre
dans l'empreinte (§ 10.4). L'exigence qui figurait jusqu'ici comme un cas particulier à
retenir devient ainsi **une conséquence de la règle générale** — ce qui est toujours préférable
à une exception, parce qu'une exception s'oublie au premier remaniement.

**Pourquoi hacher la liste des identifiants et non la taille.** Deux cercles de 35 personnes
ne sont pas le même cercle ; une empreinte fondée sur la taille les confondrait, et c'est
précisément le défaut qu'on corrige. La liste est triée, elle fait au plus 60 entrées
(§ 9.3), le cercle est déjà calculé et en cache : le surcoût est une concaténation.

**Le risque est asymétrique, et c'est ce qui tranche.** Deux `ETag` différents pour deux
représentations identiques coûtent une revalidation inutile — quelques kilo-octets, une fois.
Un `ETag` identique pour deux représentations différentes est une **fuite**. Quand on hésite,
on distingue.

> **Ce défaut n'était pas exploitable au moment où il a été trouvé**, et il ne faut pas le
> raconter autrement : `Cache-Control: private` et `Vary: Cookie` protègent les caches
> partagés, et le socle front oublie son `ETag` à tout changement de session — connexion,
> déconnexion, entrée et sortie de bascule. Le client était couvert deux fois. Mais **un
> `ETag` qui ne distingue pas deux représentations n'est pas une clé de cache correcte** : il
> était désamorcé par deux garde-fous qu'on peut retirer sans s'en apercevoir, l'un d'eux
> vivant dans du code front qu'aucun test d'API ne surveille.

Échange complet, côté client :

1. Premier appel : `GET /api/arbre/graphe`. Réponse `200`, en-têtes
   `ETag: "3f0a…"`, `Cache-Control: private, no-cache`, `Vary: Cookie`.
2. Le client **conserve la valeur de l'`ETag` telle quelle**, guillemets compris, et
   reconstruit le canevas une fois au montage (ADR 0007, règle 3).
3. Appels suivants : `If-None-Match: "3f0a…"`.
   - `304 Not Modified`, sans corps → le client **garde son SVG en place**. Il ne le
     reconstruit pas, il ne le vide pas.
   - `200` avec un nouvel `ETag` → **reconstruction complète du canevas**, pas de
     réconciliation maison (ADR 0007, règle 3).
4. Le client revalide : au montage de la vue, au retour d'onglet, et après **toute** action
   qui écrit (soumettre une demande, valider, retirer). Il ne sonde pas en boucle : à cette
   échelle, le graphe change quelques fois par semaine.

Quatre pièges, tous déjà payés ailleurs :

- **L'empreinte seule ne peut pas servir d'`ETag`.** Déposer un brouillon ne touche aucune
  table `tree_*`, donc ne change pas l'empreinte — l'auteur ne verrait jamais son propre nœud
  en attente, « c'est-à-dire précisément la seule chose que ce calque existe pour produire »
  (ADR 0005). C'est la seconde composante qui règle cela.
- **`Cache-Control: private` n'est pas décoratif.** La charge utile dépend du lecteur ; sans
  `private` + `Vary: Cookie`, un cache partagé peut servir le graphe d'un membre du cercle à
  un membre hors cercle.
- **La bascule « voir comme un membre » n'est plus un cas particulier** : le cercle effectif
  étant celui de la sérialisation, l'`ETag` incorpore le membre emprunté par construction
  (§ 10.4). Sans cela, l'administrateur revalidait et recevait `304`, c'est-à-dire son propre
  graphe non filtré, sous l'identité qu'il croyait tester.
- **Le test qui garde cette formule n'est pas dans le document** : deux lecteurs de cercles
  différents, même empreinte d'arbre, aucune demande en cours, **doivent recevoir deux `ETag`
  différents**. C'est trois lignes de test fonctionnel et c'est la seule preuve qui vaille —
  la formule écrite ici ne se vérifie pas toute seule (§ 17.5).
- **`ETag` fort, jamais faible.** Un `W/` autorise un intermédiaire à considérer deux
  représentations « assez équivalentes » ; ici deux représentations qui diffèrent d'un champ
  diffèrent d'une donnée personnelle.

### 5.3 La fiche d'une personne

`GET /api/arbre/personnes/17` — charge complète, lecteur dans le cercle (ou personne
décédée). Une personne de l'arbre d'un **autre compte** rend `404`, défunte comprise, comme
un identifiant inconnu (ADR 0003, révision du 2026-10-04). Les deux sérialisations comparées d'une **même** fiche sont au § 11 ; celle-ci est
la référence de forme.

```json
{
  "id": 17,
  "gedcomXref": "@I500017@",
  "displayName": "Marne Dolomite Sureau",
  "givenNames": "Marne Dolomite",
  "surname": "Sureau",
  "marriedName": "Sarriette",
  "nickname": null,
  "sex": "feminin",
  "deceased": true,
  "vivant": false,
  "birth": {
    "raw": "BET 1901 AND 1909",
    "qualifier": "entre",
    "min": "1901-01-01",
    "max": "1909-12-31",
    "place": "Saule, Ombrage, Sylvanie",
    "texte": "entre 1901 et 1909"
  },
  "death": {
    "raw": "1987",
    "qualifier": "annee",
    "min": "1987-01-01",
    "max": "1987-12-31",
    "place": "Charmille, Ombrage, Sylvanie",
    "texte": "1987"
  },
  "ligneDates": "1901-1909 – 1987",
  "branch": "pm",
  "generation": -4,
  "bestQuay": 2,
  "citationCount": 6,
  "forcePrivacy": false,
  "privacyNote": null,
  "version": 8,
  "visibilite": "complete",
  "parente": "arrière-arrière-grand-mère",
  "portrait": {
    "mediaId": 88,
    "url": "/api/medias/88/fichier",
    "vignetteUrl": "/api/medias/88/vignette",
    "caption": "Portrait, vers 1931"
  },
  "parents": [
    { "personId": 42, "displayName": "Jade Bérylite Sureau", "relation": "naissance", "familyId": 19 }
  ],
  "fratrie": [
    { "personId": 43, "displayName": "Lave Sureau", "siblingOrder": 1 }
  ],
  "unions": [
    {
      "familyId": 28,
      "conjoint": { "personId": 56, "displayName": "Émeri Sarriette" },
      "marriage": {
        "raw": "23 SEP 1927", "qualifier": "exacte",
        "min": "1927-09-23", "max": "1927-09-23",
        "place": "Saule, Ombrage, Sylvanie",
        "texte": "23 septembre 1927"
      },
      "divorced": false,
      "divorce": null,
      "enfants": [
        { "personId": 63, "displayName": "Sodalite Sarriette" },
        { "personId": 64, "displayName": "Rubis Sarriette" }
      ]
    }
  ],
  "faits": [
    {
      "id": 310,
      "type": "residence",
      "label": "Rue du Houx, maison 12",
      "happened": {
        "raw": "1957", "qualifier": "annee",
        "min": "1957-01-01", "max": "1957-12-31",
        "place": "Saint-Ronce-de-Verçay, Fougerie, Florie",
        "texte": "1957"
      },
      "address": null,
      "age": null,
      "cause": null
    }
  ],
  "citations": [
    {
      "id": 901,
      "scope": "naissance",
      "quay": 2,
      "quayLabel": "acte ou registre",
      "appui": "index",
      "appuiLabel": "tenu par un index ou un relevé",
      "page": "Saule, registre des naissances 1901-1909, cote 6 M 12, vue 41",
      "evenContext": "record",
      "url": "https://archives.example/…",
      "dataText": "Transcription partielle…",
      "source": {
        "id": 4,
        "title": "Dénombrements de population de la Fougerie",
        "author": null,
        "sourceKind": "recensement",
        "sourceKindLabel": "Recensement"
      },
      "medias": [
        { "mediaId": 120, "kind": "preuve", "vignetteUrl": "/api/medias/120/vignette" }
      ],
      "createdAt": "2026-09-12T18:04:00+02:00"
    }
  ],
  "notes": [
    {
      "id": 77,
      "scope": "naissance",
      "body": "MyHeritage avait recopié l'année de décès dans le champ naissance…",
      "authorLabel": null,
      "createdAt": "2026-09-13T21:13:00+02:00"
    }
  ],
  "medias": [
    {
      "mediaId": 120,
      "kind": "preuve",
      "caption": "Acte n° 3, Épinaille 1910",
      "url": "/api/medias/120/fichier",
      "vignetteUrl": "/api/medias/120/vignette",
      "createdAt": "2026-09-14T10:22:00+02:00"
    }
  ],
  "enAttente": {},
  "demandesEnAttente": []
}
```

Notes de forme :

- **`vivant` est calculé, `deceased` est stocké.** Les deux sont présents et ne disent pas la
  même chose : `deceased` est le `DEAT` du GEDCOM, `vivant` est le résultat de la règle du
  § 9.2 (seuil, repli par génération, `force_privacy`). C'est `vivant` qui allume
  `.fiche.prive` (ADR 0007), jamais `deceased` et jamais l'absence de dates.
- **`texte` est la sixième clé d'un bloc date, et elle n'existe que dans une fiche** — jamais
  dans une valeur d'op (§ 14).
- `parente` est calculée à la volée, relativement au lecteur (§ 3.1). Elle n'est pas stockée.
- `portrait` est `null` s'il n'y en a pas ; les URL sont **relatives**, et pointent vers les
  routes authentifiées du § 7. Aucun chemin de disque, jamais (§ 16).
- `quayLabel` reprend les libellés déjà retenus (§ 3.9) pour que l'interface ne change pas de
  vocabulaire : `recopie non vérifiée`, `index ou déduction`, `acte ou registre`,
  `preuve directe`.
- **`createdAt` (ATOM) date l'entrée de la ligne dans l'arbre** — citation, note et média
  de la fiche (révision du 2026-10-01). C'est la date de la ligne, pas celle de l'acte : une
  citation importée du GEDCOM porte la date de l'import. L'écran la montre sur chaque
  élément, et l'onglet Historique de la fiche s'en sert pour ranger ce qui a été ajouté.
- **`appui` dit sur quoi la citation repose réellement** — voir ci-dessous. C'est **cette**
  clé que l'écran affiche, et non `quay`.

#### `appui` — trois niveaux, composés de deux colonnes

Le commanditaire voulait voir d'un coup d'œil, sur ses 483 citations, lesquelles sont tenues
par un acte. Environ **deux sur cinq** ne le sont pas.

| `appui` | `appuiLabel` | ce qui le tient |
|---|---|---|
| `acte` | tenu par un acte | acte d'état civil, acte notarié, registre |
| `index` | tenu par un index ou un relevé | table décennale, relevé d'association, recensement |
| `arbre` | tenu par un arbre en ligne | Geneanet, Filae, MyHeritage, site familial |

**Trois niveaux et non deux**, et la nuance est celle du commanditaire : un index se trompe,
mais il a été dressé par quelqu'un qui tenait le registre. Les fondre ferait disparaître
exactement l'information qui dit s'il faut aller vérifier.

Le niveau est **le plus bas** de ce que disent les deux colonnes qui le portent :

- `tree_source.source_kind` qualifie le **fonds** — ce document est-il un acte, un
  recensement, l'arbre d'un autre utilisateur ?
- `tree_citation.quay` qualifie **cet usage-ci** — ai-je vérifié, sur ce fait précis ?

Elles peuvent diverger, et dans les deux sens. Citer un acte sans l'avoir vérifié se dit
`quay = 0` sur une source `acte_etat_civil`, et la fiche repose alors sur une recopie quoi
qu'en dise le fonds. À l'inverse, un arbre en ligne « tenu par un acte » est une
contradiction que rien n'empêchait d'écrire.

*État mesuré au 17/09/2026 : les **194** citations d'arbres en ligne de l'arbre sont
**toutes à `QUAY 0`**. L'import les avait bien cotées ; ce qui manquait était l'affichage.
La composition n'est donc pas une réparation mais un garde-fou — d'autant que `quay`
redevient modifiable par le même lot, et qu'une correction maladroite suffirait.*

Retenir le plus bas est la seule règle sûre : **une fiche ne doit jamais paraître mieux tenue
qu'elle ne l'est.** C'est le même raisonnement que le défaut fermé du cercle de visibilité.
Un `quay` absent vaut `arbre` pour la même raison : la citation n'a jamais été évaluée.

`quay` et `quayLabel` restent rendus tels quels — on ne réécrit pas ce que le contributeur a
déclaré —, mais ils décrivent une déclaration, là où `appui` décrit ce qu'elle vaut.

#### Les adresses d'arbres en ligne ne sortent pas

> « MyHeritage, retire tous les liens vers ces URL, ça sert à rien — ou du moins ne les
> affiche pas aux utilisateurs, **garde-les en DB uniquement pour l'IA**. »

Les deux moitiés de la phrase comptent. `page`, `dataText` et `url` sont rendus **débarrassés
des adresses d'arbres en ligne** ; `tree_citation` et `tree_source` ne sont pas modifiées. Le
masquage vit à la sérialisation, et une relecture de la base rend tout.

**Le retrait vise une liste fermée de domaines d'arbres, jamais toute adresse.** Les 109 URL
du fonds se répartissent en 103 d'arbres en ligne — `myheritage.fr`, `sites-cf.mhcache.com`
qui héberge les « sites web de famille », `filae.com` — et 6 de **visionneuses d'archives**,
`archives.dordogne.fr` et `recherche.archives.finistere.fr`. Ces six-là sont exactement ce
qu'on veut montrer : le point d'entrée du registre numérisé où le chercheur suivant ira lire
l'acte. Masquer « tout `http` » les emporterait.

`page` porte aussi les **cotes d'archives** — « 5 E 341/7, vue 1/6 » — dans le même champ que
l'adresse, sur 39 lignes. Le retrait découpe l'adresse et recoud le texte ; il ne vide jamais
le champ. Un champ qui ne portait **que** l'adresse rend `null`, et non `""` : c'est la
distinction absent/vide du § 1.3, et elle décide de ce que l'écran dessine.

FamilySearch n'est **pas** dans la liste, et c'est une décision : le site héberge à la fois un
arbre collaboratif et des millions de registres numérisés, les distinguer demanderait de lire
le chemin de l'URL plutôt que son domaine, et l'arbre n'en compte qu'une citation.

**`source.note` et `source.text` ne sont pas rendus par la fiche ; les adresses qu'ils portent
ne sont donc pas masquées.** `citations()` ne rend de la source que `id`, `title`, `author` et
`sourceKind`. Les **7 adresses** de ces deux colonnes — 6 dans `note`, 1 dans `text` —
n'atteignent aucun écran aujourd'hui, et c'est **la seule raison** pour laquelle elles échappent
au retrait : ce n'est pas une exemption, c'est une absence de route. **Toute route qui les
exposerait devra appliquer le même nettoyage**, et rien ne l'en avertira — le champ « marche »
déjà partout ailleurs.

#### `heritage:reclasser-les-arbres-tiers`

Une commande d'administration, **en simulation par défaut**, qui classe en `arbre_tiers` les
sources dont une adresse prouve qu'elles en sont. Elle existe parce que 41 citations de ce
fonds reposent sur des sources classées `autre` qui sont des sites familiaux hébergés par
MyHeritage — reconnaissables à leur hébergeur, pas à leur titre. Elle ne touche **ni les
`QUAY`, ni les adresses** : une seule colonne, et la sérialisation fait le reste.
- `enAttente` est **toujours présent**, à `{}` quand il n'y a rien — voir § 13.
- `version` est là pour que le formulaire de proposition puisse le renvoyer en
  `baseVersion` (§ 6.1). Sans lui, le verrou optimiste inter-requêtes du § 9.6 n'a pas de
  valeur d'entrée.

**Les références d'une fiche suivent le cercle** (révision du 2026-10-01 de l'ADR 0003) :
dans `parents`, `fratrie`, `unions[].conjoint` et `unions[].enfants`, une personne vivante
hors du cercle du lecteur **reste listée** — une fratrie amputée serait fausse —, avec son
`personId`, mais son `displayName` vaut `"Personne vivante"`. Le front n'affiche pas d'autre
nom et ne propose pas de lien qui laisserait croire le contraire : la fiche ouverte sera la
forme réduite du § 11.1.

### 5.4 La fiche d'une famille

`GET /api/arbre/familles/28` — nécessaire parce que `modification_famille` est une nature de
demande (§ 5.7). Une famille de l'arbre d'un autre compte rend `404` (ADR 0003, révision du
2026-10-04).

```json
{
  "id": 28,
  "gedcomXref": "@F500028@",
  "husband": { "personId": 56, "displayName": "Émeri Sarriette", "visibilite": "complete" },
  "wife": { "personId": 17, "displayName": "Marne Dolomite Sureau", "visibilite": "complete" },
  "marriage": {
    "raw": "23 SEP 1927", "qualifier": "exacte",
    "min": "1927-09-23", "max": "1927-09-23",
    "place": "Saule, Ombrage, Sylvanie",
    "texte": "23 septembre 1927"
  },
  "divorced": false,
  "divorce": null,
  "generation": -4,
  "rank": -7,
  "version": 3,
  "enfants": [
    { "personId": 63, "displayName": "Sodalite Sarriette", "siblingOrder": 0, "relation": "naissance" },
    { "personId": 64, "displayName": "Rubis Sarriette", "siblingOrder": 1, "relation": "naissance" }
  ],
  "citations": [],
  "notes": [],
  "enAttente": {}
}
```

Si l'un des époux est vivant hors cercle, son objet est réduit à
`{ "personId": …, "displayName": "Personne vivante", "visibilite": "restreinte" }` ; un
**enfant** vivant hors cercle reste dans `enfants`, avec `"displayName": "Personne vivante"`
(révision du 2026-10-01 de l'ADR 0003 — les enfants sortaient jusque-là sous leur vrai nom
sans contrôle de cercle). **Les dates d'union
restent visibles** : elles appartiennent à la famille, pas à la personne — et une union dont
un membre est masqué reste une union, sans quoi la fratrie devient fausse (ADR 0003).

### 5.5 La recherche

`GET /api/arbre/recherche?q=bourrache&limite=20`

- `q` : 2 caractères minimum, sinon `422`. Normalisé comme `search_key` (sans accents,
  majuscules, ponctuation retirée), **découpé en mots**, et **chaque mot** est exigé en
  tête de clé ou en début d'un autre mot — `LIKE 'MOT%' OR LIKE '% MOT%'`, le tout en
  `AND` — puis affiné en PHP (§ 0.6 — aucune extension PostgreSQL). Au plus 8 mots ;
  au-delà, les suivants sont ignorés plutôt que refusés (§ 1.5).

  > **Pourquoi par mot, et non sur le terme entier.** `search_key` s'écrit « patronyme,
  > prénoms » ; un membre tape « Prénom Nom ». Cherché comme un motif unique, « Marne
  > Sureau » n'est ni un préfixe ni un infixe de « SUREAU MARNE DOLOMITE » : la requête
  > rendait **zéro ligne**. Mesuré sur la base de travail avant correction — sur les 272
  > fiches portant un prénom et un patronyme, interrogées chacune avec son propre
  > « Prénom Nom », l'ancienne forme en retrouvait **0**.
  >
  > **L'ordre des mots devient indifférent, et c'est le seul élargissement.** La
  > conjonction ne peut pas rendre plus de lignes que l'un de ses termes pris seul : sur
  > un terme d'un seul mot, les deux formes sont rigoureusement identiques (mesuré : 749
  > termes × 279 clés, **0 divergence**). Le filtrage de confidentialité, lui, est
  > inchangé — il n'a jamais été dans le `WHERE` : une personne vivante hors cercle est
  > **absente** du résultat, qu'elle ait été candidate ou non (ADR 0003).
  >
  > **`idx_tree_person_search` ne sert pas cette requête**, et ne la servait déjà plus :
  > c'est un index de préfixe (`varchar_pattern_ops`), inutilisable pour la branche
  > infixe `'% X%'` — `EXPLAIN` rend un `Seq Scan` même avec `enable_seqscan = off`, avant
  > comme après. Il sert toujours le `ORDER BY search_key`.
- `limite` : défaut 20, maximum 50. Liste **non paginée** : une recherche qui se pagine est
  une énumération déguisée.

```json
{
  "elements": [
    {
      "personId": 44,
      "displayName": "Ambre Bourrache",
      "sex": "masculin",
      "generation": -4,
      "branch": "pp",
      "vivant": false,
      "ligneDates": "1912 – 1989",
      "visibilite": "complete"
    }
  ],
  "tronque": false
}
```

> **Aucune personne vivante hors cercle n'apparaît ici.** Ni en résultat, ni en suggestion,
> ni en libellé de rapprochement de doublon (ADR 0003). Elle n'est pas rendue en version
> réduite : elle est **absente**.

`tronque` dit que le plafond `limite` a été atteint — sur le jeu **déjà filtré**, jamais
avant. Un `total` avant filtrage indiquerait combien de personnes on cache.

**Un compte en attente a accès à cette route**, et n'y voit que des personnes **décédées** —
c'est-à-dire le même filtre, appliqué à un lecteur sans cercle : aucune personne vivante ne
franchit jamais le test, puisqu'il n'a pas de cercle où en trouver (§ 12). Lui fermer la
recherche serait un troisième régime qu'aucun document ne demande, et priverait de son
intérêt l'arbre ancien qu'on lui ouvre justement pour que l'attente ait du sens (§ 4.5).

---

### 5.6 La réciprocité des vivants — naissance et portrait (ADR 0016)

Un second filtre, **après** le cercle, sur la naissance et le portrait des vivants. Toutes
les routes qui servent l'une ou l'autre l'appliquent au serveur ; rien de retenu ne sort.

**Le bloc `birth` d'une fiche** — quand le lecteur ne voit pas toutes les composantes :

```json
{ "raw": null, "qualifier": "masquee", "min": null, "max": null,
  "place": "Saule", "texte": "12 mars", "composantes": { "mois": 3, "jour": 12 } }
```

`masquee` n'existe que dans l'API (jamais en entrée). Le bloc est émis même pour une date
inconnue. La fiche porte `naissanceModifiable` (`false` dès qu'une composante manque) ; une
demande qui touche `birth` sur un tel vivant rend `422 naissance_masquee`. `ligneDates`, la
`naissance` des proches, l'`etiquetteDates` du graphe (« n. 3/1985 · vivant·e »), la
recherche, la chronologie, la carte et les statistiques ne portent que la date réduite.

**`GET /api/anniversaires`** — la naissance d'un vivant n'y est que si le jour et le mois
sont partagés ; `annee` est alors **absente** quand l'année ne l'est pas (`texte` :
« 12 mars »). Les autres ne sont pas comptés dans `resume.sansJour`.

**La charge de session** (§ 2.2) gagne :

```json
"partageNaissance": { "annee": true, "mois": true, "jour": false, "modifieLe": "2026-10-03T10:00:00+00:00", "verrouJusquAu": { "jour": "2026-11-02T10:00:00+00:00" } },
"reciprocite": { "restrictionIgnoree": false, "photo": true }
```

`partageNaissance` est le choix du compte de la session (`null` sans rattachement) ;
`reciprocite` vaut pour le lecteur effectif (le membre emprunté sous « voir comme »).

**`PUT /api/session/partage-naissance`** — `{annee, mois, jour}` (booléens, absents = `false`)
→ `200 {annee, mois, jour, modifieLe, verrouJusquAu}`. Partager est toujours immédiat ; une
composante partagée reste visible au moins 30 jours — `verrouJusquAu` (`{mois: "<ATOM>"}`, les
seules composantes encore verrouillées) le dit, et la retirer avant rend `409 partage_verrouille`
(`details.jusquAu`) : il faut passer par une demande RGPD. `409 compte_non_rattache` sans rattachement,
`409 vue_comme_membre_active` sous la bascule, `422` sur un type invalide. Journalisé
(`partage_naissance`, source `profil`).

**`POST /api/inscription/compte`** (§ 4.3) accepte `partageNaissance: {annee, mois, jour}`,
facultatif (absent : rien n'est partagé, aucun choix n'est enregistré) ; erreurs de champ
`partageNaissance.annee`… dans le `422` unique.

**`PATCH /api/administration/utilisateurs/{id}`** accepte `ignoreRestrictionNaissance`
(booléen, journalisé `restriction_naissance`) ; la charge d'un compte porte
`ignoreRestrictionNaissance` et, dans `membre`, `partageNaissance`.

**L'autorisation signée d'un vivant sans compte** — administrateurs seulement :

| Route | Effet |
|---|---|
| `GET /api/administration/personnes/{id}/autorisation-naissance` | `{compteRattache, autorisation, historique}` |
| `POST …/autorisation-naissance` (multipart `document`, `annee`, `mois`, `jour`, `note`) | `201` ; remplace et archive l'active ; `409 compte_rattache`, `422` défunt ou document refusé (PDF, JPEG, PNG, WebP, 10 Mo) |
| `DELETE …/autorisation-naissance` | archive (`retiree`) ; `404` sans autorisation active |
| `GET /api/administration/autorisations-naissance/{id}/document` | le document, `attachment`, `Cache-Control: no-store` |

**Le formulaire RGPD** — la soupape du verrou, et le canal de toute demande sur ses données :

| Route | Effet |
|---|---|
| `GET /api/session/demandes-rgpd` | `{elements: [{id, nature, aRetirer, message, creeeLe, traiteeLe, decision, reponse}]}` |
| `POST /api/session/demandes-rgpd` | `{nature: retrait_partage\|rectification\|autre, annee?, mois?, jour?, message?}` → `201` ; `422` (`nature`, `composantes`, `message`), `409 compte_non_rattache` ; notifie les administrateurs (`demande_rgpd`) |
| `GET /api/administration/demandes-rgpd` | la file : en attente d'abord, puis traitées ; chaque demande porte `memberId`, `userId`, `userEmail` |
| `POST /api/administration/demandes-rgpd/{id}/traiter` | `{decision: appliquee\|refusee, reponse?}` (réponse obligatoire pour un refus) ; un retrait appliqué retire les composantes **sans verrou** (source `administration`) ; `409 demande_deja_traitee` ; notifie le membre (`demande_rgpd_traitee`) |

**Les médias** (§ 7) — une image dont le sujet est un vivant, demandée par un lecteur sans
photo de lui, est servie **floue** par `/fichier` comme par `/vignette` (même JPEG, `ETag`
`"f…"`). Sa charge porte `flou: true`, des URL suffixées `-f` (clé de cache, que le serveur
ignore), `width`/`height` à `null`, et ni `sha256` ni `originalName`. Exceptions : le
déposant, et l'administrateur devant une photo en attente de validation.

## 6. Propositions

Module `Proposal`. **Un seul mécanisme** : modifier un champ, ajouter une personne, ajouter un
lien, joindre un média passent par la même `ChangeRequest`, la même file, le même message
obligatoire au refus (§ 6.4 du dossier, ADR 0004).

| Méthode | Chemin | Accès | Corps | Succès | Erreurs | Débit |
|---|---|---|---|---|---|---|
| `POST` | `/api/demandes` | `MEMBRE` | § 6.1 à 6.4 | `201` demande | `422` · `403` `regime_en_attente` · `404` `introuvable` (cible) · `409` `vue_comme_membre_active` · `429` | 30 / h |
| `GET` | `/api/demandes` | `MEMBRE` | `?status=&page=&parPage=` | `200` liste paginée | `403` | — |
| `GET` | `/api/demandes/{id}` | `MEMBRE` | — | `200` § 6.5 | `403` `acces_refuse` · `404` | — |
| `POST` | `/api/demandes/{id}/soumettre` | `MEMBRE` | *(vide)* | `200` demande | `409` `demande_deja_soumise` · `422` `demande_sans_lien` | — |
| `POST` | `/api/demandes/{id}/retirer` | `MEMBRE` | *(vide)* | `200` demande (`annulee`) | `409` `demande_deja_decidee` · `403` · `404` | — |
| `GET` | `/api/demandes/{id}/refaire` | `MEMBRE` | — | `200` corps pré-rempli | `403` · `404` · `409` `demande_non_refaisable` | — |
| `GET` | `/api/demandes/{id}/brouillons/{ref}` | `MEMBRE` | — | `200` § 6.6 | `403` · `404` | — |
| `GET` | `/api/demandes/brouillons/recherche` | `MEMBRE` | `?q=&limite=` | `200` § 6.6 bis | `422` (`q` < 2) | — |
| `GET` | `/api/demandes/brouillons/{brouillonId}` | `MEMBRE` | — | `200` une ligne du § 6.6 bis | `404` | — |
| `GET` | `/api/demandes/historique/{subjectKind}/{subjectId}` | `MEMBRE` | `?page=&parPage=` | `200` liste paginée | `403` `regime_en_attente` · `404` | — |
| `GET` | `/api/administration/demandes` | `ADMIN` | `?status=&kind=&targetId=&auteur=&q=&page=` | `200` liste paginée | — | — |
| `GET` | `/api/administration/demandes/auteurs` | `ADMIN` | — | `200` `{elements}` | — | — |
| `GET` | `/api/administration/demandes/{id}` | `ADMIN` | — | `200` § 6.7 | `404` | — |
| `POST` | `/api/administration/demandes/{id}/valider` | `ADMIN` | *(vide)* | `200` § 6.8 | **`409` × 6 (§ 6.8)** · `404` · `409` `vue_comme_membre_active` | — |
| `POST` | `/api/administration/demandes/{id}/refuser` | `ADMIN` | `{message, doublonDeId?}` | `200` demande (`refusee`) | `422` `donnees_invalides` · `409` `demande_deja_decidee` · `409` `vue_comme_membre_active` | — |

`GET /api/demandes/{id}` : accessible à **son auteur** et aux **administrateurs**, personne
d'autre — `403 acces_refuse` sinon, par un voter (§ 8 du dossier). Pas `404` : à la différence
du jeton d'invitation, l'existence d'une demande n'est pas un secret, et un `404` empêcherait
de diagnostiquer un lien partagé par erreur.

### 6.1 Créer une demande — le corps commun

Une seule route, un discriminant `kind` qui sélectionne la forme du reste. C'est la
traduction directe de « un seul mécanisme, pas deux » (§ 6.4 du dossier) : **deux routes
seraient deux écrans, deux formats de diff, deux chemins de notification, et deux endroits
où oublier le message obligatoire au refus** (ADR 0004).

```json
{
  "kind": "modification_personne",
  "message": "c'est ma grand-tante, j'ai l'acte",
  "soumettre": true,
  "…": "selon kind"
}
```

| Champ | Rôle |
|---|---|
| `kind` | Une des dix valeurs de `RequestKind` (§ 5.7). |
| `message` | Le mot du demandeur. Facultatif — **sauf pour `suppression_personne`**, où il est obligatoire et où il n'a pas d'autre place (§ 6.4 quater). |
| `soumettre` | `true` ⇒ la demande naît en `en_attente` (`submitted_at` posé, notification à l'administrateur). `false` ⇒ `brouillon`, visible de son seul auteur, absent de la file. Défaut `true`. |

La réponse est la demande sérialisée (§ 6.5), en `201`.

**Une demande déposée par un administrateur n'est pas auto-validée.** Elle entre dans la file
comme les autres. L'auto-validation est un acte distinct : valider sa propre demande, ou
éditer directement — et les deux posent `autoValidated: true` (§ 6.8).

### 6.2 `kind = modification_personne` / `modification_famille` / `modification_citation`

```json
{
  "kind": "modification_personne",
  "message": "l'acte de Saule donne la fourchette",
  "soumettre": true,
  "targetKind": "personne",
  "targetId": 17,
  "ops": [
    {
      "seq": 1,
      "opKind": "champ",
      "subjectKind": "personne",
      "subjectId": 17,
      "field": "surname",
      "valueKind": "texte",
      "oldValue": "Sureau",
      "newValue": "SUREAU",
      "baseVersion": 8
    },
    {
      "seq": 2,
      "opKind": "champ",
      "subjectKind": "personne",
      "subjectId": 17,
      "field": "birth",
      "valueKind": "date_incertaine",
      "oldValue": {
        "raw": "1987", "qualifier": "annee",
        "min": "1987-01-01", "max": "1987-12-31", "place": null
      },
      "newValue": {
        "raw": "BET 1901 AND 1909", "qualifier": "entre",
        "min": "1901-01-01", "max": "1909-12-31",
        "place": "Saule, Ombrage, Sylvanie"
      },
      "baseVersion": 8
    }
  ]
}
```

Règles, toutes vérifiables :

- **`baseVersion` est obligatoire** sur tout op visant une ligne existante, et vaut le
  `version` que la fiche portait quand l'auteur l'a lue (§ 5.3). C'est le verrou optimiste
  inter-requêtes du § 9.6 — sans lui, une demande déposée lundi et validée vendredi écrase le
  travail de mardi sans que rien ne le remarque. Absent ⇒ `422` `champs.ops[n].baseVersion`.
- **`oldValue` est obligatoire** et doit correspondre à la valeur courante **au moment du
  dépôt** : c'est elle, et non `baseVersion`, qui tranche plus tard entre rejeu et caducité
  (§ 9.6). Si elle ne correspond pas déjà à la soumission ⇒ `409 conflit`, avec la valeur
  courante dans `erreur.details` : la fiche a changé pendant que le formulaire était ouvert,
  il faut la rouvrir.
- `oldValue === newValue` ⇒ `422` (`ck_prp_op_changement` le refuserait de toute façon).
- **Un bloc date est un seul op, jamais cinq.** Cinq ops permettraient à l'administrateur
  d'en valider trois et de fabriquer une date incohérente — « exactement l'aplatissement que
  le § 9.1 interdit » (§ 5.2). Le `field` d'un bloc date est donc le **nom du bloc** :
  `birth`, `death`, `marriage`, `divorce`, `happened`, `taken`. Jamais `birthMin`. *Le § 5.2
  cite `birthMin` en exemple de `field` ; c'est incompatible avec sa propre règle « un seul
  op, pas cinq » et avec le `setBirth()` du § 9.4 — point signalé au § 18.*
- **`field` est borné par une table de correspondance explicite** côté service
  (`field → callable`, § 9.4), jamais résolu par réflexion. Un `field` absent de la table
  ⇒ `422` `champs.ops[n].field`, et la demande n'est pas créée. La surface éditable d'une
  personne est exactement : `givenNames`, `surname`, `marriedName`, `nickname`, `sex`,
  `deceased`, `birth`, `death`, `forcePrivacy`, `privacyNote`. **Ni `branch`, ni `generation`,
  ni `bestQuay`, ni `citationCount`, ni `displayName`, ni `searchKey`, ni `version`** : ce
  sont des colonnes dérivées, écrites par un service de `Tree` (§ 3.1). Une demande qui les
  vise est un malentendu, pas une contribution.
- `targetKind` / `targetId` sont dénormalisés depuis les ops et servent à filtrer la file
  sans jointure (§ 5.1). Ils doivent être cohérents avec l'op de plus petit `seq`, sinon
  `422`.

### 6.3 `kind = creation_personne` — une fiche et ses liens

C'est le cas du § 6.4 du dossier. La personne **n'a pas d'identifiant** : elle vit sous une
référence provisoire `#p1`, unique dans la demande (§ 9.5).

```json
{
  "kind": "creation_personne",
  "message": "épouse de Jargon, j'ai le livret de famille",
  "soumettre": true,
  "brouillons": [
    {
      "localRef": "#p1",
      "givenNames": "Tourmaline",
      "surname": "Muguet",
      "marriedName": "Sarriette",
      "nickname": null,
      "sex": "feminin",
      "deceased": true,
      "birth": {
        "raw": "ABT 1889", "qualifier": "environ",
        "min": "1889-01-01", "max": "1889-12-31",
        "place": "Saule, Ombrage, Sylvanie"
      },
      "death": {
        "raw": "1972", "qualifier": "annee",
        "min": "1972-01-01", "max": "1972-12-31", "place": null
      },
      "note": "Livret de famille en ma possession, photographié."
    }
  ],
  "liens": [
    {
      "seq": 1,
      "kind": "conjoint_de",
      "subjectDraftRef": "#p1",
      "otherPersonId": 55,
      "marriage": {
        "raw": "23 SEP 1927", "qualifier": "exacte",
        "min": "1927-09-23", "max": "1927-09-23",
        "place": "Saule, Ombrage, Sylvanie"
      },
      "divorced": false,
      "divorce": null,
      "note": "date du livret"
    },
    {
      "seq": 2,
      "kind": "parent_de",
      "subjectDraftRef": "#p1",
      "otherPersonId": 61,
      "siblingOrder": 0
    },
    {
      "seq": 3,
      "kind": "parent_de",
      "subjectDraftRef": "#p1",
      "otherPersonId": 62,
      "siblingOrder": 1
    }
  ]
}
```

Règles :

- **Au moins un lien**, « sinon la personne flotterait hors de l'arbre » (§ 6.4 du dossier).
  Contrôlé à la **soumission**, pas à la création : on peut enregistrer un brouillon
  incomplet. `POST .../soumettre` sur une demande sans lien ⇒ `422` `demande_sans_lien`.
- `localRef` respecte `#p<n>`, unique dans la demande (`uniq_prp_draft_person_ref`).
- Chaque extrémité d'un lien est **soit** un `*PersonId`, **soit** un `*DraftRef`, **soit**
  un `*DraftPersonId` — une seule des trois (`ck_prp_link_sujet_unique`, `ck_prp_link_cible`)
  ⇒ `422` sinon. La troisième forme est celle du § 6.3 bis.
- Une filiation désigne **soit** `familyId` (un couple existant), **soit** `otherPersonId`
  (+ `other2PersonId` pour le second parent). Jamais les deux
  (`ck_prp_link_famille_filiation`).
- `marriage`, `divorce`, `divorced` ne sont acceptés que sur `conjoint_de`
  (`ck_prp_link_dates_union`) ⇒ `422` sinon. **Toutes facultatives : une union sans date reste
  une union** (§ 6.4 du dossier).
- **La demande ne décrit aucune famille.** Il n'existe pas de brouillon de famille : « la
  demande décrit l'intention (« conjoint de X »), le service en déduit les familles à créer au
  moment de la validation » (§ 6.4 du dossier, § 9.5). Un front qui enverrait un `familleRef`
  sera refusé en `422`.
- `dedup_key` est calculée par le serveur à la soumission (`search_key` + année de naissance).
  Le client ne l'envoie pas et ne la voit que dans la vue d'administration.

### 6.3 bis Désigner une personne **proposée par une autre demande**

Le besoin, dans les mots du commanditaire :

> « Si je propose une personne, elle apparaît dans mon arbre et celui de l'admin ; mais si je
> repousse une autre personne et que je cherche le père que j'ai proposé juste avant, non
> validé encore mais proposé par moi, il ne m'est pas proposé. »

Une fiche proposée n'a **pas** d'identifiant de personne, et `*DraftRef` ne vaut que **dans sa
demande** (`uniq_prp_draft_person_ref`) : `#p1` de la demande 12 et `#p1` de la demande 13
n'ont aucun rapport. Décrire une branche exigeait donc de tout saisir en une seule demande —
ou d'attendre une validation, ou de ressaisir la même personne, c'est-à-dire de fabriquer le
doublon que le § 9.5 du modèle passe son temps à écarter.

La troisième forme d'extrémité désigne la **ligne** `prp_draft_person`, qui est unique en base,
elle : `subjectDraftPersonId`, `otherDraftPersonId`, `other2DraftPersonId`.

```json
{
  "kind": "creation_personne",
  "soumettre": true,
  "brouillons": [{ "localRef": "#p1", "givenNames": "Céleste", "surname": "Muguet", "sex": "feminin", "deceased": true }],
  "liens": [
    {
      "seq": 1,
      "kind": "enfant_de",
      "subjectDraftRef": "#p1",
      "otherDraftPersonId": 7
    }
  ]
}
```

Trois contrôles au dépôt, et chacun attrape autre chose :

| Cas | Réponse |
|---|---|
| la fiche n'existe pas, **ou** appartient à la demande d'un autre membre | `404 introuvable` — jamais `403`, qui apprendrait qu'elle existe (§ 15.2) |
| sa demande n'est plus `en_attente` et la fiche n'a pas été résolue | `422`, `champs.liens[n].otherDraftPersonId` |
| sa demande a été **validée entre la recherche et le dépôt** | accepté : le serveur remplace la référence par la **personne réelle** (`resolved_person_id`) |

Le dernier cas n'est pas une tolérance : refuser ferait échouer une demande que la réalité
vient de conforter, alors que le contributeur vient de voir la fiche dans sa liste de choix.

**Une demande qui s'appuie sur une autre ne se valide pas avant elle.** C'est un ordre, pas un
refus, et il est annoncé **avant le premier clic** par `resolutionPrevue` (§ 6.7) puis par
`409 dependance_en_attente` (§ 6.8).

### 6.4 `kind = ajout_lien` et `kind = ajout_media`

`ajout_lien` — « ils étaient frère et sœur », « elle s'est remariée avec lui ». Même forme que
le § 6.3, **sans `brouillons`** — un ajout de lien ne crée aucune fiche, et en envoyer une rend
`422`. Les extrémités sont des personnes existantes, ou des fiches proposées par une autre
demande (§ 6.3 bis) ; jamais un `*DraftRef`, qui n'aurait aucun brouillon à désigner :

```json
{
  "kind": "ajout_lien",
  "message": "acte de mariage de 1934",
  "soumettre": true,
  "liens": [
    {
      "seq": 1,
      "kind": "conjoint_de",
      "subjectPersonId": 55,
      "otherPersonId": 130,
      "marriage": {
        "raw": "9 MAR 1934", "qualifier": "exacte",
        "min": "1934-03-09", "max": "1934-03-09",
        "place": "Charmille, Ombrage, Sylvanie"
      },
      "divorced": true,
      "divorce": {
        "raw": "1942", "qualifier": "annee",
        "min": "1942-01-01", "max": "1942-12-31", "place": null
      }
    }
  ]
}
```

**`targetKind` / `targetId` sont facultatifs, et il faut les envoyer.** Sans eux, le serveur
prend pour cible le sujet du **premier** lien, ce qui se trompe sur le geste le plus courant de
cet écran : « ajouter un frère à Untel » s'écrit « ce frère est enfant du couple de ses
parents », dont le **sujet est le frère**. La demande apparaîtrait alors dans la file
d'administration sur la fiche du frère, alors que le contributeur travaillait sur celle
d'Untel. La cible annoncée doit être **une des personnes que les liens touchent** — celles
qu'une extrémité nomme, **et les époux des couples désignés par `familyId`** —, sinon `422`.
Sans ce contrôle, `targetId` serait un champ libre permettant de faire apparaître n'importe
quelle demande sur n'importe quelle fiche ; sans les époux, il interdirait précisément le cas
qui l'a motivé, la fratrie ne citant pas la fiche d'où l'on vient.

Une fratrie n'est **pas** une nature de lien : `LinkKind` en compte trois, « ni plus ni moins »
(§ 9.3 du modèle). Elle s'écrit « enfant du même couple » — `enfant_de` + `familyId` —, ce qui
suppose que la fiche ait déjà une famille d'origine. Sans parent connu, il n'existe aucun
couple à désigner, et c'est aux écrans de le dire plutôt que de laisser la demande échouer.

`ajout_media` — le média est **d'abord téléversé** (§ 7.1, il naît `en_attente`), puis la
demande le désigne :

```json
{
  "kind": "ajout_media",
  "message": "acte trouvé aux AD00",
  "soumettre": true,
  "targetKind": "personne",
  "targetId": 17,
  "ops": [
    {
      "seq": 1,
      "opKind": "ajout_media",
      "subjectKind": "personne",
      "subjectId": 17,
      "field": null,
      "valueKind": "media",
      "oldValue": null,
      "newValue": {
        "mediaId": 120,
        "kind": "preuve",
        "caption": "Acte n° 3, Épinaille 1910",
        "sha256": "5e1f…"
      },
      "baseVersion": 8
    }
  ]
}
```

`field` est `null` pour tout `opKind` autre que `champ` (`ck_prp_op_champ`).

### 6.4 bis `kind = ajout_citation` — la source d'une fiche

Le pendant **création** du § 6.2 sur une citation : `modification_citation` savait corriger
page, `QUAY` et transcription d'une citation existante, rien ne savait en poser une. La règle
*chaque fiche porte sa source* n'avait donc aucun chemin dans le circuit — une cote relevée
ne pouvait être consignée que dans la note en prose d'une demande, où l'onglet « sources » ne
la voit pas et où `citationCount` reste à zéro.

La citation se rattache à une **personne existante**. La source est désignée par son
identifiant, et les trois autres champs sont exactement ceux que `modification_citation`
sait déjà manipuler, pour que création et correction parlent le même langage :

```json
{
  "kind": "ajout_citation",
  "message": "relevé aux archives départementales",
  "soumettre": true,
  "targetKind": "personne",
  "targetId": 17,
  "ops": [
    {
      "seq": 1,
      "opKind": "ajout_citation",
      "subjectKind": "personne",
      "subjectId": 17,
      "field": null,
      "valueKind": "citation",
      "oldValue": null,
      "newValue": {
        "sourceId": 204,
        "page": "AD Sylvanie, 5 MI 118, vue 12",
        "quay": 2,
        "dataText": "Le douze frimaire an IX, devant nous…",
        "scope": "naissance"
      }
    }
  ]
}
```

| Clé | Obligatoire | Ce qu'elle porte |
|---|:-:|---|
| `sourceId` | **oui** | une ligne `tree_source` existante ⇒ `422 ops[n].newValue.sourceId` sinon |
| `page` | non | le `PAGE` : de quoi **retrouver** la pièce |
| `quay` | non | `0` à `3` de la norme GEDCOM, ou `null` — `null` n'est pas `0`. En `modification_citation`, son `valueKind` est **`entier`** |
| `dataText` | non | la transcription |
| `scope` | non | ce que la source étaye ; `generale` par défaut |

**Les trois facultatifs ne le sont pas tous à la fois** : `page`, `quay` et `dataText` nuls
ensemble rendent `422 ops[n].newValue`. Une citation qui ne dit ni où retrouver la pièce, ni
ce qu'elle dit, ni quelle confiance lui accorder ne fait que faire *passer* la fiche pour
sourcée.

**`baseVersion` est absent, et c'est voulu.** Le sujet de l'op est la personne, mais la
citation s'écrit à côté d'elle, sans toucher une seule de ses colonnes éditables. Aucun
verrou optimiste ne s'exerce donc : sourcer une naissance pendant qu'un cousin corrige un
prénom ne produit ni caducité ni `verrou_concurrent`, et les deux demandes passent. La
disparition de la fiche reste couverte et rend `409 cible_disparue`, comme partout.

**`quay` et `scope` sont validés au dépôt**, contre le vocabulaire que `Tree` déclare — et
non à la validation, où l'erreur tomberait chez l'administrateur et non chez l'auteur.

> **`quay` se corrige par `modification_citation`, et son `valueKind` est `entier`.**
>
> Il ne l'était pas. Le champ a été **inatteignable par tous les chemins** jusqu'au
> 17/09/2026, par deux causes distinctes qui avaient la même racine : la surface éditable
> le déclarait `valueKind: enum`, dont le contrat de port est `string | null`, alors que
> l'état lu rend un **entier** — `CitationQuality` étant la seule énumération du projet
> adossée à `int` (§ 2.7 du modèle).
>
> Les deux symptômes ne se ressemblaient pas, et c'est pourquoi le diagnostic a tardé :
> poster `"quay": 2` passait le dépôt puis faisait lever la **validation, dans sa
> transaction**, sur un `422` « cette valeur doit être une chaîne » ; poster `"quay": "2"`
> s'écrivait, mais opposait ensuite `"2"` à `2` dans une comparaison stricte et rendait la
> demande **caduque, systématiquement**. Chacun ne voyait qu'une moitié.
>
> La correction est d'une ligne — `quay` passe à `ValueKind::Entier` — et **aucune op
> n'était à migrer** : mesuré avant d'écrire, `prp_change_op` ne portait aucune op visant
> `quay`, sous aucun `value_kind`, et n'en portait d'ailleurs aucune en `enum` tout court.
> Le champ n'avait jamais été atteint, ce qui confirme le diagnostic par la mesure.
>
> `ajout_citation` **ne passait pas par là** : sa valeur est un `valueKind: citation`, où
> `quay` voyageait déjà en entier. Le défaut ne portait que sur la correction.
>
> ⚠️ **Aucun écran ne dépose encore de `modification_citation`.** Le chemin d'API est
> ouvert et éprouvé de bout en bout, mais le formulaire manque — même lacune que pour
> `ajout_citation` et `ajout_note`, et même chantier.

**Ce qui n'est pas accepté**, et qui rend `422 ops[n].subjectDraftRef` : une citation sur une
personne créée par la **même** demande (`#p1`). La validation applique les ops avant de
résoudre les brouillons ; à cet instant la fiche n'a pas d'identifiant. On dépose donc la
création, puis la source sur la fiche obtenue.

**Ce que ce circuit ne sait pas encore poser** : une citation sur une **union** ou sur un
**fait**. Le modèle les autorise (`ck_tree_citation_sujet_unique`), `subjectKind` autre que
`personne` rend `422`.

**Ce qui bouge, et ce qui ne bouge pas, à la validation.** `citationCount` et `bestQuay`
de la fiche sont **recalculés** : ce sont eux qui la font passer de « aucune source » à
« appuyée sur un acte », et c'est tout l'objet de la demande. L'empreinte de l'arbre, en
revanche, **ne change pas** : `tree_citation` en est exclu par l'ADR 0005 — « rien de tout
cela ne déplace un nœud ni ne modifie une parenté » —, parce que l'inclure ferait
recalculer le placement des 377 nœuds et tous les cercles de visibilité pour un graphe
rigoureusement identique. Seul `GET /api/arbre/graphe` est revalidé par cette empreinte, et
la fiche ne l'est pas : elle ne peut donc pas être servie périmée.

### 6.4 ter `kind = ajout_note` — documenter une fiche existante

**Le résultat absurde que cette nature supprime :** on savait créer une personne avec sa
note — le § 6.3 porte un champ `note` par brouillon —, et on ne savait pas documenter une
personne qui existe. `prp_draft_person.note` était l'unique chemin par lequel une
`tree_note` entrait dans l'arbre après l'import, et il ne sert qu'à la création. Toute la
matière d'archives portant sur les fiches déjà en base n'avait donc aucun moyen d'entrer :
une transcription d'acte, une clôture de fratrie déclarée devant notaire, la correction
d'une cote fausse qui enverra le prochain chercheur dans le mauvais fonds.

Même forme que le § 6.4 bis, avec une valeur plus simple :

```json
{
  "kind": "ajout_note",
  "message": "relevé dans l’acte notarié",
  "soumettre": true,
  "targetKind": "personne",
  "targetId": 17,
  "ops": [
    {
      "seq": 1,
      "opKind": "ajout_note",
      "subjectKind": "personne",
      "subjectId": 17,
      "field": null,
      "valueKind": "note",
      "oldValue": null,
      "newValue": {
        "body": "Acte notarié du 12 frimaire an IX…
La cote portée jusqu’ici était fausse.",
        "scope": "naissance"
      }
    }
  ]
}
```

| Clé | Obligatoire | Ce qu'elle porte |
|---|:-:|---|
| `body` | **oui** | le texte ⇒ `422 ops[n].newValue.body` s'il manque, est vide ou n'est qu'espaces |
| `scope` | non | ce que la note documente ; `generale` par défaut |

**Les fins de ligne sont normalisées** (`
` → `
`) et le texte est `trim`é au dépôt :
le même texte saisi au navigateur et recopié depuis un fichier doit produire la même note,
sinon deux envois identiques cesseraient d'être reconnus comme des doublons et le libellé
de l'historique dépendrait du système de l'auteur.

**Une note de vingt-trois pages passe.** `tree_note.body` est un `TEXT` sans borne ; la
limite de dépôt est à 200 000 caractères, très au-dessus de ce qu'on veut recevoir, et
elle n'existe que pour écarter un corps qui ne serait pas une note du tout.

**L'auteur inscrit sur la note est le demandeur, jamais l'administrateur qui valide.**
`tree_note.author_label` est rendu sur la fiche, et pour cette colonne `null` signifie
« importée du GEDCOM » (§ 3.5) : la laisser vide ferait passer la contribution d'un membre
pour un artefact d'import, et y mettre le valideur effacerait celui qui a fait le travail.

**Ce qui n'est pas accepté** : `subjectDraftRef` ⇒ `422`, avec un message qui renvoie au
champ `note` du formulaire de création, lequel sait déjà le faire. `subjectKind` autre que
`personne` ⇒ `422` : les notes d'union et de fait, que le modèle autorise, ne passent pas
encore par une demande.

**Ce qui ne bouge pas, et c'est voulu** : ni `citationCount` ni `bestQuay`. Une note
documente, elle n'étaye pas — dix notes ne rendent pas une fiche mieux sourcée. Ni
l'empreinte de l'arbre : `tree_note` en est exclu par l'ADR 0005, au même titre que
`tree_citation`, parce qu'une note ne déplace aucun nœud. Seul `GET /api/arbre/graphe`
est revalidé par cette empreinte, et la fiche — le seul écran où une note s'affiche — ne
l'est pas : elle ne peut donc pas être servie périmée.

### 6.4 quater `kind = suppression_personne` — effacer une fiche

**Le chemin qui n'existait pas.** Le circuit des demandes ne savait ni fusionner ni
supprimer. Un doublon dans un arbre généalogique se réparait donc **à la main en base** :
un `DELETE` écrit dans un client SQL, sans demande, sans motif, sans révision et sans
relecture. C'était le seul chemin d'écriture qui contournait le mécanisme — ce que
l'ADR 0004 interdit —, et deux doublons ont déjà été traités ainsi sur ce projet, dont un
qui avait détaché six générations.

C'est la **neuvième** nature, et la première qui retire au lieu d'ajouter. Trois règles la
distinguent des huit autres, et toutes trois se voient dans le corps.

```json
{
  "kind": "suppression_personne",
  "message": "Doublon de la fiche 42 : même homme, même acte de naissance, celle-ci est vide.",
  "soumettre": true,
  "targetKind": "personne",
  "targetId": 17
}
```

| Clé | Obligatoire | Ce qu'elle porte |
|---|:-:|---|
| `targetKind` | non | `personne`, et rien d'autre ⇒ `422 targetKind` |
| `targetId` | **oui** | la fiche à supprimer ⇒ `404` si elle n'existe pas |
| `message` | **oui** | le motif, **10 caractères au minimum** ⇒ `422 message` |

**Le corps ne porte aucun `ops`.** La demande dit ce qu'elle vise ; c'est le serveur qui
mesure ce qu'elle emporte, et qui écrit l'inventaire dans l'op qu'il fabrique. Un
inventaire envoyé par le client serait une seconde source de vérité pour la seule
opération du produit qui détruit.

#### Règle 1 — le motif est obligatoire, et il n'a pas d'autre place

C'est la seule nature où `message` l'est. La raison n'est pas l'insistance : **`POST
/api/demandes/{id}/valider` ne lit aucun corps de requête** (§ 6.8). Seul un refus porte un
motif ; une validation n'en laisse aucun. Un motif demandé au moment de valider n'existerait
donc nulle part, et l'administrateur de l'an prochain lirait « fiche 42 supprimée » sans
savoir pourquoi.

Le plancher de dix caractères est arbitraire et assumé : il écarte « ok », « doublon » et
« à supprimer », trois motifs qui ne disent pas **de quelle autre fiche** il s'agit, ce qui
est la seule chose dont l'administrateur ait besoin pour trancher. « doublon de 42 » passe.

#### Règle 2 — une suppression qui détacherait des descendants est refusée

Deux fiches du même homme, l'une portant six générations et l'autre vide : supprimer la
mauvaise est trivial, supprimer la bonne serait une catastrophe silencieuse — la branche
quitte l'arbre, et rien ne le signale avant que quelqu'un ne l'ouvre.

Le refus est prononcé **au dépôt**, en `422 targetId`, et le message **nomme les enfants** :

```json
{
  "erreur": {
    "code": "donnees_invalides",
    "message": "Cette fiche ne peut pas être supprimée en l’état.",
    "champs": {
      "targetId": "23 fiches descendent de celle-ci, toutes générations confondues : la supprimer détacherait cette branche de l’arbre. Elle est le parent de Cobalt Sureau. S’il s’agit d’un doublon, c’est l’autre fiche — celle qui ne porte pas la descendance — qu’il faut supprimer."
    }
  }
}
```

L'erreur porte sur `targetId` et non sur un champ de formulaire : **il n'y a rien à
corriger dans la saisie**, c'est la fiche choisie qui n'est pas la bonne.

**Deux nombres, et il en faut deux.** `descendants` ne porte que les enfants **directs**,
nommés ; `descendanceTotale` porte la branche entière, toutes générations confondues. Le
premier seul dirait « parent de Cobalt Sureau » — exact, et sans poids : un enfant, on
l'efface sans y penser. Le second seul dirait « 23 fiches » — du poids, mais sans dire
**laquelle des deux fiches du doublon** on tient. Le message met donc le total en tête et
les noms derrière, et c'est dans cet ordre que l'écran les affiche.

Le total ne sert **qu'au message** : c'est la présence d'un seul enfant direct qui bloque.
Faire dépendre une règle de sûreté d'un parcours de graphe, ce serait la faire dépendre de
sa terminaison, là où une ligne de `tree_child` suffit.

*Être l'enfant de quelqu'un ne bloque pas* — c'est le champ `filiations` de l'inventaire, et
il part avec la fiche. Seul le fait d'être **parent** bloque : l'autre extrémité du lien
reste dans l'arbre.

**Trois autres choses bloquent, et aucune n'est protégée par une clé étrangère.**

**Une preuve rattachée.** `med_media.subject_id` est une référence polymorphe, que
PostgreSQL ne peut pas contraindre : effacer la fiche y laisserait des lignes pendantes et
des octets sur le disque, sans plus aucun écran pour y mener. On ne supprime pas la preuve
à la place de l'opérateur — `DELETE /api/medias/{id}` existe pour cela (§ 7.6), et c'est un
geste qu'il aura vu.

**Un compte membre dont la fiche est la racine.** `mbr_member.person_id` porte un
`uniq_mbr_member_person` et aucune clé étrangère — le § 2.6 du modèle interdit la jointure
qui la poserait. **Le cercle de visibilité d'un membre et son champ `parente` se calculent
depuis cette racine** : une racine absente ne lève pas, elle produit un calcul sur rien,
c'est-à-dire un compte qui ne voit plus rien ou qui voit tout. Le membre le découvre en se
connectant, des jours plus tard, sans pouvoir relier ce qu'il voit au geste qui l'a causé.
**Tous les statuts de rattachement comptent**, `en_attente` compris — son titulaire n'a pas
encore de cercle, il ne verrait donc *rien*.

**Une invitation en attente qui désigne la fiche.** Le même défaut, **en différé** : rien ne
casse aujourd'hui, un compte sans racine apparaît le jour où quelqu'un accepte. Seule une
invitation `active` compte ; une `utilisee` a déjà produit son membre, une `expiree` ou
`revoquee` ne produira plus rien.

`membreId` nomme le rattachement **par son numéro et jamais par l'adresse du compte** :
cette route est ouverte à tout membre, et le libellé d'un compte est la huitième porte de
sortie de l'ADR 0003. Le numéro suffit à le retrouver dans `GET
/api/administration/utilisateurs`, qui est le seul endroit où on peut le rattacher ailleurs.
`invitationsActives` est un compte, pour la même raison.

#### Règle 3 — rien ne part en silence

L'op fabriquée porte `old_value` = **l'inventaire de ce que la suppression emporte** au
moment du dépôt, et `new_value` = `null` : après une suppression, il n'y a rien. C'est
l'exact miroir d'une création, où `avant` vaut `null` partout.

```json
{
  "personneId": 17,
  "displayName": "Marne Dolomite Sureau",
  "notes": 3,
  "citations": 2,
  "faits": 1,
  "filiations": 1,
  "unions": [
    { "familleId": 4, "libelle": "Cobalt Sureau × Marne Dolomite Sureau", "enfants": 0, "supprimee": false }
  ],
  "descendants": [],
  "descendanceTotale": 0,
  "medias": 0,
  "membreId": null,
  "invitationsActives": 0
}
```

**L'ordre des clés est un contrat**, au même titre que celui d'un bloc date (§ 14.1) : la
comparaison porte sur le tableau entier, et `JSONB` ne préserve pas l'ordre d'écriture.

À la lecture d'administration (§ 6.7), l'op porte en plus `valeurCourante` — l'inventaire
**d'aujourd'hui** — et `libelleDiff`, la phrase prête à afficher :
« Suppression de la fiche « Marne Dolomite Sureau » : emporte 3 notes, 2 citations, 1 union. »

**Si les deux inventaires diffèrent, `resolutionPrevue` vaut `caducite`** et la validation
rend `409 demande_caduque`. Une note, une citation ou une preuve ajoutée entre le dépôt et
la validation ferait sinon effacer une ligne que personne n'a montrée à celui qui valide.
Le prix est assumé : documenter une fiche pendant qu'une demande de suppression l'attend
coûte un clic à son auteur, qui la refait (§ 6.10). L'erreur inverse coûterait la ligne.

`champs[].field` vaut alors `"suppression_personne"` — la nature de l'op, puisqu'une
suppression ne nomme aucune colonne (`ck_prp_op_champ`).

#### Ce que la validation écrit

`base_version` **est** posée sur l'op, contrairement à une note ou à une citation :
supprimer une fiche est une écriture **sur cette fiche**. Une correction validée entre-temps
fait donc rejouer la suppression sur la version courante (`resolutionPrevue: "rejeu"`), au
lieu de passer inaperçue.

La révision porte `operation: "suppression"`, `subjectKind: "personne"`, `subjectId` =
l'identifiant de la fiche effacée, `versionBefore` = sa dernière version, `versionAfter` =
`null`. **`prp_revision` n'a aucune clé étrangère vers `tree_person`** : `subject_id` est un
entier nu, délibérément, et c'est ce qui permet à l'historique de survivre à son sujet.
`changes` porte l'inventaire mesuré **dans la transaction**, clé par clé, `avant` renseigné
et `apres` à `null`, plus une clé `resume` qui reprend la phrase montrée à l'administrateur.

Ce qu'il advient de chaque référence à la fiche :

| Ce qui pointe sur elle | Sort |
|---|---|
| `tree_note.person_id`, `tree_citation.person_id`, `tree_fact.person_id` | **emportés**, comptés d'avance — y compris les notes et citations rattachées à ses **faits** |
| `tree_child.person_id` (elle est l'enfant) | **emporté** : sa propre filiation n'a plus d'objet |
| `tree_child` d'une union où elle est **parent** | **bloque** la suppression (règle 2) |
| `tree_family.husband_id` / `wife_id` | mis à `NULL` si l'autre époux existe ; l'union est **effacée** dans deux cas, et l'inventaire les annonce par `unions[].supprimee` — voir ci-dessous |
| `tree_source` | **intacte** : `ON DELETE RESTRICT`, la citation part, le registre reste |
| `med_media` | **bloque** la suppression (règle 2) |
| `prp_draft_link`, `prp_change_op`, `prp_change_request` d'autres demandes | **laissés**. Les demandes en attente qui visent la fiche deviennent `cible_disparue` à leur prochain examen ; les demandes tranchées restent l'historique de la discussion — « une demande n'est jamais supprimée » |
| `prp_revision`, `core_audit_log` | **laissés, et c'est le point** : un journal qu'une suppression pourrait amputer n'est pas un journal |
| `mbr_member.person_id` | **bloque** la suppression (règle 2) |
| `mbr_invitation.person_id` | **bloque** si l'invitation est `active` ; les autres sont de l'historique |

#### Les deux cas où une union est effacée plutôt que vidée

**1. La personne en est le seul époux.** `ck_tree_family_au_moins_un_epoux` interdit une
famille sans personne : le `ON DELETE SET NULL` produirait une ligne que la base refuse.

**2. Le couple restant existe déjà ailleurs.** `uniq_tree_family_couple` porte
`NULLS NOT DISTINCT` : deux familles `(NULL, W)` ne peuvent pas coexister. Vider
l'emplacement du mari d'une famille `(X, W)` la transforme précisément en `(NULL, W)`, et
si une famille de parent seul pour `W` existe déjà — le modèle en compte, « 3 familles
`WIFE` sans `HUSB` » —, la validation échouerait **dans sa transaction**, sur une violation
d'unicité, loin du geste qui l'a causée et sans que l'écran ait rien laissé prévoir.

Dans les deux cas la ligne part, et `unions[].supprimee` vaut `true` à l'inventaire — donc
avant le clic. Une union **effacée** emporte au passage ses propres notes, citations et
faits, par les `ON DELETE CASCADE` de `family_id` : ils sont comptés dans les totaux
`notes`, `citations` et `faits`, et non à part.

**L'empreinte de l'arbre change** — `tree_person`, `tree_family` et `tree_child` en font
partie (ADR 0005) —, ce qui invalide le cache de placement et les cercles de visibilité sans
geste. C'est la différence avec `ajout_note` et `ajout_citation`, qui ne la font pas bouger.

#### `GET /api/demandes/suppression/personne/{id}` — l'aperçu, avant tout dépôt

Lecture seule, accessible à tout lecteur qui peut voir la fiche. Elle sert le formulaire :
l'auteur doit voir ce qu'il efface **avant** d'écrire son motif, et non le découvrir en
`422` après avoir rédigé.

```json
{
  "inventaire": { "personneId": 17, "displayName": "Marne Dolomite Sureau", "notes": 3, "citations": 2, "faits": 1, "filiations": 1, "unions": [], "descendants": [], "descendanceTotale": 0, "medias": 0, "membreId": null, "invitationsActives": 0 },
  "resume": "Suppression de la fiche « Marne Dolomite Sureau » : emporte 3 notes, 2 citations, 1 fait et 1 rattachement à ses parents.",
  "bloquee": false,
  "raisons": []
}
```

`bloquee` et `raisons` sont rendus **à côté** de l'inventaire et non déduits de lui par le
client : la règle qui interdit appartient au serveur, et un front qui la redériverait
finirait par diverger — dans le sens permissif, c'est-à-dire en proposant un bouton qui
efface une branche.

`404` sur une fiche qui n'existe plus, ou que le lecteur n'a pas le droit de voir.

#### Ce qui n'est pas dans ce lot

**La fusion.** Le besoin réel est presque toujours un doublon, et fusionner conserverait ce
que la fiche supprimée portait au lieu d'exiger qu'elle soit vide. Elle demande une
résolution de conflit champ par champ entre les deux fiches, le report des unions avec
l'arbitrage de `uniq_tree_family_couple`, et le déplacement des preuves — trois choses
qu'une suppression n'a pas à savoir faire. La suppression telle qu'elle est livrée couvre
le cas du doublon **vide**, qui est celui qu'on rencontre, et refuse explicitement l'autre
plutôt que de le traiter à moitié.

**La réattribution.** L'ADR autorisait « refuser **ou** exiger une réattribution
explicite » ; c'est le refus qui est livré. Réattribuer les unions d'une fiche à une autre
est la moitié structurelle d'une fusion, et elle échoue sur le même écueil : le couple
réattribué peut entrer en collision avec une union existante, au milieu de la transaction.

### 6.4 quinquies `kind = retrait_lien` — défaire un lien de parenté

**Le pendant de `ajout_lien`, qui n'existait pas.** `OpKind::RetraitLien` est déclaré depuis la
première migration, et rien ne l'appliquait : un enfant rattaché au mauvais couple, un
conjoint saisi deux fois ne se défaisaient qu'en base, à la main — le chemin d'écriture sans
demande, sans révision et sans relecture que l'ADR 0004 interdit. C'est la **dixième** nature.

```json
{
  "kind": "retrait_lien",
  "message": "Acte de naissance relu : Pivoine est la fille de Marne seule.",
  "soumettre": true,
  "targetKind": "personne",
  "targetId": 17,
  "retraits": [
    { "kind": "enfant_de", "personId": 17, "familyId": 4, "parentId": 30 },
    { "kind": "conjoint_de", "personId": 52, "familyId": 9 }
  ]
}
```

| Clé de `retraits[i]` | Obligatoire | Ce qu'elle porte |
|---|:-:|---|
| `kind` | **oui** | `enfant_de` ou `conjoint_de`. `parent_de` ⇒ `422` : « X n'est plus le parent de C » s'écrit depuis l'enfant, `enfant_de` + `parentId` |
| `personId` | **oui** | `enfant_de` : l'enfant ; `conjoint_de` : l'époux qui **quitte** l'union |
| `familyId` | **oui** | la famille de parents de l'enfant, ou l'union |
| `parentId` | non | `enfant_de` seulement : **le** parent que l'enfant perd. Absent, l'enfant perd les deux parents du couple |

**Plusieurs liens dans une demande** : c'est le regroupement que dépose le mode édition de la
fiche — une décision pour l'administrateur, pas cinq. Le même lien deux fois ⇒ `422`.

`targetKind` vaut `personne` (autre chose ⇒ `422`) ; `targetId` est facultatif et, comme pour
`ajout_lien`, doit être **une des personnes que les liens touchent** — l'enfant, le parent nommé,
un époux d'une des familles —, sinon `422`. Absent, c'est la personne du premier retrait.

**Le corps ne porte aucun `ops`.** La demande dit ce qu'elle vise ; le serveur **mesure** ce que
défaire chaque lien ferait, et écrit cette mesure dans l'op qu'il fabrique — un op par lien,
`opKind: "retrait_lien"`, `subjectKind: "famille"`, `subjectId` = la famille, `baseVersion` =
sa version, `valueKind: "lien"`, `newValue: null`, et `oldValue` :

```json
{
  "nature": "enfant_de",
  "personneId": 17, "personneLibelle": "Pivoine Sureau",
  "familleId": 4, "familleLibelle": "Cobalt Sureau × Marne Bourrache",
  "parentId": 30, "parentLibelle": "Cobalt Sureau",
  "conjointRestant": null,
  "rattacheA": "Marne Bourrache",
  "enfantsRestants": 1,
  "familleSupprimee": false,
  "blocage": null
}
```

**L'ordre des clés est un contrat**, comme pour l'inventaire d'une suppression (§ 6.4 quater) :
la comparaison porte sur le tableau entier.

#### Ce que fait chaque retrait

| Retrait | Effet |
|---|---|
| `enfant_de` sans `parentId` | l'enfant quitte la famille : il n'est plus l'enfant d'**aucun** des deux époux |
| `enfant_de` avec `parentId`, union à deux époux | l'enfant quitte la famille, puis est rattaché à **l'autre** parent par les règles de `etablirFiliation()` — la famille qu'il a déjà, ou `(∅, autre)` trouvée ou créée : jamais de familles parentales doublées (demandes 714 → 727). `rattacheA` l'annonce avant le clic |
| `conjoint_de` | la place de l'époux dans l'union est vidée ; l'union garde ses enfants, ses dates, ses notes. `enfantsRestants` dit combien d'enfants n'y gardent que `conjointRestant` pour parent |

**Une famille qui porte des données n'est jamais supprimée.** L'union ne disparaît
(`familleSupprimee: true`) que si, après le retrait, elle ne porte **plus rien** : aucun autre
enfant, aucune date d'union ni de rupture, aucune note, citation ni fait, aucun média, et **au
plus un époux** — `X × Y` sans enfant reste une union : rien dans « il n'est pas leur enfant » ne
dit qu'ils n'étaient pas mariés. Une autre demande en attente qui désigne l'union la protège aussi,
à la validation seulement (elle ne peut que faire **conserver** une union annoncée supprimée,
jamais l'inverse).

#### Les refus, au dépôt

| Cas | Réponse |
|---|---|
| le lien n'existe pas — pas enfant de cette famille, pas époux de cette union, `parentId` qui n'en est pas un époux | `422` sur `retraits[i]`, phrase de l'arbre |
| **le seul époux** d'une union qui porte des données | `422` : l'union resterait sans personne (`ck_tree_family_au_moins_un_epoux`) |
| le couple restant **existe déjà** (`(X, ∅)` à côté de `(X, Y)`) | `422` : `uniq_tree_family_couple` porte `NULLS NOT DISTINCT`. On ne fusionne pas deux familles à la place de l'administrateur |
| l'enfant ne peut pas rester celui de l'autre parent (règle 4 de `etablirFiliation`) | `422`, la phrase de l'incompatibilité |
| la famille ou la personne n'existe pas | `404` |

#### À la validation — caducité et rejeu

À la lecture d'administration (§ 6.7), l'op porte `valeurCourante` — la mesure **d'aujourd'hui**
— et `libelleDiff`, la phrase prête à afficher : « Lien retiré : Pivoine Sureau n'est plus enfant
de Cobalt Sureau ; reste enfant de « Marne Bourrache ». ». **Si les deux mesures diffèrent,
`resolutionPrevue` vaut `caducite`** et la validation rend `409 demande_caduque`, avec
`champs[].field` = `"retrait_lien"` : un enfant arrivé dans l'union, une date posée, un lien déjà
défait par une autre demande changent ce que le retrait ferait. Une famille dont seule la
`version` a bougé, la mesure restant la même, se **rejoue**. Une famille disparue rend
`cible_disparue`.

#### Ce que la validation écrit

Par lien : une révision `lien_retire` sur **la famille** (`versionBefore`, `versionAfter` —
`null` si elle est supprimée), une `lien_retire` sur **chaque fiche** que le lien reliait —
l'enfant et ses parents, ou les deux époux — pour que l'historique de la fiche (§ 6.11) le
montre, une `suppression` sur la famille quand elle part, et, quand l'enfant est rattaché à
l'autre parent, les révisions d'un ajout (`creation` ou `modification` de la famille d'arrivée,
`lien_ajoute`). `changes` vaut `{"lien": {"avant": "<la phrase>", "apres": null}}`.

#### `GET /api/demandes/{id}/refaire`

Rend `retraits` — les mêmes liens, **sans leur mesure** : c'est le serveur qui remesure, sur
l'arbre d'aujourd'hui, qui est la raison même de la reprise.

### 6.5 Une demande vue par son auteur

`GET /api/demandes/12` :

```json
{
  "id": 12,
  "kind": "creation_personne",
  "status": "en_attente",
  "authorUserId": 3,
  "authorLabel": "Cobalt Romarin",
  "targetKind": null,
  "targetId": null,
  "targetLabel": null,
  "message": "épouse de Jargon, j'ai le livret de famille",
  "decisionMessage": null,
  "decidedByLabel": null,
  "decidedAt": null,
  "autoValidated": false,
  "duplicateOfId": null,
  "submittedAt": "2026-09-14T11:02:00+02:00",
  "createdAt": "2026-09-14T10:58:00+02:00",
  "ops": [],
  "brouillons": [
    {
      "localRef": "#p1",
      "noeudId": "#12/p1",
      "givenNames": "Tourmaline",
      "surname": "Muguet",
      "sex": "feminin",
      "deceased": true,
      "birth": { "raw": "ABT 1889", "qualifier": "environ", "min": "1889-01-01", "max": "1889-12-31", "place": "Saule, Ombrage, Sylvanie", "texte": "vers 1889" },
      "death": { "raw": "1972", "qualifier": "annee", "min": "1972-01-01", "max": "1972-12-31", "place": null, "texte": "1972" },
      "note": "Livret de famille en ma possession, photographié.",
      "resolvedPersonId": null
    }
  ],
  "liens": [
    {
      "seq": 1,
      "kind": "conjoint_de",
      "subjectDraftRef": "#p1",
      "subjectLabel": "Tourmaline Muguet (proposée)",
      "otherPersonId": 55,
      "otherDraftRef": null,
      "otherLabel": "Jargon Sarriette",
      "other2PersonId": null,
      "other2DraftRef": null,
      "other2Label": null,
      "familyId": null,
      "familyLabel": null,
      "marriage": { "raw": "23 SEP 1927", "qualifier": "exacte", "min": "1927-09-23", "max": "1927-09-23", "place": "Saule, Ombrage, Sylvanie", "texte": "23 septembre 1927" },
      "divorced": false,
      "divorce": null,
      "siblingOrder": null,
      "note": "Union relevée sur le registre paroissial.",
      "resolvedFamilyId": null
    }
  ],
  "revisions": []
}
```

`noeudId` est ajouté par l'API sur chaque brouillon : c'est **l'identifiant que ce brouillon
porte dans le graphe** (§ 5.2). Le front ne le fabrique pas par concaténation ; il le lit ici
et dans le nœud, et les deux coïncident par construction.

#### Les libellés d'un lien nomment les fiches proposées

> **Une extrémité qui désigne un brouillon de la même demande porte son nom, suivi de
> « (proposée) ».** Jamais sa référence provisoire.

Elle a porté « Personne proposée #p1 », et ce n'était pas un détail de présentation. Mesuré
sur un cas réel : une branche de vingt-deux personnes déposée en cinq demandes a été validée
**sans que sa parenté ait pu se lire** — pour savoir de qui un enfant était le fils, il
fallait relever `#p1` dans le lien et le retrouver à la main dans la liste des brouillons,
vingt-deux fois.

Trois conséquences de forme, toutes vérifiables sur la charge ci-dessus :

- **Le nom est recomposé à la sérialisation**, depuis la fiche elle-même. Il ne vient pas de
  la colonne dénormalisée : les liens déjà en base portent la forme d'avant, et un correctif
  qui ne vaudrait que pour les demandes à venir ne réparerait pas l'écran sur lequel le
  défaut a été constaté.
- **La mention « (proposée) » n'est pas stockée.** `prp_revision.subject_label` recopie ces
  colonnes **au moment même où la personne cesse d'être proposée** : l'historique dirait
  « (proposée) » d'une fiche validée.
- **`*DraftRef` reste servi à côté du libellé** — `otherDraftRef`, `other2DraftRef` — pour
  qu'un lecteur puisse distinguer les deux natures d'extrémité sans lire le texte.

`familyLabel` accompagne `familyId` pour la même raison : une filiation vers un couple
existant affichait « rattaché à la famille 28 », c'est-à-dire un identifiant de ligne, quand
la base porte le nom des deux époux.

`note` est servi sur **les brouillons et sur les liens**. Ces deux colonnes portent les
justifications écrites fiche par fiche — une homonymie père/fils signalée, une déduction
marquée comme supposition. Elles sont exactement ce sur quoi l'administrateur tranche, et
elles ne quittaient pas la base : seul le `message` global de la demande arrivait à l'écran.

Après décision, `revisions` porte ce que la validation a réellement produit — une création en
génère plusieurs (la personne, la famille, les filiations) :

```json
"revisions": [
  { "id": 210, "subjectKind": "personne", "subjectId": 312, "subjectLabel": "Tourmaline Muguet", "operation": "creation", "appliedAt": "2026-09-15T09:31:00+02:00" },
  { "id": 211, "subjectKind": "famille", "subjectId": 104, "subjectLabel": "Jargon Sarriette × Tourmaline Muguet", "operation": "creation", "appliedAt": "2026-09-15T09:31:00+02:00" }
]
```

### 6.6 Le détail d'un nœud en attente

`GET /api/demandes/12/brouillons/p1` — **la référence sans le dièse** (§ 5.2, piège n° 2).

Sans cette route, l'auteur voit son propre nœud dans le graphe et **ne peut pas l'ouvrir** :
`/api/arbre/personnes/{id}` exige `\d+` et la personne n'a pas encore d'identifiant. C'est
exactement le manque qu'une lecture rapide laisse passer — « une création qu'on ne voit pas
dans l'arbre n'est pas une contribution » (ADR 0005), et un nœud qu'on ne peut pas ouvrir
n'en est pas une non plus.

```json
{
  "demandeId": 12,
  "localRef": "#p1",
  "noeudId": "#12/p1",
  "status": "en_attente",
  "enAttente": true,
  "authorLabel": "Cobalt Romarin",
  "givenNames": "Tourmaline",
  "surname": "Muguet",
  "marriedName": "Sarriette",
  "sex": "feminin",
  "deceased": true,
  "vivant": false,
  "birth": { "raw": "ABT 1889", "qualifier": "environ", "min": "1889-01-01", "max": "1889-12-31", "place": "Saule, Ombrage, Sylvanie", "texte": "vers 1889" },
  "death": { "raw": "1972", "qualifier": "annee", "min": "1972-01-01", "max": "1972-12-31", "place": null, "texte": "1972" },
  "ligneDates": "~1889 – 1972",
  "note": "Livret de famille en ma possession, photographié.",
  "liens": [
    { "seq": 1, "kind": "conjoint_de", "otherPersonId": 55, "otherLabel": "Jargon Sarriette" }
  ],
  "medias": [
    { "mediaId": 121, "kind": "preuve", "caption": "Livret de famille, p. 2", "url": "/api/medias/121/fichier", "vignetteUrl": "/api/medias/121/vignette", "status": "en_attente" }
  ],
  "resolvedPersonId": null
}
```

Accessible à l'auteur et aux administrateurs. Après validation, `resolvedPersonId` porte
l'identifiant réel et le front redirige vers `/api/arbre/personnes/312` — c'est la raison
d'être de cette colonne (§ 5.3).

**Une personne proposée est toujours `visibilite: "complete"` pour son auteur et pour les
administrateurs**, et invisible pour tout le monde d'autre : elle n'est dans aucun cercle,
puisque le cercle porte sur des personnes validées (§ 9.3). Le calque et le cercle sont deux
filtres distincts, composés dans le même normaliseur (ADR 0004) — ils ne se remplacent pas.

### 6.6 bis Chercher parmi les personnes **proposées**

`GET /api/demandes/brouillons/recherche?q=barnab&limite=10`

`GET /api/arbre/recherche` cherche dans l'arbre **validé** : une fiche sans identifiant n'y
figure pas, et son auteur ne pouvait donc pas la désigner dans une seconde demande (§ 6.3
bis). Cette route est son pendant côté `Proposal`.

```json
{
  "elements": [
    {
      "draftPersonId": 7,
      "demandeId": 12,
      "localRef": "#p1",
      "noeudId": "#12/p1",
      "displayName": "Barnabé Muguet",
      "sex": "masculin",
      "deceased": true,
      "ligneDates": "~1901 – 1968",
      "authorLabel": "Cobalt Romarin",
      "enAttente": true
    }
  ],
  "tronque": false
}
```

**Deux routes et non une clé de plus dans la première**, parce que les deux réponses ne se
filtrent pas pareil : l'arbre se filtre par le **cercle de visibilité** (ADR 0003), les
propositions par la règle du § 13.3 — l'auteur et les administrateurs, personne d'autre. Une
route par règle, c'est une règle par endroit. Et une ligne de l'arbre porte un `personId`
qu'une fiche proposée n'a pas : les glisser dans le même tableau obligerait chaque client à
tester quelle forme il tient.

| Lecteur | Ce qu'il obtient |
|---|---|
| Un membre | les fiches de **ses** demandes en attente |
| Administrateur | celles de **toutes** les demandes en attente |
| Administrateur sous « voir comme un membre » | ce que verrait le membre emprunté |
| Compte `en_attente` | `{"elements": [], "tronque": false}` — il ne dépose pas de demande |

Seules les demandes `en_attente` : une demande validée a produit une personne, que la recherche
de l'arbre sait trouver ; une demande refusée n'a plus rien à offrir.

Comme au § 5.5, **pas de `total`** — « une recherche qui se pagine est une énumération
déguisée » —, et `tronque` se calcule sur le jeu **déjà filtré**. `q` doit faire au moins deux
caractères, sinon `422`.

`GET /api/demandes/brouillons/7` rend **la même ligne** pour une fiche dont on tient déjà
l'identifiant. Elle existe parce qu'un corps de lien ne transporte que `otherDraftPersonId` :
sans elle, un écran de reprise afficherait « personne n° 7 », c'est-à-dire un identifiant sous
les yeux du lecteur, ce que le dépôt s'interdit partout ailleurs. `404` sur la fiche d'un
autre membre, jamais `403`.

### 6.7 La file d'administration et le diff

`GET /api/administration/demandes?status=en_attente` — triée par `submittedAt` croissant
(`idx_prp_request_file`). Depuis le 2026-10-04, le front l'affiche **à plat, par numéro**
(chaque ligne porte `n° {id}`, et signale les autres demandes en attente sur la même
fiche), en onglets par nature ; l'API rend toujours `targetKind`/`targetId`.

| Paramètre | Défaut | Valeurs |
|---|---|---|
| `status` | tous | `en_attente`, `validee`, `refusee`, `caduque`, `brouillon`, `annulee` |
| `kind` | tous | une valeur de `RequestKind`, ou plusieurs séparées par des virgules (les onglets) |
| `targetId` | toutes | un `tree_person.id` |
| `auteur` | tout le monde | un `authorUserId` |
| `q` | — | un **numéro** de demande (`42`, `#42`, `n° 42`) la désigne seule ; tout autre texte cherche, sans casse, dans `targetLabel`, `authorLabel`, `message` et le nom des personnes proposées |
| `page`, `parPage` | 1, 25 (max 100) | § 1.5 |

Les filtres se posent **sur la même requête**, jamais l'un après l'autre en mémoire :
`total` doit compter ce que la liste montre, sans quoi la pagination ment.

Chaque ligne porte, en plus du résumé du § 6.5, `nouvellesPersonnes` : la liste des noms
(« Prénoms Nom ») des personnes que la demande ajoute à l'arbre, vide sinon — chargée en
une requête pour toute la page.

#### `GET /api/administration/demandes/auteurs`

Les auteurs qui ont **réellement déposé** quelque chose, pour peupler le filtre.

```json
{ "elements": [ { "userId": 3, "libelle": "Opale Romarin" } ] }
```

> **Une route à part, et non une clé de plus sur la file.** Le § 1.5 fixe l'enveloppe
> d'une liste paginée à quatre clés, et `FileEtDiffEnHttpTest` la tient « sans une clé
> de plus » : une enveloppe de liste qui varie selon l'écran cesse d'être une enveloppe
> de liste. Le premier jet l'y avait glissée ; le test l'a refusée, et il avait raison.
>
> Ce n'est pas non plus `GET /api/administration/utilisateurs` : celle-là porte **tous**
> les comptes, y compris ceux qui n'ont jamais rien proposé, et un sélecteur où presque
> toutes les entrées ne donnent aucun résultat est un sélecteur qu'on n'ouvre plus.
>
> Elle ne prend **aucun filtre** : changer de statut ne doit pas faire disparaître sous
> le doigt l'auteur qu'on venait de choisir.

`GET /api/administration/demandes/14` ajoute au § 6.5 tout ce qu'il faut pour décider :

```json
{
  "id": 14,
  "kind": "modification_personne",
  "status": "en_attente",
  "authorLabel": "Opale Romarin",
  "targetKind": "personne",
  "targetId": 17,
  "targetLabel": "Marne Dolomite Sureau",
  "message": "l'acte de Saule donne la fourchette",
  "submittedAt": "2026-09-14T11:02:00+02:00",
  "autoValidated": false,
  "ops": [
    {
      "seq": 1,
      "opKind": "champ",
      "subjectKind": "personne",
      "subjectId": 17,
      "field": "birth",
      "valueKind": "date_incertaine",
      "oldValue": { "raw": "1987", "qualifier": "annee", "min": "1987-01-01", "max": "1987-12-31", "place": null },
      "newValue": { "raw": "BET 1901 AND 1909", "qualifier": "entre", "min": "1901-01-01", "max": "1909-12-31", "place": "Saule, Ombrage, Sylvanie" },
      "baseVersion": 7,
      "valeurCourante": { "raw": "1987", "qualifier": "annee", "min": "1987-01-01", "max": "1987-12-31", "place": null, "texte": "1987" },
      "aBouge": false,
      "libelleDiff": "Naissance : 1987 → entre 1901 et 1909"
    }
  ],
  "cible": {
    "version": 8,
    "versionALaSoumission": 7,
    "aChangeDepuisSoumission": true
  },
  "resolutionPrevue": "rejeu",
  "demandesConcurrentes": [
    { "id": 13, "authorLabel": "Tuf Romarin", "kind": "modification_personne", "submittedAt": "2026-09-14T15:40:00+02:00", "champs": ["surname"] }
  ],
  "doublonsProbables": []
}
```

Trois blocs que le modèle suppose sans les nommer, et sans lesquels l'écran d'administration
ne peut pas exister :

- **`valeurCourante` et `aBouge`, par op.** Le § 9.6 décrit un bandeau « cette fiche a changé
  depuis la demande, avec ce qui a bougé » : le voici, calculé à la lecture, jamais stocké.
- **`cible.aChangeDepuisSoumission`** allume le bandeau ; `resolutionPrevue` dit ce qui se
  passera au clic :

  | `resolutionPrevue` | Sens | Ce que le front affiche |
  |---|---|---|
  | `"application"` | Aucune version n'a bougé. | « Valider » |
  | `"rejeu"` | Une version a bougé, **toutes** les `oldValue` correspondent encore. | « Valider (sera rejouée sur la version courante) » |
  | `"caducite"` | **Une** `oldValue` a changé. | « Cette demande est devenue caduque » — le bouton valider est désactivé |
  | `"cible_disparue"` | La ligne visée n'existe plus. | même chose : c'est une caducité |
  | `"dependance_en_attente"` | Elle désigne une fiche proposée par **une autre demande non tranchée** (§ 6.3 bis). | « À valider après la demande n° X » — désactivé, mais **pas** à refaire |
  | `"dependance_morte"` | Cette autre demande a été refusée, retirée ou rendue caduque. | une caducité : la personne désignée n'existera jamais |

  Les cinq valeurs sont **à traiter exhaustivement**. Le front les discrimine dans un `switch`
  sur l'union, et une valeur que le serveur rend mais que le client ignore ne tombe dans aucun
  cas — le calcul rend alors `undefined`, sur la seule demande qu'il fallait surtout ne pas
  valider.

  C'est un calcul en lecture seule, qui applique exactement la règle du § 9.6. Sans lui,
  l'administrateur découvre la caducité en cliquant, ce qui la rend indistinguable d'une
  panne.
- **`dependances`** — **toujours un tableau**, vide quand la demande ne s'appuie sur personne.
  Une entrée par fiche proposée ailleurs que les liens désignent, dédoublonnée :

  ```json
  "dependances": [
    { "draftPersonId": 7, "demandeId": 12, "libelle": "Barnabé Muguet", "etat": "en_attente" }
  ]
  ```

  Quatre états : `resolue` (l'autre demande est validée, la personne existe), `en_attente`
  (on attend), `morte` (refusée, retirée ou caduque), `disparue` (la ligne n'est plus là). Les
  résolues y figurent aussi : « s'appuie sur la demande n° 12, validée » est un contexte utile
  à qui décide, et le taire laisserait croire qu'une demande à l'arrêt l'est sans raison.
  C'est `resolutionPrevue` qui dit ce que le clic fera, jamais cette liste.
- **`doublonsProbables`** vient de `idx_prp_request_dedup` — les autres demandes `en_attente`
  de même `dedup_key` (§ 9.5). Il porte `{id, authorLabel, libelle, dedupKey}` ; il n'y a
  aucun `personId`, puisque la personne n'existe pas.

### 6.8 Valider — six issues, et deux 409 aux conseils opposés

`POST /api/administration/demandes/14/valider`, corps vide. Toute la séquence est
transactionnelle et **le contrôle est intégralement fait avant la première écriture**
(§ 9.4, point 2).

**Issue 1 — appliquée.** `200` :

```json
{
  "resultat": "validee",
  "demande": { "id": 14, "status": "validee", "decidedAt": "2026-09-15T09:31:00+02:00", "decidedByLabel": "Natron Romarin", "autoValidated": false },
  "revisions": [
    { "id": 210, "subjectKind": "personne", "subjectId": 17, "operation": "modification", "versionBefore": 8, "versionAfter": 9 }
  ],
  "empreinte": "1b7e…"
}
```

`empreinte` est la nouvelle empreinte de l'arbre (§ 2.3), reportée dans
`prp_revision.tree_fingerprint_after`. Le front s'en sert pour savoir que son `ETag` de graphe
est périmé sans attendre une revalidation.

**Issue 2 — rejouée automatiquement.** `200`, et c'est un **succès** :

```json
{
  "resultat": "rejouee",
  "demande": { "id": 14, "status": "validee", "decidedAt": "2026-09-15T09:31:00+02:00", "decidedByLabel": "Natron Romarin", "autoValidated": false },
  "revisions": [
    { "id": 210, "subjectKind": "personne", "subjectId": 17, "operation": "modification", "versionBefore": 8, "versionAfter": 9 }
  ],
  "rejeu": {
    "motif": "version_modifiee",
    "versionALaSoumission": 7,
    "versionAppliquee": 8,
    "champsVerifies": ["birth"]
  },
  "empreinte": "1b7e…"
}
```

Le verrou s'était déclenché, toutes les anciennes valeurs correspondaient encore : la demande
a été rejouée sur la version courante (§ 9.6). **Le front doit le dire** — c'est tracé au
journal sous `demande_rejouee` précisément parce qu'« une écriture qu'aucun humain n'a
réexaminée doit se voir » (§ 2.7). Un front qui affiche « validée » tout court efface cette
distinction.

**Issue 3 — caduque.** `409` :

```json
{
  "erreur": {
    "code": "demande_caduque",
    "message": "La demande est devenue caduque : la fiche a changé depuis son dépôt.",
    "details": {
      "demandeId": 14,
      "statut": "caduque",
      "rejouable": false,
      "champs": [
        {
          "field": "birth",
          "propose": { "raw": "BET 1901 AND 1909", "qualifier": "entre", "min": "1901-01-01", "max": "1909-12-31", "place": "Saule, Ombrage, Sylvanie", "texte": "entre 1901 et 1909" },
          "valeurALaSoumission": { "raw": "1987", "qualifier": "annee", "min": "1987-01-01", "max": "1987-12-31", "place": null, "texte": "1987" },
          "valeurCourante": { "raw": "ABT 1904", "qualifier": "environ", "min": "1904-01-01", "max": "1904-12-31", "place": null, "texte": "vers 1904" },
          "modifieePar": { "demandeId": 16, "auteur": "Natron Romarin", "le": "2026-09-15T08:12:00+02:00" }
        }
      ]
    }
  }
}
```

> **Trois précisions sur `details.champs[]`, toutes trouvées sur des charges capturées.**
>
> 1. **Ses blocs date portent `texte` — six clés, pas cinq.** `details.champs[]` est une
>    **sortie**, jamais une entrée : rien de ce qu'il contient n'est reposté. La règle du
>    § 14.1 vise `oldValue` et `newValue`, qui font l'aller-retour et dont la comparaison
>    `JSONB` tranche entre rejeu et caducité ; elle ne vise pas un corps d'erreur destiné à
>    être lu. Priver l'écran de la prose obligerait le front à reformater une date incertaine,
>    c'est-à-dire à réimplémenter `UncertainDate::format()` — et à en diverger.
> 2. **`modifieePar` est toujours présent, à `null` quand le serveur ne sait pas** qui a écrit
>    la valeur courante. Absent voudrait dire « tu n'as pas le droit de le savoir » (§ 1.3),
>    ce qui est faux : on l'ignore, c'est tout — une correction faite en base, un import, une
>    révision sans demande. Le front écrit `modifieePar?.auteur ?? "auteur inconnu"` sans avoir
>    à tester la présence de la clé.
> 3. **`champs` est toujours un tableau**, vide plutôt qu'absent quand la caducité ne vient pas
>    d'une valeur — `cible_disparue`, par exemple, où c'est `details.cible` qui porte
>    l'information.

> **C'est une `409` qui écrit.** Rien n'est appliqué à l'arbre — « on n'écrase jamais » — mais
> la demande **passe en `caduque`**, l'auteur est notifié (`demande_caduque`, avec le diff et
> le lien « refaire cette demande »), et le journal reçoit `demande_caduque`. Il faut l'écrire
> ici parce que c'est contre-intuitif : le front ne doit **pas** proposer « réessayer », il
> doit recharger la demande, qui n'est plus dans la file.
>
> `caduque` n'est **pas** `refusee` : personne n'a refusé, le monde a bougé (ADR 0004). Deux
> statuts, deux notifications, deux écrans.

**Issue 4 — cible disparue.** `409` `cible_disparue`, même forme, `details.statut: "caduque"`
et `details.cible: {kind, id, label}`. La personne visée a été supprimée ou fusionnée : c'est
une caducité, pas un refus (§ 9.5, passe 1). Ce cas est normalement traité **à l'événement**
publié par `Tree`, avant même qu'on ouvre la demande (ADR 0004) ; le contrôle à la validation
est la seconde barrière.

**Issue 5 — verrou concurrent.** `409` :

```json
{
  "erreur": {
    "code": "verrou_concurrent",
    "message": "Une autre écriture a touché cette fiche pendant la validation. Rien n'a été écrit.",
    "details": { "demandeId": 14, "statut": "en_attente", "rejouable": true }
  }
}
```

C'est l'`OptimisticLockException` du verrou n° 1 (§ 9.6) : deux transactions **concurrentes**
— deux administrateurs, ou une validation qui croise un import. **Rien n'a été écrit, la
demande reste `en_attente`, et réessayer est la bonne action.**

> **Les deux `409` donnent des conseils opposés, et c'est pour cela qu'elles ont deux codes.**
> `demande_caduque` : ne pas réessayer, la demande est morte. `verrou_concurrent` : réessayer,
> il ne s'est rien passé. `details.rejouable` porte la même information sous forme booléenne
> pour que le front n'ait pas à connaître la liste des codes. Un front qui afficherait un
> bouton « réessayer » sur les deux ferait boucler l'administrateur sur une demande caduque.

**Issue 6 — déjà décidée.** `409` `demande_deja_decidee`, avec `details.statut` : on ne valide
pas deux fois, et un double clic ne doit pas produire deux révisions.

**Issue 7 — dépendance en attente.** `409` :

```json
{
  "erreur": {
    "code": "dependance_en_attente",
    "message": "Cette demande s'appuie sur une personne proposée par une demande encore en attente : validez celle-là d'abord.",
    "details": {
      "demandeId": 19,
      "statut": "en_attente",
      "rejouable": true,
      "dependances": [
        { "draftPersonId": 7, "demandeId": 12, "libelle": "Barnabé Muguet", "etat": "en_attente" }
      ]
    }
  }
}
```

**La seule `409` qui n'écrit rien du tout, pas même un statut.** La demande désigne une fiche
proposée par une autre demande non tranchée (§ 6.3 bis) : il y a un **ordre** à respecter, pas
une demande à condamner. Elle reste `en_attente`, son auteur n'est pas notifié — il n'a rien à
corriger —, et elle se validera **telle quelle** dès que l'autre sera tranchée.

`rejouable: true`, comme pour `verrou_concurrent` et pour la même raison : le geste refusé
redeviendra possible sans rien changer. Ce qui diffère, c'est **quand** — d'où les dépendances
nommées, pour que le front dise « validez d'abord la demande n° 12 » plutôt que « réessayez »
sans dire quand.

**Issue 8 — dépendance morte.** `409` `dependance_morte`, même forme, `details.statut:
"caduque"` et `rejouable: false`. La demande citée a été refusée, retirée ou rendue caduque —
ou sa fiche a disparu : la personne désignée ne naîtra jamais. C'est une caducité de la même
nature que `cible_disparue`, elle **écrit** comme elle (statut `caduque`), et l'auteur est
notifié — avec une phrase qui nomme la proposition perdue, et non un diff de champs, puisque
rien n'a bougé sur aucune fiche.

### 6.9 Refuser — message obligatoire

```json
{ "message": "Cette union est déjà enregistrée sous la famille n° 28.", "doublonDeId": 11 }
```

- `message` **obligatoire**, non vide après `btrim`. Absent ⇒ `422` avec
  `champs: {"message": "Le motif du refus est obligatoire."}`. Ce n'est pas une validation de
  formulaire : la contrainte `ck_prp_request_motif_refus` refuse la ligne en base (§ 5.1). Le
  front ne doit pas être le seul à la connaître, et l'API ne doit pas compter sur le front.
- `doublonDeId` renseigne `duplicate_of_id` : l'auteur reçoit « votre proposition rejoint la
  demande n° 11 » plutôt qu'un refus sec (§ 6.4 du dossier). La demande visée doit exister et
  ne pas être la demande refusée elle-même ⇒ `422` sinon.
- Le message part dans `core_notification.body` et parvient à l'auteur (§ 2.5). Une demande
  refusée **reste consultable par son auteur, avec le motif** (§ 6.3 du dossier) : elle n'est
  jamais supprimée.

### 6.10 Refaire une demande caduque

`GET /api/demandes/14/refaire` — lecture seule, **aucune écriture**. Rend un corps de
création prêt à être posté sur `POST /api/demandes`, pré-rempli sur les **valeurs courantes** :

```json
{
  "kind": "modification_personne",
  "message": "l'acte de Saule donne la fourchette",
  "soumettre": false,
  "targetKind": "personne",
  "targetId": 17,
  "ops": [
    {
      "seq": 1,
      "opKind": "champ",
      "subjectKind": "personne",
      "subjectId": 17,
      "field": "birth",
      "valueKind": "date_incertaine",
      "oldValue": { "raw": "ABT 1904", "qualifier": "environ", "min": "1904-01-01", "max": "1904-12-31", "place": null },
      "newValue": { "raw": "BET 1901 AND 1909", "qualifier": "entre", "min": "1901-01-01", "max": "1909-12-31", "place": "Saule, Ombrage, Sylvanie" },
      "baseVersion": 9
    }
  ],
  "origine": { "demandeId": 14, "statut": "caduque" }
}
```

`oldValue` et `baseVersion` sont **rafraîchis** ; `newValue` est conservé. C'est ce que le
bouton « refaire cette demande » du § 9.6 recouvre — « une caducité sans ce bouton est une
contribution perdue, et un membre dont la première contribution se perd n'en dépose pas de
seconde ».

Disponible sur `caduque` et `refusee`. Sur `validee` ou `en_attente` ⇒ `409`
`demande_non_refaisable`.

#### Refaire une suppression — le corps rendu ne porte **pas** l'op

La boucle de reprise ignore les ops sans `field`, ce qu'une op de suppression est par
construction (`ck_prp_op_champ`). Le corps rendu porte donc `kind`, `targetKind`,
`targetId`, `message` et `"ops": []`, et il est **redéposable tel quel** : le constructeur
de `suppression_personne` ne lit pas `ops`, il remesure l'inventaire.

Ce n'est pas une perte, c'est le comportement voulu. Une suppression devient caduque
**précisément parce que ce qu'elle emportait a changé** — une note, une citation ou une
preuve est arrivée depuis le dépôt. Recopier l'inventaire d'alors remettrait sous les yeux
de l'auteur l'état périmé qui a tué sa première demande, et le motif qu'il avait écrit en
le regardant. L'écran relit, montre ce qu'il en est maintenant, et redemande un motif.

C'est aussi pourquoi le front n'ajoute pas `?refaire=<id>` sur ce seul écran : il n'y a
rien à pré-remplir qu'il ne doive relire.

#### Refaire une création de personne ou un ajout de lien

La route couvre **toutes les natures de `RequestKind`**, pas seulement les modifications. Pour
`creation_personne` et `ajout_lien`, le corps rendu est celui du § 6.3 ou du § 6.4, et la
règle de reprise est plus simple qu'elle n'en a l'air :

> **La fiche et les liens repartent tels quels.** Il n'y a ni `oldValue` ni `baseVersion` à
> rafraîchir, parce qu'il n'y a **pas d'ancienne valeur** : rien de ce que le brouillon décrit
> n'existe encore, donc rien n'a pu bouger sous lui.

```json
{
  "kind": "creation_personne",
  "message": "épouse de Jargon, j'ai le livret de famille",
  "soumettre": false,
  "brouillons": [
    {
      "localRef": "#p1",
      "givenNames": "Tourmaline",
      "surname": "Muguet",
      "marriedName": "Sarriette",
      "nickname": null,
      "sex": "feminin",
      "deceased": true,
      "birth": { "raw": "ABT 1889", "qualifier": "environ", "min": "1889-01-01", "max": "1889-12-31", "place": "Saule, Ombrage, Sylvanie" },
      "death": { "raw": "1972", "qualifier": "annee", "min": "1972-01-01", "max": "1972-12-31", "place": null },
      "note": "Livret de famille en ma possession, photographié."
    }
  ],
  "liens": [
    {
      "seq": 1,
      "kind": "conjoint_de",
      "subjectDraftRef": "#p1",
      "otherPersonId": 55,
      "marriage": { "raw": "23 SEP 1927", "qualifier": "exacte", "min": "1927-09-23", "max": "1927-09-23", "place": "Saule, Ombrage, Sylvanie" },
      "divorced": false,
      "divorce": null,
      "note": "date du livret"
    }
  ],
  "medias": [
    { "mediaId": 121, "subjectDraftRef": "#p1", "caption": "Livret de famille, p. 2" }
  ],
  "origine": { "demandeId": 12, "statut": "caduque" },
  "avertissements": []
}
```

Quatre règles, dans l'ordre où elles se trompent :

1. **`localRef` est conservé** (`#p1` reste `#p1`). Changer la référence provisoire n'aurait
   aucun bénéfice et casserait le rattachement des médias, qui la portent (point 4).
2. **`resolvedPersonId` et `resolvedFamilyId` ne sont jamais repris.** C'est le point à ne pas
   perdre, et son motif tient en une phrase :

   > **Une reprise redécrit une *intention*, pas un résultat.**

   La famille est **déduite à la validation** : désigner un conjoint sans famille existante en
   crée une (§ 6.4 du dossier, § 9.5). Reporter l'identifiant de famille résolu d'une tentative
   précédente figerait un résultat qui n'a peut-être plus lieu d'être — une autre demande a pu
   créer cette famille entre-temps, ou la validation précédente en avait choisi une qui n'est
   plus la bonne. **La demande refaite doit redire « conjoint de X », pas « rattaché à la
   famille 47 ».**
3. **`family_id` en revanche est conservé, et ce n'est pas une contradiction.** Les deux
   colonnes portent un identifiant de famille et se confondent facilement :
   `prp_draft_link.family_id` est **l'intention** de l'auteur (« enfant de ce couple
   existant », qu'il a désigné à l'écran) ; `resolved_family_id` est **le résultat** de la
   déduction. On reprend la première, jamais la seconde. Si la famille désignée a disparu, on
   tombe dans le point 4.
4. **Toute référence réelle est re-résolue à la lecture.** `otherPersonId`, `other2PersonId`,
   `familyId`, `subjectPersonId` sont vérifiés auprès de `Tree` **au moment du `GET`**. Une
   référence disparue — souvent la cause même de la caducité (`cible_disparue`, § 6.8) — est
   **retirée du corps** et signalée dans `avertissements` :

   ```json
   "avertissements": [
     { "code": "reference_disparue", "message": "Jargon Sarriette n'existe plus dans l'arbre ; le lien « conjoint de » a été retiré de la reprise.", "lien": 1 }
   ]
   ```

   Le corps rendu est donc toujours **postable en l'état**. Rendre une reprise qui échouerait
   en `422` au premier `POST` serait offrir un bouton qui ne marche pas, c'est-à-dire pire que
   pas de bouton.

#### Les médias attachés à une création

Un média joint à une personne proposée porte `subject_draft_ref = '#p1'` **et**
`subject_request_id = 12` : « une référence provisoire n'a de sens que dans le contexte de
**sa** demande » (§ 6.1, `ck_med_media_brouillon_contexte`). Or une reprise crée une demande
**neuve**, avec un **nouvel identifiant** — la référence pointe donc vers la mauvaise demande.

> **Décision : les médias suivent.** Au `POST` de la reprise, le service repointe
> `subject_request_id` des médias `en_attente` de la demande d'origine vers la demande neuve,
> à `subject_draft_ref` inchangé. C'est `origine.demandeId` qui déclenche ce transfert — c'est
> sa seule fonction, et c'est pour cela qu'il faut le renvoyer.

Conditions, toutes vérifiées avant transfert : la demande d'origine est `caduque` ou
`refusee`, le lecteur en est l'auteur, les médias sont `en_attente`, et le `localRef` cité
existe dans les `brouillons` de la reprise. Un média qui ne remplit pas ces conditions n'est
pas transféré et sort dans `avertissements`.

Le `GET .../refaire` annonce ce qui suivra dans `medias` — `mediaId`, `subjectDraftRef` et
`caption`, assez pour que le front affiche les vignettes avant de poster.

**Pourquoi transférer plutôt que perdre.** Un acte photographié est le travail le plus coûteux
d'une contribution, et une caducité est déjà une contribution à moitié perdue. Faire
retéléverser serait la perdre entièrement, sur un mécanisme dont toute la raison d'être est
l'inverse (§ 9.6).

**La conséquence à assumer** : la demande d'origine ne liste plus ces médias — ils ont
changé de demande, et rien en base n'enregistre qu'ils sont partis vers la reprise. La
consultation d'une demande caduque montre alors un dossier amputé de ses pièces. Il n'y a pas
de colonne pour dire « repris dans la demande n° 21 » — et pas davantage pour le chaînage de
la reprise elle-même : `origine` est écrit dans `message`, en texte.
`prp_change_request.duplicate_of_id` **ne convient pas** — il sert au rapprochement de
doublons entre deux auteurs, et le charger d'un second sens rendrait les deux illisibles.
*Point signalé au § 18 : si le commanditaire veut voir « votre demande n° 12 a été refaite en
n° 21 », c'est une colonne à ajouter, et ce n'est pas à ce document de la décider.*

### 6.11 L'historique d'une fiche

`GET /api/demandes/historique/personne/17` — les `prp_revision` de cette ligne, du plus récent
au plus ancien (`idx_prp_revision_sujet`). `subjectKind` accepte les six valeurs de
`SubjectKind` (§ 5.7).

```json
{
  "elements": [
    {
      "id": 210,
      "operation": "modification",
      "subjectKind": "personne",
      "subjectId": 17,
      "subjectLabel": "Marne Dolomite Sureau",
      "changes": { "birth": { "avant": "1987", "apres": "entre 1901 et 1909" } },
      "versionBefore": 8,
      "versionAfter": 9,
      "actorLabel": "Natron Romarin",
      "requestId": 14,
      "appliedAt": "2026-09-15T09:31:00+02:00"
    }
  ],
  "total": 4,
  "page": 1,
  "parPage": 25
}
```

`changes` est rendu **formaté** (`format()` sur les blocs date), pas en brut : c'est un
historique lisible, pas un dump. `requestId` à `null` signale un import GEDCOM, jamais un
raccourci d'écriture — « il n'existe aucun chemin d'écriture applicatif qui contourne le
mécanisme » (§ 5.5).

L'historique d'une personne **vivante hors cercle** n'est pas accessible : `404`. Il porte des
dates et des lieux, c'est-à-dire tout ce que la liste blanche exclut. Même `404` pour
l'historique d'une **famille** dont un époux l'est : ses révisions datent l'union. *(La route
servait ces révisions jusqu'au 2026-10-01 ; le refus est désormais tenu par
`ValeursMasquees::sujetFerme()`.)*

**Les libellés d'une demande suivent le cercle du lecteur** (révision du 2026-10-01 de
l'ADR 0003). `targetLabel`, `liens[].subjectLabel` / `otherLabel` / `other2Label`,
`liens[].familyLabel` et `revisions[].subjectLabel` sont **enregistrés** au dépôt avec le
vrai nom ; une demande de l'assistant peut viser un vivant hors du cercle de son auteur. Relue
par un membre (§ 6.5, la liste, le détail d'un nœud en attente), chaque libellé qui désigne un
tel vivant vaut `"Personne vivante"` — dans `familyLabel`, l'époux concerné seulement
(`"Personne vivante × Marne Dolomite Sureau"`). Une révision sur un tel vivant perd en outre
la clé `avant` de chacun de ses `changes`, comme l'op perd `oldValue`. L'administrateur, dont
le cercle est complet, lit les libellés stockés.

---

## 7. Médias

Module `Media`. Portraits et preuves. **Stockage hors racine web**, servi par une route
authentifiée qui applique le cercle (§ 6.5 du dossier).

| Méthode | Chemin | Accès | Corps | Succès | Erreurs | Débit |
|---|---|---|---|---|---|---|
| `POST` | `/api/medias` | `MEMBRE` | `multipart/form-data`, § 7.1 | `201` § 7.2 | `413` `fichier_trop_volumineux` · `415` `type_de_fichier_refuse` · `422` · `403` `regime_en_attente` · `429` | 20 / h |
| `POST` | `/api/medias/portrait-du-compte` | `ATTENTE` | `multipart/form-data`, champ `fichier` seul | `201` § 7.2 | `409` `compte_non_rattache` · `409` `vue_comme_membre_active` · `413` · `415` · `422` · `429` | 20 / h |
| `GET` | `/api/medias` | `MEMBRE` | `?subjectKind=&subjectId=&kind=&status=` | `200` liste non paginée | `403` `regime_en_attente` · `404` · `422` | — |
| `GET` | `/api/medias/{id}` | `MEMBRE` ¹ | — | `200` § 7.2 | `403` `regime_en_attente` · `404` | — |
| `GET` | `/api/medias/{id}/fichier` | `MEMBRE` ¹ | — | `200` **octets** | `403` `regime_en_attente` · `404` | — |
| `GET` | `/api/medias/{id}/vignette` | `MEMBRE` ¹ | — | `200` **octets** | `403` `regime_en_attente` · `404` | — |
| `DELETE` | `/api/medias/{id}` | `ADMIN` | — | `200` `{"supprime": true, "id": 120}` | `404` · `409` | — |

¹ **Révision du 2026-10-01 (§ 12).** Un compte en attente ne lit qu'un média **qu'il a
déposé lui-même** — sa photo d'inscription, que l'écran d'attente lui remontre. Tout autre
identifiant lui rend `403` `regime_en_attente`, **qu'il existe ou non** : un `404` sur
l'inexistant et un `403` sur l'existant feraient de la route un oracle d'énumération. La liste
(`GET /api/medias`) lui est fermée en entier.

### 7.1 Téléverser

`multipart/form-data`, un seul fichier par requête.

| Partie | Type | Rôle |
|---|---|---|
| `fichier` | binaire | Le fichier. Obligatoire. |
| `kind` | texte | `portrait` ou `preuve`. |
| `subjectKind` | texte | **Toujours obligatoire**, dans les deux formes : `personne`, `famille`, `citation`. Voir l'encadré ci-dessous. |
| `subjectId` | entier | La ligne visée. **Exclusif** avec le couple suivant. |
| `subjectRequestId` + `subjectDraftRef` | entier + texte | Quand le média est joint à une personne **pas encore créée** : `12` + `"#p1"` — **avec le dièse**, voir l'encadré de nommage ci-dessous. |
| `caption` | texte | Légende, 255 caractères. |
| `taken` | texte (JSON) | Bloc date à 5 clés (§ 14), sérialisé. Facultatif. |

> **`subjectKind` accompagne toujours le couple `subjectRequestId` + `subjectDraftRef`, et il vaut
> `"personne"`.** Il n'est jamais omis, et il n'existe **pas** de quatrième valeur
> `"brouillon"`.

Deux faits du schéma le décident, et il n'y a donc rien à arbitrer :

- `med_media.subject_kind` est **`NOT NULL`** (§ 6.1). Un média rattaché à un brouillon est
  une ligne comme une autre : la colonne doit porter une valeur.
- **Le seul brouillon qui existe est un brouillon de personne.** `prp_draft_person` n'a pas
  d'équivalent pour la famille ni pour la citation — « il n'existe pas de brouillon de
  famille » (§ 9.5). `"personne"` est donc la seule valeur possible, pas un défaut commode.

Ajouter `"brouillon"` à l'énumération `MediaSubject` serait doublement faux : ce serait une
migration de données pour une valeur qui ne décrit pas un **type de sujet** mais un **état de
résolution** — que la paire `subject_id` / `subject_draft_ref` porte déjà (§ 9.5) — et cela
casserait la validation, qui se contente de poser `subject_id` et d'effacer
`subject_draft_ref` : `subject_kind` **ne change pas**, avant comme après. Une ligne qui
aurait porté `"brouillon"` devrait être réécrite, et le jour où on l'oublierait, le média
disparaîtrait de la fiche sans erreur.

`subjectLabel` est `NOT NULL` lui aussi. Pour un brouillon, **le serveur le compose** depuis
`prp_draft_person` (`givenNames` + `surname`) ; le client ne l'envoie pas, et un client qui
l'enverrait le verrait ignoré. À la validation, il est repris depuis le `display_name` de la
personne créée (§ 9.5, passe 6).

> **Nommage : `subjectRequestId` / `subjectDraftRef`, et non `demandeId` / `brouillonRef`.**
> Une première rédaction de ce document employait les seconds. C'était une **entorse à sa
> propre règle** (§ 1.2) : ces deux clés reflètent les colonnes `med_media.subject_request_id`
> et `med_media.subject_draft_ref`, elles portent donc le nom de la propriété Doctrine. Le
> préfixe `subject` n'est pas décoratif — il les rattache à `subjectKind` et `subjectId`, dont
> elles sont les frères : les quatre décrivent **le sujet d'un média**, et deux d'entre elles
> ne peuvent pas s'appeler autrement que les deux autres.
>
> **La valeur porte le dièse** : `"#p1"`, comme `prp_draft_person.local_ref` en base (§ 5.3).
> Le contrat ne retire le dièse qu'à un seul endroit, et pour une raison mécanique — le
> segment d'URL de `GET /api/demandes/12/brouillons/p1`, où `#` est le séparateur de fragment
> (§ 6.6).
>
> **À ne pas confondre avec les clés du nœud de graphe.** Un nœud en attente porte
> `demandeId` et `brouillonRef`, qui ne reflètent aucune colonne et restent donc en français.
> Ce n'est pas la même relation : sur un média, `subjectDraftRef` dit « le **sujet** de ce
> média est le brouillon #p1 » ; sur un nœud, `brouillonRef` dit « ce nœud **est** le
> brouillon p1 ». Référence contre identité.
>
> | Où | Clés | Valeur | Pourquoi |
> |---|---|---|---|
> | Média (requête et réponse) | `subjectRequestId`, `subjectDraftRef` | `12`, `"#p1"` | reflètent des colonnes → nom de propriété Doctrine (§ 1.2) |
> | Nœud du graphe | `demandeId`, `brouillonRef` | `12`, `"p1"` | aucune colonne → français ; `brouillonRef` alimente l'URL, donc sans dièse (§ 5.2) |
> | Segment d'URL | — | `p1` | `#` y est le séparateur de fragment |

Contrôles d'entrée, tous obligatoires (§ 6.5 du dossier) et tous reflétés dans la réponse :

1. **Type réel déterminé par les octets d'en-tête**, jamais par l'extension ni par le
   `Content-Type` annoncé. Hors de `media.mimes_autorises` (§ 2.2) ⇒ `415`
   `type_de_fichier_refuse`. Le message ne dit **pas** quel type a été détecté sur un fichier
   refusé : c'est un service de reniflage gratuit.
2. **Taille** : au-delà de `media.taille_max_octets` (20 Mio) ⇒ `413`. Le plafond est aussi
   posé dans nginx et dans PHP ; les trois doivent concorder, sinon le refus arrive sans
   corps JSON et le front affiche une erreur muette.
3. **Ré-encodage de l'image pour purger l'EXIF** — « une photo de famille non nettoyée publie
   l'adresse du domicile ». Le résultat est dans `exifStripped`.
4. **Nom de fichier aléatoire** et **SHA-256** du fichier stocké.
5. **Vignette** générée à l'enregistrement.

Le média naît `status: "en_attente"` et suit **le même circuit de validation** que les autres
modifications : il n'est visible que de son déposant et des administrateurs jusqu'à ce qu'une
`ChangeRequest` de nature `ajout_media` (§ 6.4) soit validée. Un dépôt direct d'administrateur
naît `actif`.

#### Le portrait du compte — `POST /api/medias/portrait-du-compte`

Un seul champ, `fichier`. Le portrait de **sa propre** personne, sans demande : c'est une
décision du commanditaire (« c'est ton visage »). Les contrôles d'entrée sont ceux ci-dessus,
sans exception. Le comportement dépend du compte :

| Situation du compte | Comportement |
|---|---|
| Personne validée (membre `actif`) | Le portrait est posé sur la fiche et **devient actif** ; l'ancien passe `remplace`. |
| Rattachement **en attente** — sans fiche (`a_rattacher`) ou recherche confirmée non validée | **Dépôt sur le compte** (ADR 0013) : `status: "en_attente"`, `subjectId: null`, `subjectLabel: "Portrait en attente de rattachement"`. Aucun contrôle de régime : c'est sa propre photo, elle n'écrit dans aucune fiche. Un portrait en attente plus ancien du même compte est **supprimé**, fichier compris. |
| Pas de rattachement (compte d'amorçage), ou membre suspendu | `409` `compte_non_rattache`. |
| Sous la bascule « voir comme » | `409` `vue_comme_membre_active`. |

Un portrait posé sur un compte n'est visible que de **son titulaire et des administrateurs**
(la règle ordinaire d'un média sans sujet, § 7.4). Il n'entre **ni** dans la file de
validation **ni** dans une demande `ajout_media` (`422`) : il ne rejoint une fiche qu'au
rattachement du compte, où il devient le portrait actif (§ 10.1). Au refus de l'inscription,
il est supprimé.

### 7.2 La charge d'un média

```json
{
  "id": 120,
  "kind": "preuve",
  "subjectKind": "personne",
  "subjectId": 17,
  "subjectLabel": "Marne Dolomite Sureau",
  "mimeType": "image/jpeg",
  "byteSize": 842113,
  "width": 2400,
  "height": 1600,
  "pageCount": null,
  "exifStripped": true,
  "status": "en_attente",
  "isActivePortrait": false,
  "caption": "Acte n° 3, Épinaille 1910",
  "taken": {
    "raw": "ABT 1910", "qualifier": "environ",
    "min": "1910-01-01", "max": "1910-12-31",
    "place": "Épinaille, Fougerie, Florie",
    "texte": "vers 1910"
  },
  "uploadedByLabel": "Cobalt Romarin",
  "createdAt": "2026-09-15T21:04:00+02:00",
  "url": "/api/medias/120/fichier",
  "vignetteUrl": "/api/medias/120/vignette",
  "changeRequestId": 15,
  "avertissements": [
    {
      "code": "doublon_probable",
      "message": "Ce fichier est déjà dans l'arbre, sur la fiche de Jargon Sarriette.",
      "mediaId": 88
    }
  ]
}
```

Le même média, **joint à une personne proposée** (§ 6.3) — noter `subjectKind: "personne"`
alors que `subjectId` est `null` :

```json
{
  "id": 121,
  "kind": "preuve",
  "subjectKind": "personne",
  "subjectId": null,
  "subjectRequestId": 12,
  "subjectDraftRef": "#p1",
  "subjectLabel": "Tourmaline Muguet",
  "mimeType": "image/jpeg",
  "byteSize": 512804,
  "width": 1800,
  "height": 1200,
  "pageCount": null,
  "exifStripped": true,
  "status": "en_attente",
  "isActivePortrait": false,
  "caption": "Livret de famille, p. 2",
  "taken": null,
  "uploadedByLabel": "Cobalt Romarin",
  "createdAt": "2026-09-14T11:00:00+02:00",
  "url": "/api/medias/121/fichier",
  "vignetteUrl": "/api/medias/121/vignette",
  "changeRequestId": null,
  "avertissements": []
}
```

`subjectId` est `null` — présent, pas absent : le lecteur a le droit de le voir, il n'est
simplement pas encore attribué (§ 1.3). Après validation, la même route rend
`subjectId: 312`, `subjectRequestId: null`, `subjectDraftRef: null`, `subjectLabel` repris du
`display_name`, et `status: "actif"` — `subjectKind` n'ayant pas bougé.

- **`storageName`, `thumbnailName`, `originalSha256` et tout chemin de disque sont absents**,
  toujours, pour tout le monde, administrateur compris (§ 16). Un nom devinable rend le
  stockage énumérable même hors racine web (§ 6.1).
- `originalName` n'est rendu **que** au déposant et aux administrateurs : un nom de fichier
  d'appareil photo porte souvent une date et parfois un nom.
- Le **doublon est un avertissement, pas un refus** : le même acte de mariage étaye
  légitimement les fiches des deux époux et de leurs quatre parents (§ 6.1). `201`, pas `409`.
- `sha256` est rendu aux administrateurs seulement — le demandeur en a besoin pour la valeur
  d'op `media` (§ 6.4), il le reçoit donc aussi sur **son** média. Un tiers ne le voit pas :
  c'est une empreinte qui permet de tester la présence d'un fichier qu'on possède déjà.

### 7.3 Lister

`GET /api/medias?subjectKind=personne&subjectId=17` — tableau nu (§ 1.5), borné par le sujet.

`status` par défaut : `actif` seul. Un membre qui demande `status=en_attente` ne reçoit **que
les siens** ; un administrateur reçoit tous ceux en attente — c'est sa file de validation
(`idx_med_media_attente`).

### 7.4 et 7.5 Servir le fichier et la vignette

`GET /api/medias/120/fichier` rend les **octets**, avec le `Content-Type` réel constaté à
l'entrée, `Content-Disposition: inline; filename="…"`, et le régime de cache **C** du § 1.8 :
`Cache-Control: private, no-cache`, `Vary: Cookie`, `ETag` valant le `sha256` du fichier. Le
contrôle d'accès ci-dessous s'exécute **avant** toute comparaison d'`ETag` : un lecteur qui a
perdu l'accès reçoit `404`, jamais `304`.

> **Jamais de lien direct vers le système de fichiers, jamais de redirection** vers un
> `/uploads/...`. Une `302` vers une URL statique servirait le fichier sans repasser par le
> cercle de visibilité, et le lien ainsi obtenu serait partageable.

Le cercle est appliqué ici comme partout :

| Sujet du média | Lecteur | Réponse |
|---|---|---|
| Personne décédée | tout compte connecté | `200`, les octets |
| Personne vivante dans le cercle | membre actif | `200` |
| Personne vivante **hors cercle** | membre actif | **`404`** |
| N'importe qui | compte `en_attente`, sujet vivant | **`404`** |
| Média `en_attente` | quelqu'un d'autre que le déposant ou un administrateur | **`404`** |

**`404` et non `403`** : c'est la règle générale de l'API (§ 15.3). Une ressource que le
lecteur n'a pas le droit de voir est introuvable ; un `403` confirmerait qu'elle existe, et
sur un média cela confirmerait qu'une personne masquée a un portrait.

#### Exigence testable — l'ordre entre le voter et l'`ETag`

Le régime de cache **C** (§ 1.8) donne un `ETag` à ces deux routes. L'invariant qui le rend
sûr n'est pas une recommandation, c'est **six cas à écrire en test fonctionnel**. Une règle
qui n'a pas de test qui la garde est une règle qu'un profilage bien intentionné supprimera
dans six mois, et aucun autre test ne rougira.

| Cas | Montage | Requête | Attendu |
|---|---|---|---|
| **MED-1** | média `actif`, sujet décédé, lecteur membre actif | `If-None-Match` = l'`ETag` servi précédemment | **`304`**, sans corps |
| **MED-2** | idem | `If-None-Match` absent, ou différent | **`200`** + les octets + `ETag` |
| **MED-3** | média `actif`, sujet **vivant**, lecteur **qui vient d'en sortir** — `cercle.generations` est passé de 3 à 1 entre les deux requêtes | `If-None-Match` = l'`ETag` **qu'il détenait et qui est toujours le bon** | **`404`**, enveloppe d'erreur `introuvable`. **Jamais `304`** |
| **MED-4** | même sujet vivant hors cercle, média `actif` | `If-None-Match` correspondant | **`404`** |
| **MED-5** | compte `en_attente`, sujet vivant | `If-None-Match` correspondant | **`404`** |
| **MED-6** | média `en_attente` **d'un autre membre**, lecteur membre actif | `If-None-Match` correspondant | **`404`** |

**MED-3 est le cas qui porte la règle**, et c'est celui qu'on n'écrit pas spontanément : le
fichier n'a pas changé, l'`ETag` du lecteur est *exact*, et la réponse doit quand même être
`404`. Il échoue dès qu'une implémentation compare l'`ETag` avant d'appeler le voter — ce qui
est l'optimisation naturelle, puisqu'elle évite un accès au disque et un calcul de cercle.

> **Le voter s'exécute avant la comparaison d'`If-None-Match`, toujours.** Un `304` est une
> réponse : il apprend à son destinataire que la ressource **existe encore et n'a pas
> changé**. Servi à quelqu'un qui vient de perdre l'accès, c'est une fuite — petite, mais
> exactement du type que le § 16 interdit, et d'autant plus durable qu'elle ne transporte
> aucun octet et n'apparaît dans aucune capture de charge utile.

Les mêmes six cas valent pour `/vignette`, avec le même `ETag` que le fichier d'origine.
`ETag` et `sha256` étant identiques (§ 1.8), MED-1 et MED-2 se montent sans dépendre d'un
rendu d'image.

La seule exception à cette règle est la **fiche** d'une personne vivante hors cercle, qui rend
`200` en version réduite (§ 5.1) — parce que son nœud est visible dans le graphe et qu'il faut
pouvoir cliquer dessus.

### 7.6 Supprimer

`DELETE /api/medias/{id}` — `ADMIN` seulement. Un membre ne supprime pas un média validé : il
dépose une demande. Sur un média `actif` rattaché à une citation encore référencée ⇒ `409`
`media_reference`. Un refus n'est pas une destruction : un média `refuse` garde son fichier
sur disque jusqu'à purge explicite (§ 6.3).

---

## 8. Notifications

Module `Core`.

| Méthode | Chemin | Accès | Corps | Succès | Erreurs |
|---|---|---|---|---|---|
| `GET` | `/api/notifications` | `ATTENTE` | `?nonLues=1&page=&parPage=` | `200` liste paginée | `401` |
| `GET` | `/api/notifications/compteur` | `ATTENTE` | — | `200` `{"nonLues": 3}` | `401` |
| `POST` | `/api/notifications/{id}/lue` | `ATTENTE` | *(vide)* | `200` notification | `404` |
| `POST` | `/api/notifications/lues` | `ATTENTE` | *(vide)* | `200` `{"marquees": 3}` | `401` |

```json
{
  "elements": [
    {
      "id": 44,
      "kind": "demande_caduque",
      "title": "Votre demande sur Marne Dolomite Sureau est devenue caduque.",
      "body": "Vous proposiez « 1987 → entre 1901 et 1909 », mais la fiche porte désormais « vers 1904 » (demande n° 16, validée le 15 septembre par Natron Romarin).",
      "linkRoute": "demande.detail",
      "linkParams": { "id": 14 },
      "readAt": null,
      "createdAt": "2026-09-15T09:31:00+02:00"
    }
  ],
  "total": 6,
  "page": 1,
  "parPage": 25
}
```

- `title` et `body` sont **déjà rendus** en base : la notification ne se recompose pas à la
  lecture, « sinon un changement de libellé réécrit l'histoire » (§ 2.5). L'API les rend tels
  quels, sans interpolation.
- `linkRoute` + `linkParams` plutôt qu'une URL construite : une refonte du routage front ne
  casse pas les notifications d'hier (§ 2.5). Le front résout la route par son nom ; une route
  inconnue (notification ancienne) n'est pas un lien, pas une erreur.
- **`title` et `body` peuvent nommer une personne.** C'est une porte de sortie (ADR 0003,
  porte n° 6) : le destinataire est toujours l'auteur ou un administrateur, donc le
  rapprochement est légitime — mais le service qui **compose** le texte applique le cercle du
  **destinataire** avant d'écrire la ligne. Une notification écrite une fois se relit
  longtemps ; le filtre ne peut pas être appliqué à la lecture, il doit l'être à l'écriture.
- Le compteur est la requête la plus chaude de l'API (chaque chargement de page) et repose sur
  `idx_core_notif_non_lues`. Il ne renvoie **qu'un nombre**.
- `POST /api/notifications/{id}/lue` sur une notification d'un autre compte ⇒ `404`.

---

## 9. Chronologie

Module `Timeline`. Onglet distinct, **pas** une variante de l'arbre (§ 7.4 du dossier). Elle
n'utilise pas le moteur de placement : elle place sur un axe de temps (ADR 0005).

| Méthode | Chemin | Accès | Corps | Succès | Erreurs |
|---|---|---|---|---|---|
| `GET` | `/api/chronologie` | `MEMBRE` | `?bande=&orientation=&branches=` | `200` § 9.1 | `403` `regime_en_attente` · `422` `donnees_invalides` |
| `GET` | `/api/chronologie/preferences` | `MEMBRE` | — | `200` préférence | `401` · `403` `regime_en_attente` |
| `PUT` | `/api/chronologie/preferences` | `MEMBRE` | § 9.2 | `200` préférence | `403` `regime_en_attente` · `422` |

### 9.1 Les données de la vue

`GET /api/chronologie?bande=25&orientation=colonnes&branches=pm,mm`

| Paramètre | Défaut | Valeurs |
|---|---|---|
| `bande` | `tml_preference.band_years`, sinon `timeline.bande_annees_defaut` (10) | entier **1 à 500** (`ck_tml_preference_bande`) ; l'interface propose 1, 5, 10, 25, 50 et la saisie libre |
| `orientation` | préférence, sinon `lignes` | `lignes` \| `colonnes` |
| `branches` | préférence, sinon les cinq | liste séparée par des virgules parmi `pp,pm,mp,mm,tr` |

Les trois sont **facultatifs** : sans eux, la réponse applique la préférence enregistrée du
compte. Un paramètre fourni **n'écrit pas** la préférence — c'est `PUT` qui écrit. Sans cette
séparation, chaque exploration d'un réglage devient une écriture, et la préférence ne veut
plus rien dire.

```json
{
  "bande": 25,
  "orientation": "colonnes",
  "branches": ["pm", "mm"],
  "showUncertainty": true,
  "bornes": { "min": 1655, "max": 2037 },
  "bandes": [
    { "debut": 1655, "fin": 1679, "libelle": "1655–1679" },
    { "debut": 1680, "fin": 1704, "libelle": "1680–1704" }
  ],
  "barres": [
    {
      "personId": 17,
      "displayName": "Marne Dolomite Sureau",
      "branch": "pm",
      "generation": -4,
      "sex": "feminin",
      "vivant": false,
      "debut": { "min": 1901, "max": 1909, "certain": false },
      "fin": { "min": 1987, "max": 1987, "certain": false },
      "ouverte": false,
      "ligneDates": "1901-1909 – 1987"
    },
    {
      "personId": 1,
      "displayName": "Natron Romarin",
      "branch": "tr",
      "generation": 0,
      "sex": "masculin",
      "vivant": true,
      "debut": { "min": 2012, "max": 2012, "certain": true },
      "fin": null,
      "ouverte": true,
      "ligneDates": "n. 26.9.2012"
    }
  ],
  "jalons": [
    {
      "type": "recensement",
      "annee": 1957,
      "libelle": "Recensement, Saint-Ronce-de-Verçay",
      "personId": 17
    }
  ]
}
```

- **`debut` et `fin` sont des intervalles d'années, jamais des points.** `min` et `max`
  viennent de `birth_min` / `birth_max` ; `certain` est `qualifier === 'exacte'`. C'est ce qui
  permet la hachure aux extrémités exigée au § 7.4 du dossier — « les dates incertaines se
  dessinent comme telles ». Aplatir ici serait aplatir à l'écran, ce que le § 4 du dossier
  interdit au même titre qu'en base.

#### Les trois nullités d'une barre, et la forme exacte du type

Mesuré sur l'arbre réel, 235 barres. **Une barre n'est pas un rectangle plein de nombres**, et
un type qui le suppose ment sur une barre sur quatre.

```ts
type IntervalleAnnees = { min: number | null; max: number | null; certain: boolean }

type Barre = {
  personId: number
  displayName: string
  branch: Branch
  generation: number
  sex: Sex
  vivant: boolean
  debut: IntervalleAnnees | null   // null : aucune naissance connue — 11 barres
  fin: IntervalleAnnees | null     // null : aucune date de décès connue — 53 + les vivants
  ouverte: boolean                 // true : la personne est vivante
  ligneDates: string
}
```

| Nullité | Combien | D'où elle vient |
|---|---:|---|
| `debut: null` | **11** | On ne connaît que le décès. La barre commence au bord de l'axe, ou ne se dessine qu'à partir de `fin`. |
| `fin: null` avec `ouverte: false` | **53** | `DEAT` sans `DATE` — « un décès certain sans date » (§ 3.1). La personne est morte, on ignore quand. |
| `min: null` ou `max: null` **dans** un intervalle | **6** | Les formes `BEF` et `AFT` : « `null` = non bornée à gauche », déjà écrit au **§ 14.1**, jamais illustré ici. |

> **`fin: null` n'équivaut pas à `ouverte: true`.** Le contrat posait cette équivalence ; la
> donnée ne la tient pas, sur **53 barres**. `ouverte` dit « la personne est vivante » ;
> `fin: null` dit « on n'a pas de date de décès ». Les deux se recouvrent souvent et ne sont
> pas la même chose.
>
> Les quatre combinaisons, dont une seule est impossible :
>
> | `ouverte` | `fin` | Cas |
> |---|---|---|
> | `false` | intervalle | décès daté — le cas courant |
> | `false` | `null` | **décès certain sans date** — les 53 |
> | `true` | `null` | personne vivante |
> | `true` | intervalle | **n'arrive jamais** — une date de décès implique le décès (`ck_tree_person_deces_coherent`) |
>
> Un front qui écrit une union discriminée sur `ouverte` et déréférence `fin.max` dès qu'il
> est faux plante sur les 53. C'est arrivé.

**Et la nullité des bornes ne lève rien.** `(null - 1655) * 8` ne produit **aucune erreur** en
JavaScript : `null` se coerce en `0`, et la barre part 1 655 ans avant l'origine de l'axe —
un dessin parfaitement plausible, sans exception, sans test rouge, que seule une capture
d'écran révèle. C'est le mode de défaillance le plus coûteux du lot, et c'est pourquoi les
trois nullités sont écrites ici plutôt que laissées à découvrir : **une valeur nullable non
documentée n'est pas un manque de précision, c'est un dessin faux qui ne se plaint pas.**

- `ouverte: true` : la barre s'arrête à l'année courante **côté rendu** ; l'API n'invente
  jamais une date de fin.
- `bandes` est calculé **par le serveur** à partir de `bande` et de `bornes` — « les bandes
  se calculent à partir de N et des bornes de l'arbre, en deux lignes » (§ 7). Le front ne
  les recalcule pas. L'opération, écrite sans ambiguïté pour qu'un second implémenteur
  retombe sur les mêmes bornes : `debut(k) = bornes.min + k × bande` et
  `fin(k) = debut(k) + bande − 1`, pour `k = 0` tant que `debut(k) ≤ bornes.max`. Il n'y a
  **ni arrondi, ni division** : la dernière bande est partielle et sa `fin` dépasse
  `bornes.max`, ce qui est correct et voulu.
Les trois nullités, illustrées — parce qu'un cas qu'aucun exemple ne montre est un cas que
personne n'implémente :

```json
{
  "barres": [
    {
      "personId": 44,
      "displayName": "Lapis Fluorine Fenouil",
      "branch": "pp",
      "generation": -8,
      "sex": "masculin",
      "vivant": false,
      "debut": null,
      "fin": { "min": 1800, "max": 1800, "certain": true },
      "ouverte": false,
      "ligneDates": "† 1800"
    },
    {
      "personId": 45,
      "displayName": "Marne Aragonite Mélisse",
      "branch": "pp",
      "generation": -8,
      "sex": "feminin",
      "vivant": false,
      "debut": { "min": 1743, "max": 1743, "certain": false },
      "fin": null,
      "ouverte": false,
      "ligneDates": "n. 1743"
    },
    {
      "personId": 46,
      "displayName": "Pyrite Lapis Bruyère",
      "branch": "pm",
      "generation": -7,
      "sex": "masculin",
      "vivant": false,
      "debut": { "min": null, "max": 1792, "certain": false },
      "fin": { "min": 1841, "max": null, "certain": false },
      "ouverte": false,
      "ligneDates": "<1792 – >1841"
    }
  ]
}
```

Lecture, dans l'ordre : le premier n'a **que** son décès (`debut: null`) ; le deuxième est
**mort sans date connue** (`fin: null`, `ouverte: false`) ; le troisième porte un `BEF` et un
`AFT`, donc **une borne nulle à l'intérieur de chaque intervalle**. Aucun des trois ne se
dessine avec un type qui suppose des entiers partout.

- **`bornes` est toujours présent et n'est jamais `null`**, même quand rien n'est visible.
  `bornes.min` est la plus petite année parmi les `debut.min` des `barres` ; `bornes.max` est
  la plus grande parmi les `fin.max` des barres fermées, **et l'année courante** dès qu'il
  existe au moins une barre ouverte. Quand `barres` est vide — un compte `en_attente` dont
  aucune personne décédée ne porte de date, un filtre de branches qui ne laisse rien —
  `bornes` vaut `{"min": <année courante>, "max": <année courante>}` et `bandes` vaut `[]`.

  > **Le signal de vacuité est `bandes: []`, jamais `bornes`.** Le front type
  > `bornes: {min: number, max: number}` **non nullable**, et il a raison : rendre `bornes`
  > nullable obligerait chaque consommateur à brancher sur un cas qui ne porte aucune
  > information, et le jour où un compte en attente ouvrirait l'écran, un type qui ment se
  > casserait sans que rien ne l'ait annoncé. L'année courante est la seule borne qu'on puisse
  > nommer sans inventer de l'histoire ; elle n'est de toute façon lue par personne, puisque
  > `bornes` ne sert qu'à calculer `bandes`, qui est vide.
- **`ligneDates` est la forme compacte, et la chronologie ne masque jamais** : c'est
  `"1901-1909 – 1987"`, et pour une personne vivante `"n. 26.9.2012"` — **jamais**
  `"vivant·e"`. La constante appartient à `etiquetteDates`, la clé du nœud du graphe, dont le
  motif ne se transpose pas ici : une personne vivante hors cercle est **absente de
  `barres`**, donc celles qui restent sont dans le cercle et exposent légitimement leurs
  dates. Forme exacte et raisonnement complet : **§ 14.4**, référence unique sur ce point.
- Les mêmes règles de rendu qu'à l'arbre s'appliquent (`fill-opacity` / `stroke-opacity`,
  pan/zoom par `viewBox`, canevas impératif) — ADR 0007.

> **Les personnes vivantes hors cercle sont absentes de `barres`.** Une barre *est* faite de
> dates, et une personne vivante hors cercle n'en expose aucune : elle n'aurait rien à
> dessiner. Et la chronologie est une **énumération** au sens de l'ADR 0003 — « pas de liste,
> pas d'export ». Conséquence visible à assumer : un membre voit moins de barres que de
> nœuds. Elle n'ouvre aucune fuite nouvelle, puisque `vivant` est déjà sur chaque nœud du
> graphe — la liste des vivants est déjà déductible, c'est le risque résiduel accepté.
> *Aucun document ne tranchait ce point : il est décidé ici et repris au § 18.*

`jalons` porte les `tree_fact` datés (recensements, résidences) des personnes visibles. Il est
vide pour un compte `en_attente` dès qu'il porterait un fait d'une personne vivante — ce qui,
ce régime n'ayant pas de cercle, revient à ne porter que des faits de personnes décédées.

### 9.2 Préférences

`PUT /api/chronologie/preferences` — remplacement complet, pas de `PATCH` : quatre champs,
tous obligatoires, aucune ambiguïté sur ce que « absent » veut dire.

```json
{
  "bandYears": 25,
  "orientation": "colonnes",
  "branches": ["pm", "mm"],
  "showUncertainty": true
}
```

`bandYears` hors de 1–500 ⇒ `422`. `showUncertainty` vaut `true` par défaut, « parce que
masquer l'incertitude est précisément ce que le § 4 du dossier interdit » (§ 7.1). Le front
peut l'éteindre ; il ne doit pas le faire silencieusement au premier rendu.

> **`GET` et `PUT` rendent le même objet que le corps de requête ci-dessus : les quatre
> clés, nu, sans enveloppe.** Pas de `{"preference": …}`, pas de `updatedAt`, pas d'`id`,
> pas de `userId`. Octet pour octet la même forme dans les trois cas — corps du `PUT`,
> réponse du `PUT`, réponse du `GET`.

Ce n'est pas une préférence de style, et c'est pour cela que c'est écrit en exigence : **le
front compare les réglages courants de l'écran à cette réponse pour décider si le bouton
« Enregistrer » est actif.** Une enveloppe, une clé de plus, un `updatedAt` qui bouge à chaque
lecture, et la comparaison est toujours vraie — le bouton reste allumé en permanence et
l'utilisateur ne sait plus s'il a enregistré. Une égalité structurelle est la façon la moins
chère de répondre à « ai-je des changements non enregistrés », et elle n'existe que si les
deux objets ont exactement la même forme.

Un compte qui n'a **jamais** enregistré de préférence n'a pas de ligne `tml_preference`. Le
`GET` rend alors les **valeurs par défaut**, avec le même corps et le même `200` — jamais
`404`, jamais `null` :

```json
{
  "bandYears": 10,
  "orientation": "lignes",
  "branches": ["pp", "pm", "mp", "mm", "tr"],
  "showUncertainty": true
}
```

`bandYears` vient de `timeline.bande_annees_defaut` (§ 2.2) ; les trois autres sont les
défauts de colonne du § 7.1. Le premier `PUT` crée la ligne, les suivants la remplacent. Le
front n'a donc **aucun cas « pas de préférence »** à traiter : il lit toujours quatre valeurs.

---

## 10. Administration et GEDCOM

Modules `Core` (utilisateurs, réglages, bascule, journal), `Membership` (membres) et `Gedcom`.
Tout le préfixe `/api/administration` est derrière `ROLE_ADMIN` par `access_control` — la
règle est posée **avant** `^/api`, faute de quoi elle ne serait jamais atteinte (« seule la
première règle qui correspond est appliquée »).

| Méthode | Chemin | Accès | Corps | Succès | Erreurs |
|---|---|---|---|---|---|
| `GET` | `/api/administration/utilisateurs` | `ADMIN` | `?status=&page=` | `200` liste paginée | — |
| `GET` | `/api/administration/utilisateurs/{id}` | `ADMIN` | — | `200` § 10.1 | `404` |
| `PATCH` | `/api/administration/utilisateurs/{id}` | `ADMIN` | `{roles?, status?, displayName?}` | `200` utilisateur | `422` · `409` `dernier_administrateur`, `rattachement_non_etabli` · `404` |
| `POST` | `/api/administration/membres/{id}/activer` | `ADMIN` | `{personId?}` — **obligatoire** pour un compte `a_rattacher` | `200` membre | `404` · `409` `personne_deja_rattachee`, `compte_deja_decide` · `409` `vue_comme_membre_active` · `422` |
| `POST` | `/api/administration/membres/{id}/refuser` | `ADMIN` | *(vide)* | `200` membre | `404` · `409` `compte_deja_decide` · `409` `vue_comme_membre_active` |
| `GET` | `/api/administration/rattachements` | `ADMIN` | — | `200` liste § 10.1 | `409` `vue_comme_membre_active` |
| `GET` | `/api/administration/rattachements/{memberId}/message-vocal` | `ADMIN` | — | `200` / `206` le son (`Range`) | `404` · `409` `vue_comme_membre_active` |
| `GET` | `/api/administration/rattachements/{memberId}/suggestion` | `ADMIN` | — | `200` suggestion gardée § 10.1 · `204` aucune — **jamais un calcul** | `404` · `409` `vue_comme_membre_active` |
| `POST` | `/api/administration/rattachements/{memberId}/suggestion` | `ADMIN` | *(vide)* | `200` suggestion § 10.1 | `404` · `409` `vue_comme_membre_active` · `429` · `502` `assistant_erreur` · `503` `assistant_indisponible` |
| `POST` | `/api/administration/rattachements/{memberId}/transcription` | `ADMIN` | *(vide)* | `200` transcription § 10.1 (y compris en échec) | `404` · `409` `vue_comme_membre_active` · `429` · `503` `assistant_indisponible` |
| `PATCH` | `/api/administration/rattachements/{memberId}/transcription` | `ADMIN` | `{texte}` — 1 à 16 000 caractères | `200` transcription § 10.1 (`corrigeeLe` renseignée) | `404` · `409` `vue_comme_membre_active` · `422` |
| `POST` | `/api/administration/rattachements/{memberId}/creer-et-rattacher` | `ADMIN` | `{parents?, message?}` | `201` `{membre, personId, demandeId}` | `404` · `409` `personne_deja_rattachee`, `compte_deja_decide` · `422` |
| `GET` | `/api/administration/reglages` | `ADMIN` | — | `200` tableau nu | — |
| `PATCH` | `/api/administration/reglages/{cle}` | `ADMIN` | `{value}` | `200` réglage | `404` · `422` `valeur_hors_bornes` |
| `POST` | `/api/administration/voir-comme` | `ADMIN` | `{memberId}` | `200` session | `404` · `422` |
| `DELETE` | `/api/administration/voir-comme` | `ADMIN` | — | `200` session | — |
| `GET` | `/api/administration/journal` | `ADMIN` | `?action=&actorUserId=&subjectType=&subjectId=&page=` | `200` liste paginée | — |
| `POST` | `/api/administration/gedcom/import` | `ADMIN` | `{chemin, dryRun}` | `202` § 10.6 | `422` · `409` `import_en_cours` |
| `GET` | `/api/administration/gedcom/executions` | `ADMIN` | `?direction=&page=` | `200` liste paginée | — |
| `GET` | `/api/administration/gedcom/executions/{id}` | `ADMIN` | — | `200` § 10.6 | `404` |
| `GET` | `/api/administration/gedcom/executions/{id}/fichier` | `ADMIN` | — | `200` **octets** | `404` |
| `POST` | `/api/administration/gedcom/export` | `ADMIN` | *(vide)* | `202` § 10.6 | `409` |

### 10.1 Utilisateurs et rôles

```json
{
  "id": 9,
  "email": "cousin.saule@example.net",
  "displayName": "Pyrite Romarin",
  "roles": ["ROLE_USER"],
  "status": "en_attente",
  "totpEnabledAt": null,
  "lastLoginAt": "2026-09-15T20:31:02+02:00",
  "createdAt": "2026-09-15T20:31:02+02:00",
  "membre": {
    "id": 11,
    "status": "en_attente",
    "personId": 210,
    "personLabel": "Pyrite Romarin",
    "matchKind": "recherche_confirmee",
    "invitationId": 4,
    "linkedAt": "2026-09-15T20:31:02+02:00",
    "activatedAt": null
  }
}
```

`PATCH` accepte trois champs, tous facultatifs, appliqués ensemble :

- `roles` : `["ROLE_USER"]` ou `["ROLE_ADMIN"]`. `ROLE_USER` n'est jamais stocké et est ajouté
  à la lecture (§ 2.1) ; l'API l'accepte en entrée et l'ignore. Journalisé `role_modifie`.
- `status` : `actif`, `suspendu`. **Pas `en_attente`** — on ne remet pas un compte en attente,
  on le suspend. Journalisé `compte_suspendu`.
- Retirer `ROLE_ADMIN` au dernier administrateur ⇒ `409` `dernier_administrateur`. Il n'y a
  qu'un administrateur (ADR 0004) ; se verrouiller dehors est un incident sans porte de
  secours autre que la console.
- Passer `actif` un compte dont le membre n'est pas **actif avec une fiche** ⇒ `409`
  `rattachement_non_etabli` (`details.memberId`). `RegimeVoter` décide du régime « membre » sur
  `core_user.status` seul : « Réactiver » un compte **refusé** (ADR 0013) ou un compte en
  attente qu'on avait suspendu en faisait un compte sans cercle mais admis à écrire. Un compte
  en attente s'active par la route ci-dessous, qui passe les deux statuts ensemble ; un compte
  refusé ne revient pas. Un compte sans membre (l'administrateur d'amorçage) n'est pas concerné.

`POST /api/administration/membres/{id}/activer` fait passer `mbr_member.status` et
`core_user.status` à `actif`, pose `activated_at` et `activated_by_user_id`, et **le compte
obtient un cercle** — c'est le moment exact où le régime change (§ 12). `personId` facultatif
corrige au passage un rattachement erroné (`match_kind` devient `manuel_admin`). L'auteur
reçoit une notification.

Depuis l'inscription sans fiche (ADR 0013), l'activation rejoue trois contrôles sur toute
fiche désignée par `personId` : elle doit **exister** (`422`), être **vivante**
(`422 {personId: "Cette personne est décédée."}`) et n'être **réclamée par aucun autre
compte** (`409` `personne_deja_rattachee`). Pour un compte `a_rattacher`, `personId` est
**obligatoire** (`422 {personId: "Choisissez la fiche à laquelle rattacher ce compte."}`) ; la
déclaration et la suggestion sont **purgées** au rattachement. Pour **tout** compte activé qui
avait déposé sa photo (§ 7.1), elle passe sur la fiche et en devient le portrait actif. Le
tout tient dans une transaction.

La charge `membre` rendue (`MembreAdministration`) :

```json
{
  "id": 14,
  "status": "actif",
  "matchKind": "manuel_admin",
  "invitationId": 3,
  "linkedAt": "2026-10-01T10:02:11+02:00",
  "activatedAt": "2026-10-01T10:02:11+02:00",
  "personId": 812,
  "personLabel": "Léa Dupont"
}
```

`personId` et `personLabel` sont **absents** quand le cercle ne les laisse pas voir, et
toujours absents pour un compte `a_rattacher`.

#### Refuser une inscription — `POST /api/administration/membres/{id}/refuser`

Corps vide. Accepte tout membre `en_attente`, `a_rattacher` compris. Le compte est
**suspendu** — `mbr_member.status` et `core_user.status` passent `suspendu`, la connexion est
refusée (`401 compte_suspendu`) —, et la déclaration, la suggestion et la photo en attente
sont **purgées**. Audit `compte_suspendu` avec `{memberId, matchKind}`, sans contenu. Réponse
`200` : la charge `membre`. `409` `compte_deja_decide` si le membre n'est plus en attente.

On suspend plutôt que de supprimer : `Core` n'a aucun chemin de suppression de compte, et
`core_user` est référencé par le journal, les notifications et les sessions. Les données
personnelles partent quand même ; le courriel reste réservé (ADR 0013).

#### L'onglet « À rattacher » — `GET /api/administration/rattachements`

Les comptes `a_rattacher` **et** `en_attente`, du plus ancien au plus récent. Refusée sous la
bascule « voir comme » (`409` `vue_comme_membre_active`) — **bien que ce soit une lecture** :
la description est un texte libre qu'aucun cercle ne sait filtrer.

```json
{
  "elements": [
    {
      "memberId": 14,
      "userId": 22,
      "email": "lea@example.net",
      "inscritLe": "2026-10-01T09:12:00+02:00",
      "invitationId": 3,
      "invitationPersonId": null,
      "invitationPersonLabel": null,
      "declaration": {
        "surname": "Dupont",
        "givenNames": "Léa Marie",
        "birthDate": "1998-04-12",
        "description": "Mon père est Jean Dupont, né vers 1960 à Lille ; ma mère Marie Martin…"
      },
      "messageVocal": {
        "url": "/api/administration/rattachements/14/message-vocal", "type": "audio/webm", "taille": 553412, "duree": 134,
        "transcription": { "statut": "faite", "texte": "Bonjour, je suis Léa Dupont…", "erreur": null, "le": "2026-10-02T21:04:12+02:00", "corrigeeLe": null }
      },
      "photo": { "mediaId": 91, "url": "/api/medias/91/fichier?v=1a2b3c4d", "vignetteUrl": "/api/medias/91/vignette?v=1a2b3c4d" },
      "suggestionCalculeeLe": null
    }
  ],
  "total": 1,
  "page": 1,
  "parPage": 1
}
```

`photo` vaut `null` sans photo ; `suggestionCalculeeLe` est une date ISO ou `null`. Les
comptes `a_rattacher` figurent aussi dans `GET /api/administration/membres`, sans `personId`.

`messageVocal` vaut `null` sans message vocal ; sinon il le **décrit** (`type` reniflé,
`taille` en octets, `duree` annoncée ou `null`) sans le contenir. `declaration.description`
peut alors valoir `null`. Le son sort par `url` —
`GET /api/administration/rattachements/{memberId}/message-vocal`, `ADMIN`, refusée sous la
bascule, `Cache-Control: private, no-store`, requêtes partielles (`Range` → `206`) servies.
`404` pour un compte inconnu, déjà décidé, sans vocal, ou dont le fichier manque.
`messageVocal.transcription` — **toujours ces cinq clés** — décrit sa transcription
automatique : `statut` `a_faire` (jamais tentée), `faite` (`texte` verbatim) ou `echec`
(`erreur`, le motif écrit par le serveur) ; `le` est la date de la dernière tentative ;
`corrigeeLe` la date de la dernière **correction à la main** (3 octobre 2026), `null` tant
que le texte est celui du modèle. Le texte parle de vivants, comme la description : mêmes
règles, même purge.

Chaque élément de `GET /api/administration/membres` (la file des comptes `en_attente`) porte
en plus `portraitEnAttente` — `{mediaId, url, vignetteUrl}` ou `null` : la photo déposée à
l'inscription, qui **deviendra le portrait de la fiche à la validation**. L'écran
Utilisateurs la montre sur la ligne du compte, à côté de « Valider le rattachement » :
l'administrateur voit ce qu'il publie avant de le publier.

Une décision ne se prend qu'une fois. `activer`, `refuser` et `creer-et-rattacher` relisent
le compte **sous verrou** dans leur transaction : un compte refusé (ou activé) entre-temps,
par un autre administrateur ou un autre onglet, rend `409 compte_deja_decide` — et
`creer-et-rattacher` le vérifie **avant** de créer la fiche : rien n'est créé.
Dans la liste des invitations (§ 3.3), chaque ligne de `inscrits[]` porte désormais
`matchKind`.

#### La suggestion de rattachement (module `Assistant`)

`GET /api/administration/rattachements/{memberId}/suggestion` rend la suggestion **gardée**
sur le compte s'il y en a une — même si le modèle n'est pas configuré ; ses champs volatils
(`displayName`, `ligneDates`, `dejaRattachee`, `perimee`) sont recalculés à chaque lecture.
**Depuis le 3 octobre 2026, le `GET` ne calcule jamais rien** : sans suggestion gardée, il rend
`204` (corps vide), sans appel au modèle, sans quota, sans clé exigée. C'est `POST` sur la
même adresse — « Lancer la recherche » / « Relancer la recherche », derrière un clic — qui
calcule, stocke et rend. Sur les routes de ce préfixe : `409` `vue_comme_membre_active`
d'abord, `404` si le compte n'est plus `a_rattacher` / `en_attente`, et la session est
**relâchée** avant tout appel au modèle. Quota séparé : `assistant.rattachements`, **40
appels par 24 heures glissantes par défaut, clé globale** pour tous les administrateurs —
limite réglable globalement (« Quotas d'usage » ci-dessous) ; l'écran en lit l'état par
`GET /api/administration/quotas` pour l'afficher à côté du bouton. `503`
`assistant_indisponible` (pas de clé) · `502` `assistant_erreur` · `429` avec `Retry-After`
— sur le `POST` seulement.

```json
{
  "memberId": 14,
  "calculeeLe": "2026-10-01T09:15:40+02:00",
  "modele": "gemini-pro-latest",
  "verdict": "fiche_existante",
  "resume": "La description correspond à la fiche de Léa Dupont, fille de Jean Dupont.",
  "avertissements": [],
  "fiche": {
    "personId": 812,
    "displayName": "Léa Dupont",
    "ligneDates": "n. 1998",
    "confiance": "haute",
    "justification": "Même prénom, même père, année de naissance compatible.",
    "dejaRattachee": false
  },
  "alternatives": [],
  "creation": null,
  "transcriptionPriseEnCompte": false,
  "perimee": false
}
```

- `verdict` : `fiche_existante` · `absente` · `indecise`. `fiche` est non nul **si et
  seulement si** `verdict = fiche_existante` ; `alternatives` porte 0 à 3 fiches.
- `creation` est non nul si `verdict = absente`, facultatif si `indecise` :
  `{titre, justification, confiance, corps, changements, personnes, recevable,
  motifIrrecevable}`. `corps` est un corps `creation_personne` exact (§ 6.3) dont `#p1` est
  le titulaire du compte — **son identité vient toujours de la déclaration** ; `#p2`… sont des
  parents absents de l'arbre.
- Le prompt contient le nom, les prénoms, la date déclarée, la description, la
  **transcription du message vocal** s'il y en a une (étiquetée « transcription
  automatique », ou « relue et corrigée par l'administrateur » quand `corrigeeLe` est posée)
  et le pré-lien de l'invitation à titre d'indice ; **jamais le courriel**, ni le son. Le
  journal ne reçoit que des compteurs (dont `transcription_corrigee`). Aucun rattachement
  n'est jamais automatique.
- `transcriptionPriseEnCompte` : l'analyse a-t-elle lu une transcription ?
- `perimee` : la transcription a changé depuis le calcul (arrivée, retranscrite ou corrigée
  à la main) — la suggestion garde l'empreinte SHA-256 du texte qu'elle a lu, jamais le
  texte. Elle reste servie, l'écran dit « relancez la recherche » ; rien n'est recalculé
  sans un `POST`.

#### La transcription du message vocal (module `Assistant`)

`POST /api/administration/rattachements/{memberId}/transcription` envoie le message vocal à
Gemini (`inlineData`, base64 ; modèle `HERITAGE_GEMINI_MODELE_TRANSCRIPTION`, défaut
`gemini-2.5-flash` ; 60 s au plus) avec une consigne de **verbatim** en français — noms
propres et dates tels qu'entendus, `[?]` sur un nom incertain, `[inaudible]`, aucun résumé —,
**range le résultat sur le compte** et le rend. Les trois types de `MediaRecorder` partent
sans conversion (WebM et Ogg tels quels, le MP4 de Safari annoncé `audio/m4a`). Mêmes
contrôles que la suggestion, dans le même ordre ; même quota global
`assistant.rattachements`. `404` si le compte n'a pas de vocal.

```json
{ "memberId": 14, "statut": "faite", "texte": "Bonjour, je suis Léa Dupont…", "erreur": null, "le": "2026-10-02T21:04:12+02:00", "corrigeeLe": null }
```

- **`200` même en échec** — `{"statut": "echec", "texte": null, "erreur": "Le modèle n’a pas
  répondu à temps. Réessayez dans un moment."}` : délai, quota ou panne du fournisseur,
  refus, réponse vide. L'état est la ressource ; il est rangé sur le compte, et un nouvel
  appel (« Réessayer ») le remplace. L'audio reste servi quoi qu'il arrive.
- Rien ne la lance tout seul : l'écran l'appelle sur « Transcrire le message vocal »,
  « Réessayer » ou « Retranscrire ». Une nouvelle transcription remplace une correction à la
  main (`corrigeeLe` repasse à `null`).
- Journal : `AppelModeleDeLangage`, sujet `Assistant\Transcription` — statut, durée
  annoncée, taille, nombre de caractères ; jamais le texte, jamais le son.

`PATCH /api/administration/rattachements/{memberId}/transcription`, corps `{"texte": "…"}`
(3 octobre 2026) : range la transcription **corrigée à la main** — ou écrite, quand le
modèle a échoué ou n'est pas configuré. Ni modèle, ni quota, ni clé exigée. Le texte est
ramené (blancs autour retirés, fins de ligne `\r\n` → `\n`) ; `422 {champs: {texte}}` s'il
manque, n'est pas une chaîne, est vide, ou dépasse 16 000 caractères. Mêmes contrôles 1 à 3
que la suggestion (`409` sous la bascule, `403`, `404`), puis `404` sans message vocal. Rend
la même forme que le `POST`, `statut: "faite"` et `corrigeeLe` renseignée ; `le` garde la
date de la dernière tentative du modèle (ou prend celle de la correction s'il n'y en a pas
eu). Une suggestion gardée devient `perimee`. Journal : la ligne `modification` du journal
mécanique sur `Membership\Member`, le texte masqué (`***`), la date de correction en clair.

`POST /api/administration/rattachements/{memberId}/creer-et-rattacher`, corps
`{"parents": [12, 57], "message": "facultatif"}` : `parents` porte 0 à 2 personnes existantes.
Le corps de la demande est construit **côté serveur** — `suggestion.creation.corps` s'il
existe, sinon un corps minimal tiré de la déclaration (`#p1` : prénoms, nom, sexe
`inconnu`, non décédé, naissance exacte) ; si `parents` n'est pas vide, le lien de filiation
de `#p1` est remplacé par `enfant_de` ces parents. Dans **une transaction englobante** : dépôt
de la demande `creation_personne`, validation au nom de l'administrateur, puis rattachement et
activation du compte. `201 {membre, personId, demandeId}`. Erreurs : `422 {champs: {parents}}`
(plus de deux, ou inexistant), `422` `demande_sans_lien` quand aucun lien ne rattache `#p1` à
l'arbre, les autres `422` / `409` de `Proposal`, `404` si le compte a été traité entre-temps.
**Dans tous les cas d'erreur, rien n'est écrit.**

#### Quotas d'usage — par compte, et le compteur global

Chaque élément de `GET /api/administration/utilisateurs` (et la réponse de
`GET`/`PATCH /api/administration/utilisateurs/{id}`) porte `quotas`, un élément par quota
**compté par compte** :

```json
"quotas": [
  { "cle": "assistant.analyses", "libelle": "Analyses IA", "fenetre": "par 24 h", "consomme": 3, "limite": 20, "limiteParDefaut": 20, "personnalisee": false, "exempte": false },
  { "cle": "assistant.questions", "libelle": "Questions IA", "fenetre": "par 24 h", "consomme": 12, "limite": 15, "limiteParDefaut": 60, "personnalisee": true, "exempte": false },
  { "cle": "media.televersements", "libelle": "Médias", "fenetre": "par heure", "consomme": 0, "limite": 20, "limiteParDefaut": 20, "personnalisee": false, "exempte": false }
]
```

| `cle` | Fenêtre glissante | Défaut | Où le défaut est écrit |
|---|---|---|---|
| `assistant.analyses` | 24 h | 20 | `assistant.quota.analyses` (`config/services/assistant.yaml`) |
| `assistant.questions` | 24 h | 60 | `assistant.quota.questions` |
| `media.televersements` | 1 h | 20 | `media.quota.televersements` (`config/services/media.yaml`) |
| `assistant.rattachements` | 24 h, **clé globale** | 40 | `assistant.quota.rattachements` |

- `consomme` est **lu sans être décompté** : la lecture n'ajoute aucun coup au compteur.
  Il peut dépasser `limite` quand l'administrateur a baissé la limite sous ce qui était
  déjà consommé.
- `limite` est la limite **effective** : la personnelle si `personnalisee`, sinon
  `limiteParDefaut`. Une limite changée s'applique aussitôt aux coups déjà comptés.
- `exempte` : l'administrateur n'est pas compté sur les téléversements (§ 7.1) — ni le défaut
  ni une limite personnelle ne le contraignent.
- Ordre : celui des déclarations des modules. Le front n'a aucune clé à connaître d'avance.

`PATCH /api/administration/utilisateurs/{id}/quotas` pose ou retire des limites
personnelles, corps `{"limites": {"assistant.analyses": 30, "assistant.questions": null}}` :

- une clé absente ne change rien ; `null` rend le défaut (la ligne de `core_quota_limite`
  est supprimée) ; un **entier de 0 à 10 000** devient la limite. `0` ferme l'usage. Il n'y a
  pas d'« illimité » : la borne haute en tient lieu et garde le compteur ;
- `422 donnees_invalides`, `champs["limites.<clé>"]` : valeur hors bornes ou non entière, clé
  inconnue, ou quota global ; `422 {limites}` si le corps n'a pas `limites` ou si ce n'est
  pas un objet. **Un lot dont une valeur est invalide n'écrit rien** ;
- `404` si le compte n'existe pas, `409 vue_comme_membre_active` sous la bascule ;
- réponse `200` : la charge du compte (ci-dessus), `quotas` à jour. Le journal d'audit
  mécanique écrit chaque création, modification ou suppression de limite.

Le **rattachement assisté** compte sur **une** clé pour toute l'installation : il n'a pas de
limite par compte. `GET /api/administration/quotas` rend `{"quotas": [ … ]}` — les quotas
globaux, même forme sans `exempte` — et `PATCH /api/administration/quotas` prend le même corps
`{"limites": {"assistant.rattachements": 60}}` (mêmes règles ; une clé par compte y rend
`422`). Toutes ces routes sont réservées à l'administrateur (`403` sinon).

### 10.2 Réglages

`GET /api/administration/reglages` — tableau nu, une vingtaine de lignes, chargées d'un coup
(§ 2.2).

```json
[
  {
    "key": "cercle.generations",
    "value": 3,
    "valueType": "entier",
    "label": "Générations remontées par le cercle de visibilité",
    "description": "Combien de générations on remonte avant de redescendre. 3 = parents, grands-parents, arrière-grands-parents.",
    "minValue": 1,
    "maxValue": 6,
    "updatedByEmail": "natron@example.net",
    "updatedAt": "2026-09-15T18:00:00+02:00"
  },
  {
    "key": "media.mimes_autorises",
    "value": ["image/jpeg", "image/png", "image/webp", "application/pdf"],
    "valueType": "json",
    "label": "Types de fichiers acceptés",
    "description": "Liste blanche appliquée après reniflage des octets d'en-tête.",
    "minValue": null,
    "maxValue": null,
    "updatedByEmail": null,
    "updatedAt": "2026-09-15T18:00:00+02:00"
  }
]
```

`value` est rendu **casté** selon `valueType` (`entier` ⇒ nombre JSON, `booleen` ⇒ booléen,
`json` ⇒ structure), jamais en texte brut. La colonne est un `TEXT` ; le contrat, lui, est
typé — sinon chaque écran refait le cast à sa façon.

`PATCH /api/administration/reglages/cercle.generations` avec `{"value": 4}` :

- Hors de `[minValue, maxValue]` ⇒ `422` `valeur_hors_bornes`, avec les bornes dans
  `erreur.details`. **Au-delà de 6 le cercle couvre l'arbre entier et la règle ne protège plus
  personne** (§ 9.3, ADR 0003) — la borne n'est pas décorative.
- Valeur qui ne caste pas selon `valueType` ⇒ `422` (§ 2.2 : le service « refuse à l'écriture
  une valeur qui ne caste pas »).
- Journalisé `reglage_modifie`, avec ancienne et nouvelle valeur.
- **Il n'y a rien à invalider.** `cercle.generations` ne touche pas l'arbre, donc ne change pas
  l'empreinte ; c'est le segment `{N}` de la clé de cercle qui rend les anciennes entrées
  inatteignables (§ 2.3, § 9.3). De même, changer `tree.seuil_vivant_annees` ne demande aucune
  migration : la sérialisation n'est pas mise en cache, et quelques personnes cessent d'être
  masquées **à la requête suivante** (§ 9.2). Le front doit donc **recharger la session et le
  graphe** après un `PATCH` sur l'un de ces deux réglages, sans quoi il affichera l'ancien
  filtrage jusqu'au prochain rechargement de page.

### 10.3 Journal d'audit

`GET /api/administration/journal?action=connexion_echouee&page=1` — du plus récent au plus
ancien (`idx_core_audit_occurred_at`).

```json
{
  "elements": [
    {
      "id": 8812,
      "occurredAt": "2026-09-15T21:12:00+02:00",
      "actorEmail": "natron@example.net",
      "action": "vue_comme_membre",
      "subjectType": "Membership\\Member",
      "subjectId": "7",
      "subjectLabel": "Cobalt Romarin",
      "changes": { "memberId": 7, "personId": 129 },
      "ip": "192.168.1.24",
      "userAgent": "Mozilla/5.0 …"
    }
  ],
  "total": 8812,
  "page": 1,
  "parPage": 25
}
```

`changes` est rendu tel qu'il est en base, **champs sensibles déjà masqués à l'écriture** :
`password`, `totpSecret`, `tokenHash` valent `"***"` (§ 2.4). Ils ne sont pas masqués à la
lecture — un journal qu'on filtre à la lecture est un journal qui contient encore le secret.

`subjectLabel` est un libellé de personne : il est rempli avec le `display_name` **et jamais
avec une ligne de dates** (§ 9.2), et il reste soumis à la porte de sortie n° 8.

### 10.4 La bascule « voir comme un membre »

`POST /api/administration/voir-comme` avec `{"memberId": 7}`.

Elle calcule le cercle du `mbr_member` choisi et l'utilise **pour la sérialisation**, à la
place de celui de l'administrateur (§ 9.3). Elle est portée par la **session**, pas par un
en-tête par requête : une bascule qu'on oublie de rejouer sur un appel ne vérifie plus rien,
et l'état doit survivre à la navigation pour que l'administrateur explore réellement l'arbre
sous cette identité. Elle est journalisée **une fois**, à l'activation, sous
`vue_comme_membre` — « c'est une lecture de données masquées, elle se journalise ».

La réponse est la charge de session du § 2.2, avec :

```json
"voirComme": {
  "memberId": 7,
  "memberLabel": "Cobalt Romarin",
  "depuis": "2026-09-15T21:12:00+02:00"
},
"cercle": { "regime": "cercle", "generations": 3, "taille": 34 }
```

`cercle` décrit désormais le cercle **emprunté**. Le front affiche un bandeau permanent : sans
lui, un administrateur conclura à un bug en ne voyant plus la moitié des fiches.

Trois règles, et elles sont toutes des pièges si on les découvre après coup :

1. **L'`ETag` du graphe incorpore le membre emprunté** tant que la bascule est active
   (§ 5.2). Sans cela, l'administrateur revalide, reçoit `304`, et regarde **son propre
   graphe non filtré** en croyant tester celui de quelqu'un d'autre : la bascule ne vérifie
   plus rien, et c'est précisément ce qu'elle existe pour faire.
2. **Toute route d'écriture rend `409 vue_comme_membre_active`** tant que la bascule est
   active — création de demande, validation, refus, invitation, téléversement, réglages. On
   ne valide pas une demande sous une identité empruntée : la `prp_revision` porterait
   l'administrateur alors que l'écran montrait autre chose, et le journal deviendrait
   ininterprétable. Le front désactive les boutons ; l'API ne compte pas dessus.
3. **Le calque « en attente » suit le membre emprunté**, pas l'administrateur : c'est ce que
   voit réellement ce membre. Pour revoir toutes les demandes en attente, on quitte la
   bascule.

`DELETE /api/administration/voir-comme` rend la session normale. Le front recharge alors la
session **et** le graphe.

> **Ce n'est pas un contournement du filtre.** Le normaliseur n'a pas de mode « sans filtre » :
> l'administrateur passe par un cercle complet, jamais par un `if ($estAdmin) { return
> $tout; }` — « exactement le bug qu'on découvrira dans deux ans, le jour où un rôle sera
> attribué par erreur » (ADR 0003). La bascule remplace un cercle par un autre ; elle n'en
> supprime jamais un.

### 10.5 Ce qu'un administrateur voit de plus

Il n'y a **aucune route d'administration qui rende des données de personne autrement
filtrées**. Ce qu'un administrateur voit de plus tient en trois choses, toutes hors de `Tree` :

- le cercle de l'administrateur est le cercle complet de l'arbre, donc toute fiche lui est
  `visibilite: "complete"` ;
- il voit les demandes et les médias `en_attente` de tout le monde, pas seulement les siens ;
- il voit `sha256`, `originalName`, le journal d'audit et les invitations.

### 10.6 Import et export GEDCOM

`POST /api/administration/gedcom/import` avec `{"chemin": "…", "dryRun": true}`.

- `chemin` est **une variable de configuration résolue côté serveur**, pas un chemin libre
  envoyé par le client : le § 0 du dossier impose que le chemin du GEDCOM soit une variable
  d'environnement, « jamais un chemin en dur », et l'atelier `C:\www\myHeritage` est en
  **lecture seule**. Le corps ne porte donc qu'une **clé de source** déclarée dans
  `.env` (`gedcom.source_defaut`), jamais un chemin de système de fichiers — un chemin libre
  serait une lecture de fichier arbitraire déguisée en fonctionnalité d'administration.
- `dryRun: true` lit, compte, signale, **n'écrit rien** : « c'est le mode dans lequel on
  découvre qu'un fichier est mauvais avant d'avoir touché la base » (§ 8.1).
- `202 Accepted` : l'exécution est synchrone côté console mais longue côté HTTP ; l'API rend
  la ligne `ged_run` à l'état `en_cours` et le front interroge
  `GET /api/administration/gedcom/executions/{id}`. Une exécution déjà en cours ⇒ `409`
  `import_en_cours`.

```json
{
  "id": 3,
  "direction": "import",
  "status": "reussi",
  "filename": "arbre-romarin-v5.ged",
  "fileSha256": "c1a9…",
  "byteSize": 464812,
  "gedcomCharset": "UTF-8",
  "dryRun": true,
  "counts": {
    "individus": 274, "familles": 103, "filiations": 174,
    "sources": 28, "citations": 483, "faits": 87, "notes": 175, "medias": 31
  },
  "startedAt": "2026-09-15T22:00:00+02:00",
  "finishedAt": "2026-09-15T22:00:14+02:00",
  "error": null,
  "launchedByLabel": "natron@example.net",
  "anomalies": {
    "info": 525,
    "avertissement": 12,
    "erreur": 0,
    "extrait": [
      { "severity": "avertissement", "code": "nom_vide", "lineNo": 4471, "gedcomXref": "@I500188@", "tag": "NAME", "message": "Individu sans ligne NAME : ancêtre inconnu, branche de recherche ouverte." }
    ]
  }
}
```

`counts` est la table de contrôle du § 4 du dossier — « un import qui ne retombe pas exactement
sur ces nombres est un import raté ». Un écart émet `dénombrement_inattendu` en sévérité
`erreur` et **bloque l'import hors `dry_run`** (§ 8.2).

`anomalies.extrait` est plafonné à 50 lignes ; le rapport complet se lit par
`GET /api/administration/gedcom/executions/3?severity=avertissement&page=2`, qui pagine les
`ged_issue`.

`POST /api/administration/gedcom/export` produit un fichier GEDCOM et une ligne `ged_run` de
direction `export`. Le fichier se récupère par `GET .../executions/{id}/fichier`, route
authentifiée comme celles des médias. **Un export contient l'arbre entier, personnes vivantes
comprises** : il est réservé à `ROLE_ADMIN`, journalisé `export_gedcom`, et il n'existe
**aucune route d'export pour un membre** — « pas de liste, pas d'export » (ADR 0003).

### 10.7 Sources

Module `Tree`, ADR 0012. Une source est une notice bibliographique : l'administration la crée
et la corrige **hors de la file** de l'ADR 0004 ; la citation qui l'emploie, elle, reste une
demande `ajout_citation` (§ 6.4 bis), qui désigne la source par son `id`.

| Méthode | Chemin | Accès | Corps | Succès | Erreurs |
|---|---|---|---|---|---|
| `GET` | `/api/administration/sources` | `ADMIN` | `?q=&page=&parPage=` | `200` liste paginée | — |
| `POST` | `/api/administration/sources` | `ADMIN` | source | `201` source | `422` · `409` `vue_comme_membre_active` |
| `GET` | `/api/administration/sources/{id}` | `ADMIN` | — | `200` source | `404` |
| `PUT` | `/api/administration/sources/{id}` | `ADMIN` | source | `200` source | `404` · `422` · `409` `vue_comme_membre_active` |

- La liste est triée par `title`. `q` filtre sur le titre, **sans accents ni casse** (le
  repliement de `Core\Service\CleDeRecherche`) : `etat civil` trouve « État civil de Paris ».
  `parPage` vaut 50 par défaut, 100 au plus.
- Le corps de `POST` et de `PUT` : `title` (obligatoire, 255 caractères au plus),
  `sourceKind` (obligatoire : `acte_etat_civil`, `recensement`, `registre_officiel`,
  `arbre_tiers`, `publication`, `temoignage`, `autre`), et, facultatifs, `author` (255),
  `publication`, `text`, `note` (10 000 chacun), `mediaType` (60). Un champ blanc vaut `null`.
  `PUT` **remplace** la notice : un champ facultatif absent est vidé.
- `gedcomXref` est rendu mais **jamais lu** : il appartient au ré-import GEDCOM, et une
  source créée ici le garde à `null` (ADR 0012).
- Pas de `DELETE` (ADR 0012).

```json
{
  "id": 29,
  "gedcomXref": null,
  "title": "État civil de Paris",
  "author": "Archives de Paris",
  "publication": "archives.paris.fr — actes de l'état civil reconstitué et registres depuis 1860",
  "text": null,
  "note": null,
  "mediaType": null,
  "sourceKind": "acte_etat_civil",
  "sourceKindLabel": "Acte d'état civil",
  "citationCount": 3,
  "createdAt": "2026-09-30T10:12:00+02:00",
  "updatedAt": "2026-09-30T10:12:00+02:00"
}
```

---

## 11. Le cercle de visibilité dans les réponses

> **Le filtrage est appliqué côté serveur, dans la sérialisation. Jamais côté client.**
> Un champ masqué n'est pas envoyé puis caché : il n'est pas envoyé (§ 6.2 du dossier,
> ADR 0003, § 8 du dossier). Cette section existe pour qu'aucun agent ne puisse croire que
> filtrer à l'affichage serait une implémentation acceptable — il suffit d'ouvrir l'onglet
> réseau du navigateur pour voir que ce n'en est pas une.

Rappel de la règle (ADR 0003, § 9.3), pour que ce document se suffise :

```
A(u) = { u } ∪ { a : a ancêtre de u, distance(a, u) ≤ N }      N = cercle.generations, 3 par défaut, 1 ≤ N ≤ 6
D(u) = { d : d descendant, à toute profondeur, d'un membre de A(u) }
C(u) = A(u) ∪ D(u) ∪ { c : c conjoint d'un membre de A(u) ∪ D(u) }
```

Les conjoints sont ajoutés **en une seule passe, en dernier, et ne propagent rien.** Ce n'est
pas un point fixe : un agent qui itère jusqu'à stabilité implémente autre chose, et c'est ce
qui sépare un cercle de trente-cinq personnes d'un cercle qui avale la moitié de l'arbre au
premier remariage.

Et surtout : **le cercle ne décide que du sort des personnes vivantes.** Une personne décédée
est un fait historique, visible de tout compte connecté, sans restriction — 256 fiches sur 274
ne sont jamais concernées.

> **Révision du 2026-10-01 — les vivants visibles des membres (ADR 0003).** Décision du
> commanditaire : les membres sont de la famille. Un lecteur du régime `cercle` — membre
> validé, administrateur, administrateur sous la bascule « voir comme un membre » — reçoit
> **toutes** les personnes vivantes **complètes** : nom, dates, lieux, fiche, recherche,
> graphe, chronologie, historique, médias, assistant. Le cercle `C(u)` ci-dessus ne décide
> plus, pour lui, que du sort des fiches à **confidentialité forcée** (`forcePrivacy`) :
> une telle fiche reste servie **réduite**, sous `"Personne vivante"`, à quiconque ne l'a
> pas dans son cercle — comme avant la révision.
>
> **Comment lire le reste de ce document.** Partout où il écrit « personne vivante hors
> cercle », il faut lire, pour un lecteur du régime `cercle` : *fiche confidentielle hors de
> son cercle*. Les règles de masquage, la liste blanche, les `404` et l'absence de la
> recherche ou de la chronologie s'appliquent à ces fiches-là, inchangées. Le régime
> `aucun` (§ 12) n'est pas touché : aucune personne vivante, confidentielle ou non — et le
> compte en attente n'atteint de toute façon plus l'arbre (`403 regime_en_attente`).

### 11.1 La même fiche, sérialisée deux fois

Pyroxène Tilleul, `tree_person.id = 202`, né vers 1934, **vivant** (§ 9.2 : la règle le classe
vivant, et la note GEDCOM le décrit comme « vivant à quatre-vingt-douze ans, improbable mais non
exclu »). Deux lecteurs, la même route `GET /api/arbre/personnes/202`, le même instant.

**Lecteur A — membre actif, Pyroxène est dans son cercle.**

```json
{
  "id": 202,
  "gedcomXref": "@I500202@",
  "displayName": "Pyroxène Tilleul",
  "givenNames": "Pyroxène",
  "surname": "Tilleul",
  "marriedName": null,
  "nickname": null,
  "sex": "masculin",
  "deceased": false,
  "vivant": true,
  "birth": {
    "raw": "ABT 1934",
    "qualifier": "environ",
    "min": "1934-01-01",
    "max": "1934-12-31",
    "place": "Sorbière, Fougerie, Florie",
    "texte": "vers 1934"
  },
  "death": {
    "raw": null,
    "qualifier": "inconnue",
    "min": null,
    "max": null,
    "place": null,
    "texte": ""
  },
  "ligneDates": "n. ~1934",
  "branch": "mm",
  "generation": -2,
  "bestQuay": 1,
  "citationCount": 2,
  "forcePrivacy": false,
  "privacyNote": null,
  "version": 4,
  "visibilite": "complete",
  "parente": "grand-oncle",
  "portrait": {
    "mediaId": 91,
    "url": "/api/medias/91/fichier",
    "vignetteUrl": "/api/medias/91/vignette",
    "caption": "Photo de famille, 1979"
  },
  "parents": [
    { "personId": 150, "displayName": "Azurite Tilleul", "relation": "naissance", "familyId": 61 }
  ],
  "fratrie": [
    { "personId": 151, "displayName": "Zircon Tilleul", "siblingOrder": 2 }
  ],
  "unions": [
    {
      "familyId": 77,
      "conjoint": { "personId": 203, "displayName": "Galène Verveine" },
      "marriage": {
        "raw": "26 MAY 1962", "qualifier": "exacte",
        "min": "1962-05-26", "max": "1962-05-26",
        "place": "Sorbière, Fougerie, Florie",
        "texte": "26 mai 1962"
      },
      "divorced": false,
      "divorce": null,
      "enfants": [
        { "personId": 260, "displayName": "Marbre Tilleul" }
      ]
    }
  ],
  "faits": [
    {
      "id": 412,
      "type": "profession",
      "label": "Chainetier (Forgeron?)",
      "happened": {
        "raw": "1957", "qualifier": "annee",
        "min": "1957-01-01", "max": "1957-12-31",
        "place": "Sorbière, Fougerie, Florie",
        "texte": "1957"
      },
      "address": null, "age": null, "cause": null
    }
  ],
  "citations": [
    {
      "id": 1204,
      "scope": "naissance",
      "quay": 1,
      "quayLabel": "index ou déduction",
      "appui": "index",
      "appuiLabel": "tenu par un index ou un relevé",
      "page": "Sorbière, table décennale 1930-1939",
      "evenContext": "record",
      "url": null,
      "dataText": null,
      "source": { "id": 11, "title": "Archives départementales de la Fougerie", "author": null, "sourceKind": "acte_etat_civil", "sourceKindLabel": "Acte d'état civil" },
      "medias": []
    }
  ],
  "notes": [
    { "id": 188, "scope": "generale", "body": "Vivant à quatre-vingt-douze ans, improbable mais non exclu.", "authorLabel": null, "createdAt": "2026-09-13T21:13:00+02:00" }
  ],
  "medias": [
    { "mediaId": 91, "kind": "portrait", "caption": "Photo de famille, 1979", "url": "/api/medias/91/fichier", "vignetteUrl": "/api/medias/91/vignette" }
  ],
  "enAttente": {},
  "demandesEnAttente": []
}
```

**Lecteur B — membre actif, Pyroxène est vivant et hors de son cercle.** Même route, même
instant, `200` :

```json
{
  "id": 202,
  "displayName": "Personne vivante",
  "sex": "masculin",
  "generation": -2,
  "branch": "mm",
  "vivant": true,
  "visibilite": "restreinte"
}
```

**Sept clés. C'est tout.** Et `displayName` ne porte plus le nom : depuis la révision du
2026-10-01 de l'ADR 0003, il vaut le libellé neutre `"Personne vivante"` — la clé reste pour
que l'écran ait de quoi titrer la fiche, la valeur ne dit rien. Pas une clé de plus, et surtout aucune clé à `null` : les champs
interdits sont **absents**, non pas vides (§ 1.3). Un `"birth": null` dirait « on ne sait
pas » là où la vérité est « tu n'as pas le droit de savoir », et le front afficherait un tiret
au lieu de ne rien afficher.

Ce qui a disparu, nommément, et qu'aucune implémentation ne doit réintroduire : **le nom**
(sous quelque clé que ce soit), `gedcomXref`,
`givenNames`, `surname`, `marriedName`, `nickname`, `deceased`, `birth`, `death`,
`ligneDates`, `bestQuay`, `citationCount`, `forcePrivacy`, `privacyNote`, `version`,
`parente`, `portrait`, `parents`, `fratrie`, `unions`, `faits`, `citations`, `notes`,
`medias`, `enAttente`, `demandesEnAttente`.

Noter en particulier :

- **`ligneDates` disparaît.** C'est du formatage, mais c'est du formatage **de dates** : la
  laisser reviendrait à envoyer la naissance sous une autre orthographe. Le § 9.2 le dit pour
  `core_audit_log.subject_label` — « rempli avec le `display_name` et jamais avec une ligne de
  dates » ; la règle vaut partout.
- **`parents`, `fratrie`, `unions` disparaissent de la fiche**, alors que **les arêtes
  correspondantes restent dans le graphe**. Ce n'est pas une incohérence : le graphe expose la
  topologie, ce que l'ADR 0003 accepte explicitement comme risque résiduel ; la fiche, elle,
  exposerait **les noms et les identifiants** de la parenté sous forme de liste, c'est-à-dire
  une énumération. Le graphe montre, il n'énumère pas.
- **`version` disparaît**, et c'est ce qui rend matériellement impossible de déposer une
  demande sur cette fiche : sans `baseVersion`, `POST /api/demandes` rend `422` (§ 6.2). Le
  contrôle d'écriture est donc redondant avec le contrôle de lecture, ce qui est le bon sens
  de la défense en profondeur.
- **`vivant` reste.** Il vaut `true`, et il annonce donc que la personne est vivante. C'est le
  « masquage est son propre indice » que l'ADR 0003 accepte : `.fiche.prive` (contour en
  tirets) est conservée au rendu, et le canevas a besoin du booléen pour la poser (ADR 0007).
  *`vivant` ne figure pas dans la liste blanche écrite de l'ADR 0003 ; c'est un manque de
  l'ADR, pas une tolérance de ce document — point signalé au § 18.*

### 11.2 Le même nœud dans le graphe

Au § 5.2, `p202` porte `visibilite: "restreinte"` pour le lecteur B — **le nœud reste
présent, à sa place, avec ses arêtes.** Un arbre dont on retire des nœuds n'est plus un
arbre : « les branches flottent, les fratries sont fausses, le calcul de parenté est faux »
(ADR 0003). Et c'est ce qui permet au placement d'être calculé **une fois pour tout le monde**
(étage 1, ADR 0005) : le cercle masque des champs, pas des nœuds. Les deux décisions sont
liées, et enfreindre la première casse la seconde.

Dans le nœud, **quatre clés tombent** — `etiquetteDates`, `bestQuay`, `citationCount` et
`avecImage` — et les autres restent : `x`, `y`, `w`, `h`, `displayName`, `etiquetteNom`,
`sex`, `generation`, `branch`, `vivant`, `parente` (et `pont` sur une famille par alliance).

**`displayName` et `etiquetteNom` valent `"Personne vivante"`** — révision du 2026-10-01 de
l'ADR 0003. Elles restaient jusque-là avec le vrai nom, au motif que la seconde n'est qu'une
troncature de la première ; c'est le nom lui-même qui a quitté la liste blanche. Le masque est
posé à la construction de la charge, jamais par remplacement après coup. `etiquetteDates`,
elle, tombe toujours : elle porterait des dates. Quant aux trois champs de filtre, le § 16.2 interdit nommément `bestQuay` et
`citationCount` pour une personne vivante hors cercle, et `avecImage` révélerait qu'elle a des
médias — c'est-à-dire qu'il existe des photographies d'elle, ce qui est précisément le genre
de fait qu'on ne veut pas voir sortir.

**Ils sont absents, jamais à zéro ni à `null`** (§ 1.3). Un `citationCount: 0` sur une fiche
qui en a six est un mensonge ; une clé absente est une frontière.

> **`w` est identique pour tous les lecteurs, et il ne faut surtout pas la traiter à part.**
>
> *Révision du 2026-10-01* : le raisonnement qui suit vaut pour **le moteur**. Le graphe sert
> la fiche affichée, remesurée par l'affinage — et celle-là lisait la naissance des vivants,
> puis, une fois le nom retiré de la charge, sa longueur restait lisible dans `w` et dans
> l'écart des `x`. La largeur affichée d'un vivant est donc **une constante, 215, pour tout
> le monde** (§ 5.2) : elle reste identique pour tous les lecteurs, ce qui est la propriété
> que ce paragraphe défend, et ne dépend plus d'aucune donnée masquée.

La largeur de fiche est calculée sur le texte — `max(104, max(len(nom) × 6.6,
len(ligneDates) × 5.8) + 2 × 14)`, **tronquée** à l'entier — et l'on pourrait croire qu'elle
encode la longueur de la ligne de dates masquée. **Elle ne l'encode pas.** `graphe.py` court-
circuite `ligne_dates()` sur le drapeau privé et rend la **constante littérale `"vivant·e"`**
(8 signes) : la mesure d'une personne vivante ne lit jamais ses dates, pour personne. Elle ne
dépend que du nom — qu'on envoie de toute façon à tout le monde — et d'une constante.

Conséquence : **le moteur porté ne prend aucun paramètre de lecteur pour mesurer**, et
reproduit `graphe.py` à l'identique. Ici, **reproduire fidèlement *est* la mesure de
sécurité** : la constante ferme la fuite et satisfait le critère d'égalité exacte des
377 positions (ADR 0005) par le même geste. Une implémentation qui « colmaterait » en
mesurant autrement pour un nœud restreint casserait le critère de non-régression pour rien.

Et c'est une propriété d'architecture, pas un détail de rendu : **l'état vivant est une
propriété de la personne, jamais du lecteur.** La largeur est donc la même pour tout le monde,
et c'est précisément ce qui permet à l'étage 1 du cache de placement d'exister — un objet
unique pour toute l'application (ADR 0005). Une largeur qui aurait dépendu du cercle du
lecteur aurait imposé **un placement par compte**, c'est-à-dire exactement l'architecture que
le projet a écartée. C'est le même mécanisme que « le cercle masque des champs, pas des
nœuds » : dès qu'une grandeur géométrique dépend du lecteur, le cache partagé tombe.

`etiquetteDates` en revanche **tombe bien** du nœud restreint : c'est une décision de
sérialisation (§ 1.3), sans effet sur `w`, que le moteur a déjà calculée. Elle vaudrait
`"vivant·e"` de toute façon — l'omettre ne cache donc rien de plus que `vivant: true`
n'annonce déjà, et évite au canevas d'afficher deux fois la même information.

### 11.3 Les libellés dénormalisés — la huitième porte

Neuf colonnes `*_label` sur six tables de trois modules stockent le nom d'une personne
potentiellement vivante, et **aucune ne traverse le normaliseur de `Tree\Person`** (§ 0.4,
ADR 0003) : `mbr_invitation.person_label`, `mbr_member.person_label`,
`prp_change_request.target_label`, `prp_draft_link.subject_label` / `other_label` /
`other2_label` / `family_label`, `prp_revision.subject_label`, `med_media.subject_label`.

> **Toute clé `*Label` désignant une personne est filtrée comme la personne elle-même.**

Concrètement, dans les réponses de `Proposal`, `Membership` et `Media` : si la personne
désignée est vivante et hors du cercle du lecteur, la clé `…Label` est **absente**, et la clé
`…Id` qui l'accompagne l'est aussi. En pratique ces files sont réservées à l'auteur et aux
administrateurs, donc le risque est contenu — mais « en pratique » n'est pas une garantie, et
c'est la porte qu'aucun des deux corpus n'avait comptée.

### 11.4 Le test de liste blanche

L'ADR 0003 exige **un test fonctionnel par porte de sortie**, comparant la réponse à une
**liste blanche** de champs — jamais à une liste noire, qui oublierait le champ ajouté demain.
Ce document donne les listes exactes, pour que le test soit une recopie :

| Porte | Route | Liste blanche pour une personne vivante hors cercle |
|---|---|---|
| 1 — fiche | `GET /api/arbre/personnes/{id}` | `id`, `displayName`, `sex`, `generation`, `branch`, `vivant`, `visibilite` — **`displayName` vaut `"Personne vivante"`** ; et dans la fiche **visible** d'un proche, ses références (`parents`, `fratrie`, `unions[].conjoint`, `unions[].enfants`) portent le même masque |
| 1 bis — famille | `GET /api/arbre/familles/{id}` | époux : `personId`, `displayName`, `visibilite` ; enfant : `personId`, `displayName`, `siblingOrder`, `relation` — **`displayName` vaut `"Personne vivante"`** |
| 2 — graphe | `GET /api/arbre/graphe` (nœud) | `id`, `type`, `personId`, `x`, `y`, `w`, `h`, `displayName`, `etiquetteNom`, `sex`, `generation`, `branch`, `vivant`, `parente`, `pont` (si `parente` vaut `"alliance"`), `visibilite` — **`displayName` et `etiquetteNom` valent `"Personne vivante"`, `w` vaut 215** ; **`etiquetteDates`, `bestQuay`, `citationCount` et `avecImage` absents** |
| 3 — recherche | `GET /api/arbre/recherche` | **aucune entrée** — la personne est absente |
| 4 — chronologie | `GET /api/chronologie` | **aucune barre** — la personne est absente |
| 5 — média | `GET /api/medias`, `/api/medias/{id}` | **aucun élément** ; le fichier rend `404` |
| 6 — notification | `GET /api/notifications` | aucun `title`/`body` ne nomme la personne |
| 7 — diff | `GET /api/demandes/{id}`, `/api/administration/demandes/{id}` | `…Label` et `…Id` de cette personne absents |
| 7 bis — assistant | `POST /api/assistant/analyses` (`personnes[]`, `personne`, lignes de changement) | `personId`, `displayName` = `"Personne vivante"`, `visibilite` ; aucune date |
| 7 ter — questions à l'assistant | `GET`/`POST /api/assistant/conversations…` (`echanges[].personnes[]`, mentions de `reponse`) | `personId`, `displayName` = `"Personne vivante"`, `visibilite` ; aucune date ; libellé de mention = `"Personne vivante"` |
| 8 — libellés | `Proposal`, `Membership`, `Media` (§ 11.3) | idem |

Le test compare **l'ensemble exact des clés** de l'objet, pas un sous-ensemble : un champ en
plus le fait échouer. C'est le garde-fou réel de l'ADR 0003 ; sans lui, le reste est une
intention.

---

## 12. Le régime du compte en attente de validation

> **Ce n'est pas un cercle réduit, c'est un régime distinct.** Le service de cercle ne rend
> pas une liste vide pour un compte en attente : il rend « **pas de cercle** » — un état à
> part, que le code doit pouvoir distinguer de « cercle calculé, résultat vide ». Il ne faut
> **pas** l'implémenter comme « un cercle avec `N = 0` » : la nuance disparaîtrait au premier
> remaniement, et un `N = 0` reste un cercle — il contient encore `u` et ses conjoints, donc
> il laisserait sortir la fiche d'une personne vivante. (§ 4.5, ADR 0003, décision du
> commanditaire consignée en `docs/arbitrages.md` § 3.)

Dans le JSON, cette distinction est portée par **une clé, dans la charge de session** :

```json
"cercle": { "regime": "aucun" }
```

contre

```json
"cercle": { "regime": "cercle", "generations": 3, "taille": 34 }
```

Le front branche sur `regime`. Il ne teste **jamais** `taille === 0` : les deux champs
`generations` et `taille` sont **absents** du régime `aucun`, et les lire produirait
`undefined`, pas `0`.

**Un compte inscrit sans fiche (`a_rattacher`, ADR 0013) est dans ce régime, et rien de plus
ni de moins.** Il n'a pas de `person_id` : il n'y a donc même pas d'ancre à laquelle un
cercle se rattacherait, et la base interdit qu'un compte sans fiche soit actif
(`ck_mbr_member_sans_fiche_inactif`). Il ne lit rien de l'arbre, n'écrit rien — sa propre
photo mise à part (§ 7.1) —, et n'obtient l'arbre et un cercle qu'au rattachement (§ 10.1).

### 12.1 Ce que ce régime voit, route par route

**Rien de l'arbre** — ni les personnes vivantes, ni les décédées (révision du 2026-10-01,
ADR 0003). Toute lecture de l'arbre lui répond `403` `regime_en_attente`, dans l'enveloppe du
§ 15.1, avec le message :
`"Votre inscription est en cours de vérification. L’arbre vous sera ouvert dès qu’elle sera validée."`
Le refus est rendu **avant** le contrôleur : aucune donnée n'est chargée, et aucun `ETag`
n'est revalidé (un `304` serait une autorisation de garder l'arbre à l'écran).

| Route | Réponse pour un compte `en_attente` |
|---|---|
| `POST /api/connexion`, `/api/deconnexion`, `GET /api/session` | **normal** |
| `POST /api/session/mot-de-passe`, `POST /api/session/courriel` | **normal** |
| `GET /api/inscription/etat` | **normal** — l'écran d'attente (§ 4.4) |
| `GET /api/notifications`, `/compteur`, `POST .../lue`, `POST /lues` | **normal** — il reçoit la notification de son activation |
| `GET /api/push/cle`, `PUT`/`DELETE /api/push/abonnement` | **normal** — elle lui arrive aussi sur le téléphone |
| `POST /api/medias/portrait-du-compte` | **normal** — sa photo d'inscription (§ 7.1) |
| `GET /api/medias/{id}`, `/fichier`, `/vignette` — **sa** photo d'inscription | `200` |
| `GET /api/arbre/graphe`, `/personnes/{id}`, `/familles/{id}`, `/recherche` | **`403` `regime_en_attente`** |
| `GET /api/chronologie`, `GET`/`PUT /api/chronologie/preferences` | **`403` `regime_en_attente`** |
| `GET /api/carte`, `GET /api/anniversaires` | **`403` `regime_en_attente`** |
| `GET /api/medias`, et `GET /api/medias/{id}` (`/fichier`, `/vignette`) de tout autre média, existant ou non | **`403` `regime_en_attente`** |
| `GET /api/demandes/historique/...`, `GET /api/demandes/suppression/personne/{id}` | **`403` `regime_en_attente`** |
| `GET /api/questions`, `/api/tirages`, `/api/evenements`, `/api/demarches` (et leurs sous-routes) | **`403` `regime_en_attente`** |
| **`POST /api/demandes`**, **`POST /api/medias`** | **`403` `regime_en_attente`** |
| Toute route `/api/administration/*` | `403` (`access_control`, `ROLE_ADMIN`) |

### 12.2 Pourquoi rien n'est ouvert — ni la lecture, ni l'écriture

**La lecture — révision du 2026-10-01.** La rédaction initiale l'ouvrait : « l'attente peut
durer plusieurs jours, et un écran vide pousse à l'abandon ; l'arbre ancien donne du sens à
l'attente sans rien exposer de sensible » (§ 6.1 du dossier). C'était vrai des **fiches**,
faux du **graphe** : il sert chaque personne en nœud, et un vivant hors cercle y garde, par la
liste blanche du § 11, son **nom complet**, son sexe, sa génération et sa branche. Or le lien
d'invitation circule dans un groupe de messagerie : n'importe qui pouvait s'inscrire sous un
faux nom — sans même se trouver dans l'arbre depuis l'ADR 0013 — et lire la liste des vivants
de la famille. Décision du commanditaire : un compte en attente ne voit **rien** de l'arbre.
Le prix, assumé : l'écran d'attente reste vide de l'arbre pendant la vérification ; c'est la
notification d'activation (§ 12.3) qui fait revenir la personne.

L'écriture : contribuer suppose d'être rattaché. Une demande porte `author_user_id` et
`author_label` ; une demande déposée par un compte dont le rattachement n'est pas établi
serait une contribution sans auteur identifié, et l'administrateur devrait arbitrer deux
choses à la fois. `403 regime_en_attente`, avec un message qui dit quoi faire :
`"Votre rattachement est en cours de vérification. Vous pourrez proposer des modifications une fois votre compte activé."`

**Un seul code pour les deux refus**, deux messages : le front n'a qu'un cas à reconnaître,
et il mène au même écran.

### 12.3 Le moment de bascule

`POST /api/administration/membres/{id}/activer` (§ 10.1) est le **seul** endroit où le régime
change. À cet instant : `core_user.status` et `mbr_member.status` passent `actif`,
`activated_at` est posé, **et le compte obtient un cercle** — calculé à la première requête
suivante, mis en cache sous `cercle.{empreinte}.{memberId}.{N}` (§ 9.3).

Le compte concerné doit **recharger sa session** pour voir le changement : rien ne le lui
pousse (pas de Redis, pas de file — § 3 du dossier). C'est la notification qui le lui apprend,
et le front recharge session et graphe à sa lecture. Elle est de nature **`compte_active`**
(« Votre compte est activé », lien `accueil`) et passe par `NotificationService` : elle part
donc **aussi sur le téléphone** (Web Push). Même notification après « Créer la fiche et
rattacher » (§ 10.1). Révision du 2026-10-01 : elle partait sous `compte_en_attente`, sans
lien (`session` n'est le nom d'aucune route du front) et sans push.

**Il n'y a aucun cache à invalider à l'activation.** Le nouveau membre a une clé de cercle qui
lui est propre ; il n'existait aucune entrée sous cette clé (§ 2.3, troisième ligne du
tableau). Un agent qui ajouterait une invalidation ici ajouterait un déclencheur à une liste
qui n'existe pas — c'est exactement la dette que l'empreinte recalculée supprime.

---

## 13. Le calque « en attente »

> L'API renvoie les valeurs **validées**, plus un calque des valeurs **proposées**, et ce
> calque n'est servi **qu'à l'auteur de la demande et aux administrateurs**. Les autres
> membres voient les données validées, point. (§ 6.3 du dossier, ADR 0004, § 9.4.)

### 13.1 La forme exacte

`enAttente` est une clé **toujours présente** sur une fiche de personne, une fiche de famille
et une citation, valant `{}` quand il n'y a rien. Toujours présente, parce qu'un front qui
doit tester `"enAttente" in fiche` avant de tester son contenu écrira un jour le second test
sans le premier.

```json
"enAttente": {
  "surname": {
    "propose": "BOURRACHE",
    "actuel": "Bourrache",
    "demandeId": 14,
    "auteur": "Opale Romarin",
    "depuis": "2026-09-14T11:02:00+02:00",
    "autresDemandes": []
  },
  "birth": {
    "propose": {
      "raw": "BET 1901 AND 1909", "qualifier": "entre",
      "min": "1901-01-01", "max": "1909-12-31",
      "place": "Saule, Ombrage, Sylvanie",
      "texte": "entre 1901 et 1909"
    },
    "actuel": {
      "raw": "1987", "qualifier": "annee",
      "min": "1987-01-01", "max": "1987-12-31",
      "place": null,
      "texte": "1987"
    },
    "demandeId": 13,
    "auteur": "Tuf Romarin",
    "depuis": "2026-09-14T15:40:00+02:00",
    "autresDemandes": [17]
  }
}
```

C'est la forme du § 9.4 — `{"surname": {"propose": …, "actuel": …, "demandeId": …}}` — avec
trois ajouts que la mise à l'épreuve a rendus nécessaires :

- **`auteur` et `depuis`.** Un administrateur voit le calque de **toutes** les demandes en
  attente ; sans l'auteur, la pastille ne dit pas de qui vient la proposition, et l'écran
  d'administration doit rouvrir chaque demande pour le savoir.

  **`depuis` n'est jamais `null`**, et la raison est structurelle plutôt que défensive : le
  calque ne contient **que des demandes `en_attente`** (§ 13.3), et
  `ck_prp_request_soumission` garantit `submitted_at IS NOT NULL` dès que le statut n'est plus
  `brouillon` (§ 5.1). Un brouillon n'entre jamais dans `enAttente` — il est « visible de son
  seul auteur, pas dans la file » (§ 5.7), et il n'a rien à appliquer puisqu'il n'a pas été
  soumis. Côté serveur, un `?->` sur `getSubmittedAt()` **masque donc un cas qui ne doit pas
  exister** : si cette valeur est nulle, c'est qu'un brouillon s'est glissé dans le calque, et
  c'est un défaut à faire remonter, pas à aplatir en `null`. Côté front, la clé se type
  **non nullable**.
- **`autresDemandes`.** L'ADR 0004 prévoit explicitement que deux membres proposent en
  parallèle sur la même fiche, et le § 9.4 ne dit pas ce que devient `enAttente[champ]` dans
  ce cas. Décision : l'entrée porte la demande **la plus ancienne par `submittedAt`**, et
  `autresDemandes` liste les identifiants des autres. Un tableau plutôt qu'un objet aurait
  changé l'arité documentée au § 9.4 et cassé tout front écrit contre elle. *Point signalé au
  § 18.*

### 13.2 Comment le front distingue une valeur validée d'une valeur en attente

> **La valeur validée reste dans le corps principal du document. Le calque est à côté,
> jamais à la place.**

```
fiche.surname          → "Bourrache"    ← la valeur validée, toujours
fiche.enAttente.surname → { propose: "BOURRACHE", actuel: "Bourrache", … }
```

Règle d'affichage, une phrase : **si `enAttente[champ]` existe, afficher `propose` avec la
pastille « en attente de validation », et rendre `actuel` accessible** (au survol, dans un
repli, comme on veut) ; sinon, afficher `fiche[champ]`.

Trois conséquences qui justifient ce choix plutôt qu'une substitution dans le corps :

1. **Un client qui ignore `enAttente` affiche l'arbre validé** — le comportement correct par
   défaut. C'est la raison pour laquelle le calque est un champ **ajouté** et non une
   substitution (§ 9.4). Un client mal fini montre des données justes, pas des données
   proposées présentées comme validées.
2. **`actuel` est redondant avec `fiche[champ]`, et c'est voulu.** Il vaut `old_value` de
   l'op, c'est-à-dire la valeur **au moment de la soumission**. Si la fiche a changé depuis,
   `actuel` et `fiche[champ]` **diffèrent** — et cette différence est précisément ce qui
   annonce une caducité à venir (§ 6.8). Le front peut l'afficher ; il ne doit pas supposer
   qu'ils sont égaux.
3. **La clé du calque est littéralement la clé du corps** (§ 1.2). Pas de table de
   correspondance, pas de traduction, pas de champ qui se perd quand on en ajoute un.

Pour un bloc date, `propose` et `actuel` portent le bloc **entier**, avec sa clé `texte`
rendue — parce qu'un bloc date est atomique dans le diff comme dans le modèle, et qu'il
s'affiche en une seule ligne : « Naissance : 1987 → entre 1901 et 1909 » (§ 5.2).

### 13.3 Qui reçoit le calque

| Lecteur | `enAttente` sur une fiche |
|---|---|
| L'auteur d'une demande `en_attente` sur cette fiche | les ops **de ses propres demandes** |
| Administrateur | les ops de **toutes** les demandes `en_attente` sur cette fiche |
| Administrateur avec la bascule « voir comme un membre » active | ce que verrait **le membre emprunté** (§ 10.4) |
| Tout autre membre | `{}` |
| Compte `en_attente` | `{}` — il ne peut pas déposer de demande (§ 12) |

Le calcul est celui du § 9.4 : `idx_prp_request_cible` pour trouver les demandes en cours sur
la personne, `idx_prp_op_sujet` pour charger les ops. **Il se fait à la sérialisation**, dans
le même normaliseur que le cercle de visibilité (ADR 0004) — les deux filtres sont composés,
ils ne se remplacent pas. Une personne vivante hors cercle n'a **pas** de clé `enAttente` du
tout : la clé fait partie de ce qui n'est pas envoyé (§ 11.1).

### 13.4 Une personne proposée mais non validée

Elle apparaît dans le graphe **pour son auteur seulement** (et pour les administrateurs), avec
un identifiant provisoire. Tout est au § 5.2 ; le résumé, parce que c'est le point le plus
confondu du document :

| | Identifiant réel | Identifiant provisoire |
|---|---|---|
| Forme | `"p312"`, `"f104"` | **`"#12/p1"`, `"#12/f1"`** |
| Comment on le reconnaît | ne commence pas par `#` | **commence par `#`** |
| Origine | `tree_person.id`, `tree_family.id` | `prp_draft_person.local_ref` + l'identifiant de la demande |
| Durée de vie | permanente | jusqu'à la décision sur la demande |
| Utilisable dans une URL | via `personId` / `familyId` | **non** — `#` est le séparateur de fragment ; on passe par `demandeId` + `brouillonRef` |
| Unicité | globale | **seulement dans sa demande** — d'où le préfixe `#<demandeId>/` |
| Route de détail | `/api/arbre/personnes/312` | `/api/demandes/12/brouillons/p1` (§ 6.6) |

Le nœud porte en plus `enAttente: true`, `demandeId`, `brouillonRef` et `personId: null`. Le
rendu le distingue visuellement (§ 6.4 du dossier). Il est **inséré localement**, sans
réorganiser le graphe : « décalage des seuls voisins immédiats sur la même ligne, jamais par
un recalcul global » (ADR 0005). Conséquence assumée : un nœud en attente peut frôler une
arête lointaine. C'est le prix pour que le graphe reste un objet partagé.

À la validation, `prp_draft_person.resolved_person_id` est renseigné : `#12/p1` disparaît du
graphe, `p312` apparaît, et `GET /api/demandes/12/brouillons/p1` rend désormais
`resolvedPersonId: 312` pour que le front redirige (§ 6.6).

---

## 14. Les dates incertaines

> **Elles ne s'aplatissent jamais, y compris dans le JSON.** Ni en base, ni à l'écran, ni dans
> une valeur d'op, ni dans un libellé de diff (§ 4 du dossier, § 9.1). Il n'existe nulle part
> dans cette API un champ de date généalogique qui soit une chaîne `YYYY-MM-DD` seule.

La seule date ferme de toute l'API est `birthDate` au § 4.2 — et ce n'en est pas une : c'est
une preuve de connaissance saisie par un candidat, jamais une donnée d'arbre.

### 14.1 La forme retenue

Un bloc date est un **objet**, jamais une chaîne. Cinq clés dans une valeur d'op, six dans une
fiche.

| Clé | Type | Dans un op | Dans une fiche | Sens |
|---|---|:-:|:-:|---|
| `raw` | chaîne \| `null` | oui | oui | La chaîne GEDCOM **verbatim**. Jamais reconstruite, jamais réécrite. C'est la provenance. |
| `qualifier` | énum `DateQualifier` | oui | oui | `exacte`, `annee`, `mois`, `environ`, `avant`, `apres`, `entre`, `depuis`, `jusqu_a`, `inconnue`. |
| `min` | `YYYY-MM-DD` \| `null` | oui | oui | Borne basse **inclusive**. `null` = non bornée à gauche (`BEF`). |
| `max` | `YYYY-MM-DD` \| `null` | oui | oui | Borne haute **inclusive**. `null` = non bornée à droite (`AFT`, `FROM`). |
| `place` | chaîne \| `null` | oui | oui | Le lieu tel qu'écrit (`PLAC`). |
| `texte` | chaîne | **non** | **oui** | Le rendu de `UncertainDate::format()`. **Lecture seule.** |

> **`texte` n'apparaît jamais dans `oldValue`, `newValue`, ni dans un corps de requête.** La
> forme d'une valeur `date_incertaine` est fixée « au caractère près » par le § 5.2 : **cinq
> clés, toujours les cinq**, même quand trois valent `null`, « pour qu'un champ absent soit
> toujours une erreur et jamais un non-renseigné implicite ». Un back qui accepte `texte` en
> entrée accepte une sixième clé que la comparaison `JSONB` de la détection de conflit
> (§ 9.6) fera diverger. Un front qui renvoie tel quel le bloc reçu d'une fiche doit donc en
> **retirer `texte`** avant de le poster — c'est la seule manipulation à faire, et elle est
> explicite ici pour qu'elle ne s'invente pas.

### 14.2 `BET 1901 AND 1909`, de bout en bout

Dans une fiche (`GET /api/arbre/personnes/17`) :

```json
"birth": {
  "raw": "BET 1901 AND 1909",
  "qualifier": "entre",
  "min": "1901-01-01",
  "max": "1909-12-31",
  "place": "Saule, Ombrage, Sylvanie",
  "texte": "entre 1901 et 1909"
}
```

Dans une valeur d'op (`POST /api/demandes`, § 6.2) — **cinq clés** :

```json
"newValue": {
  "raw": "BET 1901 AND 1909",
  "qualifier": "entre",
  "min": "1901-01-01",
  "max": "1909-12-31",
  "place": "Saule, Ombrage, Sylvanie"
}
```

Dans un nœud de graphe, sous forme déjà composée :

```json
"ligneDates": "1901-1909 – 1987"
```

Dans la chronologie, sous forme d'intervalle d'années (§ 9.1) :

```json
"debut": { "min": 1901, "max": 1909, "certain": false }
```

Dans un libellé de diff (§ 6.7) :

```json
"libelleDiff": "Naissance : 1987 → entre 1901 et 1909"
```

**Cinq représentations, zéro aplatissement.** Aucune ne rend un point là où la base a un
intervalle.

### 14.3 Les autres formes, et les pièges

| GEDCOM | `qualifier` | `min` | `max` | `texte` |
|---|---|---|---|---|
| `11 MAR 1930` | `exacte` | `1930-03-11` | `1930-03-11` | `11 mars 1930` |
| `1930` | `annee` | `1930-01-01` | `1930-12-31` | `1930` |
| `ABT 1934` | `environ` | `1934-01-01` | `1934-12-31` | `vers 1934` |
| `BET 1901 AND 1909` | `entre` | `1901-01-01` | `1909-12-31` | `entre 1901 et 1909` |
| `JAN 1920` | `mois` | `1920-01-01` | `1920-01-31` | `janvier 1920` |
| `BEF 12 DEC 1920` | `avant` | `null` | `1920-12-11` | `avant le 12 décembre 1920` |
| `FROM 15 JUN 1900` | `depuis` | `1900-06-15` | `null` | `depuis le 15 juin 1900` |
| *(absente)* | `inconnue` | `null` | `null` | `""` |

Quatre pièges, tous déjà payés ailleurs :

1. **`exacte` et `annee` produisent tous deux `min ≠ max` ou `min = max` selon le cas — ce
   n'est pas `min`/`max` qui porte l'incertitude, c'est `qualifier`.** `1930` donne déjà
   `1930-01-01` / `1930-12-31` : un client qui déduirait « `min ≠ max` donc fourchette »
   classerait les trois quarts de l'arbre en incertain. C'est exactement l'erreur que
   l'arbitrage **W** a corrigée dans le cahier des charges des fixtures. **Le test « est-ce
   une fourchette » est `qualifier === 'entre'`**, rien d'autre.
2. **`ABT` n'est jamais élargi.** `ABT 1934` reste `1934-01-01` → `1934-12-31`. « La source
   dit vers 1934, elle ne dit pas entre 1929 et 1939 » (§ 9.1). Un client qui a besoin d'une
   tolérance l'applique lui-même et assume son choix.
3. **Un bloc date est un seul op** (§ 6.2), sans quoi un administrateur peut valider trois
   clés sur cinq et fabriquer une date incohérente.
4. **Il n'existe pas de `getDate()`, pas de `getYear()`, pas de `__toString()`** sur
   `UncertainDate` (§ 9.1). Un appelant qui veut un point pour dessiner le centre d'une barre
   l'écrit lui-même, à partir de `min` et `max`. Cette contrainte descend jusqu'au front :
   **le JSON n'expose aucune clé qui rende un point**, et le calcul doit être visible à
   l'endroit où il est fait.

`qualifier = 'inconnue'` implique `min` et `max` à `null` (`ck_…_inconnue`), et
`qualifier = 'entre'` implique `min < max` strictement (`ck_…_intervalle`). Une valeur d'op
qui viole l'une des deux est refusée en `422` **avant** d'atteindre la base — le `CHECK` est le
filet, pas le contrôle.

### 14.4 Trois formes rendues, trois clés — référence unique

Une même date se rend de **trois** façons, qui n'ont ni le même producteur ni les mêmes
contraintes. Elles ont donc **trois clés distinctes**. C'est la correction d'une confusion qui
a produit deux exemples faux dans ce document : une seule clé, `ligneDates`, y désignait tour
à tour de la prose, une étiquette compacte et la chaîne mesurée par le moteur.

| Forme | Clé | Producteur | Où | Exemple sur `BET 1901 AND 1909` |
|---|---|---|---|---|
| **Prose** | `texte`, **dans** le bloc date | `UncertainDate::format()` | fiche personne, fiche famille, média, calque `enAttente` | `"entre 1901 et 1909"` |
| **Compacte** | `ligneDates` | `Tree\Service\EtiquetteDeDateGedcom` | fiche personne, résultat de recherche, brouillon, **barre de chronologie** | `"1901-1909 – 1987"` |
| **Étiquette du moteur** | `etiquetteDates` | `Tree\Service\MesureDeFiche::ligneDates()` | **nœud du graphe, et nulle part ailleurs** | `"1901-1909 – 1987"`, mais `"vivant·e"` si la personne est vivante |

#### La forme compacte, au caractère près

C'est `jolie()` de `graphe.py` (l. 77), portée sous le nom `EtiquetteDeDateGedcom` :

| GEDCOM | Compacte |
|---|---|
| `11 MAR 1930` | `11.3.1930` |
| `JAN 1920` | `1/1920` |
| `1987` | `1987` |
| `ABT 1889` | `~1889` |
| `BET 1901 AND 1909` | `1901-1909` |
| `BEF 1920` | `<1920` |
| `AFT 1900` | `>1900` |
| *(absente)* | `""` |

Assemblage de la ligne, identique pour `ligneDates` et pour `etiquetteDates` — c'est
`ligne_dates()` de `graphe.py` (l. 399) :

```
naissance et décès → "<naissance> – <décès>"      (tiret demi-cadratin, espaces autour)
naissance seule    → "n. <naissance>"
décès seul         → "† <décès>"
ni l'une ni l'autre → "—"
```

#### La seule différence entre `ligneDates` et `etiquetteDates`

> **`etiquetteDates` court-circuite sur `vivant` et rend la constante `"vivant·e"`.
> `ligneDates` ne court-circuite jamais.**

Et le motif du court-circuit ne se transpose pas d'une clé à l'autre, ce qui est précisément
pourquoi il en faut deux :

- **`etiquetteDates` est mesurée.** Elle est l'une des deux chaînes sur lesquelles `w` est
  calculée — l'autre est `etiquetteNom` (§ 5.2), à l'**étage 1
  du cache de placement — partagé, sans filtre de confidentialité** (ADR 0005). Si elle
  portait les vraies dates d'une personne vivante, la largeur de sa fiche encoderait leur
  longueur pour **tous** les lecteurs, y compris hors cercle. La constante ferme cela à la
  source, et c'est ce qui permet au placement d'être calculé une fois pour tout le monde
  (§ 11.2).
- **`ligneDates` est filtrée en aval.** Elle n'est présente que si le lecteur a le droit de
  voir les dates — sur une fiche restreinte elle est absente (§ 11.1), et **une personne
  vivante hors cercle est absente de `barres`** (§ 9.1) et de la recherche (§ 5.5). Les
  personnes qui restent exposent légitimement leurs dates ; leur servir `"vivant·e"` masquerait
  une donnée que le cercle vient d'autoriser, et rendrait la chronologie illisible sur les
  vivants du cercle — qui sont le peu de vivants qu'on a.

**La même personne porte donc deux chaînes différentes dans la même réponse**, et c'est
correct : Natron Romarin, vivant, est `"vivant·e"` en `etiquetteDates` sur son nœud (§ 5.2) et
`"n. 26.9.2012"` en `ligneDates`
sur sa barre (§ 9.1). Un implémenteur qui unifierait les deux casserait l'un des deux
invariants ci-dessus, sans qu'aucun test d'affichage ne s'en plaigne.

---

## 15. Les erreurs

### 15.1 Une forme unique

Toute réponse de code ≥ 400, **sans exception**, porte exactement cette enveloppe :

```json
{
  "erreur": {
    "code": "demande_caduque",
    "message": "La demande est devenue caduque : la fiche a changé depuis son dépôt.",
    "champs": null,
    "details": null
  }
}
```

| Clé | Toujours présente | Rôle |
|---|---|---|
| `code` | oui | Chaîne stable, `snake_case`, **c'est sur elle que le front branche**. Elle ne change jamais ; le message, si. |
| `message` | oui | Français, une phrase, affichable tel quel. |
| `champs` | oui (`null` si sans objet) | `{ "nomDeChamp": "message" }` — **sur les `422` seulement**. |
| `details` | oui (`null` si sans objet) | Structure propre au code, décrite là où le code apparaît. |

`champs` et `details` sont **présents à `null`** plutôt qu'absents : c'est la seule exception
à la règle du § 1.3, et elle est assumée — une enveloppe d'erreur n'est pas une charge de
données, et un front qui doit tester la présence de deux clés avant de les lire finira par
oublier un test.

Le dépôt de référence émet deux formes — `{"error": "…"}` et `{"errors": {…}}` — et son point
d'entrée 401 émet la première. **Heritage n'en a qu'une.** Conséquences à écrire, sinon un
agent recopiera la référence :

- `Core\Security\JsonAuthenticationEntryPoint` émet `{"erreur": {"code":
  "authentification_requise", …}}`, pas `{"error": …}` ;
- `Core\EventSubscriber\ApiExceptionSubscriber` compose la même enveloppe pour toute
  `DomainException`, `ValidationException` remplissant `champs` ;
- `AuthenticationFailureHandler` et le gestionnaire de `login_throttling` la composent aussi
  (§ 1.9) ;
- les erreurs **du routeur** (404 sur chemin inconnu, 405 sur mauvaise méthode) et les `500`
  passent par le même souscripteur : une `404` de routeur qui rendrait du HTML casserait le
  front sur exactement le cas où il a le plus besoin d'un JSON.

### 15.2 Le tableau des codes

| Code | HTTP | Où | `details` |
|---|---|---|---|
| `authentification_requise` | 401 | partout | — |
| `identifiants_invalides` | 401 | `POST /api/connexion` | — |
| `compte_suspendu` | 401 | `POST /api/connexion` | — |
| `acces_refuse` | 403 | voters — demande d'autrui, média d'autrui | — |
| `regime_en_attente` | 403 | `POST /api/demandes`, `POST /api/medias` ; depuis le 2026-10-01, **toute lecture de l'arbre** par un compte en attente (§ 12.1) | — |
| `introuvable` | 404 | partout, **et sur tout ce que le lecteur n'a pas le droit de voir** | — |
| `methode_non_autorisee` | 405 | routeur | `{"autorisees": ["GET"]}` |
| `conflit` | 409 | générique — état incompatible | variable |
| `demande_caduque` | 409 | validation (§ 6.8) | `{demandeId, statut, rejouable: false, champs[]}` |
| `cible_disparue` | 409 | validation | `{demandeId, statut, rejouable: false, cible}` |
| `dependance_en_attente` | 409 | validation (§ 6.8, issue 7) | `{demandeId, statut: "en_attente", rejouable: true, dependances[]}` |
| `dependance_morte` | 409 | validation (§ 6.8, issue 8) | `{demandeId, statut: "caduque", rejouable: false, dependances[]}` |
| `verrou_concurrent` | 409 | validation | `{demandeId, statut, rejouable: true}` |
| `demande_deja_decidee` | 409 | valider, refuser, retirer | `{statut}` |
| `demande_deja_soumise` | 409 | soumettre | `{statut}` |
| `demande_non_refaisable` | 409 | refaire | `{statut}` |
| `personne_deja_rattachee` | 409 | invitation, inscription, activation | `{personId}` |
| `invitation_en_cours` | 409 | création d'invitation | `{invitationId}` |
| `invitation_deja_consommee` | 409 | révocation | — |
| `courriel_deja_pris` | 409 | `POST /api/inscription/compte` | — |
| `vue_comme_membre_active` | 409 | **toute écriture** pendant la bascule | `{memberId, memberLabel}` |
| `dernier_administrateur` | 409 | `PATCH` utilisateur | — |
| `rattachement_non_etabli` | 409 | `PATCH` utilisateur (`status: actif`) | `{memberId}` |
| `media_reference` | 409 | `DELETE` média | `{citationIds}` |
| `import_en_cours` | 409 | import GEDCOM | `{runId}` |
| `fichier_trop_volumineux` | 413 | téléversement | `{maxOctets}` |
| `type_de_fichier_refuse` | 415 | téléversement | `{mimesAutorises}` |
| `donnees_invalides` | 422 | partout | `champs` rempli |
| `valeur_hors_bornes` | 422 | `PATCH` réglage | `{minValue, maxValue}` |
| `demande_sans_lien` | 422 | soumettre une `creation_personne` | — |
| `trop_de_tentatives` | 429 | § 1.9, **sauf la recherche de rattachement** | `{retryAfter}` |
| `erreur_interne` | 500 | souscripteur d'exception | — |

### 15.3 `404` plutôt que `403` — la règle générale

> **Une ressource que le lecteur n'a pas le droit de voir rend `404`. Une action qu'il n'a pas
> le droit d'exécuter sur une ressource qu'il peut voir rend `403`.**

C'est ce qui donne : `404` sur un jeton d'invitation invalide (§ 4 — « un `403` confirmerait
que la route existe »), `404` sur le fichier d'un média dont le sujet est hors cercle (§ 7.4),
`404` sur l'historique d'une personne vivante hors cercle ; mais `403 acces_refuse` sur la
demande d'un autre membre — dont l'existence n'est pas un secret — et `403 regime_en_attente`
sur une écriture.

**Deux exceptions nommées**, parce que les deux se déduiraient mal :

1. **La fiche d'une personne vivante hors cercle rend `200`, en version réduite** (§ 11.1) —
   son nœud est visible dans le graphe, cliquer dessus doit donner un nom et une impasse
   claire, pas une erreur qui ressemble à une panne.
2. **La recherche de rattachement ne rend jamais `429` sur le plafond par jeton** (§ 4.2) —
   elle rend `200` avec zéro candidat, indistinguable d'une recherche infructueuse.

### 15.4 Le conflit de verrou optimiste, et la demande caduque

Les deux cas que le § 6.8 traite en détail méritent d'être relus ensemble, parce que ce sont
deux `409` qui **donnent des conseils opposés** :

| | `verrou_concurrent` | `demande_caduque` |
|---|---|---|
| Verrou en cause | n° 1 — `#[ORM\Version]`, transactions concurrentes | n° 2 — `prp_change_op.base_version` + comparaison des `old_value` |
| Fenêtre | l'instant de la transaction | les trois jours entre dépôt et validation |
| Écrit dans `tree_*` | non | non |
| Écrit dans `prp_change_request` | **non** — reste `en_attente` | **oui** — passe `caduque` |
| Notification à l'auteur | non | **oui**, avec le diff |
| `details.rejouable` | `true` | `false` |
| Ce que le front propose | **« Réessayer »** | **« Refaire cette demande »** (§ 6.10), jamais « réessayer » |

Et la troisième issue, qui n'est pas une erreur : **le rejeu automatique** (`200`,
`resultat: "rejouee"`, § 6.8). Le verrou s'est déclenché, toutes les `old_value`
correspondaient encore, la demande a été rejouée sur la version courante. C'est le cas
**fréquent** — celui des champs disjoints — et le traiter comme un conflit condamnerait la
seconde de deux demandes déposées la même semaine, « alors que contribuer est le but du
produit » (arbitrage **C**).

> **La version qui bouge ne condamne pas la demande ; la valeur qui bouge, si.** C'est le
> point à ne pas inverser, et c'est la seule phrase de ce paragraphe qu'il faut retenir.

---

## 16. Ce qui n'est jamais renvoyé

> Section à lire **avant** d'exposer un champ. Elle vaut pour toutes les routes, tous les
> rôles, l'administrateur compris, et pour les **réponses d'erreur**, les **journaux** et les
> **métadonnées de médias** au même titre que pour les charges utiles nominales (§ 8 du
> dossier).

### 16.1 Jamais, pour personne

| Ce qui ne sort pas | Où c'est en base | Pourquoi |
|---|---|---|
| **Empreinte de mot de passe** | `core_user.password` | Aucune clé `password` ne figure dans une réponse. Le champ est masqué en `"***"` **à l'écriture** du journal d'audit (§ 2.4), jamais à la lecture. |
| **Secret TOTP** | `core_user.totp_secret` | Seul `totpEnabledAt` sort. Le secret ne sort qu'une fois, dans le parcours d'activation MFA, qui n'est pas dans cette livraison. |
| **Jeton d'invitation en clair** | nulle part — seul `hash(jeton)` est stocké | Il n'existe qu'en mémoire à la création, et n'apparaît que dans la réponse de `POST /api/administration/invitations` (§ 3.2). **Jamais dans une liste, jamais dans une notification, jamais dans le journal d'audit.** `token_hash` est masqué en `"***"` dans `changes`. |
| **`tokenHash`** | `mbr_invitation.token_hash` | Une empreinte de jeton permet de vérifier une hypothèse de jeton. Seul `tokenPrefix` (8 caractères) sort. |
| **Chemins de fichiers sur le disque** | `med_media.storage_name`, `thumbnail_name` | Un nom devinable rend le stockage énumérable même hors racine web (§ 6.1). Les médias sortent **exclusivement** par `/api/medias/{id}/fichier` et `/vignette`. Aucune redirection vers un chemin statique, aucune URL absolue de volume, aucun `X-Accel-Redirect` visible côté client. |
| **Critères de recherche de rattachement** | `mbr_claim_attempt.query_hash` | C'est une empreinte, pas un texte — « ce journal serait sinon exactement la fuite qu'il est censé documenter » (§ 4.3). L'écran d'administration montre un **décompte**, jamais un critère. |
| **Le `ref` d'un candidat rapproché d'un `personId`** | — | Le `ref` du § 4.2 est opaque et aléatoire. Un `personId` y serait le début d'une énumération par incrémentation. |
| **Traces, classes, requêtes SQL** | — | Une `500` rend `{"erreur": {"code": "erreur_interne", …}}` et rien d'autre, en `prod` comme en `dev` : c'est le même souscripteur. **Voir l'avertissement ci-dessous : cette discrétion est due au client, pas à l'exploitant.** |

> **Ce paragraphe contraint la réponse, jamais le journal. Les deux ont été confondus une
> fois, et la confusion a coûté une soirée.**
>
> Une `500` doit être **muette pour le client** et **bavarde pour l'exploitant** : la trace,
> la classe de l'exception, le message d'origine, la requête SQL fautive et l'identifiant de
> corrélation partent dans le journal applicatif — c'est précisément ce qu'on ira lire. Un
> souscripteur qui avale l'exception pour composer l'enveloppe d'erreur **sans la journaliser
> d'abord** produit une panne sans aucune ligne de journal, qu'il faut alors diagnostiquer en
> comparant deux schémas de base à la main.
>
> Ce que le § 16 interdit, c'est de **sortir** ces informations par l'API. Le journal n'est
> pas une sortie de l'API : il est derrière `ROLE_ADMIN` et le système de fichiers. La règle
> se lit donc en deux moitiés : **ce qui est dû au client — un code, un message, rien de
> plus ; et ce qui est dû à l'exploitant — tout le reste, et tout de suite.** Le seul
> recouvrement est la liste du § 16.1 : mots de passe, secrets TOTP, jetons en clair et
> chemins de disque ne vont **ni** dans la réponse **ni** dans le journal (§ 2.4 les masque en
> `"***"` à l'écriture).
>
> Pour que les deux se rejoignent quand il le faut, la réponse `500` porte un
> **identifiant de corrélation** — `erreur.details.incident`, une chaîne courte et
> aléatoire — que le journal reprend. Il ne dit rien de la panne et permet de retrouver la
> ligne : « j'ai eu l'erreur `f3a91c` » suffit à l'exploitant.
>
> **Et le journal doit exister — il existe, et ç'a été vérifié plutôt que supposé.**
> `symfony/monolog-bundle` est installé et enregistré ; `debug:container logger` rend
> `Monolog\Logger` dans le conteneur qui tourne. Le dispositif a été éprouvé sur une panne
> reproduite dans un conteneur jetable : la réponse ne porte que
> `{"erreur":{"code":"erreur_interne","message":"Erreur interne.","details":{"incident":"99acf4ba24e625a1"}}}`,
> pendant que le journal reçoit le **même identifiant d'incident**, la classe de l'exception,
> le message SQL (`SQLSTATE[42703] … does not exist`) et la pile jusqu'à la ligne applicative,
> en `CRITICAL`. Les deux moitiés se rejoignent par `incident`, et c'est là toute la raison
> d'être de cette clé.
>
> Ce paragraphe a d'abord été écrit comme un avertissement — « le projet n'a ni dépendance
> Monolog ni destination configurée, donc un `critical()` déplacerait la panne muette au lieu
> de la fermer ». Il est conservé sous cette forme parce qu'il reste la **condition** à
> tenir, et qu'elle peut se reperdre : un canal supprimé, un `var/log` non monté, un volume
> non sauvegardé, et l'on retombe exactement là — on aurait remplacé « le souscripteur avale
> l'exception » par « le journal l'avale ». **Exiger un journal, c'est exiger sa destination**,
> et la seule preuve qui vaille est celle qu'on vient de citer : une panne provoquée, et les
> deux sorties lues côte à côte.

### 16.2 Jamais, pour un lecteur hors cercle

*Depuis le 2026-10-01, pour un membre validé, « hors cercle » ne vise plus que les fiches à
confidentialité forcée hors de son cercle (§ 11, révision du 2026-10-01). Les interdits
ci-dessous valent pour elles, sans changement.*

**Aucune donnée d'une personne vivante hors du cercle du lecteur**, au-delà des sept clés du
§ 11.1 — et cela comprend, explicitement :

- toute date, toute borne de date, tout `raw`, tout `ligneDates`, tout intervalle de
  chronologie ;
- tout lieu, y compris un lieu de fait ou d'union ;
- toute source, citation, `quay`, note, fait ;
- tout média, portrait, vignette, légende, empreinte de fichier ;
- toute liste de parents, d'enfants, de conjoints, de fratrie (les **arêtes** du graphe
  restent — c'est le risque résiduel assumé, § 11.2) ;
- tout rattachement à un compte : **on ne dit jamais qu'une personne de l'arbre a un compte**,
  ni lequel ;
- `parente`, `version`, `forcePrivacy`, `privacyNote`, `bestQuay`, `citationCount` ;
- et **toute clé `*Label` la désignant**, dans `Proposal`, `Membership` et `Media` (§ 11.3).

Trois endroits où l'oubli est le plus probable, parce qu'ils ne ressemblent pas à des fiches :

1. **Un message d'erreur.** `{"message": "Marne Dolomite Sureau n'est pas modifiable."}` sur
   une `403` est une fuite. Les messages d'erreur ne nomment **jamais** une personne : ils
   nomment un champ, un code, un identifiant de demande.
2. **Un titre de notification.** Il est composé **à l'écriture**, en appliquant le cercle du
   destinataire (§ 8) — pas à la lecture, parce qu'une notification se relit longtemps.
3. **`core_audit_log.subject_label`.** Rempli avec le `display_name` et **jamais avec une
   ligne de dates** (§ 9.2). Le journal est réservé à l'administrateur, mais un journal qui
   contient des dates de naissance est un journal qu'on ne peut plus extraire.

### 16.3 Jamais, pour un compte en attente

Tout ce qui précède, **plus toute donnée de toute personne vivante** — il n'a pas de cercle
où en trouver (§ 12). Y compris :

- la personne à laquelle il prétend se rattacher : ni `personId`, ni `personLabel`, ni dans
  `GET /api/session`, ni dans `GET /api/inscription/etat` ;
- tout `enAttente`, puisqu'il ne peut pas déposer de demande.

### 16.4 Et ce qui sort, mais qu'il faut avoir décidé

Trois choses sortent que l'on pourrait croire masquées. Elles sont écrites ici pour qu'on ne
les retire pas « par prudence », en cassant le rendu :

- **`vivant`** — il annonce que la personne est vivante. C'est le « masquage est son propre
  indice » accepté par l'ADR 0003, et `.fiche.prive` en dépend (ADR 0007).
- **`branch` et `generation`** sur un nœud restreint — ils n'apprennent rien qui ne se déduise
  des arêtes, et le canevas colore par quart d'ascendance (arbitrage **M**).
- **La topologie entière** — nœuds, arêtes, positions. « Le produit est un arbre. Un arbre
  dont on retire des nœuds n'est plus un arbre » (ADR 0003). Un lecteur patient peut parcourir
  le graphe nœud par nœud et reconstituer la liste des vivants à la main : c'est vrai, c'est
  écrit, on rend la chose coûteuse et **journalisée**, et on ne va pas plus loin dans cette
  livraison.

---

## 17. Le test des trois routes — ce qu'il a révélé

Le critère de fin de ce document est qu'un agent qui n'aurait lu que lui puisse implémenter
**soit** le contrôleur **soit** l'écran, sans question et sans divergence. Ce critère a été
éprouvé sur les trois routes les plus délicates, en écrivant les deux côtés en parallèle et en
notant chaque endroit où les deux se seraient contredits. **Le test a trouvé dix divergences
et trois manques** — plus une onzième divergence qui n'en était pas une, réfutée depuis par la
lecture du moteur et conservée ici comme mise en garde (§ 17.3). Toutes sont corrigées dans le
corps du document ; ce qui suit dit ce qu'elles étaient, parce qu'une correction dont on a
perdu le motif se défait au premier remaniement.

### 17.1 `POST /api/inscription/rattachement`

**Divergence 1 — la `429` trahissait le plafond.** Le § 1.9 pose une convention générale :
dépassement de débit ⇒ `429` + `Retry-After`. Appliquée ici, elle rend le plafond parfaitement
visible — alors que `ClaimResult.aucun_candidat` porte, dans le modèle, la mention « réponse
indistinguable, côté client, d'un plafond atteint » (§ 4.5). Un back qui suit la convention
générale livre un oracle : *le plafond existe, donc le jeton est valide, donc la route est
réelle, donc je peux mesurer.* Corrigé au § 4.2 : le plafond **par jeton** rend `200` avec
`candidats: []`. C'est la seule exception de tout le document à la convention de débit, et
elle est écrite deux fois.

**Divergence 2 — le front avait besoin d'un identifiant à renvoyer.** L'étape 2 doit dire
« c'est ce candidat-là ». Le choix naturel, côté back comme côté front, est `personId`. Il
donne au porteur d'un jeton **l'identifiant réel d'une personne vivante**, et donc le point de
départ d'une énumération par incrémentation sur `/api/arbre/personnes/{id}` une fois le compte
créé. Corrigé : `ref` opaque, aléatoire, à usage unique, lié à la tentative, 15 minutes.

**Divergence 3 — le temps de réponse.** Un back raisonnable court-circuite au plafond : il
teste le limiteur, puis sort. La réponse arrive alors plus vite qu'une vraie recherche, et
l'indistinguabilité obtenue à la divergence 1 est perdue au chronomètre. Corrigé : on cherche,
**puis** on jette.

**Manque 1 — que faire d'une date fausse en mode confirmation.** Ni le modèle ni le dossier ne
disent si `echec_date` consomme une tentative, ni ce que le client reçoit. Décidé : **elle
consomme une tentative** (sinon le plafond ne protège rien : on teste 365 dates par an
gratuitement) et elle rend `candidats: []`, jamais un message qui rapprocherait de la bonne
date.

### 17.2 `POST /api/administration/demandes/{id}/valider`

**Divergence 4 — le front voyait deux issues, le modèle en décrit quatre.** Écrit naïvement,
l'écran d'administration a un bouton « Valider », un cas de succès et un cas d'erreur. Le
§ 9.6 en impose quatre : appliquée, **rejouée automatiquement**, **caduque**, et le verrou
concurrent. Corrigé au § 6.8, avec `resultat` sur les succès.

**Divergence 5 — deux `409` aux conseils opposés.** C'est la trouvaille la plus coûteuse du
test. `demande_caduque` et `verrou_concurrent` sont toutes deux des `409` de conflit. Sur la
première, réessayer est **inutile et faux** : la demande est morte, elle a quitté la file. Sur
la seconde, réessayer est **exactement la bonne action** : rien n'a été écrit. Un front écrit
contre « 409 ⇒ bouton Réessayer » — ce que tout le monde écrit — fait boucler l'administrateur
sur une demande caduque. Corrigé : deux codes distincts, **et** un booléen `details.rejouable`
pour que le front n'ait pas à connaître la liste des codes.

**Divergence 6 — une `409` qui écrit.** `demande_caduque` ne touche pas l'arbre, mais elle
fait passer la demande en `caduque`, notifie l'auteur et écrit au journal. Un code d'erreur
qui modifie l'état est contre-intuitif, et les deux côtés l'auraient traité différemment : le
front en rechargeant la demande (juste) ou en la laissant dans sa liste (faux). Écrit en
toutes lettres au § 6.8.

**Manque 2 — l'écran d'administration n'avait pas ses données.** Le § 9.6 décrit un bandeau
« cette fiche a changé depuis la demande, avec ce qui a bougé », et le tableau « mercredi » de
son scénario suppose que l'administrateur **sait avant de cliquer** si la demande sera rejouée
ou déclarée caduque. Rien dans le modèle ne porte cette information : elle se calcule, mais
personne ne disait où. Corrigé au § 6.7 : `valeurCourante` et `aBouge` par op,
`cible.aChangeDepuisSoumission`, et surtout **`resolutionPrevue`**
(`application` / `rejeu` / `caducite`). Sans elle, la caducité se découvre au clic et devient
indistinguable d'une panne.

### 17.3 `GET /api/arbre/graphe` avec le calque

**Divergence 7 — `local_ref` ne peut pas servir d'identifiant de nœud.** Le § 9.5 écrit « le
nœud provisoire porte son `local_ref` comme identifiant de nœud ». Mais `local_ref` n'est
unique **que dans sa demande** — le même § le dit deux paragraphes plus haut : « `#p1` de la
demande 12 et `#p1` de la demande 13 n'ont aucun rapport ». Or un auteur peut avoir deux
demandes en attente, et un administrateur les a **toutes** : le graphe d'un administrateur
porterait alors plusieurs nœuds `#p1`. Le back, qui lit une demande à la fois, ne voit pas la
collision ; le front, qui reçoit une liste plate, la subit. Corrigé : **`#<demandeId>/<ref>`**.

**Divergence 8 — le `#` ne passe pas dans un chemin d'URL.** Le front qui construit la route
de détail en concaténant l'identifiant de nœud obtient `/api/demandes/…/#p1`, où tout ce qui
suit le `#` est un fragment jamais envoyé au serveur. La panne est silencieuse et se
diagnostique mal. Corrigé de deux façons : la route prend la référence **sans dièse**, et le
nœud porte `demandeId` et `brouillonRef` **séparément**, pour que la concaténation n'ait pas
lieu.

**Manque 3 — on ne pouvait pas ouvrir un nœud en attente.** `/api/arbre/personnes/{id}` exige
`\d+` ; la personne proposée n'a pas d'identifiant. L'auteur voyait donc son propre nœud dans
l'arbre et cliquait dans le vide — ce qui vide de son sens la contrepartie exigée par le § 6.4
du dossier. Corrigé : `GET /api/demandes/{id}/brouillons/{ref}` (§ 6.6).

**Divergence 9 — il manquait un nœud de jonction provisoire.** Un conjoint proposé sans
famille existante a besoin d'un losange entre lui et son conjoint. Or il n'existe **pas** de
`prp_draft_family` : la demande décrit l'intention, le service déduit les familles à la
validation (§ 9.5). Le back n'a donc rien à envoyer, et le front a une arête sans extrémité.
Corrigé : une famille provisoire **allouée à la projection, en mémoire seulement**,
`#12/f1` — cohérent avec « les familles apparaissent dans la carte de résolution en mémoire »,
et rien n'est persisté.

**Divergence 10 — l'`ETag` sous la bascule « voir comme un membre ».** L'ADR 0005 définit
l'`ETag` comme `sha256(empreinte + ':' + empreinte des demandes en attente du lecteur)`. Sous
la bascule, ces deux composantes ne bougent pas : l'administrateur revalide, reçoit `304`, et
regarde **son propre graphe non filtré** en croyant tester celui d'un membre. La bascule cesse
alors de vérifier quoi que ce soit — c'est-à-dire qu'elle échoue exactement là où elle sert.
Corrigé au § 5.2 et au § 10.4 : l'`ETag` incorpore le membre emprunté tant que la bascule est
active.

**Une onzième divergence a été soulevée, puis réfutée par la mesure — et elle mérite de
rester écrite.** Le test avait conclu que la largeur `w` encodait la longueur de la ligne de
dates masquée, et prescrivait de la mesurer « sur le nom seul » pour un nœud restreint.
**C'est faux** : la lecture de `docs/moteur-retrouve/graphe.py` montre que `ligne_dates()`
court-circuite sur le drapeau privé et rend la constante `"vivant·e"` — la largeur d'une
personne vivante ne lit jamais ses dates, pour personne. La fuite était déjà fermée, à la
source.

Ce qui rend l'erreur instructive, c'est son coût si elle était passée : un moteur qui mesure
différemment selon le lecteur **casse le critère d'égalité exacte des 377 positions**
(ADR 0005) pour colmater un trou inexistant, et rend la largeur dépendante du lecteur — donc
le placement partagé impossible, donc **un graphe en cache par compte**, l'architecture que le
projet a précisément écartée. Corrigé au § 11.2, dans l'autre sens : `w` est **identique pour
tous les lecteurs**, et reproduire `graphe.py` fidèlement *est* ici la mesure de sécurité.

La leçon, pour les quatre autres et pour les suivantes : **une fuite supposée se vérifie dans
le code qui produit la donnée avant de se colmater dans le contrat qui la transporte.** Les
quatre autres l'ont été ; celle-ci ne l'avait pas été.

### 17.4 Ce que le test n'a pas trouvé

Il n'a rien trouvé sur la forme des dates incertaines : le § 5.2 fixe la valeur d'op « au
caractère près » et le § 9.1 ferme les échappatoires côté objet valeur. Le seul ajustement a
été de nommer la sixième clé `texte` et d'interdire explicitement sa présence en entrée
(§ 14.1) — un front qui reposte tel quel un bloc reçu d'une fiche l'aurait envoyée, et la
comparaison `JSONB` de la détection de conflit aurait divergé sans que personne ne comprenne
pourquoi.

Il n'a rien trouvé non plus sur le cercle de visibilité lui-même : entre l'ADR 0003, le § 9.2
et le § 9.3, la règle est écrite trois fois et elle est cohérente. Ce qui manquait n'était pas
la règle mais **sa forme en JSON** — d'où le § 11, et d'où la règle « absent contre `null` »
du § 1.3, qui est la seule chose à retenir pour ne pas la trahir.

---

### 17.5 Ce qu'un test qui ne lit que ce document ne pourra jamais garder

`api/tests/Contrat/InvariantsDuContratTest.php` vérifie que les exemples sont cohérents
**entre eux** et avec les formules énoncées ici. Sa portée s'arrête à deux endroits, et il
vaut mieux les connaître que de le croire plus large qu'il n'est.

**1. Les valeurs de données.** Il ne sait pas que la génération de la personne 17 vaut `-4`
ni que le premier millésime de l'arbre est 1655 — les deux étaient faux ici, et il les a
laissés passer. Ce qu'il garantit, c'est qu'une valeur corrigée n'abandonne pas derrière elle
celles qui en dérivent : corriger `generation` sans corriger `y`, ou `bornes.min` sans
recalculer les bandes, le fait rougir. C'est un contrôle de **cohérence**, pas de vérité.

**2. Les propriétés d'une formule.** La question s'est posée pour l'`ETag` : un test qui lit
ce document peut-il vérifier que **tout ce qui distingue deux représentations figure dans la
clé** ? Non — et il ne faut pas essayer. Le document énonce une formule ; un test qui la relit
ne peut en contrôler que le **texte**, c'est-à-dire vérifier qu'on a bien écrit ce qu'on a
écrit. Il passerait au vert sur une formule fausse énoncée avec assurance, ce qui est pire
qu'aucun test : un instrument qui confirme l'erreur.

La propriété est réelle et elle se garde — ailleurs, en trois lignes de test fonctionnel :

> **Deux lecteurs de cercles différents, même empreinte d'arbre, aucune demande en cours,
> reçoivent deux `ETag` différents.** Un administrateur et un membre actif suffisent : leurs
> charges diffèrent de 18 fiches, leurs `ETag` doivent différer.

Ce cas appartient au module `Tree`, qui a le noyau et les fixtures sous la main. La règle
générale qu'il illustre vaut pour toute la suite : **une propriété du produit se teste contre
le produit ; seule la cohérence d'un document se teste contre le document.** Confondre les
deux donne un test qui rassure sans rien garder — et la soirée en a démonté cinq.

---

## 18. Ce que le modèle ne tranche pas — points signalés

Comme demandé : là où `docs/modele-donnees.md` ne permettait pas de trancher, le point est
signalé plutôt que décidé en silence. Chaque ligne nomme le § concerné et dit ce que ce
document a retenu **en attendant** une correction de la source.

| # | Point | Où le trou se trouve | Retenu ici | À corriger dans |
|---:|---|---|---|---|
| 1 | Le `field` d'un bloc date | **§ 5.2** cite `birthMin` en exemple de `field`, ce qui contredit sa propre règle « une date incertaine est un seul op, pas cinq » et le `setBirth()` du **§ 9.4**. Les entités posées depuis tranchent dans le même sens : `Tree\Person` porte `private UncertainDate $birth;` en `#[ORM\Embedded]` — il n'existe aucune propriété `birthMin` à atteindre | `field` = **le nom du bloc** : `birth`, `death`, `marriage`, `divorce`, `happened`, `taken` (§ 6.2) | § 5.2 — remplacer l'exemple `birthMin` |
| 2 | Identifiant de nœud d'un brouillon | **§ 9.5** : « le nœud provisoire porte son `local_ref` comme identifiant de nœud », alors que le même § pose que `local_ref` n'est unique que dans sa demande | `#<demandeId>/<localRef sans dièse>` (§ 5.2, § 13.4) | § 9.5 |
| 3 | Nœud de jonction d'un couple proposé | **§ 9.5** : pas de `prp_draft_family`, mais le rendu a besoin d'un losange | famille provisoire allouée **à la projection, en mémoire**, `#12/f1` ; rien n'est persisté (§ 5.2) | § 9.5 — une phrase suffirait |
| 4 | **`vivant` absent de la liste blanche** | **ADR 0003** liste `id`, `display_name`, `sex`, `generation`, `branch` + position + arêtes. **ADR 0007** exige `vivant` pour poser `.fiche.prive`, et le **§ 9.2** le confirme comme risque résiduel accepté | `vivant` **est** envoyé sur un nœud et une fiche restreints (§ 11.1, § 11.4) | **ADR 0003** — même geste que pour `branch` (arbitrage **M**), sinon le test de liste blanche échoue sur un champ que l'ADR 0007 rend obligatoire |
| 5 | Deux demandes sur le même champ | **§ 9.4** donne `enAttente[champ]` comme un objet unique et ne dit pas ce qu'il devient quand deux demandes visent le même champ — cas que l'**ADR 0004** prévoit pourtant explicitement | entrée = la demande la plus ancienne ; `autresDemandes: [ids]` à côté (§ 13.1). Un tableau aurait changé l'arité documentée | § 9.4 |
| 6 | Vivants hors cercle dans la chronologie | **Aucun document ne tranche.** Le § 7.4 du dossier dit seulement « le cercle s'applique ici aussi » | **absents** de `barres` — une barre est faite de dates, et la chronologie est une énumération au sens de l'ADR 0003 (§ 9.1) | à confirmer par le commanditaire |
| 7 | Données du bandeau « la fiche a changé » | **§ 9.6** décrit le bandeau et suppose que l'administrateur sait avant de cliquer, sans dire d'où vient l'information | `valeurCourante`, `aBouge`, `cible`, `resolutionPrevue` (§ 6.7) | § 9.6 — ou rien, si l'on accepte que ce soit un point de contrat |
| 8 | Débit du téléversement et de la création de demande | **§ 2.2** ne porte de clé que pour `invitation.tentatives_par_heure` ; **§ 0.6** exclut toute table de limitation | plafonds dans `framework.yaml` (§ 1.9). Asymétrie assumée : un limiteur réglable à chaud, trois en configuration | § 2.2, si l'on veut les rendre réglables |
| 9 | Écritures pendant « voir comme un membre » | Ni le **§ 9.3** ni l'**ADR 0003** ne disent si l'administrateur peut écrire sous une identité empruntée | **non** : `409 vue_comme_membre_active` sur toute écriture (§ 10.4). Une `prp_revision` porterait l'administrateur pendant que l'écran montre autre chose | à confirmer |
| 10 | L'écran d'attente peut-il nommer la personne réclamée | **§ 4.5** est catégorique : « aucune personne vivante […] y compris celle à laquelle le compte prétend se rattacher » | pris **au pied de la lettre** : ni `personId` ni `personLabel` dans `GET /api/session` ni dans `/api/inscription/etat` tant que le compte est `en_attente` (§ 2.2, § 4.4) | à confirmer — c'est rude pour l'utilisateur, mais c'est ce qui est écrit |
| 11 | Forme unique d'erreur contre le dépôt de référence | Le dépôt de référence émet **deux** formes, `{"error": …}` et `{"errors": …}` ; la mission demande une forme unique | `{"erreur": {code, message, champs, details}}`, y compris dans le point d'entrée 401 et le gestionnaire de `login_throttling` (§ 15.1) | — décision de ce document, à ne pas « recorriger » vers la référence |
| 12 | Largeur `w` d'un nœud restreint | ~~Soupçon d'une fuite par la largeur~~ — **réfuté par la mesure** : `graphe.py` court-circuite `ligne_dates()` sur le drapeau privé et rend la constante `"vivant·e"` | `w` **identique pour tous les lecteurs**, calculée sur le nom et une constante (§ 11.2). Le moteur ne prend aucun paramètre de lecteur. Rien à corriger ailleurs | — **rien** : l'ADR 0005 avait raison, et le portage doit le reproduire à l'identique |
| 13 | Chemin du GEDCOM à l'import | Le **§ 0 du dossier** impose une variable de configuration, « jamais un chemin en dur », et l'atelier est en lecture seule | le corps porte une **clé de source** déclarée dans `.env`, jamais un chemin de système de fichiers (§ 10.6) — un chemin libre serait une lecture de fichier arbitraire | — |
| 15 | **L'étiquette de nom mesurée n'a pas de nom** | Le moteur mesure `(nickname ?? givenNames)` réduit à son **premier mot** + `surname` ; **rien dans le modèle ni dans l'ADR 0005 ne nomme cette chaîne**, et `display_name` (§ 3.1) est une autre composition — tous les prénoms. Mesuré : **220 fiches sur 274 débordent** si le canevas dessine `displayName`, jusqu'à 169 unités sur une boîte de 149 | clé **`etiquetteNom`** sur le nœud, servie par `MesureDeFiche::etiquetteNom()` (§ 5.2). Non dérivable côté client : un nom de famille peut contenir des espaces | ADR 0005 — la nommer dans le résumé de l'algorithme, à côté de `ligne_dates()` |
| 14 | **Chaînage d'une reprise** | Aucune colonne n'enregistre qu'une demande en refait une autre, ni que les médias d'une demande caduque sont partis vers sa reprise (§ 6.10). `duplicate_of_id` ne convient pas : il porte le rapprochement de doublons **entre deux auteurs**, et lui donner un second sens rendrait les deux illisibles | `origine` est écrit en texte dans `message` ; les médias suivent et la demande d'origine ne les liste plus | **à trancher par le commanditaire** : veut-il lire « votre demande n° 12 a été refaite en n° 21 » ? Si oui, c'est une colonne `reprise_de_id` sur `prp_change_request`, et ce n'est pas à ce document de la décider |
| 16 | **Les anniversaires contre « pas de liste, pas d'export »** | L'ADR 0003 refuse l'énumération ; or la page des anniversaires **est** une liste de personnes et de dates, triée, filtrable, et l'année entière tient en une requête | **absents, jamais réduits** — la décision du § 9.1 reprise mot pour mot. L'ensemble résiduel ne couvre que les défunts et les vivants déjà dans le cercle du lecteur, dont il lit les dates sur `/api/chronologie`. Vérifié par un test de **sous-ensemble** entre les deux routes (§ 20.5) | **à confirmer par le commanditaire** : l'ensemble n'est pas plus large, mais il est mieux trié et moins cher à parcourir qu'un SVG |
| 17 | **Le 29 février dans un mois qui n'en a pas** | Rien ne dit ce que devient un anniversaire du 29 février les trois années sur quatre où la date n'existe pas | le serveur l'émet **tel quel** (`{"mois": 2, "jour": 29}`) et ne le déplace jamais ; le client l'affiche en case les années bissextiles et le liste sous la grille sinon. Reporter au 28 ou au 1ᵉʳ mars inventerait une date | **à confirmer** — c'est la seule décision de ce lot qu'un lecteur pourrait vouloir renverser |
| 18 | **La journalisation promise par l'ADR 0003 n'existe nulle part** | L'ADR compense l'énumération en la rendant « coûteuse et **visible** — l'accès au graphe est journalisé ». Or `Tree\Controller\ArbreController` n'écrit aucune entrée d'audit, et `AuditDoctrineSubscriber` est piloté par les **écritures** Doctrine : aucune route de lecture n'est journalisée, graphe compris | **rien ajouté.** Journaliser `/api/anniversaires` en ferait la seule route de lecture tracée du produit — une asymétrie qui se lit comme une suspicion portée sur cet écran-là. Le manque est signalé ici plutôt que comblé de travers | **à trancher** : soit l'ADR décrit une compensation qui n'a jamais été implémentée, soit elle doit l'être pour **toutes** les lectures, pas pour une |
| 19 | **`tree_place` sans `place_id`** | `docs/modele-donnees.md` § 0.6 pré-autorisait « `tree_place` et une colonne `place_id` à côté des colonnes `*_place` » | la table existe, **la colonne non** : la jointure se fait par la chaîne brute repliée. Trois raisons — Doctrine interdit une association dans un `Embeddable` ; `place_id` y aurait imposé un `use` de `Tree` dans `Core` ; et `Person` porte un `#[ORM\Version]`, donc réécrire une personne **périmerait les demandes en attente qui la visent** (§ 9.6) | — décision prise et documentée dans `modele-donnees.md` § 0.6, qui garde le raisonnement d'origine |

Rappel, parce que les deux touchent ce contrat : **`docs/arbitrages.md` § 3 laisse deux points
à l'arbitrage du commanditaire.** Le premier — ce qu'un compte en attente a le droit de lire —
est celui du § 12 de ce document ; le renverser (« ne rien montrer avant activation ») change
`GET /api/inscription/etat`, le graphe et la recherche pour ce régime, et rien d'autre. Le
second — `Member` contre `Account` — n'a aucun effet sur les chemins d'URL, qui sont en
français, mais il en a sur `membre.id`, qui est un `mbr_member.id`.

---

## 19. Carte des lieux

Module **`Almanach`**. Une bulle par lieu situé : où la famille a vécu ses événements.

| Méthode | Chemin | Accès | Corps | Succès | Erreurs |
|---|---|---|---|---|---|
| `GET` | `/api/carte` | `membre` | — | `200` | `401`, `403` `regime_en_attente` |

**Aucun paramètre, et c'est une décision.** Les filtres de genre et la bascule du
dégradé sont des **réglages de rendu** : ils ne changent pas ce que le serveur
calcule. À l'échelle de cet arbre — environ 500 événements datés après le cercle,
une trentaine de kilo-octets — une charge unique coûte moins qu'un aller-retour
par bascule, et rend le geste instantané. C'est ce qui distingue cette route de
`GET /api/chronologie`, dont `bande` et `branches` changent réellement le calcul.

Régime de cache **A** : `Cache-Control: private, no-store`, `Vary: Cookie`, pas d'`ETag`.

### 19.1 La charge

```json
{
  "lieux": [
    {
      "placeId": 17,
      "libelle": "Saule",
      "lat": 46.1234,
      "lon": 2.5678,
      "precision": "commune",
      "confiance": 100,
      "source": "communes-fr",
      "brut": ["Saule, Ombrage, Sylvanie", "SAULE (Ombrage)"]
    }
  ],
  "evenements": [
    {
      "cle": "naissance:412",
      "genre": "naissance",
      "type": "naissance",
      "placeId": 17,
      "place": "Saule, Ombrage, Sylvanie",
      "annee": 1887,
      "texte": "14 mars 1887",
      "qualifier": "exacte",
      "certain": true,
      "sujets": [
        { "personId": 412, "displayName": "Marne Dolomite Sureau", "sex": "feminin", "vivant": false }
      ],
      "familyId": null
    }
  ],
  "resume": {
    "parGenre": { "naissance": 224, "deces": 171, "union": 64, "bapteme": 3, "autre": 30 },
    "sansLieu": 118,
    "lieuNonResolu": 14,
    "sansDate": 37,
    "bornes": { "min": 1657, "max": 1994 }
  }
}
```

### 19.2 Les deux vocabulaires

`type` porte **onze** valeurs — `naissance`, `deces`, `mariage`, `divorce`, puis
les sept de `FactType` reprises **verbatim** : `profession`, `residence`,
`inhumation`, `recensement`, `bapteme_religieux`, `bapteme`, `autre`.

> **Les quatre premières n'existent pas dans `FactType`, et l'en-tête de cette
> énumération interdit de les y ajouter** : naissance, décès, mariage et divorce
> sont des **colonnes**, sur `tree_person` et `tree_family`. Un fait dupliqué est
> une divergence qui attend son heure. `Almanach\Contract\TypeDEvenement` est le
> seul endroit où les onze coexistent, et elles n'y coexistent qu'à la
> sérialisation.

`genre` porte **cinq** valeurs, le vocabulaire des pastilles :
`naissance`, `deces`, `union` (mariage + divorce), `bapteme` (les deux baptêmes),
`autre`. Un lecteur cherche « les unions », pas « les mariages, et aussi les
divorces ».

### 19.3 Ce qu'il faut savoir avant de dessiner

**`placeId` est la clé de regroupement ; `place` est la provenance.** Le front ne
fait aucun rapprochement de chaînes : deux graphies d'une commune doivent
s'effondrer sur une bulle, et c'est le serveur qui l'a décidé, en base. `brut`
porte les graphies qui ont abouti là — c'est ce qui rend **visible un mauvais
géocodage** au lieu de l'enterrer sous un beau libellé.

**`place` est présent même quand `placeId` est `null`.** C'est ce qui rend
« 14 événements sans lieu situé » énumérable plutôt qu'un nombre nu.

**`annee` n'est jamais `null` dans cette charge.** Un événement sans année
utilisable est **omis** et compté dans `resume.sansDate`. L'aplatissement est
`getMin() ?? getMax()`, écrit en clair au point d'appel — la borne basse d'abord,
parce qu'une date bornée à droite (`BEF 1920`) ne porte son information que dans
`max`. Ce n'est **pas** un `getYear()` sur `UncertainDate`, que le § 14 refuse.

**`certain` veut dire « désigne un jour unique », pas « fiable ».** C'est
l'inverse de `isRange()`.

**Trois seaux, et un invariant de conservation :**

> `evenements` + `resume.sansLieu` + `resume.sansDate` = les événements visibles.

`sansLieu` compte ceux dont le lieu n'est pas écrit — un trou dans la donnée.
`lieuNonResolu` compte ceux dont le lieu **est** écrit mais n'a pas de
coordonnées — un trou que `heritage:resoudre-les-lieux` peut combler. **Les
fusionner rendrait invisible la couverture du référentiel**, qui est le seul
chiffre disant s'il vaut la peine de rejouer la commande.

**`resume.parGenre` compte avant tout filtre du client, et après le cercle.**
Sans ces totaux, un filtre qui ne trouve légitimement rien est indistinguable
d'un filtre dont la donnée n'est jamais arrivée — le défaut que la barre d'outils
de l'arbre documente déjà.

**`bornes` n'est jamais `null`.** Sur une charge sans aucune année, les deux
bornes valent l'année courante. Le signal de vacuité est `evenements: []`, jamais
`bornes`. Même doctrine, mêmes mots, qu'au § 9.1.

---

## 20. Anniversaires

Module **`Almanach`**. Le calendrier des dates qui reviennent.

| Méthode | Chemin | Accès | Corps | Succès | Erreurs |
|---|---|---|---|---|---|
| `GET` | `/api/anniversaires` | `membre` | — | `200` | `401`, `403` `regime_en_attente` |

**L'année entière, en une fois, et sans paramètre.** Naviguer de mois en mois ne
redemande donc rien — ce qui est tout l'intérêt des deux flèches.

Régime de cache **A**.

### 20.1 La charge

`jours` est **creux** : seuls les jours pourvus y figurent, et le client construit
la grille.

```json
{
  "jours": [
    {
      "mois": 3,
      "jour": 14,
      "evenements": [
        {
          "cle": "naissance:412",
          "genre": "naissance",
          "type": "naissance",
          "annee": 1887,
          "texte": "14 mars 1887",
          "lieu": "Saule, Ombrage, Sylvanie",
          "sujets": [
            { "personId": 412, "displayName": "Marne Dolomite Sureau", "sex": "feminin", "vivant": false }
          ],
          "familyId": null
        }
      ]
    }
  ],
  "resume": {
    "parGenre": { "naissance": 168, "deces": 121, "union": 71, "bapteme": 3, "autre": 44 },
    "sansJour": { "naissance": 93, "deces": 82, "union": 25, "bapteme": 0, "autre": 40 }
  }
}
```

### 20.2 Un anniversaire exige un jour ET un mois — `exacte`, et elle seule

**C'est la pièce maîtresse de cette section, et personne ne la re-dérivera.**

| `DateQualifier` | `min` | `max` | Jour + mois ? | Pourquoi |
|---|---|---|:-:|---|
| `exacte` | le jour | **le même jour** | **OUI** | la valeur **est** le jour |
| `annee` | `AAAA-01-01` | `AAAA-12-31` | non | le 1ᵉʳ janvier est un **artefact d'encodage** |
| `mois` | `AAAA-MM-01` | dernier du mois | non | le jour est inconnu par construction |
| `environ` | — | — | non | `ABT 1934` porte sur une **année** |
| `avant` | `null` | une borne | non | une borne n'est pas une occurrence |
| `apres` | une borne | `null` | non | idem, côté bas |
| `entre` | `j1` | `j2`, `j1 ≠ j2` | non | une fourchette ; `ck_*_intervalle` interdit même `min = max` |
| `depuis` | un jour | `null` | non | début d'une période |
| `jusqu_a` | `null` | un jour | non | fin d'une période |
| `inconnue` | `null` | `null` | non | — |

> **Le défaut que cette table empêche.** Une lecture naïve de `min` poserait les
> **119 dates `annee` de l'arbre au 1ᵉʳ janvier**, sur 620 — le Nouvel An
> deviendrait le jour le plus chargé de l'histoire de la famille. Plausible,
> muet, et faux. Les 5 dates `mois` feraient la même chose au 1ᵉʳ du mois.
>
> Sur la répartition mesurée (454 `exacte`, 119 `annee`, 23 `environ`, 12
> `entre`, 6 `avant`, 5 `mois`, 1 `depuis`), **454 sur 620 passent la porte**.

**`resume.sansJour` porte les autres, par genre**, calculé après le cercle et
indépendant du mois affiché. Sans ce compte, un lecteur voit 31 naissances en
septembre et n'apprend jamais qu'il en existe 166 de plus dont personne n'a noté
le jour. **Par événement : rien.** Jamais placé sur un jour deviné, jamais au
1ᵉʳ janvier, jamais au 1ᵉʳ du mois.

**Le 29 février est émis tel quel** — `{"mois": 2, "jour": 29}`. Le serveur ne le
déplace pas : le reporter au 28 ou au 1ᵉʳ mars inventerait une date. C'est le
client qui décide, puisque lui seul sait quelle année il affiche.

### 20.3 Les unions n'ont pas de `personId`, et n'en reçoivent pas

Un mariage porte `familyId` et **un ou deux** `sujets` — `tree_family` admet un
époux unique. Sa `cle` est tirée de sa propre ligne (`mariage:42`), jamais d'une
personne : « lui en inventer un — le premier époux, par exemple — serait
attribuer à quelqu'un un événement qui ne le nomme pas » (§ 9.1).

> **Règle ET : un événement n'est rendu que si TOUS ses sujets sont visibles**, et
> s'il en a au moins un.
>
> Le § 16.2 ferme, pour un vivant hors cercle, « toute date, toute borne de date »
> et « tout lieu, **y compris un lieu de fait ou d'union** » — or la date d'un
> mariage est la date des **deux** époux. Rendre la ligne en ne nommant que
> l'époux visible publierait quand même la date de noces de l'autre, que les
> arêtes du graphe suffisent à rapprocher. Et surtout : **ET ne peut pas se
> tromper dans le sens permissif.** Un époux décédé ne doit jamais déverrouiller
> un vivant.
>
> `sujets: []` ⇒ absent. Échec fermé.

### 20.4 Le filtrage a lieu une fois, et une seule

`Almanach\Service\FluxVisible` est la **seule** classe du module qui nomme
`CercleDeVisibiliteInterface` — un test l'exige, en comptant les occurrences. Elle
appelle le port une fois, `filtrerPersonnes()` une fois, et rend le flux filtré.
Les deux normaliseurs sont sans confidentialité **par construction**.

Le port, lui, rend l'arbre validé **complet et sans filtre** : « deux filtres
valent moins qu'un — celui qu'on oublie de corriger est toujours le second ».

**Aucun total « sur N » n'existe dans ces deux charges.** Il dirait par
soustraction combien de personnes le lecteur ne voit pas. Les compteurs de
`resume` sont tous calculés **après** le cercle, et chacun a son test.

### 20.5 Ce que ces deux écrans doivent au § 18

La page des anniversaires **est** une liste de personnes et de dates, triée. C'est
ce que l'ADR 0003 appelle une **énumération**, et le § 9.1 a déjà employé ce mot
pour retirer les vivants hors cercle des barres de la chronologie.

La réponse est la même, mot pour mot : **un vivant hors cercle est absent, jamais
réduit**. L'ensemble résiduel ne couvre alors que (a) les défunts — « un fait
historique, visible de tout compte connecté » — et (b) les vivants **déjà dans le
cercle du lecteur**, dont il lit les dates sur `/api/chronologie`. Aucun fait
nouveau n'en devient déductible.

Et cela se vérifie mécaniquement : pour un lecteur donné, l'ensemble des
`personId` de `/api/anniversaires` doit être un **sous-ensemble** de celui des
`barres` de `/api/chronologie`.

La carte, elle, est hors de cette question : elle ne sert que des agrégats et
n'ouvre aucune bulle sur une liste nominative.

---

## 21. Questions

Module **`Question`**. Les trous de l'arbre que **ce compte-là** pourrait combler,
et les trois façons d'y répondre. Décision, motifs et arbitrages :
[`docs/adr/0008-questions-et-modele-de-langage.md`](adr/0008-questions-et-modele-de-langage.md).

| Méthode | Chemin | Accès | Corps | Succès | Erreurs |
|---|---|---|---|---|---|
| `GET` | `/api/questions` | `membre` | — | `200` | `401`, `409` |
| `POST` | `/api/questions/{id}/je-ne-sais-pas` | `membre` | — | `200` | `401`, `404`, `409` |
| `POST` | `/api/questions/{id}/plus-tard` | `membre` | — | `200` | `401`, `404`, `409` |
| `POST` | `/api/questions/{id}/reponse` | `membre` | `{texte?, mediaId?}` | `201` | `401`, `404`, `409`, `422`, `503` |

**Régime de cache : aucun.** `Cache-Control: no-store` sur les quatre. Une question
nomme une personne et dépend du compte ; elle ne se met en cache nulle part, pas
même dans le navigateur.

### 21.1 Le `GET` écrit, et c'est délibéré

Ouvrir la page **rejoue la détection côté serveur et crée les lignes manquantes**.

Le motif est structurel, et il n'y a pas d'autre solution propre :
`CercleDeVisibiliteInterface` résout le lecteur **depuis la session** (§ 10.4, la
bascule « voir comme un membre »), donc il n'existe pas — et il ne doit pas
exister — d'API « le cercle du compte n° 12 » qu'une tâche nocturne appellerait
pour tous les comptes. En fabriquer une aurait voulu dire réécrire la formule du
cercle une seconde fois, et *une règle de confidentialité ne se trompe qu'une fois
pour fuir* (ADR 0003).

Deux conséquences pour un client :

- **l'appel est idempotent** — un index unique (`uniq_qst_question_lacune`) fait
  que le rejouer mille fois ne crée rien de plus. C'est la seule raison pour
  laquelle l'effet de bord est acceptable ;
- **il ne se déclenche qu'au montage de son écran.** Ni sur un filtre, ni sur un
  défilement, ni en rafale.

`409 detection_concurrente` (`details.rejouable: true`) signale deux ouvertures
simultanées du même compte : rien n'est cassé, rien n'est perdu, **recharger
suffit**.

### 21.2 La charge de la page

```json
{
  "questions": [
    {
      "id": 42,
      "texte": "Savez-vous où Alberte Bourrache est décédée ?",
      "nature": "lieu_deces_inconnu",
      "natureLibelle": "Lieu de décès inconnu",
      "redacteur": "regles",
      "redacteurLibelle": "Formulation automatique",
      "attendPhoto": false,
      "sujet": { "kind": "personne", "id": 214, "libelle": "Alberte Bourrache" },
      "etat": "posee",
      "etatLibelle": "Posée",
      "reports": 0,
      "demandes": []
    }
  ],
  "total": 1,
  "redigeesParModele": 0
}
```

**`redacteur` et `redigeesParModele` ne sont pas décoratifs.** L'ADR 0008 décide
que la page dit à qui lit qu'un modèle de langage a écrit la question : *« un
membre qui découvre par hasard que ses données familiales sont passées chez un
tiers le vit autrement que celui à qui on l'a dit »*. La mention s'affiche **une
fois, en tête** — répétée sous vingt questions, elle cesse d'être lue.

**`attendPhoto` existe pour que le client n'ait pas à connaître la liste des
natures.** Une seule d'entre elles appelle une image plutôt qu'une phrase, et c'est
au serveur de le dire : un front qui testerait `nature === "aucune_photo"`
recopierait une règle métier qu'il faudrait corriger à deux endroits.

### 21.3 Les trois réponses, et ce que chacune écrit

| Geste | Écrit | Ne touche PAS |
|---|---|---|
| « Je ne sais pas » | `qst_question.etat = inconnue`, horodaté | l'arbre, la file |
| « Je vais me renseigner » | `etat = differee` + une relance | l'arbre, la file |
| Une réponse | **1 ou 2 `prp_change_request`** | `tree_person` |

**« Je ne sais pas » et « Je vais me renseigner » sont deux états, pas un.** Le
premier ferme la question définitivement — sans cette mémoire, la même question
revient à chaque chargement et la page devient un mur au bout d'une semaine. Le
second la repousse, et **le délai double à chaque report** : une promesse qu'on
rappelle indéfiniment est un harcèlement poli, au terme duquel on répond « je ne
sais pas » pour s'en débarrasser, ce qui détruit l'information au lieu de la
recueillir.

**Une réponse ne touche jamais `tree_person`.** Elle entre dans le circuit de
l'ADR 0004, par les natures qui existent déjà :

| Réponse | Nature de demande |
|---|---|
| une photo | `ajout_media` |
| un fait qui vise un champ de la surface éditable (§ 6.2) | `modification_personne` |
| un fait qui ne vise aucun champ | `ajout_note` |

*Pourquoi si peu de champs.* Une réponse en texte libre n'est pas une valeur
validée. « vers 1923 », « juste avant la guerre » : un bloc date porte cinq valeurs
solidaires (§ 14), et les deviner depuis une phrase fabriquerait une date ferme à
partir d'une fourchette — l'aplatissement que le § 9.1 du modèle interdit. La
réponse devient donc une note, que l'administrateur lit avant de saisir la valeur.

**Une réponse peut produire DEUX demandes** — le lieu en note, la photo de l'acte
en média — et il faut qu'elles soient deux : l'administrateur doit pouvoir accepter
la photo et refuser le lieu. `demandes[]` les porte toutes.

### 21.4 La photo se téléverse d'abord

`POST /api/medias` (§ 7.1) rend un média `en_attente`, puis son identifiant
accompagne la réponse. **Aucun octet ne transite par les routes de ce module** : le
téléversement, la purge des métadonnées EXIF, la vignette et l'empreinte
appartiennent à `Media` et y restent.

### 21.5 Les codes d'erreur propres à ce module

| Code | Statut | `details.rejouable` | Ce qu'il veut dire |
|---|---|---|---|
| `detection_concurrente` | `409` | `true` | deux ouvertures simultanées — **rechargez** |
| `question_deja_tranchee` | `409` | `false` | un second onglet a déjà répondu — **rechargez**, ne réessayez pas |
| `question_introuvable` | `404` | — | elle n'existe pas, **ou elle n'est pas à vous** |
| `reponse_invalide` | `422` | — | corps vide, ou texte hors bornes |
| `depot_indisponible` | `503` | — | le circuit de validation n'est pas joignable ; **rien à corriger dans la réponse** |

**`question_introuvable` couvre les deux cas, et c'est la décision qui compte.** Un
`403` sur la question d'autrui apprendrait que la ligne existe — or une question
**nomme une personne**, et « n° 412 existe mais n'est pas à vous » se recoupe avec
le reste pour reconstituer à qui l'on demande quoi, donc qui connaît qui. C'est le
§ 15.3 appliqué à une porte de sortie nouvelle.

**`depot_indisponible` est un `503` et non un `422`** parce qu'il n'y a rien à
corriger dans la réponse du membre. Un `422` le ferait chercher une faute dans un
texte qui n'en a aucune, et sans doute réécrire — c'est-à-dire perdre ce qu'il
avait écrit.

### 21.6 Ce que le cercle décide ici

La liste servie passe par le **même** `CercleDeVisibiliteInterface` que les huit
autres portes de sortie, et **deux fois** :

- **à la création** d'une question — on n'en crée pas une qui nommerait quelqu'un
  que ce compte n'a pas le droit de voir ;
- **à la lecture** — un cercle **rétrécit** (`cercle.generations` est un réglage
  d'administration), et les lignes déjà en base, elles, ne bougent pas.

**Une question se sert entière ou pas du tout.** Deux colonnes portent le nom de la
personne : `sujet.libelle`, qui est un libellé dénormalisé ordinaire (§ 0.4 du
modèle), et surtout `texte` — *une phrase en français qui contient le nom*. Aucune
liste blanche champ par champ ne l'aurait attrapée ; c'est la règle plus générale
de l'ADR 0003 qui la couvre, *« tout champ dérivé d'une donnée masquée est lui-même
une donnée masquée »*.

## 22. Tirages au sort

Module **`Tirage`**. Les tirages récurrents — « Noël côté papa, le 24 décembre » — et, chaque
année, qui offre à qui. Décision, secret et règles du tirage :
[`docs/adr/0009-tirages-au-sort.md`](adr/0009-tirages-au-sort.md).

| Méthode | Chemin | Accès | Corps | Succès | Erreurs |
|---|---|---|---|---|---|
| `GET` | `/api/tirages` | `membre` | — | `200` | `401`, `403` |
| `POST` | `/api/tirages` | `membre` | règles + `{participants: int[]}` | `201` | `409`, `422` |
| `GET` | `/api/tirages/{id}` | `membre` | — | `200` | `404` |
| `PUT` | `/api/tirages/{id}` | organisateur | règles | `200` | `403`, `404`, `409`, `422` |
| `DELETE` | `/api/tirages/{id}` | organisateur | — | `204` | `403`, `404`, `409` |
| `POST` | `/api/tirages/{id}/participants` | organisateur | `{personId, referentPersonId?}` | `201` | `403`, `404`, `409`, `422` |
| `DELETE` | `/api/tirages/{id}/participants/{personId}` | organisateur, soi, référent | — | `200` | `403`, `404`, `409` |
| `POST` | `/api/tirages/{id}/rejoindre` | membre rattaché, tirage ouvert | — | `201` | `403`, `404`, `409` |
| `POST` | `/api/tirages/{id}/editions` | organisateur | `{annee, date?}` | `201` | `403`, `404`, `409`, `422` |
| `PUT` | `/api/tirages/{id}/editions/{annee}/presences/{personId}` | organisateur, soi, référent | `{presence}` | `200` | `403`, `404`, `409`, `422` |
| `POST` | `/api/tirages/{id}/editions/{annee}/tirage` | organisateur | — | `200` | `403`, `404`, `409` |
| `DELETE` | `/api/tirages/{id}/editions/{annee}/tirage` | organisateur | — | `200` | `403`, `404`, `409` |
| `POST` | `/api/tirages/{id}/participants/{personId}/lien` | référent ; organisateur si ni compte ni référent | — | `201` `{cle, url}` | `403`, `404`, `409` |
| `DELETE` | `/api/tirages/{id}/participants/{personId}/lien` | référent ; organisateur si ni compte ni référent | — | `204` | `403`, `404`, `409` |
| `GET` | `/api/tirages/lien/{cle}` | **public** (la clé) | — | `200` | `404`, `429` |
| `PUT` | `/api/tirages/lien/{cle}/presence` | **public** (la clé) | `{presence}` | `200` | `404`, `409`, `422`, `429` |

« Organisateur » vaut aussi pour un administrateur. **Règles** :
`{nom, jour, mois, lieu?, ouvert?, memoire?, exclureConjoints?}` — `memoire` de 0 à 10
(défaut 2), `exclureConjoints` vrai par défaut. `presence` vaut `sur_place`, `a_distance`
ou `absente`. Chaque écriture rend le **tirage relu**, sérialisé pour le lecteur. Chaque
écriture rend `409 vue_comme_membre_active` sous la bascule.

**Régime de cache : aucun.** `Cache-Control: private, no-store` et `Vary: Cookie` : une
attribution est un secret par compte.

### 22.1 Le secret

`editions[].mesAttributions` ne contient que les attributions dont le **donneur** est le
lecteur ou un participant dont il est le référent. Aucune autre clé ne porte un receveur,
pour aucun lecteur — organisateur et administrateur compris. Sous la bascule « voir comme
un membre », la lecture se fait **comme ce membre** : tirages visibles, attributions et
`moi` sont les siens, et `peutGerer` ne vaut `true` que s'il organise (ADR 0009, révision du
2026-09-27). Le lien personnel d'un participant (§ 22.4) lit sous la même règle, pour la
personne qu'il désigne.

### 22.2 Les codes d'erreur

| Code | Statut | Quand |
|---|---|---|
| `ressource_introuvable` | `404` | Tirage inconnu **ou** invisible pour ce compte — indistinguables (§ 15.3). |
| `reserve_a_l_organisateur` | `403` | Geste de gestion par un autre compte. |
| `acces_refuse` | `403` | Présence ou retrait d'un participant qu'on ne suit pas. |
| `tirage_ferme` | `403` | Rejoindre un tirage non ouvert. |
| `rattachement_requis` | `409` | Rejoindre sans être rattaché à une personne de l'arbre. |
| `deja_participant` | `409` | La personne est déjà du tirage. |
| `edition_existante` | `409` | L'année existe déjà. |
| `edition_deja_tiree` | `409` | Retirer au sort, ou se retirer d'un tirage fait et à venir. |
| `edition_non_tiree` | `409` | Annuler un tirage qui n'a pas eu lieu. |
| `participants_insuffisants` | `409` | Moins de trois présents ; `details.minimum`. |
| `tirage_impossible` | `409` | Aucune attribution possible, même sans mémoire. |
| `donnees_invalides` | `422` | `champs` nomme chaque champ fautif. Une personne désignée (création, ajout) **inconnue ou vivante hors du cercle du lecteur** donne ce code, avec **le même texte** dans les deux cas : un tirage enregistre et rend le nom de ses participants, et le nom d'un vivant hors cercle ne sort plus (révision du 2026-10-01 de l'ADR 0003). Rejoindre n'est pas concerné : on n'y inscrit que sa propre fiche. |
| `participant_decede` | `422` | Inscrire (création, ajout, rejoindre) une personne qui n'est pas vivante au sens de la règle unique ; `champs` nomme `participants` ou `personId`. |
| `lien_invalide` | `404` | Lien personnel mal formé, inconnu, révoqué, remplacé, participant retiré ou tirage supprimé — **un seul corps** pour tous. |
| `presence_figee` | `409` | Présence réglée par le lien alors que l'édition est tirée, passée, ou absente. |
| `trop_de_tentatives` | `429` | Limiteur des liens publics, par IP : 20 clés invalides / h, 300 requêtes / h. `Retry-After`. |

### 22.3 La mémoire relâchée

`editions[].memoireAppliquee` peut être **inférieure** à `memoire` : quand la règle « pas la
même personne pendant N tirages » rend le tirage impossible, les années les plus anciennes
sont oubliées une à une. `memoireConseillee` (détail) dit, pour le nombre de participants,
jusqu'où la règle se tient à coup sûr.

### 22.4 Le lien personnel d'un participant

Pour qu'une personne **sans compte** suive son tirage : `/t/<cle>` côté front. Un seul lien
actif par participant, valable pour toutes les éditions ; il meurt quand le participant est
retiré ou le tirage supprimé. Seule l'empreinte `sha256` est stockée : la clé (43 caractères
base64url) n'est rendue **qu'une fois**, par le `POST`, qui **régénère** si un lien existait
— l'ancienne clé cesse alors de valoir. Il n'existe aucune route pour relire une clé.

Le détail d'un tirage ajoute à chaque participant `lienActif` (booléen, jamais la clé) et
`peutPartagerLien` : le lecteur est le **référent** de ce participant, ou il organise (ou
administre) le tirage **et** le participant n'a ni compte rattaché ni référent. Créer le lien,
c'est pouvoir lire l'attribution : l'organisateur ne le fait donc jamais pour un participant
qui a son compte, dont le secret tient contre lui (ADR 0009, révision du 2026-10-01). Hors de
cette règle, `POST` et `DELETE` rendent `403 acces_refuse`. La règle se relit à chaque
ouverture : le lien d'un participant qui a, depuis, un compte et pas de référent rend `404`.

`GET /api/tirages/lien/{cle}` — **uniquement ce participant** ; ni les autres participants,
ni leurs présences, ni l'organisateur, ni un identifiant de tirage :

```json
{
  "tirage": {"nom": "Noël côté papa", "lieu": "Chez les grands-parents"},
  "edition": {"annee": 2026, "date": "2026-12-24", "statut": "ouverte", "passee": false},
  "participant": {"personId": 12, "libelle": "…", "presence": "sur_place", "peutChangerPresence": true},
  "attributions": []
}
```

`edition` est l'édition courante (la prochaine à venir, sinon la plus récente) ou `null` ;
`participant.presence` est alors `null`. `attributions` n'est rempli qu'une fois l'édition
tirée, selon la règle de `mesAttributions` appliquée à la personne du lien (la sienne, et
celles des participants dont elle est le référent) — même forme que `mesAttributions`.
`PUT …/presence` rend la même charge ; il n'est permis que si `peutChangerPresence` (édition
ouverte et non passée). `Cache-Control: private, no-store`.

### 22.5 Les défunts

On ne tire pas au sort un cadeau pour une personne disparue : l'API refuse de l'inscrire
(`422 participant_decede`), et la recherche des tirages passe `GET /api/arbre/recherche?vivants=1`,
qui écarte les personnes non vivantes **au sens de la règle unique** (§ 9.2 du modèle — la
même qui donne le champ `vivant` des résultats). Sans ce paramètre, la recherche est
inchangée. Les participants inscrits avant cette règle ne sont pas retirés d'office.

## 22 bis. Événements de famille

Module **`Evenement`**. Les événements de famille — « Cousinade été 2027 » —, leurs invités
(des vivants de l'arbre), qui vient, et pour l'organisateur qui a vu l'annonce sans répondre.
Décision et règles : [`docs/adr/0014-evenements-de-famille.md`](adr/0014-evenements-de-famille.md).

**Addendum du 2026-10-02.** Côté utilisateur, les tirages au sort (§ 22) sont des événements
comme les autres : une seule liste et une seule création côté front, qui appelle les deux
API. **Aucune route n'est fusionnée ni ajoutée pour cela** — `GET /api/evenements` et
`GET /api/tirages` restent chacune maîtresse de ce que son lecteur voit. Deux gestes
s'ajoutent ici : l'organisateur **note la réponse d'un invité à sa place**
(`PUT …/invites/{personId}/reponse`), et **changer les dates prévient les invités**.

| Méthode | Chemin | Accès | Corps | Succès | Erreurs |
|---|---|---|---|---|---|
| `GET` | `/api/evenements` | `membre` | — | `200` `{moi, evenements}` | `401`, `403` |
| `POST` | `/api/evenements` | `membre` | annonce + `{invites: int[]}` | `201` | `409`, `422` |
| `GET` | `/api/evenements/{id}` | organisateur, invité | — | `200` (**pose la vue**) | `404` |
| `PUT` | `/api/evenements/{id}` | organisateur | annonce | `200` | `403`, `404`, `409`, `422` |
| `POST` | `/api/evenements/{id}/annulation` | organisateur | — | `200` | `403`, `404`, `409` |
| `POST` | `/api/evenements/{id}/vu` | organisateur, invité | — | `204` (**pose la vue**) | `404` |
| `PUT` | `/api/evenements/{id}/reponse` | invité | `{reponse}` | `200` | `403`, `404`, `409`, `422` |
| `POST` | `/api/evenements/{id}/invites` | organisateur | `{personIds: int[]}` | `201` | `403`, `404`, `409`, `422` |
| `DELETE` | `/api/evenements/{id}/invites/{personId}` | organisateur | — | `200` | `403`, `404`, `409` |
| `PUT` | `/api/evenements/{id}/invites/{personId}/reponse` | organisateur | `{reponse}` | `200` | `403`, `404`, `409`, `422` |
| `POST` | `/api/evenements/{id}/invites/{personId}/lien` | organisateur, pour un invité **sans compte** | — | `201` `{cle, url}` | `403`, `404`, `409` |
| `DELETE` | `/api/evenements/{id}/invites/{personId}/lien` | organisateur | — | `204` | `403`, `404`, `409` |
| `POST` | `/api/evenements/{id}/relance` | organisateur | — | `200` | `403`, `404`, `409` |
| `GET` | `/api/evenements/lien/{cle}` | **public** (la clé) | — | `200` (**pose la vue**) | `404`, `429` |
| `PUT` | `/api/evenements/lien/{cle}/reponse` | **public** (la clé) | `{reponse}` | `200` | `404`, `409`, `422`, `429` |

« Organisateur » vaut aussi pour un administrateur, qui voit tous les événements. Un
événement est **introuvable** (`404`) pour qui n'en est ni l'organisateur ni l'invité.
**Annonce** : `{titre, debut, fin, lieu?, description?}` — dates `AAAA-MM-JJ`, `fin >= debut`
(égales pour un jour) et non passée ; titre ≤ 120, lieu ≤ 160, description ≤ 4 000
caractères. `reponse` vaut `present` ou `absent` — **pas de troisième valeur**. Une personne
déjà invitée est ignorée sans erreur à l'ajout. Chaque écriture rend l'**événement relu**,
sérialisé pour le lecteur, et `409 vue_comme_membre_active` sous la bascule — sauf
`POST …/vu`, qui n'y pose simplement rien.

**Régime de cache : aucun.** `Cache-Control: private, no-store` et `Vary: Cookie`.

### 22 bis.1 La vue

La **première** vue de l'invité se pose à `GET /api/evenements/{id}`, à `POST …/vu` (la carte
de l'accueil) et à `GET /api/evenements/lien/{cle}`, et ne bouge plus ensuite. Répondre la
pose aussi si elle manquait. Elle ne se pose **ni sous la bascule** « voir comme un membre »,
**ni** quand le compte connecté qui ouvre un lien personnel gère l'événement (l'organisateur
qui vérifie le lien avant de l'envoyer) — la réponse, elle, est la même.

### 22 bis.2 Ce que voit un invité

Le résumé (`GET /api/evenements`) porte exactement : `id`, `titre`, `debut`, `fin`, `lieu`,
`description`, `annule`, `passe`, `organisateur {libelle, estMoi}`, `peutGerer`,
`suisInvite`, `maReponse`, `maReponseNoteePar` (le nom de qui l'a notée à la place de
l'invité, ou `null`), `reponseAConfirmer` (les dates ont changé depuis la réponse),
`peutRepondre`, `nombreDInvites`, `nombreDePresents`. Le détail y
ajoute `quiVient {presents: [{personId, libelle, estMoi}], autres}`, `nombreDAbsents` et
`suivi`, qui vaut **`null`** pour qui ne gère pas l'événement.

`quiVient.presents` ne nomme que les présents **du cercle du lecteur** (ADR 0003) ; les
autres sont comptés dans `autres`. **Qui a décliné, qui a vu sans répondre, et tout
horodatage de vue ou de réponse ne sortent que dans `suivi`.**

### 22 bis.3 Le suivi

```json
{
  "presents": [], "absents": [],
  "fantomes": [{"personId": 12, "libelle": "…", "estMoi": false, "aUnCompte": true, "lienActif": false, "peutPartagerLien": false, "vuLe": "2027-06-28T21:14:00+02:00", "reponduLe": null, "relanceLe": null}],
  "nonVus": [],
  "derniereRelanceLe": null,
  "prochaineRelancePossibleLe": null,
  "aRelancer": 1
}
```

`ligne` : `{personId, libelle, estMoi, aUnCompte, lienActif, peutPartagerLien, vuLe,
reponduLe, relanceLe, noteePar, aConfirmer, peutNoterReponse}` — horodatages ATOM ou `null`.
`noteePar` : la réponse a été notée par l'organisateur (ou un administrateur) — elle ne pose
**pas** `vuLe`, et s'efface quand l'invité répond lui-même. `aConfirmer` : répondu avant le
dernier changement de dates. `peutNoterReponse` : l'événement est modifiable et la ligne
n'est pas celle du lecteur. `fantomes` : vus, sans réponse ;
`nonVus` : pas encore ouverts. `aRelancer` : les invités **avec un compte** et sans réponse
qu'une relance toucherait maintenant (l'organisateur exclu). Une relance par **24 heures** au
plus, pour tout l'événement ; les invités sans compte ne reçoivent rien d'elle.

### 22 bis.4 Le lien personnel

Réservé aux invités **sans compte** (un rattachement, même en attente, compte) ; il cesse de
valoir quand l'invité en ouvre un. Mêmes règles que le lien de tirage (§ 22.4) : clé de 43
caractères, empreinte seule en base, un seul refus `404 lien_invalide`, limiteur par IP
(20 échecs / h, 300 requêtes / h). Il rend exactement :

```json
{
  "evenement": {"titre": "…", "debut": "2027-07-17", "fin": "2027-07-18", "lieu": "…", "description": "…", "annule": false, "passe": false},
  "invite": {"libelle": "…", "reponse": null, "aConfirmer": false, "peutRepondre": true}
}
```

Ni les autres invités, ni qui vient, ni l'organisateur, ni aucun identifiant.

### 22 bis.5 Les notifications

`evenement_invitation` (création, ajout), `evenement_relance`, `evenement_annule` (aux invités
qui avaient vu ou répondu), `evenement_dates_modifiees` (début ou fin changés par
`PUT /api/evenements/{id}`, à **tous** les invités ; titre « <titre> : nouvelles dates ») ; aux
seuls invités qui ont un compte, jamais à l'auteur du geste ;
`linkRoute: "evenement.detail"`, `linkParams: {id}`.

### 22 bis.6 Les codes d'erreur

| Code | Statut | Quand |
|---|---|---|
| `ressource_introuvable` | `404` | Événement inconnu **ou** invisible ; invité inconnu à retirer. |
| `reserve_a_l_organisateur` | `403` | Geste de gestion par un autre compte. |
| `non_invite` | `403` | Répondre sans être invité (l'organisateur qui ne s'est pas invité). |
| `acces_refuse` | `403` | Lien demandé pour un invité qui a son compte. |
| `evenement_annule` | `409` | Modifier, inviter, retirer, relancer ou annuler un événement annulé. |
| `evenement_passe` | `409` | Les mêmes gestes sur un événement terminé. |
| `reponse_figee` | `409` | Répondre — ou noter une réponse — à un événement annulé ou terminé. |
| `relance_trop_recente` | `409` | Moins de 24 h depuis la dernière ; `details.prochaineRelancePossibleLe`. |
| `rien_a_relancer` | `409` | Aucun invité avec un compte n'attend de réponse. |
| `donnees_invalides` | `422` | `champs` nomme chaque champ fautif. Une personne **inconnue ou vivante hors du cercle** du lecteur : le même texte dans les deux cas. |
| `invite_decede` | `422` | Inviter une personne qui n'est pas vivante ; `champs` nomme `invites` ou `personIds`. |
| `lien_invalide` | `404` | Lien mal formé, inconnu, révoqué, remplacé, invité retiré ou qui a ouvert un compte. |

## 23. Incarnation d'un compte

Se connecter **en tant qu'un autre compte**, et en revenir. Décision et garde-fous :
[`docs/adr/0010-incarnation-d-un-compte.md`](adr/0010-incarnation-d-un-compte.md).

| Méthode | Chemin | Accès | Corps | Succès | Erreurs |
|---|---|---|---|---|---|
| `POST` | `/api/administration/incarnation` | `ADMIN` | `{userId}` | `200` charge de session **du compte incarné** | `404` · `409` `incarnation_impossible` · `409` `incarnation_active` · `422` |
| `DELETE` | `/api/session/incarnation` | connecté | — | `200` charge de session **de l'administrateur** | `409` `incarnation_inactive` |

- Pendant l'incarnation, la charge de session (§ 2.2) est celle du membre, plus
  `incarnation: {par: {id, displayName}}` ; hors incarnation, `incarnation` vaut `null`.
- `POST /api/session/mot-de-passe` et `POST /api/session/courriel` rendent
  `409 incarnation_active`.
- `GET /api/administration/journal` expose `impersonatorUserId` et `impersonatorEmail` sur
  chaque ligne, `null` hors incarnation.

## 24. Actes demandés

Module **`Demarche`**. Les pièces demandées à une mairie, à des archives, à un notaire — et
leur suivi, chaque message gardé avec son texte entier. Décision, visibilité et révisions :
[`docs/adr/0011-actes-demandes.md`](adr/0011-actes-demandes.md).

| Méthode | Chemin | Accès | Corps | Succès | Erreurs |
|---|---|---|---|---|---|
| `GET` | `/api/demarches?personId=&enAttente=` | `membre` | — | `200` `{elements, total}` (résumés) | `403`, `422` |
| `POST` | `/api/demarches` | `membre` | description + `premierMessage?` + `precedenteId?` | `201` (détail) | `409`, `422` |
| `GET` | `/api/demarches/{id}` | `membre` | — | `200` (détail) | `404` |
| `PUT` | `/api/demarches/{id}` | `membre` | description | `200` | `404`, `409`, `422` |
| `PUT` | `/api/demarches/{id}/suivi` | `membre` | `{statut, resultat?}` | `200` | `404`, `409`, `422` |
| `DELETE` | `/api/demarches/{id}` | auteur, administrateur | — | `204` | `403`, `404`, `409` |
| `POST` | `/api/demarches/{id}/messages` | `membre` | message | `201` | `404`, `409`, `422` |
| `DELETE` | `/api/demarches/{id}/messages/{messageId}` | qui l'a saisi, administrateur | — | `200` | `403`, `404`, `409` |

**Description** : `{nature, forme, intitule, commune?, dateDeLActe?, destinataire,
personIds: int[]}`. **Message** : `{sens, canal, date (AAAA-MM-JJ), de?, a?, objet?, texte,
reference?}`. Chaque écriture rend la **démarche relue** ; chacune rend `409` sous la bascule
« voir comme un membre ». `Cache-Control: private, no-store`, `Vary: Cookie`.

**Qui voit** : une démarche n'existe que pour qui voit **toutes** les personnes qu'elle
concerne — masquée et inexistante rendent `404` au détail, absence en liste.

### 24.1 Les statuts

| `statut` | `statutLibelle` | `enAttente` | `attendUneReponse` |
|---|---|---|---|
| `envoyee` | Envoyée, en attente | `true` | `true` |
| `relancee` | Relancée, en attente | `true` | `true` |
| `expediee` | Expédiée par courrier, en attente de réception | `true` | **`false`** |
| `recue` | Reçue | `false` | `false` |
| `refusee` | Refusée | `false` | `false` |
| `sans_suite` | Abandonnée | `false` | `false` |

`expediee` (2026-10-01) : la mairie a répondu « traité, l'acte part par la poste ». La
démarche attend encore la pièce — elle sort dans `?enAttente=1`, et `joursDAttente` reste
compté —, mais **plus la mairie** : `aRelancer` y vaut toujours `false`.

`aRelancer` est calculé par le serveur : `attendUneReponse` **et** `joursDAttente ≥ 30`. Le
front ne recompte pas.

### 24.2 Refaire autrement — `precedente` et `suites`

Après un refus (« nous ne traitons pas les demandes par courriel »), on crée une **nouvelle**
démarche par un autre canal, en passant `precedenteId` au `POST`. Elle porte alors :

```json
"precedente": { "id": 12, "statut": "refusee", "demandeeA": "Mairie de Charmille" },
"suites": []
```

et la démarche reprise, `"suites": [{ "id": 15, "statut": "envoyee" }]`. Les deux clés sont
présentes sur les résumés comme sur le détail ; `precedente` vaut `null` et `suites` `[]` en
l'absence de lien.

- **Seules les démarches que le lecteur voit** figurent dans ces liens : une démarche liée
  masquée n'y apparaît pas, comme dans les listes.
- `precedenteId` d'une démarche inexistante **ou** masquée : `422 donnees_invalides`,
  `champs.precedenteId`, même message dans les deux cas — comme une personne masquée dans
  `personIds`. Une valeur qui n'est pas un entier positif : même code, même clé.
- Le lien se fixe à la création et ne se modifie pas. **Supprimer la démarche reprise** ne
  supprime pas celle qui la reprend : son `precedente` repasse à `null`
  (`ON DELETE SET NULL`).

### 24.3 Les codes d'erreur

| Code | Statut | Quand |
|---|---|---|
| `ressource_introuvable` | `404` | Démarche ou message inconnu, **ou** démarche masquée — indistinguables (§ 15.3). |
| `reserve_a_l_auteur` | `403` | Supprimer la démarche d'un autre, retirer le message d'un autre. |
| `regime_en_attente` | `403` | Compte en attente (§ 16.3). |
| `donnees_invalides` | `422` | `champs` nomme chaque champ fautif ; ceux du premier message sont préfixés `premierMessage.`. |

## 25. Poser une question à l'assistant

Module **`Assistant`**. Un membre interroge l'arbre en langage naturel — « Qui est né à
Étaples ? », « Comment suis-je lié à Marie Becquelin ? » — dans une **conversation suivie** :
l'assistant répond à partir des fiches, cite celles qu'il a lues, et relit les échanges
précédents pour les questions de suivi. **Lecture seule** : aucune demande n'est déposée,
rien n'est écrit dans l'arbre. Décision et données transmises : addendum du 1er octobre 2026
de l'[ADR 0008](adr/0008-questions-et-modele-de-langage.md).

| Méthode | Chemin | Accès | Corps | Succès | Erreurs |
|---|---|---|---|---|---|
| `GET` | `/api/assistant/conversations` | connecté | — | `200` `{elements: ResumeConversation[]}` | — |
| `POST` | `/api/assistant/conversations` | `membre` | `{question}` | `201` `{conversation, restant}` | `403`, `409`, `422`, `429`, `502`, `503` |
| `GET` | `/api/assistant/conversations/{id}` | auteur | — | `200` `{conversation}` | `404` |
| `POST` | `/api/assistant/conversations/{id}/questions` | auteur, `membre` | `{question}` | `201` `{conversation, restant}` | `403`, `404`, `409`, `422`, `429`, `502`, `503` |
| `DELETE` | `/api/assistant/conversations/{id}` | auteur, `membre` | — | `204` | `403`, `404`, `409` |

```json
{
  "conversation": {
    "id": 4, "titre": "Qui est né à Étaples ?",
    "createdAt": "2026-10-01T14:05:12+02:00", "updatedAt": "2026-10-01T14:07:40+02:00",
    "nombreDEchanges": 2,
    "echanges": [{
      "id": 9, "question": "Qui est né à Étaples ?",
      "reponse": "D'après l'arbre, [Marie Becquelin](personne:12) est née à Étaples vers 1850 (repris d'un arbre en ligne, non vérifié).",
      "personnes": [{ "personId": 12, "displayName": "Marie Becquelin", "visibilite": "complete", "ligneDates": "~1850 – 1921" }],
      "createdAt": "2026-10-01T14:05:12+02:00"
    }]
  },
  "restant": 58
}
```

- **Une conversation ne se crée pas vide** : `POST /conversations` porte la première
  question, et la conversation n'existe qu'au retour de la réponse. Un `502` ne laisse rien.
- `titre` est la première question, coupée à 120 signes ; il ne change jamais.
  `ResumeConversation` est le même objet sans `echanges`. La liste en sert au plus 50, la
  plus récemment poursuivie d'abord.
- `reponse` est un **texte** où chaque fiche citée s'écrit `[Libellé](personne:12)` ; le
  front le découpe en texte et en liens vers `/personnes/{id}` et ne l'interprète jamais
  comme du HTML. Une mention vers une fiche inexistante a perdu son lien côté serveur.
- `personnes` est une liste de `PersonneCitee` (§ 11.4, porte 7 ter) : relue **à chaque
  lecture** à travers le cercle du moment, comme les libellés des mentions. Une personne
  sortie du cercle depuis la réponse apparaît masquée.
- **Une conversation n'existe que pour son auteur** — `404` pour celle d'un autre, y
  compris pour un administrateur.
- Question : 1 à 1 000 caractères (`422`, `champs.question`). Une conversation compte au
  plus 50 échanges ; au-delà, `422` invite à en ouvrir une nouvelle.
- Quota : **60 questions par 24 heures glissantes et par compte** par défaut, administrateur
  compris (`assistant.questions`, réglable par compte au § 10.1) ; `429` avec `Retry-After`,
  dont le message cite la limite du compte. `restant` le décompte.
- `409 vue_comme_membre_active` sur les trois écritures sous la bascule ; les lectures
  restent ouvertes. **Sous une incarnation** (§ 23), on écrit : la conversation appartient
  au compte incarné, et le journal nomme l'administrateur.
- `403 regime_en_attente` pour un compte en attente ; `503 assistant_indisponible` sans
  clé ; `502 assistant_erreur` si le modèle échoue ou ne conclut pas en huit tours ou
  90 secondes. Une réponse prend en général 20 à 60 secondes.
- `Cache-Control: private, no-store`, `Vary: Cookie`.

**Ce que le modèle voit** : uniquement ce que le lecteur a le droit de voir. Ses outils
(`chercher_personnes`, `lire_personne`, `chercher_par_lieu`) appliquent les portes de sortie
de l'ADR 0003 **avant** de lui répondre — un vivant hors cercle est absent des recherches et
réduit, partout ailleurs, à son identifiant et « Personne vivante ». C'est la différence avec
l'assistant de proposition, qui voit l'arbre entier.

**Journal** : une ligne `appel_modele_langage`, sujet `Assistant\Question`, par question —
des comptes (tours, appels d'outils, échanges transmis, fiches citées), **jamais** la
question, la réponse ni un nom.
