<?php

declare(strict_types=1);

namespace App\Module\Core\Enum;

/**
 * Le motif d'une fin de recherche (ADR 0023, § 3.5 bis du modèle de données).
 *
 * Les libellés sont recopiés **au caractère près** dans le front
 * (`app/src/lib/finDeRecherche.ts`).
 */
enum MotifDeFinDeRecherche: string
{
    case RegistresInexistants = 'registres_inexistants';
    case RegistresDetruits = 'registres_detruits';
    case Lacune = 'lacune';
    case PereInconnu = 'pere_inconnu';
    case EnfantTrouve = 'enfant_trouve';
    case OrigineInconnue = 'origine_inconnue';
    case Homonymie = 'homonymie';
    case Autre = 'autre';

    public function label(): string
    {
        return match ($this) {
            self::RegistresInexistants => 'Registres inexistants avant une année',
            self::RegistresDetruits => 'Registres détruits',
            self::Lacune => 'Lacune dans les registres',
            self::PereInconnu => 'Père inconnu',
            self::EnfantTrouve => 'Enfant trouvé ou abandonné',
            self::OrigineInconnue => 'Lieu d’origine inconnu',
            self::Homonymie => 'Homonymie impossible à trancher',
            self::Autre => 'Autre motif',
        };
    }

    /** Le motif porte une année (`before_year`) — et lui seul. */
    public function avecAnnee(): bool
    {
        return self::RegistresInexistants === $this;
    }

    /** Le texte libre est obligatoire — pour `autre` seul. */
    public function texteObligatoire(): bool
    {
        return self::Autre === $this;
    }

    /** « Père inconnu » ne vaut que du côté du père. */
    public function admisDuCote(CoteDeRecherche $cote): bool
    {
        return self::PereInconnu !== $this || CoteDeRecherche::Pere === $cote;
    }
}
