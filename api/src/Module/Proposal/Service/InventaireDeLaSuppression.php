<?php

declare(strict_types=1);

namespace App\Module\Proposal\Service;

use App\Module\Core\Contract\AnnuaireDesMembresInterface;
use App\Module\Core\Enum\SubjectKind;
use App\Module\Proposal\Contract\DependancesDUneFiche;
use App\Module\Proposal\Contract\FicheASupprimer;
use App\Module\Proposal\Contract\InventaireDeSuppression;
use App\Module\Proposal\Service\Port\PortArbre;
use App\Module\Proposal\Service\Port\PortMedias;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

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
 * ## Et ce que les autres modules y accrochent
 *
 * Une quatrième source s'y ajoute, par greffe plutôt que par port : `Demarche`,
 * `Evenement`, `Membership` et `Question` tiennent eux aussi un `person_id` sans
 * clé étrangère, et chacun répond pour le sien ({@see DependancesDUneFiche}). Ils sont
 * rassemblés par l'étiquette `proposal.dependances_d_une_fiche` : un module qui en ajoute
 * un n'a rien à modifier ici.
 *
 * ## Les preuves de ce qui part avec la fiche
 *
 * `medias` compte les preuves visant la fiche **et** celles qui visent une union ou une
 * citation emportées avec elle. Une union dont elle est le seul époux part avec elle, une
 * citation de ses faits aussi — et une preuve rattachée à l'une d'elles serait devenue
 * orpheline exactement comme celle de la fiche, sans que le compte l'ait annoncée.
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
        /** @var iterable<DependancesDUneFiche> */
        #[AutowireIterator('proposal.dependances_d_une_fiche')]
        private iterable $dependances = [],
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
        $fiche = new FicheASupprimer($personneId, $arbre->famillesEmportees());

        $mesures = [];
        foreach ($this->dependances as $module) {
            $mesures = [...$mesures, ...$module->mesurer($fiche)];
        }

        return $arbre
            ->avecMedias($this->compterLesPreuves($arbre))
            ->avecComptes($comptes['membreId'], $comptes['invitationsActives'])
            ->avecDependances($mesures);
    }

    /** Les preuves de la fiche, de ses unions emportées et de ses citations emportées. */
    private function compterLesPreuves(InventaireDeSuppression $arbre): int
    {
        $preuves = $this->medias->compterLesMediasDe(SubjectKind::Personne->value, $arbre->personneId);

        foreach ($arbre->famillesEmportees() as $familleId) {
            $preuves += $this->medias->compterLesMediasDe(SubjectKind::Famille->value, $familleId);
        }

        foreach ($arbre->citationsEmportees as $citationId) {
            $preuves += $this->medias->compterLesMediasDe(SubjectKind::Citation->value, $citationId);
        }

        return $preuves;
    }
}
