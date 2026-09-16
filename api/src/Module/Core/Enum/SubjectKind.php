<?php

declare(strict_types=1);

namespace App\Module\Core\Enum;

/**
 * Le type de ligne sur laquelle porte un op ou une révision (§ 5.7 du modèle).
 *
 * **Elle vit dans `Core` et non dans `Proposal`**, pour la même raison que `Sex`,
 * `Branch` et `UncertainDate` (arbitrage E) : elle est nommée dans la signature du
 * contrat partagé {@see \App\Module\Core\Contract\CalqueEnAttenteInterface}, que `Tree`
 * consomme. La laisser dans `Proposal` aurait obligé `Core` à référencer un module —
 * exactement ce que le § 2.6 du modèle interdit, et ce que la remontée du contrat dans
 * `Core` sert à supprimer. Les valeurs stockées ne changent pas : la colonne est un
 * `VARCHAR`, seuls les `use` ont bougé.
 *
 * **Six valeurs ici, quatre seulement pour `prp_change_request.target_kind`** —
 * `Personne`, `Famille`, `Citation`, `Media` : ce sont les seules portées qu'une demande
 * peut viser (§ 6.3 du dossier de mission). Une note ou un fait ne sont jamais *la
 * cible* d'une demande ; ils sont touchés **par** une demande qui vise la personne ou la
 * famille qui les porte.
 *
 * L'incohérence entre les deux listes était interne au modèle ; elle est tranchée en les
 * distinguant (arbitrage X), et {@see ciblesDeDemande()} porte la restriction pour que
 * le service de validation n'ait pas à la redécouvrir.
 */
enum SubjectKind: string
{
    case Personne = 'personne';
    case Famille = 'famille';
    case Citation = 'citation';
    case Media = 'media';
    case Note = 'note';
    case Fait = 'fait';

    /**
     * Les quatre portées qu'une `ChangeRequest` peut viser.
     *
     * @return list<self>
     */
    public static function ciblesDeDemande(): array
    {
        return [self::Personne, self::Famille, self::Citation, self::Media];
    }

    public function peutEtreCibleDeDemande(): bool
    {
        return \in_array($this, self::ciblesDeDemande(), true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Personne => 'Personne',
            self::Famille => 'Union',
            self::Citation => 'Citation',
            self::Media => 'Média',
            self::Note => 'Note',
            self::Fait => 'Fait',
        };
    }
}
