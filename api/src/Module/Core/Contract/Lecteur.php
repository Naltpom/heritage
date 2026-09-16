<?php

declare(strict_types=1);

namespace App\Module\Core\Contract;

/**
 * Qui lit, et à quel titre.
 *
 * `Proposal` a besoin de trois faits sur son lecteur, et de rien d'autre : son
 * identifiant de compte (est-il l'auteur ?), son rôle (est-il administrateur ?), et son
 * régime (un compte `en_attente` ne contribue pas). Les trois suffisent à trancher qui
 * reçoit le calque, et il est délibéré de ne pas en demander plus : le **cercle de
 * visibilité** ne figure pas ici parce qu'il n'appartient pas à ce module — c'est
 * `Membership` qui le calcule, et c'est le normaliseur de `Tree` qui **compose** les
 * deux filtres (ADR 0004). Ils ne se remplacent pas : une personne vivante hors cercle
 * n'a pas de clé `enAttente` du tout, et c'est le cercle qui le décide, pas le calque.
 *
 * `membreEmprunteUserId` porte la bascule « voir comme un membre » du § 10.4 : elle
 * n'interdit rien en lecture — c'est son intérêt — mais **toute écriture** rend `409
 * vue_comme_membre_active` tant qu'elle est active. Sinon une `prp_revision` porterait
 * l'administrateur pendant que l'écran montre quelqu'un d'autre.
 */
final readonly class Lecteur
{
    public function __construct(
        public int $userId,
        public string $libelle,
        public bool $administrateur,
        public bool $regimeEnAttente = false,
        public ?int $membreEmprunteUserId = null,
    ) {
    }

    /**
     * L'identité **effective** pour la lecture du calque.
     *
     * Sous la bascule, un administrateur doit voir ce que verrait le membre emprunté —
     * c'est tout l'objet de la bascule, et une bascule qui laisserait passer le calque
     * complet de l'administrateur cesserait de vérifier quoi que ce soit (§ 17.3,
     * divergence 10, sur l'`ETag`, même mécanisme).
     */
    public function identiteEffective(): int
    {
        return $this->membreEmprunteUserId ?? $this->userId;
    }

    /** Sous la bascule, l'administrateur lit **comme un membre** : plus de calque global. */
    public function voitToutesLesDemandes(): bool
    {
        return $this->administrateur && null === $this->membreEmprunteUserId;
    }

    public function estAuteurDe(int $authorUserId): bool
    {
        return $this->identiteEffective() === $authorUserId;
    }
}
