<?php

declare(strict_types=1);

namespace App\Module\Assistant\Service;

use App\Module\Core\Exception\ValidationException;
use App\Module\Membership\Contract\DeclarationDuCompte;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 *  LE CORPS `creation_personne` DU TITULAIRE D'UN COMPTE « À RATTACHER ».
 * ═══════════════════════════════════════════════════════════════════════════════.
 *
 * Trois gestes, partagés par la suggestion ({@see SuggestionsDeRattachement}) et par
 * « Créer la fiche et rattacher » ({@see CreationEtRattachement}) — écrits une fois pour
 * que la demande que l'administrateur a vue soit celle qui est déposée.
 *
 * 1. **{@see minimal()}** — le corps tiré de la seule déclaration, quand le modèle n'a rien
 *    proposé (pas de clé, échec, verdict « fiche existante » écarté) : `#p1` et aucun lien.
 * 2. **{@see imposerLIdentite()}** — l'identité de `#p1` vient **toujours de la
 *    déclaration** : prénoms, nom, naissance exacte. Le modèle ne fournit que le sexe, le
 *    lieu de naissance et les liens. Un modèle qui « corrige » une orthographe ou une date
 *    déclarée par la personne elle-même ferait une fiche fausse sur le seul point où la
 *    source est certaine.
 * 3. **{@see avecLesParents()}** — les parents choisis par l'administrateur remplacent la
 *    filiation de `#p1` ; les personnes nouvelles qui ne tiennent plus à `#p1` par aucun
 *    lien sont retirées, avec leurs liens, pour ne pas créer de fiches flottantes.
 *
 * `#p1` n'est jamais décédée : c'est une personne qui vient de créer un compte.
 */
final class CorpsDuTitulaire
{
    public const string TITULAIRE = '#p1';

    /**
     * @return array<string, mixed>
     */
    public static function minimal(DeclarationDuCompte $declaration): array
    {
        return self::imposerLIdentite([
            'kind' => 'creation_personne',
            'brouillons' => [['localRef' => self::TITULAIRE, 'sex' => 'inconnu']],
            'liens' => [],
        ], $declaration);
    }

    /**
     * @param array<string, mixed> $corps
     *
     * @return array<string, mixed>
     */
    public static function imposerLIdentite(array $corps, DeclarationDuCompte $declaration): array
    {
        $brouillons = \is_array($corps['brouillons'] ?? null) ? array_values($corps['brouillons']) : [];
        $rang = null;

        foreach ($brouillons as $index => $brouillon) {
            if (\is_array($brouillon) && self::TITULAIRE === ($brouillon['localRef'] ?? null)) {
                $rang = $index;
            }
        }

        if (null === $rang) {
            array_unshift($brouillons, ['localRef' => self::TITULAIRE]);
            $rang = 0;
        }

        /** @var array<string, mixed> $titulaire */
        $titulaire = \is_array($brouillons[$rang]) ? $brouillons[$rang] : [];
        $lieu = \is_array($titulaire['birth'] ?? null) && \is_string($titulaire['birth']['place'] ?? null) ? $titulaire['birth']['place'] : null;
        $sexe = $titulaire['sex'] ?? null;

        $brouillons[$rang] = [
            'localRef' => self::TITULAIRE,
            'givenNames' => $declaration->givenNames,
            'surname' => $declaration->surname,
            'marriedName' => null,
            'nickname' => null,
            'sex' => \in_array($sexe, ['masculin', 'feminin', 'inconnu'], true) ? $sexe : 'inconnu',
            'deceased' => false,
            'birth' => DateProposee::versBloc([
                'qualificatif' => 'exacte',
                'date' => $declaration->birthDate->format('Y-m-d'),
                'lieu' => $lieu,
            ]),
            'death' => null,
            'note' => null,
        ];

        $corps['kind'] = 'creation_personne';
        $corps['brouillons'] = $brouillons;
        $corps['liens'] = \is_array($corps['liens'] ?? null) ? array_values($corps['liens']) : [];

        return $corps;
    }

    /**
     * Remplace la filiation de `#p1` par les parents choisis (0 à 2 fiches de l'arbre,
     * déjà vérifiées par l'appelant). Sans parent, le corps est rendu tel quel.
     *
     * @param array<string, mixed> $corps
     * @param list<int>            $parents
     *
     * @return array<string, mixed>
     */
    public static function avecLesParents(array $corps, array $parents): array
    {
        if ([] === $parents) {
            return $corps;
        }

        $liens = [];

        foreach (\is_array($corps['liens'] ?? null) ? $corps['liens'] : [] as $lien) {
            if (!\is_array($lien)) {
                continue;
            }

            $filiationDuTitulaire =
                ('enfant_de' === ($lien['kind'] ?? null) && self::TITULAIRE === ($lien['subjectDraftRef'] ?? null))
                || ('parent_de' === ($lien['kind'] ?? null) && self::TITULAIRE === ($lien['otherDraftRef'] ?? null));

            if (!$filiationDuTitulaire) {
                $liens[] = $lien;
            }
        }

        $filiation = ['kind' => 'enfant_de', 'subjectDraftRef' => self::TITULAIRE, 'otherPersonId' => $parents[0]];

        if (isset($parents[1])) {
            $filiation['other2PersonId'] = $parents[1];
        }

        array_unshift($liens, $filiation);
        $corps['liens'] = $liens;

        return self::sansPersonneFlottante($corps);
    }

    /**
     * Les invariants du geste, **quel que soit** l'origine du corps : une création, `#p1`
     * présente et vivante. `422` sinon.
     *
     * @param array<string, mixed> $corps
     */
    public static function verifier(array $corps): void
    {
        if ('creation_personne' !== ($corps['kind'] ?? null)) {
            throw new ValidationException(['kind' => 'Seule une demande d’ajout de personne crée la fiche d’un compte.']);
        }

        foreach (\is_array($corps['brouillons'] ?? null) ? $corps['brouillons'] : [] as $brouillon) {
            if (\is_array($brouillon) && self::TITULAIRE === ($brouillon['localRef'] ?? null)) {
                if (false !== ($brouillon['deceased'] ?? null)) {
                    throw new ValidationException(['brouillons' => 'La personne qui s’inscrit ne peut pas être créée décédée.']);
                }

                return;
            }
        }

        throw new ValidationException(['brouillons' => 'La demande ne décrit pas la personne inscrite (#p1).']);
    }

    /**
     * Garde les seules personnes nouvelles reliées à `#p1` (directement ou de proche en
     * proche, par les liens entre personnes nouvelles), retire les autres et leurs liens,
     * puis renumérote `seq`.
     *
     * @param array<string, mixed> $corps
     *
     * @return array<string, mixed>
     */
    private static function sansPersonneFlottante(array $corps): array
    {
        /** @var list<array<string, mixed>> $liens */
        $liens = $corps['liens'];
        $atteintes = [self::TITULAIRE => true];

        do {
            $avant = \count($atteintes);

            foreach ($liens as $lien) {
                $references = array_values(array_filter(
                    [$lien['subjectDraftRef'] ?? null, $lien['otherDraftRef'] ?? null, $lien['other2DraftRef'] ?? null],
                    'is_string',
                ));

                foreach ($references as $reference) {
                    if (isset($atteintes[$reference])) {
                        foreach ($references as $voisine) {
                            $atteintes[$voisine] = true;
                        }

                        break;
                    }
                }
            }
        } while (\count($atteintes) !== $avant);

        $corps['brouillons'] = array_values(array_filter(
            \is_array($corps['brouillons'] ?? null) ? $corps['brouillons'] : [],
            static fn (mixed $b): bool => \is_array($b) && \is_string($b['localRef'] ?? null) && isset($atteintes[$b['localRef']]),
        ));

        $gardes = [];
        foreach ($liens as $lien) {
            foreach (['subjectDraftRef', 'otherDraftRef', 'other2DraftRef'] as $cle) {
                if (\is_string($lien[$cle] ?? null) && !isset($atteintes[$lien[$cle]])) {
                    continue 2;
                }
            }

            $lien['seq'] = \count($gardes) + 1;
            $gardes[] = $lien;
        }

        $corps['liens'] = $gardes;

        return $corps;
    }
}
