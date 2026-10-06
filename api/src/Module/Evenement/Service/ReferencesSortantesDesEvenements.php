<?php

declare(strict_types=1);

namespace App\Module\Evenement\Service;

use App\Module\Core\Contract\ReferenceInterModules;
use App\Module\Core\Contract\ReferencesInterModulesInterface;
use App\Module\Core\Enum\CibleDeReference;
use App\Module\Core\Service\ReleveurDeReferences;

/**
 * Les références de `Evenement` (ADR 0014).
 *
 * `evt_invitation.person_id` est déclarée sous **deux portées**, la même frontière que
 * celle de `ServiceDesEvenements::exigerModifiable()` :
 *
 * - un événement **à venir ou en cours, non annulé** : l'invité reçoit des relances, répond,
 *   ouvre son lien. Il doit désigner une fiche qui existe — stricte, et c'est ce qui bloque
 *   la suppression ({@see DependancesDesEvenements}) ;
 * - un événement **passé ou annulé** : plus rien ne s'y écrit, et « on annule, on ne
 *   supprime pas » — le suivi est l'historique de la fête, avec le libellé dénormalisé de
 *   chaque invité. Tolérée.
 *
 * « Aujourd'hui » est la date du serveur, comme pour `Evenement::estPasse()`.
 */
final readonly class ReferencesSortantesDesEvenements implements ReferencesInterModulesInterface
{
    private const string JOINTURE = 'JOIN evt_evenement e ON e.id = i.evenement_id';

    public function __construct(private ReleveurDeReferences $releveur)
    {
    }

    public function references(): array
    {
        $aujourdhui = ['aujourdhui' => (new \DateTimeImmutable('today'))->format('Y-m-d')];

        return [
            new ReferenceInterModules(
                'evt_invitation.person_id',
                CibleDeReference::Personne,
                $this->releveur->occurrences('evt_invitation i', 'i.person_id', 'e.annule_le IS NULL AND e.fin >= :aujourdhui', $aujourdhui, self::JOINTURE),
                'événements à venir ou en cours, non annulés',
            ),
            new ReferenceInterModules(
                'evt_invitation.person_id',
                CibleDeReference::Personne,
                $this->releveur->occurrences('evt_invitation i', 'i.person_id', 'e.annule_le IS NOT NULL OR e.fin < :aujourdhui', $aujourdhui, self::JOINTURE),
                'événements passés ou annulés',
                'historique : le suivi d\'un événement clos garde le libellé de chaque invité (ADR 0014, « on annule, on ne supprime pas »)',
            ),
            new ReferenceInterModules('evt_evenement.organisateur_user_id', CibleDeReference::Compte, $this->releveur->occurrences('evt_evenement', 'organisateur_user_id')),
            new ReferenceInterModules('evt_invitation.invite_par_user_id', CibleDeReference::Compte, $this->releveur->occurrences('evt_invitation', 'invite_par_user_id')),
            new ReferenceInterModules('evt_invitation.reponse_notee_par_user_id', CibleDeReference::Compte, $this->releveur->occurrences('evt_invitation', 'reponse_notee_par_user_id')),
        ];
    }
}
