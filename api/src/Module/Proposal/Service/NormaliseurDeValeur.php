<?php

declare(strict_types=1);

namespace App\Module\Proposal\Service;

use App\Module\Core\Enum\DateQualifier;
use App\Module\Core\ValueObject\UncertainDate;
use App\Module\Proposal\Enum\ValueKind;
use App\Module\Proposal\Exception\DonneesInvalides;

/**
 * La traduction entre la valeur d'op telle qu'elle voyage en JSON et en `JSONB`
 * (§ 5.2 du modèle de données) et la valeur PHP telle qu'elle traverse
 * {@see Port\PortArbre}.
 *
 * C'est `Proposal` qui a défini la forme d'op, c'est donc `Proposal` qui la traduit :
 * `Tree` ne reçoit et ne rend que des types PHP, ce qui lui évite d'apprendre un format
 * de sérialisation qui ne le concerne pas.
 *
 * ── Un bloc date est **un seul op**, jamais cinq ─────────────────────────────────
 *
 * Cinq ops (`birthRaw`, `birthQualifier`, `birthMin`, `birthMax`, `birthPlace`)
 * permettraient à l'administrateur d'en valider trois et de **fabriquer une date
 * incohérente** — exactement l'aplatissement que le § 9.1 interdit. Le bloc est
 * atomique dans le modèle, il l'est donc dans le diff : le `field` d'un bloc date est
 * le **nom du bloc** (`birth`, `death`, `marriage`, `divorce`), jamais `birthMin`.
 * *(Le § 5.2 du modèle cite `birthMin` en exemple ; c'est une incohérence interne
 * relevée et tranchée au point 1 du § 18 du contrat d'API, dans ce sens-ci.)*
 *
 * ── Cinq clés en entrée, six en sortie ───────────────────────────────────────────
 *
 * `texte` (le rendu de `UncertainDate::format()`) est **en lecture seule**. Il apparaît
 * dans une fiche et dans le calque, **jamais** dans `oldValue`, `newValue` ni dans un
 * corps de requête (§ 14.1). Un back qui l'accepterait en entrée accepterait une
 * sixième clé que la comparaison `JSONB` de la détection de conflit ferait diverger —
 * et personne ne comprendrait pourquoi deux valeurs identiques ne le sont pas. On le
 * refuse donc explicitement, en 422, plutôt que de l'ignorer en silence.
 */
final class NormaliseurDeValeur
{
    /** Les cinq clés d'une valeur `date_incertaine`, toujours les cinq. */
    private const array CLES_DATE = ['raw', 'qualifier', 'min', 'max', 'place'];

    /**
     * JSON venu d'une requête ou de `JSONB` → valeur PHP prête pour le port.
     *
     * @param string $chemin le chemin du champ dans le corps, pour que la 422 se pose
     *                       sur la bonne ligne du formulaire (`ops[0].newValue`)
     */
    public function versPhp(ValueKind $kind, mixed $json, string $chemin): mixed
    {
        return match ($kind) {
            ValueKind::Texte => $this->texte($json, $chemin),
            ValueKind::Entier => $this->entier($json, $chemin),
            ValueKind::Booleen => $this->booleen($json, $chemin),
            ValueKind::Enum => $this->texte($json, $chemin),
            ValueKind::DateIncertaine => $this->blocDate($json, $chemin),
            // Les cinq formes composées (référence, lien, média, citation, note) ne sont
            // pas appliquées par un op de champ : elles passent par leur applicateur
            // dédié, qui connaît leur forme. Voir ApplicateurDOperation.
            //
            // L'en-tête annonçait « quatre » alors que la liste en portait trois : ce
            // n'était pas une faute d'accord, c'était la place de la citation, vide
            // depuis le premier jour. Elle est occupée.
            // `Suppression` rejoint la liste pour une raison de plus : elle n'est jamais
            // appliquée comme un champ **ni comme une valeur** — c'est un inventaire, pas
            // une donnée à écrire. Son applicateur ne lit même pas `newValue`, qui vaut
            // `null` : il relit l'arbre, parce qu'après une suppression il n'y a rien à poser.
            ValueKind::Reference, ValueKind::Lien, ValueKind::Media, ValueKind::Citation, ValueKind::Note, ValueKind::Suppression => $json,
        };
    }

    /**
     * Valeur PHP rendue par le port → JSON **comparable** à un `old_value` stocké.
     *
     * La comparaison de la détection de conflit (§ 9.6) se fait sur cette sortie, avec
     * `===` sur des tableaux : elle exige donc que l'ordre des clés soit stable. C'est
     * pourquoi le bloc date est reconstruit dans l'ordre de {@see CLES_DATE} et jamais
     * recopié tel quel.
     */
    public function versJson(ValueKind $kind, mixed $valeurPhp): mixed
    {
        if (ValueKind::DateIncertaine === $kind) {
            return $valeurPhp instanceof UncertainDate
                ? $this->dateVersJson($valeurPhp)
                : $this->dateVersJson(UncertainDate::inconnue());
        }

        if ($valeurPhp instanceof \BackedEnum) {
            return $valeurPhp->value;
        }

        return $valeurPhp;
    }

    /**
     * Le bloc date **à six clés** des fiches et du calque : les cinq de l'op, plus
     * `texte`, le rendu de `UncertainDate::format()`.
     *
     * @return array{raw: string|null, qualifier: string, min: string|null, max: string|null, place: string|null, texte: string}
     */
    public function dateAvecTexte(UncertainDate $date): array
    {
        $bloc = $this->dateVersJson($date);
        $bloc['texte'] = $date->format();

        return $bloc;
    }

    /**
     * Une valeur d'op, rendue pour l'affichage : c'est ce que `propose`, `actuel` et
     * `valeurCourante` portent.
     *
     * Un bloc date y gagne sa clé `texte`, « parce qu'un bloc date est atomique dans le
     * diff comme dans le modèle, et qu'il s'affiche en une seule ligne » (§ 13.2).
     */
    public function pourAffichage(ValueKind $kind, mixed $json): mixed
    {
        if (ValueKind::DateIncertaine !== $kind || !\is_array($json)) {
            return $json;
        }

        return $this->dateAvecTexte($this->reconstruire($json));
    }

    /**
     * Le rendu d'une valeur en une ligne de français, pour `libelleDiff` :
     * « Naissance : 1987 → entre 1901 et 1909 ».
     */
    public function enUneLigne(ValueKind $kind, mixed $json): string
    {
        if (ValueKind::DateIncertaine === $kind && \is_array($json)) {
            $texte = $this->reconstruire($json)->format();

            return '' === $texte ? '(inconnue)' : $texte;
        }

        return match (true) {
            null === $json => '(vide)',
            true === $json => 'oui',
            false === $json => 'non',
            \is_scalar($json) => (string) $json,
            default => json_encode($json, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE),
        };
    }

    /**
     * « vers 1889 – 1972 » — la ligne de dates d'une fiche, **composée à la
     * sérialisation et jamais stockée**.
     *
     * C'est ce qui garantit qu'elle ne peut pas afficher un point là où la base a un
     * intervalle (§ 9.1) : elle passe par `UncertainDate::format()`, qui n'expose aucun
     * moyen d'en tirer une date ferme.
     *
     * **Le moteur de placement ne l'appelle pas pour une personne vivante** : `graphe.py`
     * court-circuite sur le drapeau privé et rend la constante `"vivant·e"`, de sorte que
     * la largeur d'un nœud ne lise jamais une date masquée (§ 11.2, point 12 du § 18).
     * Ce court-circuit appartient à `Tree` ; ce service ne rend que la ligne réelle.
     */
    public function ligneDates(UncertainDate $naissance, UncertainDate $deces): string
    {
        $debut = $naissance->format();
        $fin = $deces->format();

        return match (true) {
            '' !== $debut && '' !== $fin => $debut.' – '.$fin,
            '' !== $debut => $debut.' –',
            '' !== $fin => '– '.$fin,
            default => '',
        };
    }

    /**
     * Reconstruit l'objet valeur depuis un bloc déjà validé (venu de `JSONB`).
     *
     * Tolérant, volontairement : un bloc stocké a déjà passé {@see blocDate()} à
     * l'entrée, et lever ici sur une donnée en base transformerait un affichage en
     * panne.
     *
     * @param array<string, mixed> $bloc
     */
    public function reconstruire(array $bloc): UncertainDate
    {
        $qualifier = DateQualifier::tryFrom(\is_string($bloc['qualifier'] ?? null) ? $bloc['qualifier'] : '')
            ?? DateQualifier::Inconnue;

        return new UncertainDate(
            \is_string($bloc['raw'] ?? null) ? $bloc['raw'] : null,
            $qualifier,
            $this->jour($bloc['min'] ?? null),
            $this->jour($bloc['max'] ?? null),
            \is_string($bloc['place'] ?? null) ? $bloc['place'] : null,
        );
    }

    /** @return array{raw: string|null, qualifier: string, min: string|null, max: string|null, place: string|null} */
    private function dateVersJson(UncertainDate $date): array
    {
        return [
            'raw' => $date->getRaw(),
            'qualifier' => $date->getQualifier()->value,
            'min' => $date->getMin()?->format('Y-m-d'),
            'max' => $date->getMax()?->format('Y-m-d'),
            'place' => $date->getPlace(),
        ];
    }

    private function blocDate(mixed $json, string $chemin): UncertainDate
    {
        if (!\is_array($json)) {
            throw DonneesInvalides::pourChamp($chemin, 'Un bloc date est un objet à cinq clés, jamais une chaîne.');
        }

        // Cinq clés, toujours les cinq, même quand trois valent `null` : « pour qu'un
        // champ absent soit toujours une erreur et jamais un non-renseigné implicite ».
        foreach (self::CLES_DATE as $cle) {
            if (!\array_key_exists($cle, $json)) {
                throw DonneesInvalides::pourChamp($chemin, \sprintf('La clé « %s » manque : un bloc date en porte toujours cinq.', $cle));
            }
        }

        $surplus = array_diff(array_keys($json), self::CLES_DATE);
        if ([] !== $surplus) {
            throw DonneesInvalides::pourChamp($chemin, \sprintf('Clé inattendue dans le bloc date : %s. « texte » est en lecture seule et doit être retiré avant envoi.', implode(', ', array_map(strval(...), $surplus))));
        }

        $qualifier = DateQualifier::tryFrom(\is_string($json['qualifier']) ? $json['qualifier'] : '');
        if (null === $qualifier) {
            throw DonneesInvalides::pourChamp($chemin.'.qualifier', 'Qualificatif de date inconnu.');
        }

        $min = $this->borne($json['min'], $chemin.'.min');
        $max = $this->borne($json['max'], $chemin.'.max');

        if (null !== $min && null !== $max && $min > $max) {
            throw DonneesInvalides::pourChamp($chemin, 'La borne basse est postérieure à la borne haute.');
        }

        // Les deux contraintes `CHECK` de la migration, appliquées **avant** d'atteindre
        // la base : « le CHECK est le filet, pas le contrôle » (§ 14.3).
        if (DateQualifier::Inconnue === $qualifier && (null !== $min || null !== $max)) {
            throw DonneesInvalides::pourChamp($chemin, 'Une date inconnue ne porte aucune borne.');
        }

        if (DateQualifier::Entre === $qualifier && (null === $min || null === $max || $min >= $max)) {
            throw DonneesInvalides::pourChamp($chemin, 'Une fourchette exige deux bornes, et la basse strictement avant la haute.');
        }

        return new UncertainDate(
            \is_string($json['raw']) ? $json['raw'] : null,
            $qualifier,
            $min,
            $max,
            \is_string($json['place']) ? $json['place'] : null,
        );
    }

    private function borne(mixed $valeur, string $chemin): ?\DateTimeImmutable
    {
        if (null === $valeur) {
            return null;
        }

        if (!\is_string($valeur) || 1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', $valeur)) {
            throw DonneesInvalides::pourChamp($chemin, 'Une borne de date s\'écrit AAAA-MM-JJ, sans heure.');
        }

        $jour = $this->jour($valeur);
        if (null === $jour) {
            throw DonneesInvalides::pourChamp($chemin, 'Cette date n\'existe pas au calendrier.');
        }

        return $jour;
    }

    private function jour(mixed $valeur): ?\DateTimeImmutable
    {
        if (!\is_string($valeur)) {
            return null;
        }

        $jour = \DateTimeImmutable::createFromFormat('!Y-m-d', $valeur);

        return false === $jour || $jour->format('Y-m-d') !== $valeur ? null : $jour;
    }

    private function texte(mixed $json, string $chemin): ?string
    {
        if (null !== $json && !\is_string($json)) {
            throw DonneesInvalides::pourChamp($chemin, 'Cette valeur doit être une chaîne ou null.');
        }

        return $json;
    }

    private function entier(mixed $json, string $chemin): ?int
    {
        if (null !== $json && !\is_int($json)) {
            throw DonneesInvalides::pourChamp($chemin, 'Cette valeur doit être un entier ou null.');
        }

        return $json;
    }

    private function booleen(mixed $json, string $chemin): bool
    {
        // Jamais `0`/`1` : le § 1.4 du contrat d'API le dit pour la sortie, et l'accepter
        // en entrée reviendrait à documenter deux formes pour un booléen.
        if (!\is_bool($json)) {
            throw DonneesInvalides::pourChamp($chemin, 'Cette valeur doit être true ou false.');
        }

        return $json;
    }
}
