<?php

declare(strict_types=1);

namespace App\Module\Demarche\Service;

use App\Module\Demarche\Entity\Demarche;
use App\Module\Demarche\Entity\Echange;
use App\Module\Demarche\Entity\PersonneConcernee;

/**
 * La forme JSON d'une démarche.
 *
 * `resume` sert les listes : sans le texte des messages, qui peut faire plusieurs pages et
 * ne se lit qu'en ouvrant la démarche. `detail` ajoute les messages en entier.
 *
 * `joursDAttente` est calculé ici plutôt qu'à l'écran : « en attente depuis 40 jours » est
 * ce qui décide de relancer, et deux écrans qui le calculeraient chacun finiraient par ne
 * pas dire la même chose.
 */
final readonly class SerialiseurDeDemarche
{
    public function __construct(private ServiceDesDemarches $service)
    {
    }

    /** @return array<string, mixed> */
    public function resume(Demarche $demarche, LecteurDeDemarche $lecteur, \DateTimeImmutable $aujourdhui): array
    {
        $dernierEnvoi = $demarche->getDernierEnvoiOuNull();
        $echanges = $demarche->getEchanges();
        $dernier = [] === $echanges ? null : $echanges[\count($echanges) - 1];

        return [
            'id' => $demarche->getId(),
            'nature' => $demarche->getNature()->value,
            'natureLibelle' => $demarche->getNature()->libelle(),
            'forme' => $demarche->getForme()->value,
            'formeLibelle' => $demarche->getForme()->libelle(),
            'intitule' => $demarche->getIntitule(),
            'commune' => $demarche->getCommune(),
            'dateDeLActe' => $demarche->getDateDeLActe(),
            'destinataire' => $demarche->getDestinataire(),
            'statut' => $demarche->getStatut()->value,
            'statutLibelle' => $demarche->getStatut()->libelle(),
            'enAttente' => $demarche->getStatut()->enAttente(),
            'resultat' => $demarche->getResultat(),
            'personnes' => array_map(
                static fn (PersonneConcernee $p): array => ['personId' => $p->getPersonId(), 'libelle' => $p->getPersonLabel()],
                $demarche->getPersonnes(),
            ),
            'auteur' => [
                'libelle' => $demarche->getAuteurLibelle(),
                'estMoi' => $demarche->getAuteurUserId() === $lecteur->userId,
            ],
            'nombreDeMessages' => \count($echanges),
            'dernierEnvoiLe' => $dernierEnvoi?->getDate()->format('Y-m-d'),
            'dernierMessageLe' => $dernier?->getDate()->format('Y-m-d'),
            'joursDAttente' => $demarche->getStatut()->enAttente() && null !== $dernierEnvoi
                ? (int) $dernierEnvoi->getDate()->diff($aujourdhui->setTime(0, 0))->format('%r%a')
                : null,
            'peutSupprimer' => $this->service->peutSupprimer($demarche, $lecteur),
            'creeeLe' => $demarche->getCreatedAt()->format(\DATE_ATOM),
            'modifieeLe' => $demarche->getUpdatedAt()->format(\DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    public function detail(Demarche $demarche, LecteurDeDemarche $lecteur, \DateTimeImmutable $aujourdhui): array
    {
        return [
            ...$this->resume($demarche, $lecteur, $aujourdhui),
            'messages' => array_map(
                static fn (Echange $e): array => [
                    'id' => $e->getId(),
                    'sens' => $e->getSens()->value,
                    'sensLibelle' => $e->getSens()->libelle(),
                    'canal' => $e->getCanal()->value,
                    'canalLibelle' => $e->getCanal()->libelle(),
                    'date' => $e->getDate()->format('Y-m-d'),
                    'de' => $e->getDe(),
                    'a' => $e->getA(),
                    'objet' => $e->getObjet(),
                    'texte' => $e->getTexte(),
                    'reference' => $e->getReference(),
                    'saisiPar' => $e->getSaisiParLibelle(),
                    'peutRetirer' => $lecteur->administrateur || $e->getSaisiParUserId() === $lecteur->userId,
                ],
                $demarche->getEchanges(),
            ),
        ];
    }
}
