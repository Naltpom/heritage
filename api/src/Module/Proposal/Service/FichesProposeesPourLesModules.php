<?php

declare(strict_types=1);

namespace App\Module\Proposal\Service;

use App\Module\Core\Contract\FichesProposeesInterface;
use App\Module\Core\Enum\DepotSurFicheProposee;
use App\Module\Proposal\Entity\ChangeRequest;
use App\Module\Proposal\Enum\RequestStatus;
use App\Module\Proposal\Repository\ChangeRequestRepository;
use App\Module\Proposal\Repository\DraftPersonRepository;

/**
 * Qui peut joindre une pièce à une personne **proposée** — l'implémentation de
 * {@see FichesProposeesInterface}, le port par lequel `Media` le demande à `Proposal`.
 *
 * L'en-tête du contrat décrit la faille fermée et la règle ; ce fichier n'en garde que ce
 * qui tient à son exécution.
 *
 * ── L'ordre des contrôles, et pourquoi la référence passe avant le statut ───────
 *
 * 1. **La demande**, puis **la référence dans la demande**. Les deux absences rendent
 *    `Introuvable` avant tout jugement : on ne qualifie pas une demande — ni « pas à
 *    vous », ni « tranchée » — pour une référence qui n'y désigne personne. Un auteur qui
 *    se tromperait de référence sur sa demande validée recevrait sinon `409`, c'est-à-dire
 *    « relisez la demande », alors que la relire ne lui montrerait pas de `#p9`.
 * 2. **L'auteur** — `brouillon` et `en_attente` joignables, le reste `Tranchee`. Un
 *    administrateur qui est l'auteur de la demande passe ici, et c'est voulu : il y joint
 *    une pièce à son propre brouillon comme n'importe quel auteur.
 * 3. **L'administrateur** — `en_attente` seulement. Pas un brouillon d'un autre : il
 *    n'est visible que de son auteur (§ 5.7), et y glisser une pièce serait décider à sa
 *    place de ce qu'il enverra. Pas une demande tranchée non plus : il n'y a plus rien à
 *    instruire, et `Introuvable` plutôt que `Tranchee`, parce que la règle de ce contrat
 *    ne fait une exception au `404` que pour **l'auteur** — le seul pour qui une
 *    réponse muette ressemblerait à une panne de sa propre demande.
 * 4. **Tout le reste** — `Introuvable`. Un autre membre ne voit pas les demandes des
 *    autres (§ 13.3) ; lui répondre autre chose que `404` lui apprendrait qu'un numéro
 *    existe, et à quel point il en est.
 *
 * ── Les deux formes de la référence ─────────────────────────────────────────────
 *
 * `prp_draft_person.local_ref` porte le dièse (`#p1`) ; `Media` le reçoit sans (`p1`) —
 * voir {@see \App\Module\Media\Service\Proposition\MediasDeBrouillon}, qui fait le chemin
 * inverse. On rajoute donc le dièse **s'il manque**, puis on exige la forme exacte de
 * `ConstructeurDeCreation` (`#p1` à `#p999`) : une chaîne qui n'a pas cette forme ne peut
 * désigner aucune fiche, et la chercher en base serait une requête pour rien.
 */
final readonly class FichesProposeesPourLesModules implements FichesProposeesInterface
{
    /** La forme de `ConstructeurDeCreation::FORME_DE_REFERENCE`, imposée à la création. */
    private const string FORME_DE_REFERENCE = '/^#p[1-9]\d{0,2}$/';

    public function __construct(
        private ChangeRequestRepository $demandes,
        private DraftPersonRepository $brouillons,
    ) {
    }

    public function depot(int $demandeId, string $localRef, int $userId, bool $administrateur): DepotSurFicheProposee
    {
        $reference = str_starts_with($localRef, '#') ? $localRef : '#'.$localRef;

        if (1 !== preg_match(self::FORME_DE_REFERENCE, $reference)) {
            return DepotSurFicheProposee::Introuvable;
        }

        $demande = $this->demandes->find($demandeId);

        if (!$demande instanceof ChangeRequest || null === $this->brouillons->parReference($demande, $reference)) {
            return DepotSurFicheProposee::Introuvable;
        }

        $statut = $demande->getStatus();

        // Les deux statuts ouverts sont **nommés**, plutôt que déduits de
        // `estDefinitif()` : un statut ajouté demain tomberait sinon du côté joignable
        // sans que personne l'ait décidé. Nommer ce qui ouvre, c'est fermer par défaut.
        if ($demande->getAuthorUserId() === $userId) {
            return \in_array($statut, [RequestStatus::Brouillon, RequestStatus::EnAttente], true)
                ? DepotSurFicheProposee::Joignable
                : DepotSurFicheProposee::Tranchee;
        }

        if ($administrateur && RequestStatus::EnAttente === $statut) {
            return DepotSurFicheProposee::Joignable;
        }

        return DepotSurFicheProposee::Introuvable;
    }

    /**
     * L'auteur de la demande, quel qu'en soit le statut : une demande tranchée reste
     * consultable par son auteur avec ce qu'elle portait (ADR 0004). Une demande qui
     * n'existe pas n'a pas d'auteur.
     */
    public function estAuteurDe(int $demandeId, int $userId): bool
    {
        return $this->demandes->find($demandeId)?->getAuthorUserId() === $userId;
    }
}
