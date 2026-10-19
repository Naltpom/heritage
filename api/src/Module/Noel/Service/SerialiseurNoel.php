<?php

declare(strict_types=1);

namespace App\Module\Noel\Service;

use App\Module\Core\Entity\User;
use App\Module\Noel\Entity\Participation;
use App\Module\Noel\Entity\TirageNoel;

/**
 * Le tirage tel que le site de Noël le lit. Sans session : de quoi donner envie de
 * participer (titre, dates, budget, nombre de participants), pas la liste des noms.
 *
 * Un participant connecté lit en plus, sur chaque personne, son `lien` de parenté avec
 * lui (« cousine germaine », `null` sans lien établi ou sans rattachement validé). Le
 * conjoint, lui, est celui des réponses au tirage : « conjoint » l'emporte sur l'arbre.
 *
 * Chacun lit `conjointDansLArbre` sur les participants : son propre conjoint selon
 * heritage, pour lui proposer de le lier d'un geste.
 */
final readonly class SerialiseurNoel
{
    public function __construct(
        private AnnuaireDuTirage $annuaire,
        private ServiceDesTiragesNoel $service,
    ) {
    }

    /** @return array<string, mixed> */
    public function tirage(TirageNoel $tirage, ?User $lecteur, \DateTimeImmutable $maintenant = new \DateTimeImmutable()): array
    {
        $participants = $tirage->participants();

        $charge = [
            'code' => $tirage->getCode(),
            'titre' => $tirage->getTitre(),
            'dateTirage' => self::instant($tirage->getDateTirage()),
            'dateEvenement' => self::instant($tirage->getDateEvenement()),
            'budget' => $tirage->getBudget(),
            'lieu' => $tirage->getLieu(),
            'message' => $tirage->getMessage(),
            'presentation' => $tirage->getPresentation(),
            'statut' => $tirage->getStatut()->value,
            'tireLe' => null !== $tirage->getTireLe() ? self::instant($tirage->getTireLe()) : null,
            'nbParticipants' => \count($participants),
            'createur' => ['prenom' => $this->annuaire->nom($tirage->getCreateur())['prenom']],
        ];

        if (null === $lecteur) {
            return $charge;
        }

        $this->annuaire->precharger([
            $tirage->getCreateur(),
            ...array_map(static fn (Participation $p): User => $p->getUser(), $participants),
        ]);

        $mienne = $tirage->participationDe($lecteur);

        return $charge + [
            'estCreateur' => $tirage->estCreePar($lecteur),
            'maParticipation' => null === $mienne ? null : [
                'reponse' => $mienne->getReponse()->value,
                'presence' => $mienne->getPresence()?->value,
                'vote' => $mienne->getVote()?->value,
                'conjointUserId' => $mienne->getConjoint()?->getId(),
            ],
            'participants' => array_map(fn (Participation $p): array => [
                'userId' => $p->getUser()->getId(),
                ...$this->annuaire->nom($p->getUser()),
                'photoUrl' => $this->annuaire->photoUrl($p->getUser()),
                'presence' => $p->getPresence()?->value,
                'estMoi' => $p->getUser()->getId() === $lecteur->getId(),
                'estCreateur' => $tirage->estCreePar($p->getUser()),
                'conjointDansLArbre' => $this->annuaire->estConjointDansLArbre($lecteur, $p->getUser()),
                'lien' => $this->lien($tirage, $lecteur, $p->getUser()),
            ], $participants),
            'votes' => $this->service->votes($tirage),
            'revelation' => $tirage->getRevelation()?->value,
            'revelationOuverte' => $tirage->revelationOuverte($maintenant),
        ];
    }

    /**
     * Une ligne de liste — « Mes tirages » sur noel2026, « Mes événements » sur heritage.
     * Le budget et le lieu y sont parce qu'heritage affiche la carte sans ouvrir le
     * tirage : ce sont les deux réglages qu'on lit avant de cliquer, et ils n'ont rien
     * de plus confidentiel que le titre, déjà servi sans session par {@see tirage()}.
     *
     * @return array<string, mixed>
     */
    public function resume(TirageNoel $tirage, User $lecteur): array
    {
        return [
            'code' => $tirage->getCode(),
            'titre' => $tirage->getTitre(),
            'dateTirage' => self::instant($tirage->getDateTirage()),
            'dateEvenement' => self::instant($tirage->getDateEvenement()),
            'budget' => $tirage->getBudget(),
            'lieu' => $tirage->getLieu(),
            'statut' => $tirage->getStatut()->value,
            'estCreateur' => $tirage->estCreePar($lecteur),
            'reponse' => $tirage->participationDe($lecteur)?->getReponse()->value,
            'nbParticipants' => \count($tirage->participants()),
        ];
    }

    /** @return array<string, mixed> */
    public function personne(TirageNoel $tirage, User $compte, User $lecteur, ?\DateTimeImmutable $ageAu = null): array
    {
        return [
            'userId' => $compte->getId(),
            ...$this->annuaire->nom($compte),
            'photoUrl' => $this->annuaire->photoUrl($compte),
            ...(null !== $ageAu ? ['age' => $this->annuaire->age($compte, $ageAu)] : []),
            'lien' => $this->lien($tirage, $lecteur, $compte),
        ];
    }

    private function lien(TirageNoel $tirage, User $lecteur, User $compte): ?string
    {
        $conjoint = $tirage->participationDe($lecteur)?->getConjoint();

        return null !== $conjoint && $conjoint->getId() === $compte->getId()
            ? 'conjoint'
            : $this->annuaire->lien($lecteur, $compte);
    }

    private static function instant(\DateTimeImmutable $instant): string
    {
        return $instant->format(\DateTimeInterface::ATOM);
    }
}
