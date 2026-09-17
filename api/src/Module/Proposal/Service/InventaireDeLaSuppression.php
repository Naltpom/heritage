<?php

declare(strict_types=1);

namespace App\Module\Proposal\Service;

use App\Module\Core\Contract\AnnuaireDesMembresInterface;
use App\Module\Core\Enum\SubjectKind;
use App\Module\Proposal\Contract\InventaireDeSuppression;
use App\Module\Proposal\Service\Port\PortArbre;
use App\Module\Proposal\Service\Port\PortMedias;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * LES TROIS MOITIÉS DE L'INVENTAIRE, RECOLLÉES EN UN SEUL ENDROIT.
 *
 * Ce que la suppression d'une fiche emporte — ou ce qui l'interdit — se lit dans **trois
 * modules** :
 *
 * - `Tree` connaît les six clés étrangères qui pointent sur `tree_person`, et la
 *   contrainte `CHECK` qui décide si une union survit à la perte d'un époux ;
 * - `Media` connaît `med_media.subject_id`, que **rien** ne protège — c'est une
 *   référence polymorphe, PostgreSQL ne peut pas la contraindre, et une fiche effacée y
 *   laisse des preuves qui pointent dans le vide, octets compris ;
 * - `Membership` connaît `mbr_member.person_id` et `mbr_invitation.person_id`, que rien
 *   ne protège non plus — le § 2.6 du modèle interdit la jointure qui poserait la clé.
 *   Une fiche effacée y laisse un compte **sans racine**, donc sans cercle de visibilité
 *   ni parenté calculables. Cette question-là ne passe pas par un port de `Proposal` mais
 *   par `Core\Contract\AnnuaireDesMembresInterface`, qui existait déjà et que `Core` est
 *   seul autorisé à déclarer.
 *
 * `Proposal` ne peut lire aucune des trois directement (règle 3 de l'ADR 0002), et les
 * trois chemins existaient déjà. Ce service ne fait donc qu'une chose : les appeler
 * ensemble, **toujours ensemble**, et dans cet ordre.
 *
 * ## Pourquoi une classe pour trois appels
 *
 * Parce que trois endroits ont besoin du même inventaire complet — le constructeur au
 * dépôt, le contrôle préalable à chaque lecture d'administration, l'applicateur à la
 * validation — et qu'une recomposition écrite trois fois est une recomposition qui sera
 * un jour écrite deux fois sur trois. Le jour où l'un des trois manquerait à l'un
 * des trois, l'écart entre l'inventaire stocké et l'inventaire courant serait **permanent** :
 * toute demande de suppression deviendrait caduque, sur une fiche sans le moindre média,
 * et rien dans le message ne dirait pourquoi.
 */
final readonly class InventaireDeLaSuppression
{
    public function __construct(
        private PortArbre $arbre,
        private PortMedias $medias,
        private AnnuaireDesMembresInterface $annuaire,
    ) {
    }

    /**
     * L'inventaire complet d'une fiche, ou `null` si elle n'existe plus.
     *
     * `null` n'est pas une erreur : c'est le cas `cible_disparue` du § 6.8, une caducité.
     * Une fiche que quelqu'un d'autre a déjà supprimée n'a pas à faire échouer la lecture
     * de la demande qui proposait de la supprimer — c'est même le seul cas où la demande
     * a obtenu ce qu'elle voulait.
     */
    public function de(int $personneId): ?InventaireDeSuppression
    {
        $arbre = $this->arbre->inventaireDeSuppression($personneId);

        if (null === $arbre) {
            return null;
        }

        $comptes = $this->annuaire->rattachementsDeLaPersonne($personneId);

        return $arbre
            ->avecMedias($this->medias->compterLesMediasDe(SubjectKind::Personne->value, $personneId))
            ->avecComptes($comptes['membreId'], $comptes['invitationsActives']);
    }
}
