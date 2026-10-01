<?php

declare(strict_types=1);

namespace App\Module\Tirage\Service;

use App\Module\Tirage\Entity\Attribution;
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
 *
 * Le lien personnel d'un participant ({@see self::lien()}) lit sous la même règle, pour la
 * personne qu'il désigne : il est écrit ici pour la même raison.
 */
final readonly class SerialiseurDeTirage
{
    public function __construct(
        private ServiceDesTirages $service,
        private ServiceDesLiens $liens,
    ) {
    }

    /** @return array<string, mixed> */
    public function resume(Tirage $tirage, LecteurDuTirage $lecteur, \DateTimeImmutable $aujourdhui): array
    {
        $suisParticipant = null !== $lecteur->personId && null !== $tirage->participant($lecteur->personId);
        $prochaine = $this->service->editionCourante($tirage, $aujourdhui);

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
                // Qu'un lien existe, jamais sa clé : elle n'est rendue qu'à sa création.
                'lienActif' => $participant->aUnLienActif(),
                'peutPartagerLien' => $this->liens->peutPartager($tirage, $lecteur, $participant),
            ], $participants),
            'editions' => array_map(
                fn (Edition $edition): array => $this->edition($edition, $tirage, $lecteur, $aujourdhui),
                $tirage->getEditions(),
            ),
        ];
    }

    /**
     * Ce que montre le lien personnel `/t/<clé>` : **ce participant, et rien d'autre**.
     *
     * Ni la liste des participants, ni leurs présences, ni l'organisateur, ni un identifiant
     * de tirage — rien qui permette de rebondir ailleurs. Le seul autre nom est celui du
     * **receveur**, sans lequel le lien ne servirait à rien.
     *
     * Les attributions suivent la règle de `mesAttributions`
     * ({@see ServiceDesTirages::personnesSuiviesPar()}) appliquée à la personne du lien : la
     * sienne, et celles des participants dont elle est le référent. Elles n'apparaissent
     * qu'une fois l'édition tirée.
     *
     * @return array<string, mixed>
     */
    public function lien(Participant $participant, \DateTimeImmutable $aujourdhui): array
    {
        $tirage = $participant->getTirage();
        $edition = $this->service->editionCourante($tirage, $aujourdhui);
        $attributions = [];

        if (null !== $edition && $edition->estTiree()) {
            $suivies = $this->service->personnesSuiviesPar($tirage, $participant->getPersonId());

            foreach ($edition->getAttributions() as $attribution) {
                if (\in_array($attribution->getDonneurPersonId(), $suivies, true)) {
                    $attributions[] = $this->attribution($attribution, $edition);
                }
            }
        }

        return [
            'tirage' => [
                'nom' => $tirage->getNom(),
                'lieu' => $tirage->getLieu(),
            ],
            'edition' => null === $edition ? null : [
                'annee' => $edition->getAnnee(),
                'date' => $edition->getDate()->format('Y-m-d'),
                'statut' => $edition->getStatut()->value,
                'passee' => $edition->getDate() < $aujourdhui->setTime(0, 0),
            ],
            'participant' => [
                // Son propre identifiant de personne, pour que la page reconnaisse SA ligne
                // parmi les attributions (la sienne, ou celle d'un participant qu'il
                // accompagne) sans comparer des libellés — deux homonymes se confondraient.
                // Rien de neuf n'est dévoilé : les attributions portent déjà des `personId`.
                'personId' => $participant->getPersonId(),
                'libelle' => $participant->getPersonLabel(),
                'presence' => $edition?->presenceDe($participant->getPersonId())->value,
                'peutChangerPresence' => $this->liens->peutChangerPresence($participant, $aujourdhui),
            ],
            'attributions' => $attributions,
        ];
    }

    /** @return array<string, mixed> */
    private function attribution(Attribution $attribution, Edition $edition): array
    {
        return [
            'donneur' => ['personId' => $attribution->getDonneurPersonId(), 'libelle' => $attribution->getDonneurLabel()],
            'receveur' => ['personId' => $attribution->getReceveurPersonId(), 'libelle' => $attribution->getReceveurLabel()],
            'presenceDuDonneur' => $edition->presenceDe($attribution->getDonneurPersonId())->value,
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

            $mesAttributions[] = $this->attribution($attribution, $edition);
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
}
