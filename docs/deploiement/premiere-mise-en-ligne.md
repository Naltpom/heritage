# Première mise en ligne — https://heritage.naltpom.fr

**Ce document est exécutable par quelqu'un qui n'était pas là.** Chaque étape porte
la commande exacte, ce qu'elle doit répondre, et ce qu'il faut faire si elle
répond autre chose. Les commandes précédées de `vps$` se tapent **sur le
serveur** ; celles précédées de `poste$` sur une machine de travail ; le reste se
fait dans l'interface web de GitHub.

> **Lisez le § 2 avant tout.** Cinq gestes n'appartiennent qu'au commanditaire —
> personne d'autre ne peut les faire à sa place — et deux d'entre eux bloquent
> tout le reste.

---

## 1. Ce qui est déjà fait, et ce qui ne l'est pas

Mesuré sur le VPS le **16 septembre 2026**, en lecture seule sauf mention
contraire.

| Élément | État | Détail |
|---|---|---|
| Entrée DNS | ✅ fait | `heritage.naltpom.fr` résout vers le VPS, via l'enregistrement générique `*.naltpom.fr` chez OVH. Rien à demander à l'hébergeur. |
| Port hôte | ✅ réservé | **5511**, libre et vérifié libre. Suite de la convention du serveur : certivio 5471, sitter 5481, pokemon 5491, learnly 5501. |
| Clé SSH *GitHub Actions → VPS* | ✅ posée | Paire ed25519 dédiée, partie publique ajoutée à `~/.ssh/authorized_keys` (9ᵉ ligne, commentaire `github-actions-deploy-heritage`). Empreinte : `SHA256:fnrYoF0KZbKAiGyNmEJKZw+di8pbxfIYDqkUf5MSEl0`. Authentification par cette clé **essayée et réussie**. |
| Clé SSH *VPS → GitHub* | ⚠ créée, pas encore acceptée | `~/.ssh/heritage_deploy` sur le VPS, alias `github-heritage` ajouté à `~/.ssh/config`. Empreinte : `SHA256:PdkWGXCpNdoT9QfEfeUoRCV1y3HxEiGNdBgY4ca6NW8`. Elle rend `Permission denied` **tant que le § 2.1 n'est pas fait**, et c'est normal. |
| Clone `~/apps/heritage` | ❌ | Impossible avant le § 2.1. |
| `.env` de production | ❌ | S'écrit **à la main sur le serveur**. § 5. |
| vhost nginx de l'hôte | ❌ | Le fichier est prêt : `docs/deploiement/heritage.naltpom.fr.conf`. § 7. |
| Certificat TLS | ❌ | `certbot --nginx`. § 8. **Lire le § 8.2, le piège du compte ACME.** |
| Secrets GitHub | ❌ | § 2.2. |
| Premier administrateur | ❌ | § 9 — en console, il n'existe **aucune** route qui crée un compte. |
| Données de l'arbre | ❌ | § 10 — restauration d'une sauvegarde. Il n'y a plus d'import GEDCOM (ADR 0017). |

---

## 2. Les gestes du commanditaire

Ce sont les seuls qui demandent un accès que personne d'autre n'a.

### 2.1 Déposer la clé de clonage sur GitHub *(bloque tout le reste)*

Le VPS doit pouvoir cloner le dépôt privé. La clé existe déjà sur le serveur ;
il reste à l'autoriser.

```
vps$ cat ~/.ssh/heritage_deploy.pub
```

Copier la ligne entière, puis sur GitHub :
**`Naltpom/heritage` → Settings → Deploy keys → Add deploy key**

- *Title* : `vps-naltpom (lecture seule)`
- *Key* : la ligne copiée
- **`Allow write access` : NON COCHÉ.** Le VPS ne pousse jamais. Une clé de
  déploiement en écriture sur une machine qui exécute du code téléchargé est une
  porte ouverte vers l'historique du dépôt.

Vérification, qui doit cesser de dire « Permission denied » :

```
vps$ ssh -T github-heritage
```

> Réponse attendue : `Hi Naltpom/heritage! You've successfully authenticated, but
> GitHub does not provide shell access.` — **et c'est un succès**, malgré le ton.

### 2.2 Créer les secrets et la variable GitHub

**`Naltpom/heritage` → Settings → Secrets and variables → Actions.**

| Nom | Type | Valeur |
|---|---|---|
| `SSH_HOST` | secret | l'adresse du VPS (celle que rend `dig +short heritage.naltpom.fr`) |
| `SSH_USER` | secret | `ubuntu` |
| `SSH_KEY` | secret | **la clé privée** de la paire *GitHub Actions → VPS*, en entier |
| `SSH_PORT` | secret | `22` |
| `APP_DIR` | secret | `/home/ubuntu/apps/heritage` |
| `DOMAINE_PUBLIC` | **variable**, pas secret | `heritage.naltpom.fr` |

Sur `SSH_KEY`, trois détails qui font échouer le flux avec un message
incompréhensible (`ssh: handshake failed`) quand on les rate :

1. **Tout le fichier**, de `-----BEGIN OPENSSH PRIVATE KEY-----` à
   `-----END OPENSSH PRIVATE KEY-----` inclus.
2. **Le saut de ligne final compris.** Une clé OpenSSH sans retour à la ligne
   terminal est refusée.
3. C'est la **clé privée**, pas la publique. Si le contenu tient sur une ligne et
   commence par `ssh-ed25519`, c'est la mauvaise.

Le fichier de cette clé privée vous a été indiqué séparément, hors de ce dépôt.
**Une fois collée dans le secret GitHub, effacez-le du poste** — elle n'a pas à
survivre ailleurs que dans le secret :

```
poste$ Remove-Item <le chemin indiqué>, <le même>.pub
```

Sur `DOMAINE_PUBLIC` : un domaine public **n'est pas un secret**. En faire un le
rendrait masqué (`***`) dans les journaux d'exécution, au moment précis où l'on
cherche pourquoi un contrôle échoue. Le flux a de toute façon un repli en dur sur
`heritage.naltpom.fr` : la variable ne sert qu'à pouvoir changer de domaine sans
toucher au dépôt.

### 2.3 Choisir et noter le SMTP des invitations

Il faut un vrai serveur d'envoi, et une adresse d'expéditeur **au domaine**. Ces
deux valeurs iront dans le `.env` du serveur (§ 5), jamais dans le dépôt.

Et il faut publier **SPF, DKIM et DMARC** pour le domaine expéditeur, dans la
zone `naltpom.fr` chez OVH. Ce n'est pas une formalité : une invitation classée
en indésirable n'est **pas récupérable** par le destinataire, et l'administrateur
ne l'apprend jamais — l'interface lui dit « envoyée ». Le repli, praticable, est
la copie manuelle du lien depuis l'administration.

### 2.4 Décider de la copie hors site des sauvegardes

Le service `sauvegarde` écrit un dump et une archive par jour **sur le disque du
VPS**. Une sauvegarde qui dort sur la machine qu'elle sauvegarde n'est pas une
sauvegarde. Décider d'un `rsync`, d'un `rclone` ou d'un instantané du
fournisseur, et **faire une vraie restauration d'essai une fois** — la procédure
est écrite au-dessus du service, dans `compose.prod.yaml`.

### 2.5 Donner le feu vert pour la première poussée

Rien de ce qui suit ne peut partir tant que les fichiers de ce lot
(`.github/`, `compose.prod.yaml`, `.env.example`, `app/nginx.conf`, `docs/`)
ne sont pas **poussés sur `main`**. Le flux de déploiement lit le dépôt distant,
pas le poste.

---

## 3. Ce que la pile fait, en une page

```
   navigateur
       |  https://heritage.naltpom.fr
       v
   nginx de l'HOTE  (paquet Ubuntu, /etc/nginx/sites-enabled/heritage.naltpom.fr)
       |  termine le TLS, ecrase X-Forwarded-Proto, relaie en clair
       v
   127.0.0.1:5511
       |
   conteneur app  (nginx)  ---- /api ---->  conteneur api  (FrankenPHP/Symfony)
       |  la SPA, les actifs,                    |
       |  et /sante.php                          v
       |                                   conteneur db (PostgreSQL 17)
       |                                         ^
       |                                   conteneur sauvegarde
```

Trois choses à retenir, parce qu'elles expliquent la moitié des pannes
possibles :

1. **Origine unique.** L'API n'a pas de domaine à elle : le conteneur `app`
   relaie `/api` par le réseau interne. C'est ce qui permet de n'avoir aucun
   CORS et un cookie de session `HttpOnly; SameSite=Lax` sans exception. Le
   vhost de l'hôte n'a donc **qu'un seul amont**, contrairement à ses voisins
   sur ce serveur.
2. **Un seul port publié**, sur la boucle locale. La base, l'API et Mailpit ne
   publient rien (`ports: !reset []`), et Mailpit est même désactivé par un
   profil jamais activé — une boîte aux lettres sans mot de passe contenant les
   liens d'invitation n'a rien à faire sur un VPS.
3. **Le domaine se pose à quatre endroits** et le `garde-secrets` refuse de
   démarrer s'ils ne coïncident pas. Voir le § 5.

---

## 4. Cloner

```
vps$ mkdir -p ~/apps && cd ~/apps
vps$ git clone git@github-heritage:Naltpom/heritage.git heritage
vps$ cd ~/apps/heritage && git remote -v
```

> Attendu : `origin  git@github-heritage:Naltpom/heritage.git`.
> **Pas** `https://github.com/...` : l'alias `github-heritage` est ce qui force
> l'usage de la clé dédiée (`IdentitiesOnly yes`). Une URL HTTPS demanderait un
> jeton à chaque `fetch`, que le flux de déploiement n'a pas.

---

## 5. Écrire le `.env` de production

**À la main, sur le serveur, et nulle part ailleurs.** Ce fichier ne remonte
jamais dans le dépôt : il y est déjà couvert par `.gitignore`.

```
vps$ cd ~/apps/heritage
vps$ cp .env.example .env
vps$ nano .env
```

### 5.1 Les valeurs à poser

| Clé | Valeur | Comment l'obtenir |
|---|---|---|
| `APP_PORT` | `5511` | **Pas 8080** : il est déjà pris sur ce serveur par le pgadmin d'un autre projet. |
| `POSTGRES_PASSWORD` | un mot de passe long, propre à cette machine | `openssl rand -base64 24` |
| `APP_SECRET` | 32 octets au moins | `openssl rand -hex 32` |
| `MAILER_DSN` | le SMTP réel | fourni au § 2.3 |
| `MAILER_EXPEDITEUR` | `Heritage <invitations@…>` | § 2.3 |
| `DEFAULT_URI` | `https://heritage.naltpom.fr` | |
| `HERITAGE_URL_PUBLIQUE` | `https://heritage.naltpom.fr` | **la même**, voir 5.2 |
| `HERITAGE_DOMAINE_PUBLIC` | `heritage.naltpom.fr` | le domaine **seul** |
| `HERITAGE_HOTES_AUTORISES` | `^heritage\.naltpom\.fr$` | ancres et points échappés obligatoires |
| `IMAGE_TAG` | **ne pas y toucher** | le flux de déploiement l'écrit ailleurs, dans `.deploiement/env` |
| `BACKUP_DIR` | `/home/ubuntu/backups/heritage` | hors de l'arbre git, comme learnly |

`BIND_ADDR` n'a aucun effet en production : `compose.prod.yaml` force `127.0.0.1`
en dur, pour qu'un `BIND_ADDR=0.0.0.0` oublié dans un `.env` de poste de travail
ne puisse pas court-circuiter le TLS. **`ufw` est inactif sur cette machine** :
un port publié sur `0.0.0.0` y est joignable depuis Internet, sans aucun filtre
devant.

### 5.2 Pourquoi quatre variables pour un seul domaine

Elles ne font pas la même chose, et trois d'entre elles ont un **repli muet** —
c'est là qu'est le danger. Chacune a déjà coûté une panne ailleurs :

- **`HERITAGE_URL_PUBLIQUE`** fabrique les **liens d'invitation**. Elle n'était
  injectée nulle part en production : le conteneur retombait sur `api/.env`,
  copié dans l'image, où elle vaut `http://localhost:8080`. La pile démarrait, le
  garde-fou annonçait « configuration vérifiée », et chaque invitation partait
  vers `localhost`. Le destinataire clique, rien ne répond, **et le jeton est à
  usage unique** : l'invitation est brûlée, il faut la réémettre.
- **`DEFAULT_URI`** règle le routeur de Symfony. Voisine, mais distincte.
- **`HERITAGE_HOTES_AUTORISES`** est la liste blanche de `Host` de l'API. Vide,
  l'API retombe sur la liste blanche de **développement** et rend `400` sur le
  vrai domaine — c'est-à-dire sur tout.
- **`HERITAGE_DOMAINE_PUBLIC`** entre dans le `server_name` du conteneur `app`.
  Vide, le front ne nomme que `localhost` ; comme le premier bloc de son
  `nginx.conf` est un `return 444`, toute requête portant le vrai domaine y tombe
  et **la connexion se ferme sans un octet de réponse**. Panne totale, journal
  muet.

Le service `garde-secrets` refuse désormais de démarrer si l'une manque, **et si
les quatre ne désignent pas le même domaine**. Une faute de frappe sur l'une des
trois recopies est arrêtée au démarrage, pas découverte par un membre de la
famille sur un lien mort.

### 5.3 Les trois lignes de la page « Questions » — **facultatives**

La page « Questions » demande à chaque membre ce que l'arbre ne sait pas encore.
Un modèle de langage **formule** ces questions ; il n'en ouvre aucune et n'en
ferme aucune (ADR 0008).

**Ne rien écrire est un choix valide, et la page marche quand même.** Sans clé,
aucun appel ne part, les questions sont rédigées par des gabarits, et la page
sert exactement les mêmes questions — moins bien tournées. **Rien ne rougit,
délibérément** : c'est une dégradation gracieuse, pas une panne, et un rouge
permanent sur un état normal détruit l'alarme là où elle sert.

Pour les activer, **trois lignes à ajouter dans le même `.env` que ci-dessus** —
celui de `~/apps/heritage`, à la racine, **celui que Compose lit** :

```
HERITAGE_GEMINI_CLE_API=
HERITAGE_GEMINI_MODELE=
HERITAGE_GEMINI_POINT_DACCES=
HERITAGE_GEMINI_MODELE_ASSISTANT=
HERITAGE_GEMINI_MODELE_TRANSCRIPTION=
```

| Clé | Valeur | Comment l'obtenir |
|---|---|---|
| `HERITAGE_GEMINI_CLE_API` | la clé d'API | Google AI Studio ou Google Cloud — **palier payant**, voir ci-dessous |
| `HERITAGE_GEMINI_MODELE` | **laisser vide** | le code retombe sur son défaut ; à ne renseigner que si le fournisseur retire ce modèle |
| `HERITAGE_GEMINI_POINT_DACCES` | **laisser vide** | à renseigner **uniquement** pour Vertex AI, dont l'URL porte le projet et la région |
| `HERITAGE_GEMINI_MODELE_ASSISTANT` | **laisser vide** en principe | le modèle de « Proposer avec l'assistant » (lecture d'actes, parcours de l'arbre) ; vide = `gemini-pro-latest` (alias du dernier Pro servi ; `gemini-2.5-pro` rend `404 … no longer available to new users` aux clés récentes, constaté le 24 septembre 2026) |
| `HERITAGE_GEMINI_MODELE_TRANSCRIPTION` | **laisser vide** en principe | le modèle qui transcrit les messages vocaux de l'inscription sans fiche ; vide = `gemini-2.5-flash` |

Seule la première est à remplir. Les autres existent parce qu'un identifiant
de modèle et une URL écrits en dur transforment une décision du fournisseur en
panne de production réparable seulement par un déploiement.

#### Le piège qui coûterait une heure

**Ces trois lignes vont dans le `.env` de la RACINE, pas dans `api/.env.local`.**

`api/.dockerignore` exclut `.env.local` du contexte de construction — à raison, un
secret n'a rien à faire dans une image. **Le conteneur ne voit donc jamais ce
fichier**, quoi qu'on écrive dedans. C'est exactement le piège qui a déjà coûté
une passe sur `HERITAGE_ADMIN_EMAIL`.

Et une ligne dans le `.env` racine ne suffit pas non plus **par elle-même** : il
faut que `compose.yaml` la reprenne dans le bloc `environment:` du service `api`,
sans quoi *« Compose lirait la valeur sans jamais l'injecter dans le conteneur »*.
**Les trois lignes y sont déjà** — rien à faire de ce côté.

#### Le palier : payant ou Vertex, jamais le gratuit

Le corpus transmis contient **l'arbre entier, personnes vivantes comprises** —
arbitrage pris en connaissance de la réserve, et écrit dans l'ADR 0008, qui dit
aussi exactement quelle phrase de l'ADR 0003 il amende.

Les conditions du **palier gratuit** prévoient que les contenus soumis peuvent
être examinés par des personnes et servir à améliorer les produits ; celles des
offres **payantes** (clé payante, Vertex AI) s'engagent à ne pas les utiliser
pour entraîner des modèles. Pour un corpus qui nomme dix-huit personnes vivantes,
ce n'est pas une nuance de facturation.

**Ces conditions appartiennent à Google : les relire chez eux avant de poser la
clé.** Ce qui est gravé de notre côté, c'est la règle — *jamais le palier
gratuit* — quelle que soit leur rédaction du moment.

#### Vérifier que la clé a pris, sans jamais l'afficher

```
vps$ docker compose exec api printenv HERITAGE_GEMINI_CLE_API | wc -c
```

Un nombre **supérieur à 1** dit que la variable est arrivée dans le conteneur.
`1` (le seul retour à la ligne) dit qu'elle est vide. **La valeur n'est jamais
imprimée** — un secret affiché une fois dans un terminal est un secret dans un
historique de commandes.

Le contrôle qui compte vraiment vient **après** : ouvrir la page une fois, puis

```sql
SELECT redacteur, count(*) FROM qst_question GROUP BY 1;
```

`gemini` ⇒ les appels partent. Uniquement `regles` ⇒ le repli rédige, ce qui est
le bon comportement mais veut dire que la clé n'est pas prise **ou** que le
fournisseur ne répond pas. C'est la seule façon de distinguer les deux, parce que
l'écran, lui, est identique dans les deux cas.

Le journal d'audit garde la trace de chaque appel sortant — combien de personnes
transmises, combien de vivantes parmi elles, quel modèle. **Jamais le corpus,
jamais la réponse, jamais la clé.**

### 5.4 La carte : situer les lieux en ligne — **rien à écrire, sauf pour couper**

```
HERITAGE_GEOCODAGE_EN_LIGNE=
LIEUX_HEURE_UTC=3
```

| Clé | Valeur | Effet |
|---|---|---|
| `HERITAGE_GEOCODAGE_EN_LIGNE` | **laisser vide** | vide = activé ; `0` = aucun lieu n'est plus envoyé à OpenStreetMap Nominatim, pour toute l'instance |
| `LIEUX_HEURE_UTC` | `3` par défaut | l'heure UTC (0–23) de la résolution de nuit, lancée à HH:17 |

Les référentiels versionnés (`api/ressources/gazetteer/`) situent la France, le
Canada et les villes du monde de plus de 15 000 habitants **sous leur nom
anglais**. Pour le reste — « Liège, Belgique », un village polonais —
`heritage:resoudre-les-lieux --en-ligne` interroge **OpenStreetMap Nominatim**.

**Ce qui sort de la machine : la chaîne du lieu, et rien d'autre.** Ni nom de
personne, ni date, ni identifiant. L'en-tête `User-Agent` porte
`HERITAGE_URL_PUBLIQUE` et l'adresse de `MAILER_EXPEDITEUR`, comme l'exige la
politique d'usage de Nominatim (agent identifié, une requête par seconde au
plus) — c'est pourquoi ces deux variables sont aussi injectées dans le service
`lieux`.

Ce recours tourne **tout seul**, à deux moments : à la fin de chaque
déploiement (§ 11.2, étape 8) et chaque nuit (service `lieux` de
`compose.prod.yaml`). Il ne traite que les lieux **jamais situés**, jamais une
correction manuelle, jamais un nom porté par plusieurs communes (la commande ne
laisse pas un tiers choisir à sa place), et ne redemande pas avant **trente
jours** un lieu que Nominatim n'a pas su situer. Une panne de Nominatim est
rapportée dans le journal et **ne fait échouer ni le déploiement ni la nuit**.

Comme pour les lignes Gemini, ces variables vont dans le `.env` de la
**racine** ; `compose.yaml` (service `api`) et `compose.prod.yaml` (service
`lieux`) les reprennent déjà.

### 5.5 Contrôler le fichier avant d'aller plus loin

```
vps$ docker compose -f compose.yaml -f compose.prod.yaml config --quiet ; echo $?
```

> Attendu : `0`, sans rien afficher.

---

## 6. Premier démarrage

```
vps$ cd ~/apps/heritage
vps$ mkdir -p .deploiement
vps$ printf 'IMAGE_TAG=%s\n' "$(git rev-parse --short=7 HEAD)" > .deploiement/env
vps$ docker compose --env-file .env --env-file .deploiement/env \
       -f compose.yaml -f compose.prod.yaml build
vps$ docker compose --env-file .env --env-file .deploiement/env \
       -f compose.yaml -f compose.prod.yaml up -d
```

> Les deux `--env-file` ne sont pas une coquetterie. `.env` porte les secrets et
> s'écrit à la main ; `.deploiement/env` ne porte que l'étiquette d'image, et
> c'est le flux de déploiement qui l'écrira ensuite. **Dès qu'on passe
> `--env-file`, Compose cesse de charger `.env` tout seul** : les deux doivent
> être nommés, `.env` en premier, le dernier l'emportant sur les clés qu'il
> redéfinit. C'est ce qui permet de changer de version sans jamais toucher au
> fichier qui contient les mots de passe.

> La construction des deux images sur cette machine prend plusieurs minutes.
> **Le VPS n'a pas de swap** : un build qui dépasse la RAM est *tué* par le noyau,
> pas ralenti. Si `npm run build` disparaît sans message, c'est cela — relancer
> quand la machine est moins chargée.

Le premier conteneur à s'exécuter est `garde-secrets`. S'il refuse, il dit
exactement quelle variable manque et pourquoi :

```
vps$ docker compose --env-file .env --env-file .deploiement/env \
       -f compose.yaml -f compose.prod.yaml logs garde-secrets
```

> Attendu :
> `[heritage] configuration de production verifiee — domaine public : heritage.naltpom.fr`
>
> Le domaine est imprimé volontairement : « configuration vérifiée » sans dire de
> quoi ne prouve rien et se lit distraitement.

### 6.1 Les trois mesures de contrôle, avant de brancher le domaine

```
vps$ curl -sS -o /dev/null -w '%{http_code}\n' -H 'Host: heritage.naltpom.fr' http://127.0.0.1:5511/
```
> Attendu : `200`. Si vous obtenez une connexion fermée, `HERITAGE_DOMAINE_PUBLIC`
> n'est pas arrivée jusqu'au `server_name` : relire le § 5.1, puis
> `docker compose … up -d --force-recreate app`.

```
vps$ curl -sS -H 'Host: evil.attaquant.test' http://127.0.0.1:5511/ ; echo "code=$?"
```
> Attendu : `curl: (52) Empty reply from server`, code `52`. C'est le bloc par
> défaut en `444` qui ferme la connexion. **Un `200` ici est un défaut grave** :
> la liste blanche d'hôtes est ouverte.

```
vps$ curl -sS -H 'Host: heritage.naltpom.fr' http://127.0.0.1:5511/sante.php
vps$ ( cd api && sh docker/empreinte.sh )
```
> Les deux doivent donner **la même empreinte**. Sinon, l'image ne contient pas le
> code du dépôt : reconstruire. C'est le seul contrôle qui distingue
> « déployé » de « cru déployé ».

---

## 7. Poser le vhost de l'hôte

Le fichier est dans le dépôt, en **ASCII pur** — certbot relit ce fichier pour y
écrire la moitié TLS et le rejette s'il n'est pas décodable ; un seul commentaire
accentué suffit à faire échouer l'émission du certificat.

```
vps$ cd ~/apps/heritage
vps$ LC_ALL=C grep -n '[^ -~\t]' docs/deploiement/heritage.naltpom.fr.conf ; echo "code=$?"
```
> Attendu : aucune ligne, code `1`.

```
vps$ sudo cp docs/deploiement/heritage.naltpom.fr.conf \
        /etc/nginx/sites-available/heritage.naltpom.fr
vps$ sudo ln -sfn /etc/nginx/sites-available/heritage.naltpom.fr \
        /etc/nginx/sites-enabled/heritage.naltpom.fr
vps$ sudo nginx -t ; echo "code=$?"
```
> **Attendu : code `0`.** Ne rechargez rien tant que ce n'est pas `0`. Ce nginx
> sert **33 autres sites** : une erreur de syntaxe dans ce fichier les coupe
> tous, pas seulement celui-ci.

```
vps$ sudo systemctl reload nginx
vps$ curl -sS -o /dev/null -w '%{http_code}\n' http://heritage.naltpom.fr/
```
> Attendu : `200` (en clair, avant certbot).

> **Ceci est une copie, donc un artefact dérivé.** Modifier le fichier du dépôt ne
> change **rien** au serveur tant que le `cp` n'est pas refait. Et après le § 8,
> la copie installée ne ressemble plus à la source : certbot y a écrit la moitié
> TLS. **Ne jamais recopier par-dessus une version passée par certbot sans
> relancer certbot ensuite** — les lignes `# managed by Certbot` sont la marque à
> chercher avant d'écraser quoi que ce soit.

---

## 8. Le certificat

### 8.1 Émission

```
vps$ sudo certbot --nginx -d heritage.naltpom.fr --redirect \
        --non-interactive --agree-tos -m <adresse de contact>
vps$ sudo nginx -t ; echo "code=$?"
vps$ curl -sS -o /dev/null -w '%{http_code}\n' https://heritage.naltpom.fr/
```
> Attendu : `0` puis `200`. Et `http://heritage.naltpom.fr/` doit désormais
> répondre `301`.

### 8.2 Le piège de ce serveur — à faire, pas à survoler

Cette machine porte **67 certificats, dont 60 expirés**. Ils ne sont pas expirés
par négligence : ils sont rattachés à un **compte ACME absent du disque**. Un
certificat émis sous un compte fantôme a l'air parfait le jour de l'émission, et
**meurt à 90 jours sans un mot**. C'est le cas, aujourd'hui, de
`certivio.naltpom.fr`, qui est servi avec un certificat invalide.

Il n'existe qu'**un seul compte valide** : `0a3ca0b57478e27eba5ceff9b7a84df2`.
Deux vérifications, immédiatement après l'émission :

```
vps$ sudo grep -E '^account' /etc/letsencrypt/renewal/heritage.naltpom.fr.conf
```
> **Attendu, littéralement : `account = 0a3ca0b57478e27eba5ceff9b7a84df2`.**
> Une autre valeur = le certificat ne se renouvellera jamais. Le refaire
> immédiatement plutôt que dans 90 jours.

```
vps$ sudo certbot renew --cert-name heritage.naltpom.fr --dry-run ; echo "code=$?"
```
> Attendu : code `0`.
> **`--cert-name` n'est pas facultatif** : un `certbot renew` global sort
> **toujours** en erreur sur cette machine, à cause des 60 certificats morts. Un
> échec global ne dirait rien sur le nôtre.

Le renouvellement automatique est déjà en place (`certbot.timer`, deux fois par
jour, `enabled`) et couvrira heritage **à condition** que le compte soit le bon.

---

## 9. Le premier administrateur — en console, jamais par une route

**La base est vide au départ, et il n'existe aucune route qui crée un compte.**
« Les comptes naissent exclusivement du parcours d'invitation ou de la commande
console d'amorçage » : or l'invitation suppose un administrateur pour l'émettre.
Le premier vient donc forcément d'ailleurs, et la console est le seul
« ailleurs » qui ne soit pas une route.

```
vps$ cd ~/apps/heritage
vps$ docker compose --env-file .env --env-file .deploiement/env \
       -f compose.yaml -f compose.prod.yaml exec api \
       php bin/console heritage:creer-administrateur <adresse> "<nom affiché>"
```

La commande demande ensuite le mot de passe, **masqué** : il n'apparaît ni dans
l'historique du terminal, ni dans la liste des processus. **Ne le passez pas par
un tube** ici — la forme `printf … | docker compose exec -T …` est celle du
développement, où le mot de passe est jetable et noté dans `.env.local`.

- 12 caractères minimum, refusé en deçà.
- La commande refuse si un compte existe déjà pour cette adresse.
- **Cette création n'est pas au journal d'audit** (`core_audit_log`) : le
  souscripteur Doctrine ne journalise pas hors requête HTTP. La trace d'un
  amorçage est dans l'historique du serveur, pas dans l'application.

C'est aussi **la porte de secours** du `409 dernier_administrateur` : se
verrouiller dehors n'a pas d'autre issue que celle-ci.

---

## 10. Peupler l'arbre — restaurer une sauvegarde

> **Ce paragraphe décrivait l'import du GEDCOM** (`heritage:import-gedcom`, à blanc puis
> avec `--premier-import`). L'import a été supprimé
> ([ADR 0017](../adr/0017-suppression-de-l-import-gedcom.md)) : l'arbre réel vit dans la
> base de production et dans ses sauvegardes, et une base neuve se peuple **en restaurant
> un dump**. Il n'y a pas d'autre chemin.

### 10.1 Le dump ne monte pas par le dépôt

Il porte l'état civil de personnes vivantes. `docs/controle-donnees-reelles.md`
fixe la règle : **aucune donnée réelle n'entre dans le dépôt**, ni nom, ni date
exacte, ni lieu, ni cote d'archives. On copie le dump sur le serveur, on le
restaure, on l'efface. Il vient d'une sauvegarde du service `sauvegarde`
(`base-AAAAMMJJ-HHMMSS.dump`, et l'archive `televersements-…tar.gz` du même
horodatage pour les médias).

```
poste$ scp <chemin local>/base-AAAAMMJJ-HHMMSS.dump ubuntu@<vps>:/tmp/base.dump
vps$  cd ~/apps/heritage
vps$  docker compose … stop api app
vps$  docker compose … exec -T db \
        pg_restore --clean --if-exists -U "$POSTGRES_USER" -d "$POSTGRES_DB" < /tmp/base.dump
vps$  docker compose … up -d
vps$  rm -f /tmp/base.dump
```

`up -d` relance l'API, dont l'entrypoint rejoue les migrations plus récentes que le
dump. Les téléversements se restaurent comme l'écrit la procédure au-dessus du service
`sauvegarde` de `compose.prod.yaml`.

### 10.2 Les comptes viennent avec le dump

Le dump porte `core_user` : l'administrateur créé au § 9 est **remplacé** par les comptes
de la sauvegarde. Pour reposer celui que décrit l'environnement sans rien purger :

```
vps$ docker compose … exec api php bin/console heritage:amorcer-comptes
```

### 10.3 Sans dump : l'arbre d'essai, jamais en production

Une base de démonstration ou de recette peut partir de l'arbre d'essai anonymisé
(onze personnes inventées, `doctrine:fixtures:load --group=essai --append`). Il refuse un
arbre déjà peuplé, et il n'a **rien à faire** sur la base de l'arbre familial.

### 10.4 Situer les lieux — sinon la carte est vide

**C'est l'étape qu'on oublie, et son symptôme est un écran vide sans erreur.**

```
vps$ docker compose … exec api \
       php bin/console heritage:resoudre-les-lieux --dry-run
vps$ docker compose … exec api \
       php bin/console heritage:resoudre-les-lieux
```

La carte des lieux (§ 19 du contrat) a besoin de coordonnées. Les lieux de
l'arbre sont du texte GEDCOM brut — `Saint-Ronce-de-Verçay, Fougerie, Florie` —
et c'est cette commande qui les rapproche du référentiel versionné dans
`api/ressources/gazetteer/` pour remplir `tree_place`.

Pourquoi ce n'est pas dans une migration : **une migration ne peut pas lire un
fichier de référence**, et surtout les 104 chaînes de lieux n'existent que sur ce
serveur — le dépôt ne porte aucune donnée réelle (ADR 0006). Même raison que
`heritage:recalculer-les-cles-de-recherche`.

Le `--dry-run` annonce **deux nombres** : ce qui a été résolu et ce qui ne l'a pas
pu, avec les lieux restants par nombre d'occurrences. Un lieu non résolu n'est pas
une panne — c'est une bulle en moins, comptée à part dans l'interface. Un nom de
commune porté par plusieurs communes n'est **jamais** résolu au hasard : la
commande l'imprime, et un humain tranche en écrivant la ligne à la main
(`source = 'manuel'`, que la commande ne réécrit plus jamais, même avec
`--forcer`).

> **Rejouée toute seule, désormais — mais pas sur-le-champ.** Le service `lieux`
> relance la commande chaque nuit avec `--en-ligne`, et le déploiement la relance
> à la fin de ses contrôles (§ 11.2). Après une restauration, on peut ne pas attendre :
>
> ```
> vps$ docker compose … exec api \
>        php bin/console heritage:resoudre-les-lieux --en-ligne
> ```
>
> `--en-ligne` demande à OpenStreetMap Nominatim les lieux que le référentiel
> local ne connaît pas (§ 5.4) — la chaîne du lieu seule sort de la machine. Un
> lieu situé ainsi porte `source = 'nominatim'` et une confiance de 50 ; un lieu
> introuvable garde `source = 'nominatim'` **sans coordonnées**, et n'est pas
> redemandé avant trente jours. Une correction manuelle reste la réponse à tout ce
> qu'aucune des deux voies ne sait lire.

### 10.5 Rattacher l'administrateur à sa personne

Le rattachement se fait par **référence GEDCOM** (`@I500001@`), jamais par un
`tree_person.id`. Mesuré le 15 septembre 2026 : après un réimport GEDCOM (l'import
est supprimé depuis, ADR 0017), les identifiants numériques ont glissé de 1–274 à 275–548. Un rattachement numérique
aurait désigné quelqu'un d'autre sans que rien ne le signale.

---

## 11. Le bouton de déploiement

À partir d'ici, **plus rien ne se déploie à la main**.

**Actions → Déploiement → Run workflow**, ou :

```
poste$ gh workflow run deploy.yml -f mode=production --ref main
```

Le flux fait trois choses, dans cet ordre, et chacune peut tout arrêter.

### 11.1 La porte

Il **refuse de partir si la CI n'est pas verte sur le commit visé**. Il interroge
les exécutions de `ci.yml` pour ce SHA précis, et non l'état global des
« checks » du commit — parce que le déploiement en cours crée lui-même des checks
sur ce même commit, et qu'un contrôle naïf se bloquerait sur lui-même à chaque
fois.

Trois refus possibles, tous explicites :
- *Aucune exécution de la CI pour ce commit* — le commit n'a jamais été poussé
  sur une branche suivie.
- *La CI n'est pas terminée* — attendre, puis relancer.
- *La CI a conclu « failure »* — rien ne part.

### 11.2 Le déploiement, réversible

Sur le VPS, `.github/scripts/deployer-sur-le-vps.sh` :

1. relève le SHA **d'avant** (avant le `git reset --hard` : après, il est trop
   tard, `git rev-parse HEAD` rend déjà la nouvelle valeur) ;
2. `git fetch` + `git reset --hard <sha>` ;
3. construit les deux images sous l'étiquette `IMAGE_TAG=<sha court>` ;
4. `up -d`, puis attend jusqu'à 5 minutes une réponse sur `/sante.php` ;
5. **compare l'empreinte servie à celle que le dépôt produit** ;
6. vérifie qu'un `Host` inconnu est toujours fermé, et que `/` rend `200` ;
7. n'enregistre le déploiement comme réussi qu'après ces quatre contrôles ;
8. **puis seulement**, situe les lieux nouveaux :
   `heritage:resoudre-les-lieux --en-ligne` dans le conteneur `api`, borné à cinq
   minutes. Cette étape vient **après** tout ce qui décide du retour arrière et
   ne peut pas en déclencher un : si Nominatim est tombé ou si la commande
   échoue, le script le dit et le déploiement reste réussi — le service `lieux`
   rejouera la nuit suivante.

**Si l'un échoue, il revient tout seul** : `git reset --hard` sur le commit
précédent, `IMAGE_TAG` remis à l'étiquette précédente, `up -d` **sans
reconstruction** — l'image d'avant est encore là, entière, et c'est tout
l'intérêt d'une étiquette par commit. Le flux sort en erreur quand même : un
retour arrière réussi reste un déploiement raté.

L'état vit dans `~/apps/heritage/.deploiement/`, **non suivi par Git** — donc
intact après le `git reset --hard`, ce qui est précisément le moment où il sert :

```
vps$ cat ~/apps/heritage/.deploiement/courant      # le SHA en service
vps$ cat ~/apps/heritage/.deploiement/precedent    # la cible du retour arrière
vps$ tail -5 ~/apps/heritage/.deploiement/journal  # l'historique
```

### 11.3 Le contrôle public

Depuis le runner GitHub, par le **vrai domaine** et le **vrai certificat** :
`https://heritage.naltpom.fr/sante.php` doit répondre, et son `empreinte` doit
être celle du commit déployé — recalculée sur place par le même
`api/docker/empreinte.sh`.

Ce n'est pas une redite du contrôle du § 11.2. Celui-là sonde `127.0.0.1:5511`
depuis le VPS : il prouve que **la pile** va bien. Celui-ci traverse le DNS, le
certificat et le nginx de l'hôte : il prouve que **le public voit cette pile**.
Les deux pannes qu'il attrape — un vhost qui pointe ailleurs, un certificat mort
— ne se voient pas du tout de l'intérieur.

### 11.4 Revenir en arrière à la demande

```
poste$ gh workflow run deploy.yml -f mode=retour-arriere --ref main
```

Remet en service le déploiement précédent, **sans reconstruire**. Ce mode ne
passe pas par la porte CI, et c'est délibéré : il vise un commit qui a déjà été
déployé, donc qui l'a déjà franchie. Exiger une CI verte pour reculer
l'interdirait au moment où l'on en a le plus besoin.

### 11.5 Ce que le retour arrière ne rattrape pas

**La base de données ne revient pas en arrière.** Les migrations sont jouées par
l'entrypoint de l'image api (`doctrine:migrations:migrate`), et une migration qui
supprime une colonne ne se défait pas en redémarrant l'image précédente :
l'ancien code y chercherait une colonne disparue.

Conséquence pratique, et c'est une règle de conception, pas une précaution :
**une migration destructrice se déploie en deux temps.** Ajouter au déploiement
*n*, supprimer au déploiement *n+1*, quand on ne peut plus vouloir revenir avant.

Ne sont pas rattrapés non plus : les courriels déjà partis, les jetons
d'invitation déjà consommés, les fichiers déjà téléversés.

---

## 12. Recette — la liste à cocher

| # | Commande | Attendu |
|---|---|---|
| 1 | `ssh -T github-heritage` *(vps)* | `Hi Naltpom/heritage!` |
| 2 | `docker compose … logs garde-secrets` | `configuration de production verifiee — domaine public : heritage.naltpom.fr` |
| 3 | `curl -o /dev/null -w '%{http_code}' -H 'Host: heritage.naltpom.fr' http://127.0.0.1:5511/` | `200` |
| 4 | `curl -H 'Host: evil.test' http://127.0.0.1:5511/` | `curl: (52)` |
| 5 | `sudo nginx -t` | code `0` |
| 6 | `curl -o /dev/null -w '%{http_code}' http://heritage.naltpom.fr/` | `301` |
| 7 | `curl -o /dev/null -w '%{http_code}' https://heritage.naltpom.fr/` | `200` |
| 8 | `sudo grep '^account' /etc/letsencrypt/renewal/heritage.naltpom.fr.conf` | `0a3ca0b57478e27eba5ceff9b7a84df2` |
| 9 | `sudo certbot renew --cert-name heritage.naltpom.fr --dry-run` | code `0` |
| 10 | `curl -s https://heritage.naltpom.fr/sante.php` **vs** `(cd api && sh docker/empreinte.sh)` | **même empreinte** |
| 11 | connexion avec le compte du § 9 | l'arbre s'affiche |
| 12 | inviter une adresse à soi et **ouvrir le lien reçu** | il pointe sur `https://heritage.naltpom.fr`, **pas** `localhost` |
| 13 | `ls ~/backups/heritage` (le lendemain) | un `.dump` et un `.tar.gz` |

La ligne 12 est celle qu'on saute, et c'est la seule qui teste la chaîne entière
du courriel. Utilisez une adresse à vous : un lien d'invitation est à usage
unique, l'essai le consomme.

---

## 13. Exploitation courante

### Journaux

```
vps$ cd ~/apps/heritage
vps$ docker compose --env-file .env --env-file .deploiement/env \
       -f compose.yaml -f compose.prod.yaml logs -f --tail=100 api
```

Cette double option `--env-file` est **obligatoire** dans toutes les commandes
manuelles : dès qu'on passe `--env-file`, Compose cesse de charger `.env` tout
seul, et les deux doivent être nommés, `.env` en premier. Sans le second, Compose
prendrait le `IMAGE_TAG` de `.env` (`dev`) et parlerait d'une autre pile.

### Résolution des lieux, chaque nuit

Le service `lieux` lance `heritage:resoudre-les-lieux --en-ligne` une fois par
jour à `LIEUX_HEURE_UTC`h17 UTC (3 h 17 par défaut). Pour lire ce qu'il a fait,
et ce qui reste hors de la carte :

```
vps$ docker compose --env-file .env --env-file .deploiement/env \
       -f compose.yaml -f compose.prod.yaml logs --tail=200 lieux
```

Les lieux non résolus y sont listés par nombre d'occurrences, chacun avec sa
raison — homonymes (à trancher à la main), introuvable en ligne (daté), ou
Nominatim injoignable (redemandé la nuit suivante).

### Sauvegardes

Automatiques, une fois par jour, dans `${BACKUP_DIR}`, rétention 14 jours. La
procédure de **restauration** est écrite au-dessus du service `sauvegarde` dans
`compose.prod.yaml`. Essayez-la une fois **avant** d'en avoir besoin : une
restauration qu'on n'a jamais faite n'est pas une sauvegarde, c'est une intention.

### Place disque

Le flux garde les **5 dernières étiquettes** d'images par dépôt, et jamais celle
qui tourne ni celle du retour arrière. Pour regarder :

```
vps$ docker image ls 'heritage/*'
vps$ df -h /
```

### Le fichier `app/nginx.conf` est un gabarit

Il n'est plus figé dans l'image : `compose.prod.yaml` le monte dans
`/etc/nginx/templates/` et l'entrypoint de l'image nginx y substitue
`HERITAGE_DOMAINE_PUBLIC` au démarrage. **Conséquence :** le modifier dans le
dépôt change la configuration sans reconstruction — mais il faut **recréer le
conteneur** pour que la substitution soit rejouée (`up -d` le fait quand la
définition change ; sinon `restart app`).

Le jour où `app/Dockerfile` recevra la ligne
`COPY nginx.conf /etc/nginx/templates/default.conf.template`, le montage
deviendra inutile et pourra disparaître. **Pas avant** : le retirer seul
remettrait tout le front en `444`.

---

## 14. Quand ça ne marche pas

| Symptôme | Cause la plus probable | Geste |
|---|---|---|
| `curl` rend « Empty reply from server » sur le vrai domaine | `HERITAGE_DOMAINE_PUBLIC` vide ou mal substituée : le `server_name` ne nomme pas le domaine, tout tombe dans le `444` | `docker compose … exec app grep 'server_name localhost' /etc/nginx/conf.d/default.conf` — le domaine doit y être. Sinon corriger le `.env` puis `up -d --force-recreate app`. |
| Toutes les réponses de l'API sont `400` | `HERITAGE_HOTES_AUTORISES` absente ou non ancrée | Vérifier `^heritage\.naltpom\.fr$` dans `.env`, redémarrer `api`. |
| Le lien d'invitation pointe sur `localhost` | `HERITAGE_URL_PUBLIQUE` non injectée | Elle doit être dans `.env` **et** le conteneur `api` doit la voir : `docker compose … exec api printenv HERITAGE_URL_PUBLIQUE`. |
| Les invitations ne sont jamais reçues | `MAILER_EXPEDITEUR` en `@localhost`, ou SPF/DKIM absents | § 2.3. Le repli praticable est la copie manuelle du lien depuis l'administration. |
| `up` échoue sur « address already in use » | `APP_PORT` laissé à `8080`, qui est pris | `APP_PORT=5511`. |
| Le build s'arrête sans message | le noyau a tué le processus : **pas de swap** sur cette machine | Relancer machine moins chargée, ou construire une image à la fois. |
| Le flux échoue en « Le domaine sert l'empreinte X et non Y » | le domaine ne mène pas à cette pile | Vérifier le lien dans `sites-enabled`, le port du `proxy_pass` (5511), et qu'aucun autre vhost ne nomme ce domaine. |
| Le site sert un certificat invalide au bout de 90 jours | compte ACME fantôme | § 8.2. Réémettre, puis **vérifier `account =`**. |

---

## 15. Écarts assumés par rapport aux voisins du serveur

Ils sont listés ici pour qu'on ne les prenne pas pour des oublis.

1. **Un seul amont dans le vhost**, là où certivio, sitter, pokemon et learnly en
   déclarent deux. Heritage ne publie qu'un port : son conteneur `app` relaie
   `/api` lui-même, par le réseau interne. C'est l'origine unique, dont dépend
   toute la politique de sécurité du projet.
2. **Un seul en-tête de sécurité dans le vhost** (HSTS), là où les voisins en
   posent cinq. Les autres sont déjà posés par la pile — le nginx du conteneur
   pour la coque, l'API pour ses réponses JSON. Les reposer ici les mettrait en
   **double**, et un `Referrer-Policy` ou un `Permissions-Policy` en double
   **écrase** le plus strict par le plus permissif. Le défaut a déjà été payé une
   fois dans ce projet, un étage plus bas.
3. **`compose.yaml` / `compose.prod.yaml`**, quand le serveur utilise
   `docker-compose.yml` / `.prod.yml` / `.vps.yml` partout sauf synetis. Ce n'est
   pas un défaut : `compose.yaml` est le nom canonique moderne. Mais **toute
   commande recopiée depuis learnly doit être réécrite**.
4. **Pas de troisième fichier `.vps.yml`.** Son seul rôle chez les voisins est de
   forcer les publications de ports sur la boucle locale ;
   `compose.prod.yaml` le fait déjà (`ports: !reset []` partout, `127.0.0.1` en
   dur pour `app`).
5. **Le vhost est versionné dans `docs/deploiement/`** et non dans `deploy/nginx/`
   comme chez learnly. C'est la seule concession au découpage du chantier : le
   lot qui a écrit ce fichier n'avait pas `deploy/` dans son périmètre. Le
   déplacer un jour ne demande qu'un `git mv` et une ligne à changer au § 7.
6. **`/sante.php` est exposé publiquement.** Elle ne rend qu'un condensé de 12
   caractères et une date UTC : ni version, ni chemin, ni environnement, et elle
   n'ouvre pas la base. Une sonde joignable seulement de l'intérieur ne prouverait
   rien sur la chaîne publique, qui est justement ce qu'on veut prouver.
