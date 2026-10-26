# ADR 0021 — Les jetons d'API : un agent écrit sans cookie, sous le compte du commanditaire

Date : 2026-10-25 · Statut : acceptée

## Contexte

Le contrat (§ 1.6) ne connaissait qu'un porteur : le cookie de session, `HttpOnly`. « Aucun
jeton dans un en-tête, aucun jeton en `localStorage` » — et c'est toujours la bonne règle
pour **un navigateur**, où un jeton lisible par le script est un jeton volable.

Mais le travail de recherche ne passe plus par un navigateur : des agents déposent des
demandes (actes lus, personnes, citations) et la session principale en valide une partie. Ils
n'ont aujourd'hui, pour atteindre l'API, que deux issues, toutes deux mauvaises :

- **rejouer une connexion**, donc détenir le mot de passe du commanditaire et son cookie, avec
  la portée de ses droits entière, sans limite de durée ni moyen de couper un agent sans
  couper l'autre ;
- **écrire en base** à la main, hors de la file de l'ADR 0004, et donc hors de ses garanties.

Un compte de service serait la réponse ordinaire. Elle est écartée : `docs/securite-comptes.md`
(§ 8 quater, « Un compte par personne, jamais partagé ») pose qu'un compte qui n'est pas une
personne rend `core_audit_log` muet sur l'auteur. Un compte « Claude » aurait aussi un statut,
un rôle, un `mbr_member` à rattacher — un second administrateur dont personne ne se souvient.

## Décision

### 1. Un jeton est une clé de plus sur le compte, pas un compte

Un **jeton d'API** est rattaché à l'utilisateur 1, le commanditaire. Ce que l'agent écrit a
pour auteur « Administration », comme si le commanditaire l'écrivait ; **la règle métier ne
change pas** (file de l'ADR 0004, voters, cercle). Ce qui change est l'**audit** : chaque
ligne de `core_audit_log` écrite sous un jeton nomme ce jeton.

Deux jetons sont prévus, parce que couper l'un ne doit pas couper l'autre :

| Libellé | Portée | Usage |
|---|---|---|
| « Claude — agents » | `contribution` | les agents déposent, complètent, téléversent |
| « Claude — session principale » | `validation` | la session principale tranche les demandes |

### 2. Trois portées, ordonnées

`lecture` < `contribution` < `validation`. Chacune comprend les précédentes.

- `lecture` : les routes `GET` utiles à la recherche (la session, le graphe, les fiches et
  familles, les demandes et leur brouillon, les sources, les médias et les démarches déjà
  là), plus deux `POST` de recherche qui n'écrivent rien : les ressemblances dans l'arbre et
  parmi les demandes en attente (la saisie reste hors de l'URL).
- `contribution` : déposer une demande (sans la valider) ; téléverser un média ; créer une
  démarche, y ajouter un courrier, en poser le statut. Compléter, soumettre ou retirer une
  demande **ne sont pas ouverts** : aucun outil des agents ne les appelle.
- `validation` : relire le diff d'une demande, la valider ou la refuser ; créer une source.

La liste des routes de chaque portée n'est **pas** dans ce document : elle est dans
`Core\EventSubscriber\PorteeDuJetonSubscriber` (liste blanche, **fermée par défaut**), par
**nom de route et méthode** — une route que la liste ne nomme pas rend `403 acces_refuse`, et
une requête `HEAD` sur une route `GET` est refusée. Elle est celle des outils des agents et
de la session principale, ni plus ni moins. Un test vérifie que chaque route de la liste
existe dans le routeur. **Exclus pour toutes les portées**, par dix-sept motifs de nom de
route jugés *avant* la liste (une ligne ajoutée par erreur à la liste ne les ouvre pas) :
utilisateurs, réglages et quotas, incarnation, voir-comme, jetons, connexion et
déconnexion, clés d'accès, mot de passe, courriel, inscription, invitations et membres,
droits RGPD et portrait du compte, journal d'audit. Un jeton ne se crée pas, ne se révoque
pas et n'en crée pas un autre par l'API.

### 3. Le jeton lui-même

- **Forme** : `hrt_` suivi de 32 octets aléatoires en base64url. Le générateur vit dans
  `Core` : l'ADR 0002 interdit d'importer celui de `Membership`.
- **Stockage** : seule l'**empreinte sha256** est gardée (`core_api_token`, entité
  `Core\Entity\JetonDApi`, sur le modèle de `Passkey`), unique. Le jeton en clair n'existe
  qu'à sa création. Un **préfixe** (les premiers caractères) reste affichable pour reconnaître
  un jeton dans une liste ou un journal.
- **Durée de vie** : `expires_at` **obligatoire**, 90 jours par défaut. Pas de jeton éternel.
- **Cycle de vie** : `revoked_at` (révocation), `last_used_at` et `last_used_ip` (dernier
  usage). `JetonDApiHandler` n'écrit `last_used_at` qu'une fois par 5 minutes au plus : une
  lecture ne doit pas devenir une écriture à chaque requête.
- **Gestion** : trois commandes. `heritage:jeton-api:creer <utilisateur id|courriel> <libelle>
  --portee=lecture|contribution|validation [--jours=90]` (1 à 365) ; `heritage:jeton-api:lister
  [--utilisateur=] [--tous]` ; `heritage:jeton-api:revoquer <id|préfixe>` (idempotent). Le
  jeton est **affiché une seule fois**, à la création. Les commandes journalisent
  elles-mêmes (pas de requête HTTP, donc pas de souscripteur d'audit).
- **Écran d'administration des jetons : renvoyé à plus tard.** Deux jetons et une personne ne
  justifient pas encore une page ; les commandes suffisent.

### 4. Le porteur : le cookie reste celui du front

Le front continue de vivre du cookie et **n'envoie aucun en-tête d'autorisation**.
`Authorization: Bearer hrt_…` est **admis hors navigateur**, et nulle part ailleurs : le
cookie n'est pas déprécié, il n'est pas non plus requis.

Côté Symfony, un pare-feu `api_jetons`, **sans état** (`stateless`), placé **avant** `main`,
avec `access_token` et `JetonDApiHandler`. Il prend les requêtes `/api` dont `Authorization`
est au **schéma `Bearer`** (casse ignorée, `Core\Security\RequeteParJetonDApi`) : un
`Authorization: Basic` posé par un intermédiaire reste à `main`. Il n'est **pas `lazy`** : un
jeton faux est refusé même sur une route publique. Il garde **le même fournisseur, le même
`user_checker` et le même point d'entrée JSON** que `main` : un compte suspendu n'obtient pas
plus de jeton que de session, et une requête sans identification reçoit la même enveloppe
`401` (§ 15.1 du contrat). **`access_control` ne change pas** : les jetons passent par les
mêmes règles de rôle que le cookie.

Le sans-état a des conséquences, traitées plutôt que découvertes :

- **Le Bearer fait foi, sans session.** Une requête qui porte cookie *et* Bearer est jugée
  par le jeton seul : `JetonSansSessionSubscriber` retire ses cookies, et la session est
  remplacée par `Core\Security\SessionRefuseeAuJetonDApi`, qui se dit absente et rend `403`
  à toute lecture ou écriture. **Aucune réponse par jeton ne pose de `Set-Cookie`.**
- `BasculeVoirComme` teste `hasPreviousSession()` et non `hasSession()`, pour ne pas ouvrir
  de session sur une requête à jeton : activer « voir comme » sous un jeton rend
  `403 acces_refuse`, la désactiver est sans effet.
- **Un jeton ne prouve jamais une identité récente.** Sous un jeton,
  `PreuveDIdentiteRecente::exiger()` rend `403 acces_refuse` quel que soit l'état de la
  session (même avec le bon mot de passe dans le corps), `marquer()` est sans effet, et
  `PreuveDIdentiteSubscriber` ne marque rien. Sans cela, un jeton volé réglerait ce que le
  mot de passe protège.

### 5. Décider sur une demande exige d'en être l'auteur

La portée `validation` ne suffit pas : `DemandeVoter::DECIDER` exige l'administrateur **et**,
avec un jeton, que l'auteur de la demande soit l'utilisateur du jeton (la vue administrateur,
l'aperçu de fusion et la fusion y sont soumis comme valider et refuser). Comme tout se fait sous l'utilisateur 1, cela
reproduit la règle humaine (« on ne valide pas la demande d'un autre ») sans créer d'exception
qui ferait du jeton un administrateur universel.

### 6. Refus, limites

- **Un seul `401`** pour un jeton inconnu, expiré, révoqué ou mal formé, ou dont le compte
  est suspendu (`RefusDuJetonDApi`) : même statut, même code `authentification_requise`,
  même corps. L'appelant n'apprend pas lequel.
- **`429`** (`trop_de_tentatives`, avec `Retry-After`) : `jeton_api_echecs_ip`, **10 échecs
  par IP sur 15 minutes** (fenêtre glissante), contrôlé *avant* la recherche du jeton — une IP
  bloquée ne peut plus tester même un bon jeton : le balayage d'un jeton se règle sur l'IP,
  pas sur le jeton qu'on ne connaît pas. Et `jeton_api_requetes`, **2 000 requêtes par heure
  et par jeton** (paramètre `app.core.jeton_api.requetes_par_heure`), pour qu'un agent qui
  boucle ne bloque pas les autres. Un `Bearer` illisible (`Bearer` seul, caractère interdit)
  rend le `401` du point d'entrée et n'est pas compté.
- **`Vary: Cookie, Authorization`** sur **toutes** les réponses `/api`, celles à `ETag`
  comprises (`SecurityHeadersSubscriber`) : une réponse qui varie selon l'un ou l'autre le
  déclare.

### 7. L'audit

- `core_audit_log` gagne `api_token_id` et `api_token_label`, remplis pour toute ligne écrite
  pendant une requête par jeton (un écouteur d'`AuthenticationTokenCreatedEvent` que lit
  `AuditDoctrineSubscriber`, comme il lit déjà l'incarnation). Le libellé est **recopié** :
  un jeton supprimé ne laisse pas des lignes anonymes.
- `AuditAction` reçoit `JetonApiCree` et `JetonApiRevoque`, écrites par les commandes **sans
  acteur** (console) ; le propriétaire est dans `changes.utilisateur_id`.
- Les **succès** d'authentification par jeton n'écrivent **aucune** ligne de connexion — à
  deux requêtes par seconde, le journal ne dirait plus rien ; ce que fait un jeton se lit
  dans les lignes qu'il écrit. Les **échecs** le sont (`methode: jeton_api`), avec `prefixe` :
  les 12 premiers caractères (`hrt_` et 8), **seulement** si la chaîne présentée commence
  comme un jeton d'Heritage — jamais un jeton, même faux, ni son empreinte. Pour un compte
  suspendu, la ligne nomme aussi le jeton.
- Le **dernier usage** (`last_used_at`, `last_used_ip`) s'écrit au plus toutes les 300 s,
  sans ligne d'audit.

## Conséquences

- **Cette décision annule en partie `docs/api-contract.md` § 1.6** (« Aucun jeton dans un
  en-tête, aucun jeton en `localStorage` : le cookie est le seul porteur de session »). La
  règle reste vraie **du front** ; elle cesse de l'être de l'API. Le contrat porte le renvoi
  réciproque (§ 1.6, 1.7, 1.8, 1.9, 15.2).
- Le contrat ne gagne **aucune route** : les jetons se gèrent en ligne de commande. Il gagne un
  second porteur, un code `429` et un en-tête `Vary`.
- Une migration : table `core_api_token` et deux colonnes de `core_audit_log`.
- La CI refuse un jeton `hrt_` commité (étape « Aucun jeton d'API hrt_ n'est commité » du job
  `deploiement`) : le dépôt est public.
- Le commentaire de `app/src/lib/api/client.ts` est corrigé : le front n'envoie toujours aucun
  en-tête d'autorisation, mais il ne dit plus que l'API n'en admet aucun.
- **Ce qui n'est pas fait, et pourrait l'être** : un écran d'administration des jetons ; des
  jetons rattachés à d'autres utilisateurs ; une portée par module plutôt que par geste ; une
  alerte sur un jeton qui change d'IP.
- **Refusé** : un compte de service (§ 8 quater de `docs/securite-comptes.md`) ; un jeton sans
  expiration ; un jeton qui prouve une identité récente ; une liste de routes ouverte par
  défaut.
