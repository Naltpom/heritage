<?php

declare(strict_types=1);

namespace App\Module\Proposal\Service;

use App\Module\Core\Enum\SubjectKind;
use App\Module\Proposal\Contract\LienARetirer;
use App\Module\Proposal\Contract\RetraitDeLien;
use App\Module\Proposal\Service\Port\PortArbre;
use App\Module\Proposal\Service\Port\PortMedias;

/**
 * Ce que défaire un lien ferait, **complété de ce que `Tree` ne voit pas** — le pendant de
 * {@see InventaireDeLaSuppression} pour un retrait de lien.
 *
 * `Tree` mesure l'union : ses époux, ses enfants, ses dates, ses notes, la collision d'un
 * couple restant. Il ne lit pas `med_media`, qui appartient à `Media` : une union qui porte
 * un acte de mariage numérisé lui paraîtrait vide, et serait annoncée supprimée. C'est ici
 * que le média est compté, avant que la mesure ne parte dans l'op ou devant
 * l'administrateur.
 *
 * ── Deux protections, et une seule entre dans la mesure ─────────────────────────────
 *
 * - **le média** entre dans la mesure du dépôt, de la lecture d'administration et du
 *   contrôle préalable : c'est une donnée de l'union, et la mesure doit être la même aux
 *   trois moments pour que leur comparaison ait un sens ;
 * - **une autre demande en attente** qui désigne l'union n'y entre qu'à l'écriture
 *   ({@see pourValider()}). La mettre dans la mesure rendrait une demande caduque parce
 *   qu'un autre membre a déposé quelque chose à côté — ce qui ne change rien à ce que
 *   l'auteur a demandé. Elle ne peut que **conserver** une union que la mesure annonçait
 *   supprimée, c'est-à-dire faire moins que promis, jamais plus.
 */
final readonly class MesureDesRetraits
{
    public function __construct(
        private PortArbre $arbre,
        private PortMedias $medias,
        private RattachementDesFiliations $filiations,
    ) {
    }

    /** La mesure du dépôt et de la lecture, ou `null` si une des deux lignes a disparu. */
    public function de(LienARetirer $lien): ?RetraitDeLien
    {
        return $this->arbre->prevoirRetraitDeLien($lien, $this->porteUnMedia($lien->familleId));
    }

    /**
     * La protection complète, pour l'écriture : média **ou** autre demande en attente.
     */
    public function pourValider(LienARetirer $lien, ?int $demandeCourante): bool
    {
        return $this->filiations->familleProtegee($lien->familleId, $demandeCourante);
    }

    private function porteUnMedia(int $familleId): bool
    {
        return $this->medias->compterLesMediasDe(SubjectKind::Famille->value, $familleId) > 0;
    }
}
