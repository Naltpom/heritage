<?php

declare(strict_types=1);

namespace App\Module\Membership\Service;

use App\Module\Core\Exception\ValidationException;

/**
 * Ce que le candidat soumet à l'étape 1b : nom, prénom(s), date de naissance.
 *
 * ## `birthDate` est la seule date ferme de toute l'API
 *
 * Partout ailleurs une date est une {@see \App\Module\Core\ValueObject\UncertainDate} —
 * le § 4 du dossier interdit d'aplatir une fourchette en date ferme, « ni en base, ni à
 * l'écran ». Ici, l'exception est justifiée et le § 4.2 du contrat la nomme : ce n'est pas
 * une donnée généalogique, c'est **une preuve de connaissance fournie par le candidat**.
 * Elle n'est jamais écrite en base ; elle sert à comparer, puis elle est jetée.
 *
 * ## L'empreinte, et pourquoi les critères ne sont pas journalisés en clair
 *
 * {@see self::empreinte()} donne le `mbr_claim_attempt.query_hash`. Le § 4.3 du modèle
 * explique le choix : « on ne stocke pas les critères en clair — ils désignent une
 * personne vivante, souvent réelle, et ce journal serait sinon exactement la fuite qu'il
 * est censé documenter ». L'empreinte suffit à ce qu'on lui demande : détecter un
 * balayage, c'est-à-dire mille tentatives avec mille empreintes différentes.
 *
 * L'empreinte porte les critères **normalisés**, pour que « ROMARIN » et « Romarin » ne
 * comptent pas comme deux sondes distinctes.
 */
final readonly class CriteresDeRattachement
{
    private function __construct(
        public string $nom,
        public string $prenoms,
        public \DateTimeImmutable $naissance,
    ) {
    }

    /**
     * @param array<string, mixed> $corps
     *
     * @throws ValidationException si l'un des trois champs manque ou est mal formé
     */
    public static function depuisLeCorps(array $corps): self
    {
        $champs = [];

        $nom = $corps['surname'] ?? null;
        $prenoms = $corps['givenNames'] ?? null;
        $naissance = $corps['birthDate'] ?? null;

        if (!\is_string($nom) || '' === trim($nom)) {
            $champs['surname'] = 'Le nom est requis.';
        }
        if (!\is_string($prenoms) || '' === trim($prenoms)) {
            $champs['givenNames'] = 'Le ou les prénoms sont requis.';
        }

        $date = \is_string($naissance) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $naissance) : false;

        if (false === $date) {
            $champs['birthDate'] = 'Une date au format AAAA-MM-JJ est attendue.';
        }

        if ([] !== $champs) {
            throw new ValidationException($champs);
        }

        \assert(\is_string($nom) && \is_string($prenoms) && $date instanceof \DateTimeImmutable);

        return new self(trim($nom), trim($prenoms), $date);
    }

    /** `sha256` des critères **normalisés** — le `query_hash` du § 4.3 du modèle. */
    public function empreinte(NormalisationDeNom $normalisation): string
    {
        return hash('sha256', implode('|', [
            $normalisation->normaliser($this->nom),
            $normalisation->normaliser($this->prenoms),
            $this->naissance->format('Y-m-d'),
        ]));
    }

    public function annee(): int
    {
        return (int) $this->naissance->format('Y');
    }
}
