<?php

declare(strict_types=1);

namespace App\Module\Timeline\Service;

/**
 * Les **bornes de l'arbre** au sens du § 9.1 : l'année la plus ancienne et la plus récente
 * que l'axe doit couvrir.
 *
 * Elles sont calculées sur les **seules barres visibles du lecteur**, jamais sur l'arbre
 * entier — sans quoi un axe commençant en 1646 dirait à un lecteur qui ne voit rien avant
 * 1900 qu'il existe des fiches plus anciennes. Le § 16.2 du contrat range précisément ce
 * genre de fuite par déduction parmi celles qu'on ferme.
 */
final readonly class Bornes
{
    public function __construct(
        public int $min,
        public int $max,
    ) {
    }

    /**
     * Les bornes d'un ensemble d'années, plus l'année courante si une barre est **ouverte**.
     *
     * L'année courante entre dans le calcul parce qu'une personne vivante n'a pas d'année
     * de fin — « l'API n'invente pas une date de fin » (§ 9.1) — et que sa barre s'arrête
     * néanmoins à aujourd'hui côté rendu. Sans elle, l'axe s'arrêterait à la dernière
     * année **morte** de l'arbre et les barres ouvertes déborderaient de la dernière bande.
     *
     * Le cas sans aucune année connue rend l'année courante des deux côtés : un axe d'une
     * seule bande, vide. C'est ce que reçoit un lecteur dont le cercle ne montre rien —
     * régime `Aucun` sur un arbre sans personne décédée, ou port non branché — et il vaut
     * mieux qu'une réponse sans `bornes`, que le front devrait traiter à part.
     *
     * @param list<int> $annees
     */
    public static function surLesAnnees(array $annees, bool $auMoinsUneBarreOuverte, int $anneeCourante): self
    {
        if ($auMoinsUneBarreOuverte) {
            $annees[] = $anneeCourante;
        }

        if ([] === $annees) {
            return new self($anneeCourante, $anneeCourante);
        }

        return new self(min($annees), max($annees));
    }
}
