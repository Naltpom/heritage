<?php

declare(strict_types=1);

namespace App\Module\Demarche\Service;

use App\Module\Demarche\Entity\Demarche;
use App\Module\Demarche\Entity\Echange;
use App\Module\Demarche\Entity\PersonneConcernee;
use App\Module\Demarche\Enum\StatutDeDemarche;

/**
 * La forme JSON d'une démarche.
 *
 * `resume` sert les listes : sans le texte des messages, qui peut faire plusieurs pages et
 * ne se lit qu'en ouvrant la démarche. `detail` ajoute les messages en entier.
 *
 * `joursDAttente` est calculé ici plutôt qu'à l'écran : « en attente depuis 40 jours » est
 * ce qui décide de relancer, et deux écrans qui le calculeraient chacun finiraient par ne
 * pas dire la même chose. `aRelancer` l'est pour la même raison (révision du 2026-10-01) :
 * il ne suffit plus de compter les jours, il faut aussi savoir **qui** on attend — une
 * démarche « expédiée par courrier » attend le facteur, pas la mairie, et ne se relance pas
 * ({@see StatutDeDemarche::attendUneReponse()}).
 *
 * `precedente` et `suites` disent le lien « refaite autrement » : la démarche reprise, et
 * celles qui la reprennent. **Seules celles que le lecteur voit** y figurent — même règle
 * que pour les listes ; une démarche liée masquée n'apparaît pas plus ici qu'ailleurs.
 */
final readonly class SerialiseurDeDemarche
{
    /**
     * Au-delà, une démarche qui attend une réponse est « à relancer ». Un mois : c'est le
     * délai au-delà duquel une mairie qui n'a pas répondu a le plus souvent perdu la
     * demande, pas seulement pris du retard.
     */
    public const int JOURS_AVANT_RELANCE = 30;

    public function __construct(private ServiceDesDemarches $service)
    {
    }

    /** @return array<string, mixed> */
    public function resume(Demarche $demarche, LecteurDeDemarche $lecteur, \DateTimeImmutable $aujourdhui): array
    {
        $dernierEnvoi = $demarche->getDernierEnvoiOuNull();
        $echanges = $demarche->getEchanges();
        $dernier = [] === $echanges ? null : $echanges[\count($echanges) - 1];
        $statut = $demarche->getStatut();
        $joursDAttente = $statut->enAttente() && null !== $dernierEnvoi
            ? (int) $dernierEnvoi->getDate()->diff($aujourdhui->setTime(0, 0))->format('%r%a')
            : null;

        $precedente = $demarche->getPrecedente();
        $precedente = null === $precedente ? null : ($this->service->visiblesParmi([$precedente])[0] ?? null);

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
            'enAttente' => $statut->enAttente(),
            'attendUneReponse' => $statut->attendUneReponse(),
            'resultat' => $demarche->getResultat(),
            'personnes' => array_map(
                static fn (PersonneConcernee $p): array => ['personId' => $p->getPersonId(), 'libelle' => $p->getPersonLabel()],
                $demarche->getPersonnes(),
            ),
            'auteur' => [
                'libelle' => $demarche->getAuteurLibelle(),
                'estMoi' => $demarche->getAuteurUserId() === $lecteur->userId,
            ],
            // Les adresses des messages, sans leur texte : c'est par l'expéditeur d'une
            // réponse qu'on retrouve la démarche qu'elle concerne (« etat.civil@… »).
            'correspondants' => array_values(array_unique(array_filter(
                array_merge(...array_map(static fn (Echange $e): array => [$e->getDe(), $e->getA()], $echanges)),
                static fn (?string $adresse): bool => null !== $adresse,
            ))),
            'nombreDeMessages' => \count($echanges),
            'dernierEnvoiLe' => $dernierEnvoi?->getDate()->format('Y-m-d'),
            'dernierMessageLe' => $dernier?->getDate()->format('Y-m-d'),
            'joursDAttente' => $joursDAttente,
            'aRelancer' => $statut->attendUneReponse() && null !== $joursDAttente && $joursDAttente >= self::JOURS_AVANT_RELANCE,
            'precedente' => null === $precedente ? null : [
                'id' => $precedente->getId(),
                'statut' => $precedente->getStatut()->value,
                'demandeeA' => $precedente->getDestinataire(),
            ],
            'suites' => array_map(
                static fn (Demarche $suite): array => ['id' => $suite->getId(), 'statut' => $suite->getStatut()->value],
                $this->service->visiblesParmi($demarche->getSuites()),
            ),
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
