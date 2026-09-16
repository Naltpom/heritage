<?php

declare(strict_types=1);

namespace App\Module\Core\Contract;

/**
 * Le **minimum** que `Core` demande à `Membership` pour servir ses propres écrans
 * d'administration : un libellé de membre, et la charge `membre` d'un compte.
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
}
