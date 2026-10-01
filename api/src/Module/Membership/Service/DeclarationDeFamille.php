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
        public string $description,
    ) {
    }

    /**
     * La déclaration, ou la liste de ce qui ne va pas — jamais les deux.
     *
     * Les clés d'erreur sont préfixées par `declaration.` : c'est le chemin du champ dans le
     * corps, et le front les range sous le bon champ sans table de correspondance.
     *
     * @return array{0: self|null, 1: array<string, string>}
     */
    public static function lire(mixed $brut, ?\DateTimeImmutable $aujourdhui = null): array
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

        if ($longueur < self::DESCRIPTION_MIN || $longueur > self::DESCRIPTION_MAX) {
            $champs['declaration.description'] = \sprintf(
                'Décrivez votre famille en %d à %d caractères : vos parents, vos grands-parents, des dates, des lieux.',
                self::DESCRIPTION_MIN,
                self::DESCRIPTION_MAX,
            );
        }

        if ([] !== $champs) {
            return [null, $champs];
        }

        \assert(null !== $nom && null !== $prenoms && null !== $naissance && null !== $description);

        return [new self($nom, $prenoms, $naissance, $description), []];
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
