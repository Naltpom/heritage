<?php

declare(strict_types=1);

namespace App\Module\Proposal\Service\Port;

use App\Module\Core\Enum\SubjectKind;

/**
 * L'état courant d'une ligne visée par une demande, tel que `Tree` le rend.
 *
 * Trois choses, et pas une de plus : de quoi afficher le diff (`libelle`), de quoi
 * faire jouer le verrou n° 1 (`version`), et de quoi faire jouer le verrou n° 2
 * (`valeurs`, comparées aux `old_value` des ops). C'est le § 9.6 du modèle de données
 * en un objet.
 */
final readonly class EtatCible
{
    /**
     * @param string               $libelle `display_name` de la personne, libellé du couple,
     *                                      titre de la citation — ce qui s'affiche, jamais ce
     *                                      sur quoi on décide (§ 0.4 du modèle)
     * @param int                  $version la colonne `#[ORM\Version]`
     * @param array<string, mixed> $valeurs les champs **éditables** de la ligne, en valeurs PHP
     *                                      (voir le tableau de {@see PortArbre}). Une ligne qui
     *                                      rendrait moins de champs que la surface éditable rend
     *                                      indétectable une caducité sur les champs manquants :
     *                                      les rendre tous est une obligation, pas une commodité
     * @param int|null             $personneId la personne à laquelle la ligne appartient, quand ce n'est
     *                                         pas elle-même : celle d'une citation. Elle dit si la ligne est
     *                                         fermée au déposant ({@see \App\Module\Proposal\Service\ValeursMasquees::cibleFermee()})
     */
    public function __construct(
        public SubjectKind $kind,
        public int $id,
        public string $libelle,
        public int $version,
        public array $valeurs,
        public ?int $personneId = null,
    ) {
    }

    /** La clé qui identifie une ligne dans les cartes de travail de la validation. */
    public function cle(): string
    {
        return self::cleDe($this->kind, $this->id);
    }

    public static function cleDe(SubjectKind $kind, int $id): string
    {
        return $kind->value.':'.$id;
    }
}
