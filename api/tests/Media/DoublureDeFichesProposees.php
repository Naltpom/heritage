<?php

declare(strict_types=1);

namespace App\Tests\Media;

use App\Module\Core\Contract\FichesProposeesInterface;
use App\Module\Core\Enum\DepotSurFicheProposee;

/**
 * Le droit de joindre une pièce à une personne proposée, joué par un double.
 *
 * Il tient la place de `Proposal\Service\FichesProposeesPourLesModules`, que ces tests ne
 * peuvent pas monter : ils n'ont que `med_media`, et la règle se lit sur
 * `prp_change_request`.
 *
 * ── Permissif par défaut, et pourquoi ce n'est pas le repli du noyau ───────────
 *
 * Le repli de production, `Core\Contract\FichesProposeesFermees`, refuse tout — et c'est
 * juste. Mais la plupart des cas de ce dossier déposent sur « la demande 12, `p1` » pour
 * éprouver **autre chose** : la purge, la résolution du brouillon à la validation, la
 * visibilité d'un média sans sujet. Ils ne parlent pas de qui a le droit de déposer ; un
 * double qui refuserait les ferait tous échouer pour une raison qui n'est pas la leur.
 *
 * Le droit lui-même est éprouvé ailleurs, et des deux côtés :
 * `tests/Proposal/FichesProposeesPourLesModulesTest.php` pour la règle, sur une vraie
 * base ; `tests/Media/TeleversementSurFicheProposeeTest.php` de bout en bout, par HTTP,
 * avec le câblage réel. Le repli fermé, lui, est tenu par `DefautFermeTest`.
 *
 * Il **enregistre** chaque question posée : un test peut ainsi vérifier ce que le service
 * a demandé — la référence telle qu'il la tient, le compte, le rôle — et surtout qu'il ne
 * l'a pas demandé du tout pour un dépôt sur une ligne existante.
 */
final class DoublureDeFichesProposees implements FichesProposeesInterface
{
    private DepotSurFicheProposee $verdict = DepotSurFicheProposee::Joignable;

    /** @var list<array{demandeId: int, localRef: string, userId: int, administrateur: bool}> */
    private array $questions = [];

    public function repondre(DepotSurFicheProposee $verdict): self
    {
        $this->verdict = $verdict;

        return $this;
    }

    public function depot(int $demandeId, string $localRef, int $userId, bool $administrateur): DepotSurFicheProposee
    {
        $this->questions[] = [
            'demandeId' => $demandeId,
            'localRef' => $localRef,
            'userId' => $userId,
            'administrateur' => $administrateur,
        ];

        return $this->verdict;
    }

    /** @return list<array{demandeId: int, localRef: string, userId: int, administrateur: bool}> */
    public function questions(): array
    {
        return $this->questions;
    }

    /** @var array<int, int> demande ⇒ auteur */
    private array $auteurs = [];

    /** Déclare l'auteur d'une demande, pour la lecture des pièces de ses fiches. */
    public function auteurDe(int $demandeId, int $userId): self
    {
        $this->auteurs[$demandeId] = $userId;

        return $this;
    }

    public function estAuteurDe(int $demandeId, int $userId): bool
    {
        return ($this->auteurs[$demandeId] ?? null) === $userId;
    }
}
