<?php

declare(strict_types=1);

namespace App\Module\Tirage\Service;

use App\Module\Tirage\Entity\Edition;
use App\Module\Tirage\Entity\Participant;
use App\Module\Tirage\Entity\Tirage;

/**
 * La forme JSON d'un tirage, **pour un lecteur donné**.
 *
 * ## Le secret se tient ici, et seulement ici
 *
 * `mesAttributions` ne contient que les attributions dont le **donneur** est le lecteur
 * ou quelqu'un qu'il accompagne ({@see ServiceDesTirages::personnesSuivies()}). Aucune
 * autre clé ne porte un receveur : ni un compte, ni un « qui a déjà tiré qui », ni une
 * liste réservée à l'organisateur. Le jour où l'on voudrait dévoiler le tirage après la
 * fête, c'est ici qu'il faudra l'écrire — et nulle part ailleurs.
 */
final readonly class SerialiseurDeTirage
{
    public function __construct(private ServiceDesTirages $service)
    {
    }

    /** @return array<string, mixed> */
    public function resume(Tirage $tirage, LecteurDuTirage $lecteur, \DateTimeImmutable $aujourdhui): array
    {
        $suisParticipant = null !== $lecteur->personId && null !== $tirage->participant($lecteur->personId);
        $prochaine = $this->prochaineEdition($tirage, $aujourdhui);

        return [
            'id' => $tirage->getId(),
            'nom' => $tirage->getNom(),
            'jour' => $tirage->getJour(),
            'mois' => $tirage->getMois(),
            'lieu' => $tirage->getLieu(),
            'ouvert' => $tirage->isOuvert(),
            'memoire' => $tirage->getMemoire(),
            'exclureConjoints' => $tirage->isExclureConjoints(),
            'organisateur' => [
                'libelle' => $tirage->getOrganisateurLibelle(),
                'estMoi' => $tirage->getOrganisateurUserId() === $lecteur->userId,
            ],
            'peutGerer' => $this->service->peutGerer($tirage, $lecteur),
            'suisParticipant' => $suisParticipant,
            'peutRejoindre' => !$suisParticipant && null !== $lecteur->personId
                && ($tirage->isOuvert() || $this->service->peutGerer($tirage, $lecteur)),
            'nombreDeParticipants' => \count($tirage->getParticipants()),
            'prochaineEdition' => null === $prochaine ? null : $this->edition($prochaine, $tirage, $lecteur, $aujourdhui),
        ];
    }

    /** @return array<string, mixed> */
    public function detail(Tirage $tirage, LecteurDuTirage $lecteur, \DateTimeImmutable $aujourdhui): array
    {
        $suivies = $this->service->personnesSuivies($tirage, $lecteur);
        $participants = $tirage->getParticipants();

        return $this->resume($tirage, $lecteur, $aujourdhui) + [
            'memoireConseillee' => TirageAuSort::memoireConseillee(\count($participants), $tirage->isExclureConjoints()),
            'participantsMin' => TirageAuSort::PARTICIPANTS_MIN,
            'participants' => array_map(fn (Participant $participant): array => [
                'personId' => $participant->getPersonId(),
                'libelle' => $participant->getPersonLabel(),
                'referent' => null === $participant->getReferentPersonId() ? null : [
                    'personId' => $participant->getReferentPersonId(),
                    'libelle' => $participant->getReferentLabel(),
                ],
                'estMoi' => $participant->getPersonId() === $lecteur->personId,
                'suivi' => \in_array($participant->getPersonId(), $suivies, true),
            ], $participants),
            'editions' => array_map(
                fn (Edition $edition): array => $this->edition($edition, $tirage, $lecteur, $aujourdhui),
                $tirage->getEditions(),
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function edition(Edition $edition, Tirage $tirage, LecteurDuTirage $lecteur, \DateTimeImmutable $aujourdhui): array
    {
        $suivies = $this->service->personnesSuivies($tirage, $lecteur);
        $mesAttributions = [];

        foreach ($edition->getAttributions() as $attribution) {
            if (!\in_array($attribution->getDonneurPersonId(), $suivies, true)) {
                continue;
            }

            $mesAttributions[] = [
                'donneur' => ['personId' => $attribution->getDonneurPersonId(), 'libelle' => $attribution->getDonneurLabel()],
                'receveur' => ['personId' => $attribution->getReceveurPersonId(), 'libelle' => $attribution->getReceveurLabel()],
                'presenceDuDonneur' => $edition->presenceDe($attribution->getDonneurPersonId())->value,
            ];
        }

        $presences = [];
        $arrivesApres = [];

        foreach ($tirage->getParticipants() as $participant) {
            $presence = $edition->presenceDe($participant->getPersonId());
            $presences[] = [
                'personId' => $participant->getPersonId(),
                'libelle' => $participant->getPersonLabel(),
                'presence' => $presence->value,
                'presenceLibelle' => $presence->libelle(),
            ];

            if ($edition->estTiree() && $presence->participe() && null === $edition->attributionDe($participant->getPersonId())) {
                $arrivesApres[] = $participant->getPersonId();
            }
        }

        return [
            'annee' => $edition->getAnnee(),
            'date' => $edition->getDate()->format('Y-m-d'),
            'passee' => $edition->getDate() < $aujourdhui->setTime(0, 0),
            'statut' => $edition->getStatut()->value,
            'statutLibelle' => $edition->getStatut()->libelle(),
            'memoireAppliquee' => $edition->getMemoireAppliquee(),
            'tireeLe' => $edition->getTireeLe()?->format(\DATE_ATOM),
            'nombreDAttributions' => \count($edition->getAttributions()),
            'presences' => $presences,
            'arrivesApresLeTirage' => $arrivesApres,
            'mesAttributions' => $mesAttributions,
        ];
    }

    /** L'édition à venir la plus proche ; à défaut, la plus récente. */
    private function prochaineEdition(Tirage $tirage, \DateTimeImmutable $aujourdhui): ?Edition
    {
        $editions = $tirage->getEditions();
        $aVenir = array_values(array_filter(
            $editions,
            static fn (Edition $edition): bool => $edition->getDate() >= $aujourdhui->setTime(0, 0),
        ));

        return [] !== $aVenir ? end($aVenir) : ($editions[0] ?? null);
    }
}
