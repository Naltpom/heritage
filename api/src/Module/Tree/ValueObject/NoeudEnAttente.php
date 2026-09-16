<?php

declare(strict_types=1);

namespace App\Module\Tree\ValueObject;

/**
 * Une personne **proposée et non encore validée**, telle que la projection du lecteur
 * doit la faire apparaître dans l'arbre (§ 6.4 du dossier de mission, ADR 0005).
 *
 * C'est la contrepartie directe de « le demandeur voit sa modification avant
 * validation » : une création qu'on ne voit pas dans l'arbre n'est pas une
 * contribution, c'est un formulaire envoyé dans le vide.
 *
 * **Visible de son auteur et des administrateurs seulement.** Le filtrage de l'audience
 * ne se fait pas ici — ce sont les demandes en attente *du lecteur* qu'on passe à la
 * projection, jamais toutes les demandes en attente.
 *
 * `$personne->id` est l'**identifiant provisoire de la demande** (`prp_draft_person`) :
 * la personne n'a pas d'identifiant d'arbre tant qu'elle n'est pas validée, et tout ce
 * qui la référence utilise ce provisoire jusqu'à la validation, qui le résout.
 */
final readonly class NoeudEnAttente
{
    /**
     * @param string|null $familleProvisoireId l'identifiant à donner au losange provisoire,
     *                                         quand l'ancrage impose d'en créer un. À
     *                                         renseigner par l'appelant qui connaît le
     *                                         format du contrat d'API — `#12/f1` pour la
     *                                         première famille provisoire de la demande 12.
     *                                         Laissé à `null`, un identifiant dérivé est
     *                                         fabriqué : il est stable mais il ne respecte
     *                                         pas ce format, et la sérialisation doit alors
     *                                         le renommer
     */
    public function __construct(
        public PersonnePlacable $personne,
        public AncrageEnAttente $ancrage,
        private ?string $familleProvisoireId = null,
    ) {
    }

    public function id(): string
    {
        return $this->personne->id;
    }

    /**
     * L'identifiant du losange provisoire, quand l'ancrage impose d'en créer un.
     *
     * Dérivé de l'identifiant de la demande à défaut d'être fourni, donc **stable d'un
     * affichage à l'autre** : le viewer peut animer le nœud plutôt que de le voir
     * disparaître et réapparaître.
     */
    public function familleProvisoireId(): string
    {
        return $this->familleProvisoireId ?? $this->personne->id.'#union';
    }
}
