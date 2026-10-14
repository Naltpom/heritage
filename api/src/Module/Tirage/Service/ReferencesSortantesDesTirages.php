<?php

declare(strict_types=1);

namespace App\Module\Tirage\Service;

use App\Module\Core\Contract\ReferenceInterModules;
use App\Module\Core\Contract\ReferencesInterModulesInterface;
use App\Module\Core\Enum\CibleDeReference;
use App\Module\Core\Service\ReleveurDeReferences;

/**
 * Les références de `Tirage` (ADR 0009).
 *
 * - **Les participants** — et leur référent, qui est lui-même un participant — sont
 *   stricts : un participant pendant serait tiré au sort, recevrait un cadeau, et ne
 *   pourrait plus être retiré par aucun écran. C'est ce qui bloque la suppression d'une
 *   fiche ({@see DependancesDesTirages}).
 * - **Les attributions** sont déclarées sous deux portées. Celles d'une édition **à venir**
 *   sont strictes : quelqu'un attend ce cadeau. Celles d'une édition **passée** sont
 *   l'historique — « les éditions passées gardent leurs attributions, qui sont
 *   l'historique » (ADR 0009, `retirerParticipant()`) — et portent les deux libellés
 *   dénormalisés qui les rendent lisibles sans la fiche.
 * - **Les présences** sont tolérées : une présence ne vaut que pour un participant, et les
 *   participants sont relevés à part, strictement.
 */
final readonly class ReferencesSortantesDesTirages implements ReferencesInterModulesInterface
{
    private const string JOINTURE = 'JOIN trg_edition e ON e.id = a.edition_id';

    private const string HISTORIQUE = 'historique : les éditions passées gardent leurs attributions (ADR 0009), libellés dénormalisés compris';

    public function __construct(private ReleveurDeReferences $releveur)
    {
    }

    public function references(): array
    {
        $aujourdhui = ['aujourdhui' => (new \DateTimeImmutable('today'))->format('Y-m-d')];
        $references = [
            new ReferenceInterModules('trg_participant.person_id', CibleDeReference::Personne, $this->releveur->occurrences('trg_participant', 'person_id')),
            new ReferenceInterModules('trg_participant.referent_person_id', CibleDeReference::Personne, $this->releveur->occurrences('trg_participant', 'referent_person_id')),
        ];

        foreach (['donneur_person_id', 'receveur_person_id'] as $colonne) {
            $references[] = new ReferenceInterModules(
                'trg_attribution.'.$colonne,
                CibleDeReference::Personne,
                $this->releveur->occurrences('trg_attribution a', 'a.'.$colonne, 'e.date >= :aujourdhui', $aujourdhui, self::JOINTURE),
                'éditions à venir',
            );
            $references[] = new ReferenceInterModules(
                'trg_attribution.'.$colonne,
                CibleDeReference::Personne,
                $this->releveur->occurrences('trg_attribution a', 'a.'.$colonne, 'e.date < :aujourdhui', $aujourdhui, self::JOINTURE),
                'éditions passées',
                self::HISTORIQUE,
            );
        }

        $references[] = new ReferenceInterModules(
            'trg_presence.person_id',
            CibleDeReference::Personne,
            $this->releveur->occurrences('trg_presence', 'person_id'),
            null,
            'une présence ne vaut que pour un participant, et trg_participant.person_id est relevée strictement ; le reste est l\'historique des éditions',
        );
        $references[] = new ReferenceInterModules('trg_tirage.organisateur_user_id', CibleDeReference::Compte, $this->releveur->occurrences('trg_tirage', 'organisateur_user_id'));
        $references[] = new ReferenceInterModules('trg_participant.ajoute_par_user_id', CibleDeReference::Compte, $this->releveur->occurrences('trg_participant', 'ajoute_par_user_id'));

        return $references;
    }
}
