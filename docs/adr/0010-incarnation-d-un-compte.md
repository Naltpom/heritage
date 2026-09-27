# ADR 0010 — Se connecter en tant qu'un autre compte : l'incarnation

Date : 2026-09-28 · Statut : acceptée

## Contexte

La bascule « voir comme un membre » (§ 10.4 du contrat) prête à un administrateur le cercle
de visibilité d'un membre, **en lecture seule** : toute écriture rend `409`. Elle répond à
« que voit-il ? », pas à « que se passe-t-il quand il fait ceci ? ».

Le commanditaire veut davantage : se connecter **en tant que** le membre, voir et faire
exactement ce qu'il ferait, puis revenir à son propre compte — avec, sur tout ce qui est
fait entre-temps, la trace de l'administrateur.

Deux règles du dépôt s'y opposaient : « aucune écriture sous identité empruntée »
(`BasculeVoirComme`, piège n° 2) et « on ne partage pas un compte » (README). La première
protège le journal, la seconde les secrets du compte. Cette décision les remplace par des
garanties qui tiennent **avec** l'écriture.

## Décision

Une **incarnation**, par le mécanisme de Symfony : le jeton de la session devient un
`SwitchUserToken`, dont l'utilisateur est le compte incarné et qui garde le jeton d'origine
(`Core\Service\Incarnation`).

```
POST   /api/administration/incarnation   {userId}   ROLE_ADMIN
DELETE /api/session/incarnation                     connecté
```

- **Tout** ce qui lit « qui est connecté » voit le membre : `access_control`, le voter de
  régime, le cercle, les tirages, les demandes. Aucun module n'a changé pour cela.
- L'administrateur **perd ses droits** le temps de l'incarnation : il est le membre. D'où
  le retour hors de `/api/administration`.
- La charge de session porte `incarnation: {par: {id, displayName}}` ; le front en tire un
  bandeau permanent et le bouton « Revenir à mon compte ».

### La trace

- `incarnation_debut` (acteur : l'administrateur, sujet : le compte incarné) et
  `incarnation_fin` au journal d'audit ;
- **chaque ligne écrite pendant l'incarnation** — par `AuditService` comme par le
  souscripteur Doctrine, donc toute création ou modification — porte l'administrateur dans
  `core_audit_log.impersonator_user_id` / `impersonator_email`, à côté du membre en acteur.

Les tables métier (`prp_revision`, `trg_participant.ajoute_par_user_id`, …) nomment le
membre : aux yeux du produit, c'est lui qui a agi. Le journal dit qui tenait le clavier.

### Ce qu'elle refuse

| Refus | Pourquoi |
|---|---|
| Incarner un administrateur | Aucun gain à voir ce que voit un autre administrateur, et l'on brouillerait qui a administré quoi. |
| Incarner un compte suspendu | Il ne peut pas se connecter ; l'incarner montrerait un produit qu'il ne voit pas. |
| S'incarner soi-même, incarner depuis une incarnation | Une seule couche, un seul retour. |
| Changer le mot de passe ou le courriel du compte incarné | `409 incarnation_active`. L'administrateur agit au nom du membre ; il ne s'approprie pas son compte. |

## Ce que cette décision n'est pas

**Ce n'est pas un remplacement de la bascule.** « Voir comme un membre » reste l'outil de
vérification de la confidentialité : sans risque, sans écriture. L'incarnation désactive la
bascule en entrant ; les deux ne se superposent pas.

**Ce n'est pas silencieux pour le membre** — mais ce n'est pas non plus annoncé : ses
notifications, ses demandes et ses tirages sont modifiés par ce que fait l'administrateur,
sans qu'il en soit prévenu. Le journal est le seul endroit où la distinction se lit.

## Conséquences

- `core_audit_log` gagne deux colonnes (`Version20260928090000`).
- `Core\Enum\AuditAction` gagne `incarnation_debut` et `incarnation_fin`.
- Le secret des tirages (ADR 0009) n'est pas garanti contre un administrateur qui incarne
  un participant : il lit son attribution comme lui. C'était déjà vrai de la bascule depuis
  la révision du 2026-09-27.
- Les sessions ne sont pas invalidées quand un compte est suspendu pendant qu'on l'incarne :
  même limite que pour une session ordinaire, déjà inscrite en feuille de route.
