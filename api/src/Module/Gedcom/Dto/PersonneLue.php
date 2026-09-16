<?php

declare(strict_types=1);

namespace App\Module\Gedcom\Dto;

use App\Module\Core\Enum\Sex;
use App\Module\Core\ValueObject\UncertainDate;

/**
 * Une personne lue dans le fichier, avant de devenir une `tree_person`.
 *
 * `decede` est distinct du bloc date : `DEAT Y` sans `DATE` est un décès certain sans
 * date, et il y en a 25 dans le fichier réel. Le décès est un fait, sa date est une
 * information.
 */
final class PersonneLue
{
    /**
     * @param list<FaitLu>      $faits
     * @param list<NoteLue>     $notes
     * @param list<CitationLue> $citations
     */
    public function __construct(
        public readonly string $xref,
        public readonly ?string $uid,
        public readonly ?string $rin,
        public readonly string $prenoms,
        public readonly string $nom,
        public readonly ?string $nomEpouse,
        public readonly ?string $surnom,
        public readonly string $affichage,
        public readonly string $cleRecherche,
        public readonly Sex $sexe,
        public readonly bool $decede,
        public readonly UncertainDate $naissance,
        public readonly UncertainDate $deces,
        public readonly array $faits,
        public readonly array $notes,
        public readonly array $citations,
        /** Le nombre d'`OBJE` portés : comptés, signalés, jamais téléchargés. Voir {@see ResultatAnalyse}. */
        public readonly int $medias,
        /** Le `RESN privacy` du GEDCOM. Conservé pour le contrôle, pas écrit en base — voir § 9.2 du modèle. */
        public readonly bool $resnPrivacy,
        public readonly int $ligne,
    ) {
    }

    /**
     * Le meilleur `QUAY` parmi les citations de la personne, `null` si aucune n'en porte.
     *
     * C'est la colonne dérivée `tree_person.best_quay`, et c'est elle qui définit les
     * 84 « fiches appuyées sur un acte » : des **fiches**, non des faits.
     */
    public function meilleurQuay(): ?int
    {
        $meilleur = null;

        foreach ($this->citations as $citation) {
            if (null === $citation->quay) {
                continue;
            }

            $meilleur = null === $meilleur ? $citation->quay->value : max($meilleur, $citation->quay->value);
        }

        return $meilleur;
    }
}
