<?php

declare(strict_types=1);

namespace App\Tests\Question\Double;

use App\Module\Core\Enum\DateQualifier;
use App\Module\Core\ValueObject\UncertainDate;
use App\Module\Question\Contract\FicheEtudiee;
use App\Module\Question\Contract\PortArbrePourQuestions;

/**
 * L'arbre, joué par une liste que chaque test peuple en deux lignes.
 *
 * C'est ce qui rend les tests de la page écrivables **sans aucune table `tree_*`** :
 * `tests/Question/` n'en charge aucune et n'a pas à savoir qu'elles existent. La
 * traduction depuis les vraies tables est éprouvée ailleurs, sur
 * {@see \App\Module\Tree\Service\VueDeLArbrePourQuestions}, et c'est le bon découpage —
 * un test de la page qui monterait un arbre réel mesurerait deux choses à la fois et
 * accuserait la mauvaise.
 */
final class ArbreEnMemoire implements PortArbrePourQuestions
{
    /** @var list<FicheEtudiee> */
    private array $fiches = [];

    /**
     * Une fiche **récente et vide** : dans la mémoire vivante par défaut, donc toutes ses
     * lacunes se déclarent. Un test ne renseigne que ce qu'il veut faire taire.
     */
    public function ajouter(int $id, string $nom = 'Prune Bréval', bool $vivante = true): self
    {
        $this->fiches[] = new FicheEtudiee(
            id: $id,
            nomAffiche: $nom,
            prenoms: 'Prune',
            nom: 'Bréval',
            sexe: 'feminin',
            vivante: $vivante,
            naissance: new UncertainDate(null, DateQualifier::Inconnue, null, null, null),
            deces: new UncertainDate(null, DateQualifier::Inconnue, null, null, null),
            pereId: null,
            mereId: null,
            conjointIds: [],
            enfantIds: [],
        );

        return $this;
    }

    /** Une fiche **complète**, qui ne produit aucune lacune — pour éprouver la disparition. */
    public function ajouterComplete(int $id, string $nom = 'Thym Bréval'): self
    {
        $this->fiches[] = new FicheEtudiee(
            id: $id,
            nomAffiche: $nom,
            prenoms: 'Thym',
            nom: 'Bréval',
            sexe: 'masculin',
            vivante: true,
            naissance: new UncertainDate(
                '1960',
                DateQualifier::Exacte,
                new \DateTimeImmutable('1960-01-01'),
                new \DateTimeImmutable('1960-12-31'),
                'Saule',
            ),
            deces: new UncertainDate(null, DateQualifier::Inconnue, null, null, null),
            pereId: 900,
            mereId: 901,
            conjointIds: [],
            enfantIds: [],
        );

        return $this;
    }

    /** Remplace la liste — pour rejouer une détection sur un arbre qui a changé. */
    public function oublierTout(): self
    {
        $this->fiches = [];

        return $this;
    }

    public function fiches(): array
    {
        return $this->fiches;
    }

    public function libelleDe(int $personneId): ?string
    {
        foreach ($this->fiches as $fiche) {
            if ($fiche->id === $personneId) {
                return $fiche->nomAffiche;
            }
        }

        return null;
    }
}
