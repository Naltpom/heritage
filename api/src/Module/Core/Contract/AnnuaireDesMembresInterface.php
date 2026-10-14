<?php

declare(strict_types=1);

namespace App\Module\Core\Contract;

/**
 * Le **minimum** que `Core` demande à `Membership`, pour ses propres écrans d'administration
 * — un libellé de membre, la charge `membre` d'un compte — et, depuis
 * {@see rattachementsDeLaPersonne()}, pour un **refus** que `Proposal` doit pouvoir
 * prononcer sans connaître `mbr_member`.
 *
 * `Core` tient les comptes (`core_user`), pas le rattachement à l'arbre (`mbr_member`).
 * Deux de ses routes en ont pourtant besoin :
 *
 * - `POST /api/administration/voir-comme` doit refuser un `memberId` inconnu par un
 *   `404`, et nommer le membre emprunté dans le bandeau permanent (§ 10.4) ;
 * - `GET /api/administration/utilisateurs` sérialise un objet `membre` à côté du compte
 *   (§ 10.1).
 *
 * Plutôt qu'une jointure inter-modules — que l'ADR 0002 interdit — on publie ces deux
 * questions. `Membership` y répond ; `Core` ne connaît ni `mbr_member`, ni la forme de
 * la table, ni la façon dont le libellé est obtenu.
 *
 * **Le défaut est fermé**, comme pour le cercle de visibilité : {@see AnnuaireVide} rend
 * `null` à tout. Conséquence assumée — tant que `Membership` n'est pas branché, la
 * bascule « voir comme un membre » répond `404` et la charge d'administration ne porte
 * pas de `membre`. Une bascule qui refuse de s'activer est un désagrément ; une bascule
 * qui s'activerait sur un membre inexistant afficherait un arbre filtré par un cercle
 * inconnu, et l'administrateur croirait vérifier quelque chose.
 *
 * **Ces méthodes ne filtrent rien et ne doivent rien filtrer.** Elles servent des écrans
 * `ROLE_ADMIN` uniquement. Le libellé d'un membre est celui d'une personne possiblement
 * vivante : c'est la huitième porte de sortie de l'ADR 0003, et elle est ici fermée par
 * `access_control`, pas par le cercle.
 */
interface AnnuaireDesMembresInterface
{
    /**
     * Le libellé affichable du membre, ou `null` s'il n'existe pas.
     *
     * `null` vaut « inconnu » **et** « pas de réponse à donner » : l'appelant rend `404`
     * dans les deux cas (§ 15.3 du contrat d'API).
     */
    public function libelleDuMembre(int $memberId): ?string;

    /**
     * La charge `membre` du § 10.1, pour un compte donné, ou `null` s'il n'a pas encore
     * de rattachement.
     *
     * La forme appartient à `Membership` : `Core` la recopie telle quelle dans sa
     * réponse sans la relire. C'est une entorse assumée à la règle « on ne sérialise pas
     * ce qu'on ne possède pas », et elle est bornée à une route d'administration ; la
     * seule alternative aurait été de recopier dans `Core` la forme d'une table de
     * `Membership`, ce qui aurait figé son schéma ici.
     *
     * @return array<string, mixed>|null
     */
    public function membreDuCompte(int $userId): ?array;

    /**
     * Le `core_user.id` du membre, ou `null` s'il n'existe pas.
     *
     * **Le sens inverse de `membreDuCompte()`, et il est indispensable à la bascule.**
     * `POST /api/administration/voir-comme` reçoit un `mbr_member.id`, mais tout ce qui
     * raisonne en aval raisonne en **compte** : `Core\Contract\Lecteur` exprime l'identité
     * empruntée par un identifiant de compte, et c'est sur lui que reposent
     * `identiteEffective()` et `estAuteurDe()`.
     *
     * Sans cette question, l'appelant n'a d'autre choix que de ranger un identifiant de
     * membre là où un identifiant de compte est attendu. Les deux sont des entiers, rien
     * ne tombe, et le calque « en attente » se met à désigner le mauvais auteur — une
     * panne qui ment dans le sens rassurant, comme les précédentes.
     */
    public function userIdDuMembre(int $memberId): ?int;

    /**
     * La `tree_person.id` à laquelle un compte est rattaché, ou `null`.
     *
     * Elle existe pour le champ `parente` d'une fiche (§ 3.1 du modèle) : « cousine
     * germaine », « épouse de ton arrière-grand-oncle ». Le calcul est relatif **au
     * lecteur**, et `Tree` sait le faire — il lui manquait seulement le point de départ,
     * qui vit dans `mbr_member`.
     *
     * `null` vaut « pas de rattachement validé », et l'appelant rend alors `parente` à
     * `null` plutôt qu'une parenté relative à quelqu'un d'autre, *qui serait fausse sans
     * en avoir l'air*.
     */
    public function personneDuCompte(int $userId): ?int;

    /**
     * Les `core_user.id` dont la fiche rattachée **correspond à une saisie** : son libellé
     * la contient (sans casse), ou son numéro est celui tapé (`12`, `#12`, `n° 12`).
     *
     * Elle sert la recherche de `GET /api/administration/utilisateurs`, paginée côté
     * serveur depuis le 2026-10-14 : chercher « Dupont » doit trouver le compte rattaché à
     * la fiche de Jeanne Dupont, même quand son nom affiché est un courriel. Comme les
     * autres questions de ce contrat, elle ne filtre rien — écran `ROLE_ADMIN` seulement.
     *
     * @return list<int>
     */
    public function comptesParFiche(string $terme): array;

    /**
     * ═══════════════════════════════════════════════════════════════════════════
     * **CE QUI, CÔTÉ COMPTES, DÉPEND D'UNE FICHE DE L'ARBRE.**.
     *
     * La cinquième question, et la première qui ne serve pas un écran : elle sert un
     * **refus**. `Proposal` la pose avant d'accepter une demande de suppression de fiche.
     *
     * ## Le défaut qu'elle empêche
     *
     * `mbr_member.person_id` porte un `uniq_mbr_member_person` et **aucune clé
     * étrangère** — `Membership` et `Tree` sont deux modules, et le § 2.6 du modèle
     * interdit la jointure qui la poserait. Supprimer `tree_person` ne fait donc rien
     * ici : la ligne reste, et le compte se retrouve rattaché à une fiche qui n'existe
     * plus.
     *
     * Ce n'est pas un effet de bord cosmétique. **Le cercle de visibilité d'un membre et
     * son champ `parente` se calculent depuis cette racine** ({@see personneDuCompte()},
     * consommé par `Tree\Serializer\ParenteDuLecteur`). Une racine absente ne produit pas
     * une erreur : elle produit un calcul sur rien, c'est-à-dire un compte qui ne voit
     * plus rien ou qui voit tout, selon ce que le calcul fait d'un point de départ
     * manquant. Le membre le découvre en se connectant, des jours plus tard, et sans
     * pouvoir relier ce qu'il voit au geste qui l'a causé.
     *
     * `mbr_invitation.person_id` pose le même problème **en différé** : une invitation
     * active désignant une fiche supprimée crée un compte sans racine **à son
     * acceptation**, donc plus tard encore et plus loin du geste.
     *
     * ## Ce qu'elle rend, et ce qu'elle se garde de rendre
     *
     * `membreId` est l'identifiant du rattachement : c'est ce qui permet à
     * l'administrateur de retrouver le compte dans `GET /api/administration/utilisateurs`
     * et de le rattacher ailleurs avant de supprimer.
     *
     * `membreLibelle` est `mbr_member.person_label` — **le nom de la fiche elle-même**,
     * que celui qui demande sa suppression a déjà sous les yeux. C'est délibérément ce
     * libellé-là et **pas l'adresse du compte** : contrairement aux quatre autres méthodes
     * de ce contrat, celle-ci est appelée depuis une route ouverte à tout membre — le
     * dépôt d'une demande —, et non depuis un écran `ROLE_ADMIN`. Y faire sortir un
     * courriel serait une neuvième porte de sortie, ouverte sur le chemin le plus
     * fréquenté du produit.
     *
     * `invitationsActives` est un **compte**, pour la même raison : une invitation porte
     * l'adresse de son destinataire, et le refus n'a pas besoin de la nommer pour dire
     * quoi faire.
     *
     * @return array{membreId: int|null, membreLibelle: string|null, invitationsActives: int}
     */
    public function rattachementsDeLaPersonne(int $personneId): array;
}
