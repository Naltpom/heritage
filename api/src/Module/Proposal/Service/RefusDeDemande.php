<?php

declare(strict_types=1);

namespace App\Module\Proposal\Service;

use App\Module\Core\Contract\Lecteur;
use App\Module\Proposal\Entity\ChangeRequest;
use App\Module\Proposal\Enum\RequestStatus;
use App\Module\Proposal\Exception\Conflit;
use App\Module\Proposal\Exception\DonneesInvalides;
use App\Module\Proposal\Repository\ChangeRequestRepository;
use App\Module\Proposal\Service\Port\NotificateurDeDemandes;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Refuser une demande — **le message est obligatoire**.
 *
 * C'est une règle métier du § 6.3 du dossier de mission, pas une préférence
 * d'interface, et elle est tenue à trois niveaux qui ne se remplacent pas :
 *
 * 1. ici, en `422` avec `champs: {"message": "Le motif du refus est obligatoire."}` ;
 * 2. par la contrainte `ck_prp_request_motif_refus`, qui **refuse la ligne en base** —
 *    « pour qu'aucun chemin de code (console, test, correctif à chaud) ne puisse la
 *    contourner » ;
 * 3. par le front, qui désactive le bouton.
 *
 * Les trois, parce que « le front ne doit pas être le seul à la connaître, et l'API ne
 * doit pas compter sur le front » (§ 6.9 du contrat d'API).
 *
 * ── Pourquoi le motif compte autant ──────────────────────────────────────────────
 *
 * Un refus sec est une contribution perdue et un contributeur perdu. Le motif est aussi
 * ce qui permet de refuser une demande **en renvoyant à une autre** — « votre
 * proposition rejoint la demande n° 11 » — quand deux cousins ont proposé le même
 * arrière-grand-oncle (§ 6.4 du dossier). Une demande refusée **reste consultable par
 * son auteur, avec son motif** : elle n'est jamais supprimée.
 */
final readonly class RefusDeDemande
{
    public function __construct(
        private EntityManagerInterface $em,
        private ChangeRequestRepository $demandes,
        private NotificateurDeDemandes $notificateur,
    ) {
    }

    public function refuser(ChangeRequest $demande, Lecteur $lecteur, mixed $message, mixed $doublonDeId): ChangeRequest
    {
        if (null !== $lecteur->membreEmprunteUserId) {
            throw Conflit::vueCommeMembreActive($lecteur->membreEmprunteUserId);
        }

        if ($demande->getStatus()->estDefinitif()) {
            throw Conflit::dejaDecidee($demande->getStatus());
        }

        $motif = $this->motif($message);
        $doublon = $this->doublon($demande, $doublonDeId);

        $demande->decider(RequestStatus::Refusee, $lecteur->userId, $lecteur->libelle, $motif);
        $demande->setDuplicateOf($doublon);

        $this->em->flush();

        // Après le `commit` : une notification annulée par un `ROLLBACK` annoncerait un
        // refus qui n'a pas eu lieu.
        $this->notificateur->demandeRefusee($demande, $motif);

        return $demande;
    }

    /**
     * Non vide **après `btrim`** : c'est le texte exact de la contrainte `CHECK`, et
     * l'appliquer à l'identique évite qu'une chaîne d'espaces passe le service pour se
     * faire refuser par la base — c'est-à-dire une `500` là où une `422` était due.
     */
    private function motif(mixed $message): string
    {
        if (!\is_string($message) || '' === trim($message)) {
            throw DonneesInvalides::motifDeRefusManquant();
        }

        return trim($message);
    }

    private function doublon(ChangeRequest $demande, mixed $doublonDeId): ?ChangeRequest
    {
        if (null === $doublonDeId) {
            return null;
        }

        if (!\is_int($doublonDeId)) {
            throw DonneesInvalides::pourChamp('doublonDeId', 'L\'identifiant de la demande visée est un entier.');
        }

        if ($doublonDeId === $demande->getId()) {
            throw DonneesInvalides::pourChamp('doublonDeId', 'Une demande ne peut pas être le doublon d\'elle-même.');
        }

        $autre = $this->demandes->find($doublonDeId);

        if (null === $autre) {
            throw DonneesInvalides::pourChamp('doublonDeId', 'La demande visée est introuvable.');
        }

        return $autre;
    }
}
