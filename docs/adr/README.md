# Décisions d'architecture

Les ADR sont écrits **avant** la génération du code. Ce qui y est tranché fait autorité : un
agent qui trouve une meilleure idée ne l'applique pas, il propose un nouvel ADR.

| # | Décision | Ce qu'elle tranche |
|---|---|---|
| [0001](0001-choix-de-la-pile.md) | Choix de la pile | Symfony 8 / PHP 8.4 / Doctrine / PostgreSQL 17 / FrankenPHP, front Vue 3 + TS + Vite + Tailwind 4, nginx en même origine — et pourquoi on écrit une application plutôt que d'adapter webtrees ou Gramps Web, qui tournent déjà. |
| [0002](0002-monolithe-modulaire-et-frontieres.md) | Monolithe modulaire et frontières | Sept modules, tables préfixées, aucune relation Doctrine inter-modules — et les quatre mesures qui compensent l'intégrité référentielle perdue, notamment pour `Membership` et `Proposal`. |
| [0003](0003-cercle-de-visibilite.md) | Le cercle de visibilité | La règle de confidentialité écrite formellement (ancêtres sur N générations, N = 3 réglable, leurs descendants, les conjoints en une passe), le filtrage dans la sérialisation, et le risque résiduel accepté : la topologie reste visible. |
| [0004](0004-ecriture-par-proposition.md) | Toute écriture passe par une proposition | Diff champ par champ, calque « en attente », refus motivé obligatoire ; conflits, caducité, administrateur auteur — et pourquoi une file de validation plutôt qu'un wiki. |
| [0005](0005-moteur-de-placement-recalcule.md) | Le moteur de placement est **porté**, et recalculé | Le code d'origine n'était pas perdu : `docs/moteur-retrouve/graphe.py`, 624 lignes, est la source de vérité de l'algorithme. Portage en PHP, `graphe.json` comme référence de non-régression **à l'identique** — tout écart est un bug de portage. Et un cache à deux étages pour servir un graphe qui dépend du lecteur sans le recalculer par lecteur : étage 1 partagé, mis en cache sous une **empreinte recalculée depuis la base** et restreinte aux trois tables structurantes ; étage 2 par lecteur, jamais mis en cache. C'est aussi ici que se décide l'unique mécanisme d'invalidation du produit : pas de compteur, pas de table. |
| [0006](0006-donnees-reelles-non-versionnees.md) | Les données réelles ne sont pas versionnées | Fixtures anonymisées à graine fixe, import réel par commande locale — et le cahier des charges chiffré des fixtures, qui doivent reproduire les défauts du vrai arbre. |
| [0007](0007-rendu-svg-performance.md) | Rendu SVG : les trois règles de performance | Zoom par `viewBox`, `fill-opacity`/`stroke-opacity` jamais `opacity`, canevas piloté impérativement hors de Vue — avec le mécanisme derrière chaque règle. |

Le modèle de données (tables, colonnes, index) est décrit à part, dans
[`docs/modele-donnees.md`](../modele-donnees.md).

Les deux corpus ont été rédigés en parallèle, donc sans se lire. Les points sur lesquels ils
divergeaient, ce qui a été retenu et pourquoi : [`docs/arbitrages.md`](../arbitrages.md) —
35 arbitrages. **Cinq ADR y ont été amendés** — 0002, 0003, 0004, 0005 et 0006 — et le repère se
trouve dans leur section « Conséquences », sous une ligne « Révision du … » ; l'ADR 0005
porte en plus une **prémisse corrigée** en tête de son « Contexte ». Un ADR qu'on corrige
parce qu'on a mesuré est un ADR qui fonctionne ; un ADR qu'on contourne en silence est un
ADR mort.
