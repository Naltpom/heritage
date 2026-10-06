<?php

declare(strict_types=1);

namespace App\Module\Proposal\Contract;

/**
 * La citation **proposée** sur une personne existante, telle que `Tree` doit l'insérer.
 *
 * C'est le pendant de {@see \App\Module\Proposal\Service\Port\PersonneAcreer} pour
 * `tree_citation` : la copie, champ pour champ, du sous-ensemble qu'une demande a le
 * droit de poser. Trois d'entre eux sont exactement ceux que
 * `Proposal\Service\SurfaceEditable::CITATION` déclare modifiables — `page`, `quay`,
 * `dataText` — plus la source, qui n'est pas modifiable après coup et doit donc être
 * choisie ici : « rattacher une citation ailleurs n'est pas une correction de citation,
 * c'est un lien ».
 *
 * ── Pourquoi `Contract\` et non `Service\Port\`, où vivent ses voisins ───────────
 *
 * Parce que `Service\Port\` est **le mauvais endroit, et la liste des exemptions de
 * frontière le dit** : les cinq objets de transfert que `Tree` y lit y figurent sous le
 * motif « port à déplacer de `Service\Port\` vers `Contract\` ». Un objet de transfert
 * neuf rangé à côté d'eux aurait demandé deux lignes d'exemption de plus, donc une
 * ventilation à corriger dans quatre fichiers — pour de la dette **créée exprès**, le
 * jour même où le reste du lot attend d'être soldé. Il naît donc à l'arrivée. La
 * frontière passe par `Contract\`, et rien n'oblige les anciens à déménager avant lui.
 *
 * **Ce qu'elle ne porte pas** : ni `evenContext`, ni `role`, ni `dataDateRaw`.
 * Ce sont des colonnes de provenance GEDCOM, écrites par l'import initial et conservées
 * telles quelles (il n'y a ni import ni export GEDCOM depuis l'ADR 0017) ; un
 * contributeur n'a rien à y mettre, et les ouvrir ici ferait entrer dans l'arbre des
 * valeurs qu'aucun écran ne sait relire. Ni `version`, ni `createdAt`, qui
 * appartiennent à `Tree`.
 *
 * ── `quay` traverse en `int`, et c'est l'exception assumée du § 2.7 ──────────────
 *
 * Le tableau de {@see \App\Module\Proposal\Service\Port\PortArbre} dit qu'une
 * énumération traverse **en chaîne**.
 * `CitationQuality` est la seule du projet adossée à `int` — la colonne est un
 * `SMALLINT` qui porte les valeurs 0 à 3 de la norme GEDCOM —, et le port la rend déjà
 * en entier dans `EtatCible::valeurs['quay']`. La faire voyager en chaîne ici
 * obligerait `Tree` à deviner laquelle des deux formes il reçoit selon l'appelant ;
 * l'implémentation accepte les deux, mais **le contrat n'en nomme qu'une**.
 *
 * `null` n'est pas `0` : « non renseigné » et « recopie non vérifiée » sont deux
 * affirmations différentes, et la seconde est une information.
 */
final readonly class CitationAcreer
{
    /**
     * @param int         $personneId la fiche que la citation étaye — une ligne
     *                                `tree_person` qui existe déjà, jamais un brouillon
     * @param int         $sourceId   la ligne `tree_source` citée
     * @param string|null $page       le `PAGE` : de quoi **retrouver** la pièce
     * @param int|null    $quay       0 à 3, ou `null` quand le demandeur ne se prononce pas
     * @param string|null $dataText   le `DATA/TEXT` : la transcription de ce que dit la pièce
     * @param string      $scope      valeur de `Tree\Enum\NoteScope` — ce que la citation
     *                                étaye précisément. Elle traverse en chaîne : c'est une
     *                                énumération de `Tree`, que `Proposal` ne peut pas
     *                                nommer sans enfreindre la règle 3 de l'ADR 0002
     * @param string|null $url        où la pièce se consulte en ligne (visionneuse
     *                                d'archives) — contrôlé par `AdresseDeCitation`
     */
    public function __construct(
        public int $personneId,
        public int $sourceId,
        public ?string $page,
        public ?int $quay,
        public ?string $dataText,
        public string $scope,
        public ?string $url = null,
    ) {
    }
}
