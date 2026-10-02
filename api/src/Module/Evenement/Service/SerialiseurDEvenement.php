<?php

declare(strict_types=1);

namespace App\Module\Evenement\Service;

use App\Module\Core\Security\CercleDeVisibiliteInterface;
use App\Module\Evenement\Entity\Evenement;
use App\Module\Evenement\Entity\Invitation;
use App\Module\Evenement\Enum\Reponse;

/**
 * La forme JSON d'un événement, **pour un lecteur donné** — en liste blanche : chaque clé
 * est écrite ici, aucune n'est déduite d'une entité. Le test de chaque forme compare
 * l'ensemble **exact** des clés (`tests/Evenement`), sur le principe de
 * `ListeBlancheDeSerialisationTest` : un champ ajouté demain le fait échouer.
 *
 * ## Ce que voit un invité, et ce qu'il ne voit pas
 *
 * Un invité voit l'événement, **sa** réponse, et **qui vient** (`quiVient`) — c'est ce qui
 * décide souvent d'une venue : « si les cousins de Lyon y sont, j'y vais ». Il ne voit pas :
 *
 * - **qui a décliné**, seulement combien. Dire non à une cousinade est une affaire entre
 *   l'invité et l'organisateur ; l'afficher à toute la famille ferait de chaque refus une
 *   déclaration publique, et pousserait à ne pas répondre plutôt qu'à dire non ;
 * - **qui a vu sans répondre**, ni aucun horodatage de vue. « Vu à 23 h 14, pas de réponse »
 *   est une surveillance acceptable de la part de qui invite et doit compter ses couverts —
 *   c'est le service rendu, et l'organisateur l'a demandé —, pas de la part de toute la
 *   famille. C'est la règle du secret des tirages transposée : chacun ne voit que ce qui le
 *   regarde, et l'organisateur voit ce qu'il lui faut pour organiser.
 *
 * Et même parmi ceux qui viennent, un invité ne lit le nom que des personnes **de son
 * cercle** (ADR 0003, révision du 2026-10-01) : l'organisateur a pu inviter un cousin
 * confidentiel que tel autre invité ne voit pas dans l'arbre. Celui-là compte dans `autres`,
 * sans nom.
 *
 * ## Ce que voit l'organisateur
 *
 * Le suivi (`suivi`), réservé à qui gère l'événement : les invités en quatre groupes —
 * présents, absents, **vus sans réponse** (les fantômes 👻), **pas encore vus** —, chacun
 * avec ses horodatages et s'il a un compte ou un lien. Les noms sont ceux que l'organisateur
 * a lui-même choisis dans son cercle : rien de neuf ne lui est appris. Depuis le 2026-10-02,
 * chaque ligne dit aussi si la réponse a été **notée par l'organisateur** (`noteePar`) et si
 * elle est **à confirmer** après un changement de dates (`aConfirmer`).
 */
final readonly class SerialiseurDEvenement
{
    public function __construct(
        private ServiceDesEvenements $service,
        private CercleDeVisibiliteInterface $cercle,
    ) {
    }

    /** @return array<string, mixed> */
    public function resume(Evenement $evenement, LecteurDEvenement $lecteur, \DateTimeImmutable $aujourdhui): array
    {
        $moi = $this->service->invitationDuLecteur($evenement, $lecteur);

        return [
            'id' => $evenement->getId(),
            'titre' => $evenement->getTitre(),
            'debut' => $evenement->getDebut()->format('Y-m-d'),
            'fin' => $evenement->getFin()->format('Y-m-d'),
            'lieu' => $evenement->getLieu(),
            'description' => $evenement->getDescription(),
            'annule' => $evenement->estAnnule(),
            'passe' => $evenement->estPasse($aujourdhui),
            'organisateur' => [
                'libelle' => $evenement->getOrganisateurLibelle(),
                'estMoi' => $evenement->getOrganisateurUserId() === $lecteur->userId,
            ],
            'peutGerer' => $this->service->peutGerer($evenement, $lecteur),
            'suisInvite' => null !== $moi,
            'maReponse' => $moi?->getReponse()?->value,
            // « Notée par Nathan » : la réponse a été saisie par l'organisateur, de vive voix.
            // L'invité le lit, pour la reconnaître — et la changer si elle était mal comprise.
            'maReponseNoteePar' => $moi?->getReponseNoteeParLibelle(),
            // Les dates ont bougé depuis la réponse : l'écran demande de la confirmer.
            'reponseAConfirmer' => null !== $moi && $moi->estAConfirmer(),
            'peutRepondre' => null !== $moi && $this->service->peutRepondre($evenement, $aujourdhui),
            'nombreDInvites' => \count($evenement->getInvitations()),
            'nombreDePresents' => \count($this->avecLaReponse($evenement, Reponse::Present)),
        ];
    }

    /** @return array<string, mixed> */
    public function detail(Evenement $evenement, LecteurDEvenement $lecteur, \DateTimeImmutable $maintenant): array
    {
        $gere = $this->service->peutGerer($evenement, $lecteur);
        $presents = $this->avecLaReponse($evenement, Reponse::Present);

        // Le nom de qui vient : tous pour qui gère (il les a invités), ceux de son cercle
        // pour un invité. Le lecteur lui-même est toujours dans son propre cercle.
        $nommables = $gere
            ? array_map(static fn (Invitation $i): int => $i->getPersonId(), $presents)
            : $this->cercle->filtrerPersonnes(array_map(static fn (Invitation $i): int => $i->getPersonId(), $presents));
        $nommes = array_values(array_filter(
            $presents,
            static fn (Invitation $i): bool => \in_array($i->getPersonId(), $nommables, true),
        ));

        return $this->resume($evenement, $lecteur, $maintenant) + [
            'quiVient' => [
                'presents' => array_map(static fn (Invitation $i): array => [
                    'personId' => $i->getPersonId(),
                    'libelle' => $i->getPersonLabel(),
                    'estMoi' => $i->getPersonId() === $lecteur->personId,
                ], $nommes),
                'autres' => \count($presents) - \count($nommes),
            ],
            'nombreDAbsents' => \count($this->avecLaReponse($evenement, Reponse::Absent)),
            'suivi' => $gere ? $this->suivi($evenement, $lecteur, $maintenant) : null,
        ];
    }

    /**
     * Ce que montre le lien personnel `/e/<clé>` : **cet événement et cette invitation, rien
     * d'autre**. Ni la liste des invités, ni qui vient, ni l'organisateur, ni un identifiant
     * d'événement ou de personne — rien qui permette de rebondir ailleurs. C'est la règle du
     * lien de tirage (`Tirage\Service\SerialiseurDeTirage::lien()`) : le lien vaut une
     * personne dans un événement, et un lien transféré par erreur ne doit rien apprendre de
     * la famille à qui le reçoit.
     *
     * @return array<string, mixed>
     */
    public function lien(Invitation $invitation, \DateTimeImmutable $aujourdhui): array
    {
        $evenement = $invitation->getEvenement();

        return [
            'evenement' => [
                'titre' => $evenement->getTitre(),
                'debut' => $evenement->getDebut()->format('Y-m-d'),
                'fin' => $evenement->getFin()->format('Y-m-d'),
                'lieu' => $evenement->getLieu(),
                'description' => $evenement->getDescription(),
                'annule' => $evenement->estAnnule(),
                'passe' => $evenement->estPasse($aujourdhui),
            ],
            'invite' => [
                'libelle' => $invitation->getPersonLabel(),
                'reponse' => $invitation->getReponse()?->value,
                // Sans compte, pas de notification : le lien est le seul endroit où l'invité
                // apprend que les dates ont changé depuis sa réponse.
                'aConfirmer' => $invitation->estAConfirmer(),
                'peutRepondre' => $this->service->peutRepondre($evenement, $aujourdhui),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function suivi(Evenement $evenement, LecteurDEvenement $lecteur, \DateTimeImmutable $maintenant): array
    {
        $invitations = $evenement->getInvitations();
        $comptes = $this->service->comptes(array_map(static fn (Invitation $i): int => $i->getPersonId(), $invitations));
        $modifiable = !$evenement->estAnnule() && !$evenement->estPasse($maintenant);
        $groupes = ['presents' => [], 'absents' => [], 'fantomes' => [], 'nonVus' => []];

        foreach ($invitations as $invitation) {
            $compte = $comptes[$invitation->getPersonId()];

            $groupe = match (true) {
                Reponse::Present === $invitation->getReponse() => 'presents',
                Reponse::Absent === $invitation->getReponse() => 'absents',
                null !== $invitation->getVuLe() => 'fantomes',
                default => 'nonVus',
            };

            $groupes[$groupe][] = [
                'personId' => $invitation->getPersonId(),
                'libelle' => $invitation->getPersonLabel(),
                'estMoi' => $invitation->getPersonId() === $lecteur->personId,
                'aUnCompte' => $compte['aUnCompte'],
                // Qu'un lien existe, jamais sa clé : elle n'est rendue qu'à sa création.
                'lienActif' => $invitation->aUnLienActif(),
                'peutPartagerLien' => $modifiable && !$compte['aUnCompte'],
                'vuLe' => $invitation->getVuLe()?->format(\DATE_ATOM),
                'reponduLe' => $invitation->getReponduLe()?->format(\DATE_ATOM),
                'relanceLe' => $invitation->getRelanceLe()?->format(\DATE_ATOM),
                // La réponse a été notée à la place de l'invité : par qui (affichage discret).
                'noteePar' => $invitation->getReponseNoteeParLibelle(),
                // Répondu avant le dernier changement de dates : à reconfirmer.
                'aConfirmer' => $invitation->estAConfirmer(),
                // L'organisateur peut noter (ou corriger) la réponse de cet invité. Pas la
                // sienne : il répond lui-même, avec ses propres boutons.
                'peutNoterReponse' => $modifiable && $invitation->getPersonId() !== $lecteur->personId,
            ];
        }

        $prochaine = $evenement->prochaineRelancePossibleLe();

        return $groupes + [
            'derniereRelanceLe' => $evenement->getDerniereRelanceLe()?->format(\DATE_ATOM),
            'prochaineRelancePossibleLe' => null !== $prochaine && $prochaine > $maintenant ? $prochaine->format(\DATE_ATOM) : null,
            // Qui une relance toucherait maintenant : l'écran éteint le bouton à zéro.
            'aRelancer' => $modifiable ? \count($this->service->aRelancer($evenement, $lecteur)) : 0,
        ];
    }

    /** @return list<Invitation> */
    private function avecLaReponse(Evenement $evenement, Reponse $reponse): array
    {
        return array_values(array_filter(
            $evenement->getInvitations(),
            static fn (Invitation $i): bool => $reponse === $i->getReponse(),
        ));
    }
}
