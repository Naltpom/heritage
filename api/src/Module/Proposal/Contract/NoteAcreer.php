<?php

declare(strict_types=1);

namespace App\Module\Proposal\Contract;

/**
 * La note de recherche **proposée** sur une personne existante, telle que `Tree` doit
 * l'insérer.
 *
 * Le pendant de {@see CitationAcreer} pour `tree_note`, et la moitié qui manquait au
 * circuit : on savait donner une note à quelqu'un qu'on **crée** — `prp_draft_person`
 * porte une colonne `note`, recopiée en `tree_note` à la validation —, jamais à quelqu'un
 * qui **existe déjà**. Or les notes sont « les lignes les plus précieuses du fichier »
 * (§ 3.5 du modèle) : elles portent le raisonnement derrière une correction, la décision
 * consignée pour ne pas être refaite, et le faisceau d'indices non conclu.
 *
 * ── L'auteur est le **demandeur**, jamais le valideur ────────────────────────────
 *
 * `tree_note.author_user_id` et `author_label` sont rendus sur la fiche : le normaliseur
 * de personne expose `authorLabel` dans chaque note. Les laisser vides ferait passer la
 * contribution d'un membre pour une ligne importée du GEDCOM — c'est ce que `null`
 * signifie pour cette colonne, et c'est ce qu'il signifiera pour tout lecteur.
 *
 * L'auteur porté ici est donc celui de la **demande**, pas le `Lecteur` reçu par
 * l'applicateur : ce dernier est l'administrateur qui valide, et inscrire son nom sous le
 * raisonnement d'un autre serait faux deux fois — il n'a pas écrit la note, et le membre
 * qui l'a écrite disparaîtrait de la fiche.
 *
 * *(La note d'un brouillon de création, elle, reste sans auteur applicatif : c'est un
 * choix antérieur, assumé dans `EcritureDeLArbrePourProposal::creerPersonne()`, où la
 * note accompagne une fiche dont la révision nomme déjà l'auteur. Les deux chemins
 * divergent, et ce paragraphe est là pour qu'on sache que c'est su.)*
 */
final readonly class NoteAcreer
{
    /**
     * @param int         $personneId   la fiche que la note documente — une ligne
     *                                  `tree_person` qui existe déjà, jamais un brouillon
     * @param string      $corps        le texte de la note, jamais vide : une note sans
     *                                  corps n'est pas une note
     * @param string      $scope        valeur de `Tree\Enum\NoteScope` — ce que la note
     *                                  documente. Elle traverse en chaîne : c'est une
     *                                  énumération de `Tree`, que `Proposal` ne peut pas
     *                                  nommer sans enfreindre la règle 3 de l'ADR 0002
     * @param int|null    $auteurUserId l'auteur de la **demande**, pas celui qui valide
     * @param string|null $auteurLabel  son libellé dénormalisé, affiché sur la fiche
     */
    public function __construct(
        public int $personneId,
        public string $corps,
        public string $scope,
        public ?int $auteurUserId,
        public ?string $auteurLabel,
    ) {
    }
}
