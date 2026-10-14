# ADR 0001 — Choix de la pile : Symfony 8 + Vue 3 + PostgreSQL, et pourquoi on écrit une application

Date : 2026-09-15 · Statut : acceptée

## Contexte

Une application web familiale et privée autour d'un arbre de **274 personnes, 103 familles,
12 générations**, dont **18 personnes vivantes**. Quelques dizaines de comptes au maximum,
tous invités nominativement. Auto-hébergée : local aujourd'hui, prête pour un VPS.

Deux logiciels de généalogie **tournent déjà sur la machine** et ont importé le même
GEDCOM : une instance **webtrees** (port 8080) et une instance **Gramps Web** (port 5000).
La première question d'un relecteur est donc : pourquoi ne pas en adapter une ?

Ce que le produit doit faire, et qui n'est pas de la généalogie généraliste :

- entrée **uniquement sur invitation**, avec rattachement obligatoire du compte à une
  personne de l'arbre ;
- un **cercle de visibilité relatif au lecteur** (ADR 0003) — pas un drapeau « privé » par
  fiche ;
- **toute écriture passe par une proposition validée**, avec diff champ par champ et calque
  « en attente » visible du seul auteur (ADR 0004) ;
- un **rendu du réseau entier** — cousins, collatéraux, alliances — et une vue chronologique.

Le commanditaire a désigné la pile et le dépôt de référence
(`C:\www\entretient\synetis\synapse`). Cet ADR écrit ce choix, pas le rediscute.

## Décision

| Couche | Choix | Version |
|---|---|---|
| API | Symfony, PHP | 8.1.* / ≥ 8.4 |
| ORM | Doctrine ORM + Migrations + Fixtures | ^3.6 / ^4.0 / ^4.3 |
| Base | PostgreSQL | 17-alpine |
| Serveur PHP | FrankenPHP | — |
| Front | Vue 3 + TypeScript + Vite + Tailwind | ^3.5 / ~5.8 / ^7 / ^4.1 |
| Reverse proxy | nginx, même origine front/API | — |
| Courriel (dev) | Mailpit | — |

- **On écrit une application.** On n'adapte ni webtrees ni Gramps Web.
- Les deux instances **restent en service comme outils de travail du généalogiste**
  (saisie lourde, contrôle qualité, rapports de confiance). Elles ne sont jamais exposées
  à la famille.
- Le GEDCOM `arbre-romarin-v5.ged` reste la **source de vérité amont** : l'application
  l'importe et sait le ré-exporter.

  > **Remplacé par l'[ADR 0018](0018-suppression-de-l-import-gedcom.md) (2026-10-06)** : l'application a été peuplée une fois par
  l'import ; il est supprimé, et l'export n'a jamais été écrit. La source de vérité est la
  base de production et ses sauvegardes.
- **Pas de Redis, pas de file d'attente, pas de moteur de recherche.** Le cache Symfony sur
  système de fichiers suffit à 274 personnes.

## Justification

1. **Le besoin est transactionnel, pas documentaire.** File de validation, verrou optimiste,
   diff champ par champ, journal d'audit, voters anti-IDOR : Doctrine, le composant Security
   et le Serializer couvrent exactement ce périmètre. C'est un système de gestion qui affiche
   un arbre, pas un site de contenu.
2. **La sérialisation est le point de contrôle de la confidentialité.** Le cercle de
   visibilité s'applique dans des normalizers Symfony : un seul endroit à écrire, un seul à
   auditer (ADR 0003). C'est l'argument qui verrouille le choix du framework, plus que la
   vélocité.
3. **Vélocité.** C'est la pile du dépôt de référence, écrite par le même auteur, avec les
   mêmes conventions. Chaque heure produit du logiciel.
4. **PostgreSQL partout, y compris en développement** — là où le dépôt de référence utilisait
   SQLite en local. Le cercle de visibilité et le moteur de placement font des parcours de
   parenté (ancêtres sur N générations, descendants à toute profondeur) qu'on veut pouvoir
   écrire en SQL récursif. Faire diverger le dialecte entre développement et production sur
   *ces* requêtes-là, c'est garantir un bug qu'aucun test ne verra.
5. **TypeScript strict** : une fiche personne porte ~25 champs, plusieurs nullables et **deux
   fourchettes de dates** (`an_nais` / `an_nais_max`). C'est précisément là qu'un `undefined`
   non typé devient une date fausse à l'écran — et le dossier de mission interdit d'aplatir
   une fourchette en date ferme.
6. **Même origine, session, cookie `HttpOnly`** : pas de CORS ouvert, pas de jeton à stocker
   côté client. FrankenPHP sert l'API, nginx sert le front et fait l'origine commune.

## Alternatives considérées

**Adapter webtrees.** L'option la plus rationnelle sur le papier : l'instance tourne,
l'authentification, les rôles et l'import GEDCOM existent. Écartée pour trois raisons
cumulées.

1. Le **cercle de visibilité n'est pas son modèle**. webtrees raisonne en « vivants /
   décédés » et en rôles par arbre ; il ne sait pas calculer une visibilité *relative au
   rattachement du compte*. L'implémenter voudrait dire réécrire son moteur de
   confidentialité dans un module tiers, et le maintenir contre chaque montée de version —
   sur la fonction la plus sensible du produit.
2. Le **circuit de proposition n'existe pas** sous la forme demandée : webtrees a des
   modifications en attente, mais pas de diff champ par champ, pas de message obligatoire au
   refus, pas de nœud en attente affiché dans le graphe au seul auteur.
3. **Aucun diagramme webtrees ne sort le réseau entier** — constat déjà établi et écrit dans
   le `README.md` du chantier. Le rendu, qui est la capacité la plus visible du produit,
   serait à écrire de toute façon.

Adapter, c'était donc : réécrire la confidentialité, écrire le workflow, écrire le rendu —
dans une base de code qu'on ne choisit pas. Détail qui confirme : **l'instance webtrees
tourne encore sur un import à 165 individus quand le GEDCOM courant en compte 274.** Elle a
déjà décroché de la donnée ; elle est un outil, pas un socle.

**Adapter Gramps Web.** Meilleur modèle de données (598 événements, 92 lieux, confiance par
citation) et une vraie vue réseau. Écartée : front en Web Components sur une API Python, dont
le modèle de confidentialité est encore plus binaire (`private` par objet), et dont la version
`latest` est **actuellement cassée** (migration Alembic sur `oidc_accounts`, image épinglée en
26.6.2 — voir `README.md`). On dépendrait du rythme et des régressions d'un projet tiers pour
la fonction la plus sensible du produit. Gramps Web reste l'outil d'analyse.

**Repartir du viewer statique `arbre.html`** en lui ajoutant un backend minimal. Écartée : ce
fichier est un artefact figé, produit par des scripts Python hors ligne (ADR 0005). Le garder
revient à garder des données non modifiables — c'est exactement la contrainte qu'on veut
lever.

**Laravel, ou une pile Node full-stack.** Équivalents fonctionnels. Écartés pour la même
raison que dans le dépôt de référence : la maîtrise de la pile prime, et il n'y a aucun motif
d'en changer sur un projet plus petit et plus sensible.

**SQLite en développement** (choix du dépôt de référence). Écarté, voir justification 4.

## Conséquences

- **Docker devient obligatoire même en développement local.** Acceptable : Docker est déjà sur
  la machine et `compose.yaml` est un livrable de toute façon.
- **Deux instances généalogiques continuent de tourner à côté.** Elles ne sont pas la source de
  vérité, le GEDCOM l'est. Toute saisie faite dans webtrees et non ré-exportée est perdue pour
  l'application. À la mise en service : soit la saisie s'arrête dans webtrees, soit elle reste
  un chantier de recherche séparé, ré-importé à la main. On ne synchronise pas.

  > **Remplacé par l'[ADR 0018](0018-suppression-de-l-import-gedcom.md) (2026-10-06)** : il n'y a plus de ré-import possible ; une saisie
  faite dans webtrees ou Gramps Web n'entre dans l'application que par une demande.
- L'API est du JSON sur HTTP sans couplage au front : remplacer la SPA resterait un chantier
  front isolé.
- Le recrutement futur n'est pas un critère ici — le projet a un seul développeur et des
  agents.
