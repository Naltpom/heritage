<?php

declare(strict_types=1);

namespace App\Module\Assistant\Contract;

/**
 * La fiche née de la validation d'une demande de création : l'identifiant de la personne
 * réelle (le `resolved_person_id` du brouillon `#p1`), son libellé, et la demande qui l'a
 * produite — de quoi rattacher le compte et le dire à l'administrateur.
 */
final readonly class FicheCreee
{
    public function __construct(public int $personId, public string $libelle, public int $demandeId)
    {
    }
}
