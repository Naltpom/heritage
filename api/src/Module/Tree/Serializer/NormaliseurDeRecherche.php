<?php

declare(strict_types=1);

namespace App\Module\Tree\Serializer;

use App\Module\Tree\Entity\Person;

/**
 * La recherche — § 5.5 du contrat.
 *
 * ═══════════════════════════════════════════════════════════════════════════════
 *  LA RÈGLE DE L'ADR 0003 : **LE GRAPHE MONTRE, IL N'ÉNUMÈRE PAS.**
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Une personne vivante hors du cercle du lecteur **n'apparaît ici sous aucune forme** :
 * ni en résultat, ni en suggestion, ni en libellé de rapprochement de doublon. Elle
 * n'est **pas** rendue en version réduite — elle est **absente**.
 *
 * C'est la seule porte de sortie de ce module où une personne disparaît vraiment, et la
 * différence avec le graphe n'est pas une incohérence : un nœud muet dans l'arbre est
 * acceptable, un arbre amputé serait mensonger ; une ligne de résultat, elle, **est**
 * une énumération. Sans cette règle, le masquage des fiches ne sert à rien — il
 * suffirait de lister.
 *
 * ## Le piège du décompte, et pourquoi il n'y a pas de `total`
 *
 * Cette réponse ne porte **pas** de `total`, contrairement aux listes paginées du § 1.5 :
 * une recherche qui se pagine est une énumération déguisée. Et `tronque` se calcule sur
 * le jeu **déjà filtré**, jamais avant.
 *
 * La règle de fond est celle du § 9.2 du modèle : *tout champ dérivé d'une donnée masquée
 * est lui-même une donnée masquée.* Un nombre de résultats en est un. Si l'on comptait
 * avant le cercle, « 12 résultats » affichant 9 lignes apprendrait au lecteur qu'on lui
 * en cache trois — et, en variant le terme recherché, combien et lesquelles.
 *
 * Le même raisonnement condamne le `LIMIT` posé en SQL au plafond demandé : il faut
 * sur-échantillonner, filtrer, **puis** couper ({@see LectureDeLArbre::candidatsDeRecherche()}).
 *
 * ## Le compte en attente de validation
 *
 * Il a accès à cette route et n'y voit que des personnes décédées — c'est-à-dire le
 * **même** filtre appliqué à un lecteur sans cercle, pas un troisième régime. Aucune
 * personne vivante ne franchit jamais le test, puisqu'il n'a pas de cercle où en trouver.
 * Il n'y a donc aucune branche particulière à écrire ici, et c'est le signe que le
 * régime est bien porté par {@see CercleDuLecteur} plutôt que par des `if` disséminés.
 */
final readonly class NormaliseurDeRecherche
{
    public function __construct(
        private NormaliseurDeDate $dates,
        private LectureDeLArbre $lecture,
        private NormaliseurDeRequete $requetes,
    ) {
    }

    /**
     * @return array{elements: list<array<string, mixed>>, tronque: bool}
     */
    public function resultats(string $terme, int $limite, CercleDuLecteur $cercle, EtatVivant $vivants, bool $vivantsSeulement = false): array
    {
        // Les **mots** et non la clé entière : la clé s'écrit « patronyme, prénoms », et
        // un terme « Prénom Nom » n'en est ni un préfixe ni un infixe. Voir
        // {@see LectureDeLArbre::candidatsDeRecherche()} — l'ancienne forme rendait 0
        // fiche sur 272 pour ce terme-là.
        $mots = $this->requetes->mots($terme);

        if ([] === $mots) {
            return ['elements' => [], 'tronque' => false];
        }

        $elements = [];
        $tronque = false;

        foreach ($this->lecture->candidatsDeRecherche($mots, $limite, $vivantsSeulement) as $personne) {
            $vivante = $vivants->estVivante($personne);

            if (!$cercle->voit($personne->getId(), $vivante)) {
                // **Absente**, et non réduite. C'est toute la règle de l'ADR 0003.
                continue;
            }

            // Le filtre des tirages : une personne qui n'est pas vivante au sens de la
            // règle unique est écartée **avant** le décompte, comme le cercle — `tronque`
            // reste calculé sur le jeu filtré. Retirer des défunts n'apprend rien : ils
            // sont visibles de tous.
            if ($vivantsSeulement && !$vivante) {
                continue;
            }

            if (\count($elements) >= $limite) {
                // On a trouvé un résultat de plus **après** filtrage : le plafond est
                // réellement atteint, et `tronque` le dit sans rien apprendre de ce qui
                // est caché.
                $tronque = true;
                break;
            }

            $elements[] = $this->ligne($personne, $vivants, $cercle);
        }

        return ['elements' => $elements, 'tronque' => $tronque];
    }

    /**
     * @return array<string, mixed>
     */
    private function ligne(Person $personne, EtatVivant $vivants, CercleDuLecteur $cercle): array
    {
        return [
            'personId' => $personne->getId(),
            'displayName' => $personne->getDisplayName(),
            'sex' => $personne->getSex()->value,
            'generation' => $personne->getGeneration(),
            'branch' => $personne->getBranch()?->value,
            'vivant' => $vivants->estVivante($personne),
            // La ligne de dates **d'une fiche**, pas l'étiquette du moteur : on n'arrive
            // ici que sur une personne dont le lecteur a le droit de voir les dates.
            // La naissance d'un vivant passe par la réciprocité (ADR 0016).
            'ligneDates' => $this->dates->ligneDeFiche(
                $personne->getBirth(),
                $personne->getDeath(),
                $cercle->naissance($personne->getId(), $vivants->estVivante($personne)),
            ),
            // Toujours `complete` par construction : ce qui ne l'est pas n'a pas été émis.
            'visibilite' => NiveauDeVisibilite::Complete->value,
        ];
    }
}
