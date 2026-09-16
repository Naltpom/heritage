<?php

declare(strict_types=1);

namespace App\Module\Gedcom\Service;

/**
 * Les totaux de contrôle du fichier de référence — « un import qui ne retombe pas
 * exactement sur ces nombres est un import raté » (§ 4 du dossier de mission).
 *
 * **Ils sont indexés par l'empreinte du fichier, et c'est le point important.** Un jeu de
 * totaux écrit en dur et appliqué à n'importe quel fichier serait un contrôle qu'on
 * désactive au premier GEDCOM différent — donc, en pratique, jamais rallumé. Indexé par
 * `sha256`, il ne peut ni dériver ni s'appliquer par erreur : ou bien le fichier est
 * celui qu'on a recompté, et les nombres valent ; ou bien c'en est un autre, et on le
 * dit au lieu de faire semblant de contrôler.
 *
 * **Ces nombres ont été recomptés sur `arbre-romarin-v5.ged` lui-même**, pas repris d'un
 * document. Le `README.md` de l'atelier en annonce d'autres (« 165 individus, 64
 * familles ») : ce sont ceux du premier import, cinq fusions avant l'état actuel, et ils
 * ont déjà induit en erreur deux fois dans ce projet.
 *
 * Si l'un de ces nombres ne tombe pas, **on ne l'ajuste pas ici** : on cherche pourquoi.
 * Un contrôle qu'on aligne sur le résultat ne contrôle plus rien.
 */
final class TotauxAttendus
{
    /**
     * `arbre-romarin-v5.ged`, 454 697 octets, 9 516 lignes.
     *
     * Le dossier contient onze autres `.ged` : ce sont des versions antérieures, et
     * aucune ne fait foi. L'empreinte les écarte toutes sans qu'on ait à y penser.
     */
    public const string SHA256_REFERENCE = '11626e8d8ead9865f29b0704a3010c9f3e4a91d1d4a9fc6bbde4d303b3895a2e';

    /**
     * @var array<string, array<string, int>>
     */
    private const array JEUX = [
        self::SHA256_REFERENCE => [
            'personnes' => 274,
            'familles' => 103,
            'filiations' => 174,
            'sources' => 28,
            'citations' => 483,
            'citations_individus' => 414,
            'citations_familles' => 69,
            'notes' => 175,
            'notes_de_source' => 14,
            'faits' => 87,
            'medias' => 31,
            'generations' => 12,
            // « Personnes sans date de décès » du dossier de mission, au sens de la
            // colonne `deceased` : 274 − 196 `DEAT`. C'est le décompte du § 3.1 du
            // modèle, dont l'index partiel `idx_tree_person_vivants` annonce « 78 lignes
            // sur 274 ». À ne pas confondre avec les 103 personnes dont on ignore la
            // *date* du décès — 78 sans `DEAT`, plus 25 `DEAT Y` sans `DATE`.
            'sans_deces' => 78,
            'deces_sans_date' => 25,
            'fiches_actees' => 84,
            'resn_privacy' => 18,
            'branche_pp' => 20,
            'branche_pm' => 134,
            'branche_mp' => 51,
            'branche_mm' => 64,
            'branche_tr' => 5,
        ],
    ];

    /**
     * L'ordre et les libellés du tableau de contrôle. Les huit premières lignes sont les
     * totaux que le dossier de mission exige ; les suivantes situent l'import.
     *
     * @var array<string, string>
     */
    public const array LIBELLES = [
        'personnes' => 'personnes',
        'familles' => 'familles',
        'citations' => 'citations',
        'citations_individus' => '    dont sur un individu',
        'citations_familles' => '    dont sur une famille',
        'notes' => 'notes de recherche',
        'sources' => 'sources',
        'sans_deces' => 'personnes sans décès connu',
        'fiches_actees' => 'fiches appuyées sur un acte (best_quay ≥ 2)',
        'generations' => 'générations',
        'branche_pm' => 'branche pm',
        'branche_mm' => 'branche mm',
        'branche_mp' => 'branche mp',
        'branche_pp' => 'branche pp',
        'branche_tr' => 'tronc',
        'filiations' => 'filiations',
        'faits' => 'faits',
        'notes_de_source' => 'notes portées par une source',
        'medias' => 'médias (comptés, non importés)',
        'deces_sans_date' => 'décès certains sans date',
        'resn_privacy' => 'marques RESN privacy',
    ];

    /**
     * @return array<string, int>|null `null` si le fichier n'a pas de jeu de référence
     */
    public function pour(string $sha256): ?array
    {
        return self::JEUX[strtolower($sha256)] ?? null;
    }

    /**
     * Un jeu de totaux fourni à la main : `personnes=8,familles=3,generations=4`.
     *
     * Existe pour qu'un **autre** fichier puisse être contrôlé — le GEDCOM de fixture des
     * tests s'en sert, et c'est ainsi que le mécanisme de contrôle est lui-même vérifié
     * sans dépendre des données réelles, qui ne sont pas versionnées.
     *
     * @return array<string, int>
     *
     * @throws \InvalidArgumentException si la chaîne est mal formée ou nomme un total inconnu
     */
    public function depuisOption(string $option): array
    {
        $totaux = [];

        foreach (explode(',', $option) as $couple) {
            $couple = trim($couple);

            if ('' === $couple) {
                continue;
            }

            if (1 !== preg_match('/^([a-z_]+)\s*=\s*(\d+)$/', $couple, $trouve)) {
                throw new \InvalidArgumentException(\sprintf('Total attendu mal formé : « %s ». Forme attendue : cle=nombre.', $couple));
            }

            if (!isset(self::LIBELLES[$trouve[1]])) {
                throw new \InvalidArgumentException(\sprintf('Total attendu inconnu : « %s ». Clés possibles : %s.', $trouve[1], implode(', ', array_keys(self::LIBELLES))));
            }

            $totaux[$trouve[1]] = (int) $trouve[2];
        }

        return $totaux;
    }
}
