<?php

declare(strict_types=1);

namespace App\Module\Core\Enum;

/**
 * Le sexe tel que le GEDCOM l'écrit (§ 2.8 du modèle de données).
 *
 * Dans `Core` parce que `Proposal` l'instancie aussi (`prp_draft_person.sex`).
 *
 * `Inconnu` n'est pas un remplissage : on peut proposer l'ajout d'un enfant dont on
 * ignore le sexe (§ 6.4 du dossier de mission).
 */
enum Sex: string
{
    case Masculin = 'masculin';
    case Feminin = 'feminin';
    case Inconnu = 'inconnu';

    public function label(): string
    {
        return match ($this) {
            self::Masculin => 'Masculin',
            self::Feminin => 'Féminin',
            self::Inconnu => 'Inconnu',
        };
    }

    /** Le code GEDCOM correspondant, pour l'import comme pour l'export. */
    public function gedcom(): string
    {
        return match ($this) {
            self::Masculin => 'M',
            self::Feminin => 'F',
            self::Inconnu => 'U',
        };
    }
}
