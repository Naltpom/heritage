<?php

declare(strict_types=1);

namespace App\Module\Proposal\Contract;

/**
 * Un op à appliquer, tel qu'un module **extérieur** le voit.
 *
 * Même raison que {@see DemandeEnCours} : `Entity\ChangeRequestOp` dans une signature
 * publique oblige l'implémenteur à importer le modèle de `Proposal`, et lui donne au
 * passage `marquerApplique()` — un geste qui appartient au validateur, et à lui seul.
 * L'op traverse donc en lecture seule.
 *
 * ── Ce qu'un applicateur en fait, et ce qu'il ne doit pas en faire ──────────────
 *
 * `oldValue` et `newValue` sont l'**instantané du dépôt** : elles disent ce que l'auteur
 * voyait et ce qu'il proposait, à ce moment-là. Trois jours ont pu passer. Un
 * applicateur qui a besoin de l'état réel d'une ligne la **relit** — c'est ce que fait
 * `Media`, qui retrouve son fichier par `subjectId` plutôt que de faire confiance à
 * `newValue`. La valeur d'op sert au diff et à la décision de caducité, pas de source de
 * vérité sur le présent.
 *
 * `baseVersion` est là pour information : la comparaison entre rejeu et caducité est
 * **déjà faite** quand un applicateur est appelé, et elle appartient à `Proposal`.
 */
final readonly class OpEnAttente
{
    /**
     * @param string      $opKind          valeur de `OpKind`
     * @param string      $subjectKind     valeur de `SubjectKind`
     * @param int|null    $subjectId       la ligne visée, `null` quand l'op vise un brouillon
     * @param string|null $subjectDraftRef la référence provisoire (`#p1`), exclusive de `subjectId`
     * @param string      $valueKind       valeur de `ValueKind` — il dit comment lire les deux valeurs
     */
    public function __construct(
        public int $seq,
        public string $opKind,
        public string $subjectKind,
        public ?int $subjectId,
        public ?string $subjectDraftRef,
        public ?string $field,
        public string $valueKind,
        public mixed $oldValue,
        public mixed $newValue,
        public ?int $baseVersion,
    ) {
    }
}
