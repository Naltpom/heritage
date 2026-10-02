<?php

declare(strict_types=1);

namespace App\Module\Membership\Service;

/**
 * Ce que déclare un candidat qui **ne s'est pas trouvé** dans l'arbre — le bloc
 * `declaration` de `POST /api/inscription/compte` (ADR 0013).
 *
 * Son identité — nom, prénoms, date de naissance, les trois champs déjà saisis à l'étape
 * 1b — et un texte libre qui décrit sa famille : « Mon père est Jean Dupont, né vers 1960
 * à Lille ; ma mère Marie Martin… ». C'est ce texte qu'un administrateur lira, et que
 * l'assistant pourra confronter à l'arbre, pour retrouver ou créer la fiche.
 *
 * ## Pourquoi la validation rend ses erreurs au lieu de les lever
 *
 * Le § 3.1 du contrat veut **un seul `422`** pour toutes les erreurs de champ de la route —
 * courriel, mot de passe **et** déclaration. Une validation qui lèverait à la première
 * erreur ferait corriger le formulaire en trois allers-retours. {@see self::lire()} rend
 * donc la déclaration **ou** ses erreurs, et c'est l'appelant qui les fusionne aux siennes.
 *
 * ## La date est ferme, et c'est la deuxième exception de l'API
 *
 * Comme `birthDate` de l'étape 1b ({@see CriteresDeRattachement}), ce n'est pas une donnée
 * généalogique : c'est ce que le candidat dit de lui-même. Contrairement à l'étape 1b, elle
 * est **conservée** — le temps de la décision de l'administrateur, pas davantage : elle est
 * purgée au rattachement comme au refus (`Member::purgerLaDeclaration()`).
 *
 * ## Le texte devient facultatif quand un message vocal l'accompagne
 *
 * Depuis le 2 octobre 2026, le candidat peut **dire** sa famille au lieu de l'écrire
 * ({@see MessageVocalDeDeclaration}). La règle devient « un texte **ou** un vocal, ou les
 * deux » :
 *
 * - sans vocal, rien ne change : 60 à 4 000 caractères ;
 * - avec un vocal, le texte peut être vide — il est alors rangé à `null` — ou court : il
 *   complète ce qui a été dit et n'a plus à porter seul la recherche. Le plafond de
 *   4 000 caractères, lui, tient toujours.
 *
 * `lire()` ne voit pas le fichier : l'appelant lui dit seulement s'il y en a un **valide**.
 * Un vocal refusé (mauvais type, trop lourd) ne rend pas le texte facultatif — sinon le
 * candidat corrigerait le vocal et découvrirait au second envoi que son texte, lui aussi,
 * était trop court.
 */
final readonly class DeclarationDeFamille
{
    public const int DESCRIPTION_MIN = 60;
    public const int DESCRIPTION_MAX = 4000;
    public const int NOM_MAX = 120;
    public const int PRENOMS_MAX = 160;
    public const int ANNEE_MIN = 1900;

    private function __construct(
        public string $nom,
        public string $prenoms,
        public \DateTimeImmutable $naissance,
        /** `null` quand un message vocal tient lieu de description. */
        public ?string $description,
    ) {
    }

    /**
     * La déclaration, ou la liste de ce qui ne va pas — jamais les deux.
     *
     * Les clés d'erreur sont préfixées par `declaration.` : c'est le chemin du champ dans le
     * corps, et le front les range sous le bon champ sans table de correspondance.
     *
     * `$avecMessageVocal` : un message vocal **valide** accompagne-t-il la déclaration ? Il
     * rend le texte facultatif (voir l'en-tête de classe).
     *
     * @return array{0: self|null, 1: array<string, string>}
     */
    public static function lire(mixed $brut, ?\DateTimeImmutable $aujourdhui = null, bool $avecMessageVocal = false): array
    {
        if (!\is_array($brut)) {
            return [null, ['declaration' => 'Décrivez votre famille.']];
        }

        $champs = [];

        $nom = self::texte($brut['surname'] ?? null);
        $prenoms = self::texte($brut['givenNames'] ?? null);
        $description = self::texte($brut['description'] ?? null);
        $naissance = self::date($brut['birthDate'] ?? null);

        if (null === $nom || '' === $nom || mb_strlen($nom) > self::NOM_MAX) {
            $champs['declaration.surname'] = \sprintf('Le nom est requis (%d caractères au plus).', self::NOM_MAX);
        }

        if (null === $prenoms || '' === $prenoms || mb_strlen($prenoms) > self::PRENOMS_MAX) {
            $champs['declaration.givenNames'] = \sprintf('Le ou les prénoms sont requis (%d caractères au plus).', self::PRENOMS_MAX);
        }

        $limite = ($aujourdhui ?? new \DateTimeImmutable('today'))->setTime(0, 0);

        if (null === $naissance) {
            $champs['declaration.birthDate'] = 'Une date au format AAAA-MM-JJ est attendue.';
        } elseif ((int) $naissance->format('Y') < self::ANNEE_MIN || $naissance > $limite) {
            $champs['declaration.birthDate'] = \sprintf('La date de naissance doit être comprise entre %d et aujourd\'hui.', self::ANNEE_MIN);
        }

        $longueur = null === $description ? 0 : mb_strlen($description);
        // Avec un vocal, le texte n'a plus de plancher — seulement son plafond.
        $minimum = $avecMessageVocal ? 0 : self::DESCRIPTION_MIN;

        if ($longueur < $minimum || $longueur > self::DESCRIPTION_MAX) {
            $champs['declaration.description'] = $avecMessageVocal
                ? \sprintf('La description ne doit pas dépasser %d caractères.', self::DESCRIPTION_MAX)
                : \sprintf(
                    'Décrivez votre famille en %d à %d caractères, ou enregistrez un message vocal : vos parents, vos grands-parents, des dates, des lieux.',
                    self::DESCRIPTION_MIN,
                    self::DESCRIPTION_MAX,
                );
        }

        if ([] !== $champs) {
            return [null, $champs];
        }

        \assert(null !== $nom && null !== $prenoms && null !== $naissance);

        // Un texte vide qui accompagne un vocal est rangé à `null`, pas à `''` : « rien
        // d'écrit » ne se dit que d'une façon en base.
        return [new self($nom, $prenoms, $naissance, 0 === $longueur ? null : $description), []];
    }

    /** Une chaîne débarrassée de ses blancs extrêmes, ou `null` si ce n'en est pas une. */
    private static function texte(mixed $valeur): ?string
    {
        return \is_string($valeur) ? trim($valeur) : null;
    }

    /**
     * `AAAA-MM-JJ`, et une date **qui existe** : `createFromFormat()` accepte le 31 février
     * et le reporte en mars sans rien dire. On relit donc ce qu'on a lu.
     */
    private static function date(mixed $valeur): ?\DateTimeImmutable
    {
        if (!\is_string($valeur) || 1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', $valeur)) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $valeur);

        return false !== $date && $date->format('Y-m-d') === $valeur ? $date : null;
    }
}
