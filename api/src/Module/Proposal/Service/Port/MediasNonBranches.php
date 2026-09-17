<?php

declare(strict_types=1);

namespace App\Module\Proposal\Service\Port;

/**
 * Repli de {@see PortMedias} : aucun média.
 *
 * Contrairement aux deux autres replis de ce dossier, celui-ci ne lève pas. Un
 * brouillon sans média joint est un cas parfaitement ordinaire, et la clé `medias`
 * du § 6.6 vaut alors `[]` : le repli produit exactement la même réponse qu'un
 * brouillon auquel personne n'a rien joint, ce qui est sans conséquence pour le front.
 */
final readonly class MediasNonBranches implements PortMedias
{
    public function mediasDeBrouillon(int $demandeId, string $localRef): array
    {
        return [];
    }

    /**
     * Aucun média joint, donc aucun à résoudre.
     *
     * **`0` et non une exception**, pour la même raison que ci-dessus : une demande de
     * création sans preuve jointe est le cas ordinaire. Le repli rend ici exactement ce
     * que rendrait le port branché sur une demande sans média, et l'applicateur n'a donc
     * aucune branche particulière à écrire pour le distinguer.
     */
    public function resoudreLesBrouillons(int $demandeId, array $creees): int
    {
        return 0;
    }

    /**
     * Aucun média, donc aucun blocage.
     *
     * **Et c'est le repli le plus dangereux des trois**, parce qu'il répond « rien à
     * signaler » à une question de sûreté. Si `Media` cessait d'être branché, une
     * suppression de fiche passerait alors qu'une preuve y est rattachée. C'est assumé
     * pour la même raison que les deux autres méthodes de cette classe — le conteneur doit
     * compiler sans `Media` —, et c'est écrit ici pour que celui qui débranche le module
     * sache ce qu'il débranche avec.
     */
    public function compterLesMediasDe(string $subjectKind, int $subjectId): int
    {
        return 0;
    }
}
