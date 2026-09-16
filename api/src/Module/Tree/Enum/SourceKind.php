<?php

declare(strict_types=1);

namespace App\Module\Tree\Enum;

/**
 * La qualification d'un fonds documentaire (§ 3.9 du modèle de données).
 *
 * **Ajout par rapport au GEDCOM**, et c'est délibéré : c'est cette colonne qui permet
 * de dire « ces 40 fiches reposent sur l'arbre d'un autre utilisateur ». Posée à
 * l'import à partir du `TITL`/`PUBL`, révisable à la main.
 */
enum SourceKind: string
{
    case ActeEtatCivil = 'acte_etat_civil';
    case Recensement = 'recensement';
    case RegistreOfficiel = 'registre_officiel';
    case ArbreTiers = 'arbre_tiers';
    case Publication = 'publication';
    case Temoignage = 'temoignage';
    case Autre = 'autre';

    public function label(): string
    {
        return match ($this) {
            self::ActeEtatCivil => "Acte d'état civil",
            self::Recensement => 'Recensement',
            self::RegistreOfficiel => 'Registre officiel',
            self::ArbreTiers => "Arbre d'un autre utilisateur",
            self::Publication => 'Publication',
            self::Temoignage => 'Témoignage familial',
            self::Autre => 'Autre',
        };
    }
}
