<?php

declare(strict_types=1);

namespace App\Module\Tree\Serializer;

use App\Module\Core\Service\ComparaisonDIdentites;
use App\Module\Core\Service\ProximiteDeNoms;
use App\Module\Core\ValueObject\IdentiteComparee;
use App\Module\Core\ValueObject\Ressemblance;
use App\Module\Core\ValueObject\UncertainDate;
use App\Module\Tree\Entity\Person;

/**
 * « Cette personne est peut-être déjà dans l'arbre » — § 5.5 bis du contrat.
 *
 * Le formulaire « Ajouter une personne » envoie ce qu'on est en train de saisir ; on rend
 * les fiches de l'arbre qui lui ressemblent, pour qu'on complète la bonne au lieu d'en
 * créer un double. La règle de ressemblance n'est pas ici : elle est dans
 * {@see ComparaisonDIdentites}, partagée avec les propositions en attente. Ce qui est ici,
 * c'est **ce que le lecteur a le droit de faire comparer** — et c'est la partie sensible.
 *
 * ═══════════════════════════════════════════════════════════════════════════════
 *  LES DEUX RÈGLES DE CONFIDENTIALITÉ, APPLIQUÉES ICI ET NULLE PART AILLEURS
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * **1. Le cercle, comme à la recherche (ADR 0003).** Une personne que le cercle ne montre
 * pas — vivante confidentielle hors du cercle, ou d'un autre arbre — est **absente** : ni
 * résultat, ni score, ni raison. C'est le même `voit()` que {@see NormaliseurDeRecherche},
 * posé au même endroit : après la requête, avant tout calcul.
 *
 * **2. La réciprocité des vivants (ADR 0016) — la naissance réduite, et elle seule.** Pour
 * un vivant, on ne compare **que** la naissance réduite aux composantes que le lecteur
 * voit déjà (`CercleDuLecteur::naissance()`, `UncertainDate::reduite()`). Quand l'année
 * même n'est pas vue, la date est **non comparable** : elle n'écarte rien, ne rapporte
 * rien, ne produit aucune raison.
 *
 * Sans cette règle, la route serait un **oracle de date de naissance**. Il suffirait de
 * saisir le nom d'un cousin avec 1934, puis 1935, puis 1936 : la fiche disparaîtrait à la
 * première année incompatible, ou gagnerait un critère à la bonne — et la date que la
 * réciprocité retient sortirait par la bande, sans avoir jamais été envoyée. D'où le test
 * qui exige des réponses **identiques**, octet pour octet, pour une saisie 1934 et une
 * saisie 1990 sur un vivant dont la naissance n'est pas partagée.
 *
 * Le **lieu** de naissance, lui, se compare : la fiche d'un vivant visible le montre
 * (`NormaliseurDeDate::naissancePourFiche()` le conserve dans le bloc masqué), le comparer
 * n'apprend donc rien. Le décès aussi, que la fiche complète montre en entier.
 *
 * ## Pas de `total`, et `tronque` sur le jeu filtré
 *
 * Même règle qu'au § 5.5, pour la même raison : *tout champ dérivé d'une donnée masquée est
 * lui-même une donnée masquée* (§ 9.2 du modèle). Les ressemblances retenues sont triées
 * **puis** coupées à `limite` ; `tronque` dit qu'il en restait, sans dire combien.
 */
final readonly class NormaliseurDeRessemblance
{
    /**
     * Le nombre de candidats lus en base, au plus. L'arbre en compte moins de 300 : le
     * plafond ne mord pas, il borne seulement la requête si l'arbre grossit
     * ({@see LectureDeLArbre::candidatsDeRessemblance()}).
     */
    public const int PLAFOND_DE_CANDIDATS = 500;

    /** Les premières lettres de chaque mot saisi qui servent à ramener les candidats. */
    public const int LONGUEUR_DU_PREFIXE = 3;

    /**
     * Le nombre de préfixes, au plus — la borne du § 5.5 sur le nombre de mots, pour la
     * même raison : chaque préfixe ajoute deux `LIKE` à la requête. Le nom passe en
     * premier, puis le nom d'usage, les prénoms et le surnom.
     */
    public const int PREFIXES_MAXIMUM = 8;

    public function __construct(
        private NormaliseurDeDate $dates,
        private LectureDeLArbre $lecture,
        private ComparaisonDIdentites $comparaison,
        private ProximiteDeNoms $noms,
    ) {
    }

    /**
     * @return array{elements: list<array<string, mixed>>, tronque: bool}
     */
    public function resultats(IdentiteComparee $saisie, int $limite, CercleDuLecteur $cercle, EtatVivant $vivants): array
    {
        /** @var list<array{personne: Person, ressemblance: Ressemblance, vivante: bool}> $retenues */
        $retenues = [];

        foreach ($this->lecture->candidatsDeRessemblance($this->prefixes($saisie), self::PLAFOND_DE_CANDIDATS) as $personne) {
            $vivante = $vivants->estVivante($personne);

            if (!$cercle->voit($personne->getId(), $vivante)) {
                // **Absente**, et non réduite : c'est toute la règle de l'ADR 0003. Rien de
                // ce qui suit — ni le score, ni le tri, ni `tronque` — ne la voit.
                continue;
            }

            $ressemblance = $this->comparaison->comparer($saisie, $this->identiteVisible($personne, $cercle, $vivante));

            if (null !== $ressemblance) {
                $retenues[] = ['personne' => $personne, 'ressemblance' => $ressemblance, 'vivante' => $vivante];
            }
        }

        // Les plus ressemblantes d'abord ; à score égal, un nom identique avant un nom à une
        // faute près ; puis l'ordre de la clé de recherche (patronyme, prénoms), puis
        // l'identifiant — un ordre total, pour que deux appels identiques rendent la même
        // liste. Aucune de ces clés ne lit une donnée que le lecteur ne voit pas : le score
        // ne compte que ce qui a été comparé, c'est-à-dire la naissance réduite.
        usort($retenues, static fn (array $a, array $b): int => [
            $b['ressemblance']->score,
            $b['ressemblance']->nomExact,
            $a['personne']->getSearchKey(),
            $a['personne']->getId(),
        ] <=> [
            $a['ressemblance']->score,
            $a['ressemblance']->nomExact,
            $b['personne']->getSearchKey(),
            $b['personne']->getId(),
        ]);

        $elements = [];

        foreach (\array_slice($retenues, 0, $limite) as $retenue) {
            $elements[] = $this->ligne($retenue['personne'], $retenue['ressemblance'], $retenue['vivante'], $cercle);
        }

        return ['elements' => $elements, 'tronque' => \count($retenues) > $limite];
    }

    /**
     * Les préfixes de la saisie : les trois premières lettres de chaque mot du nom, du nom
     * d'usage, des prénoms et du surnom, repliés — donc `[A-Z0-9]+`, sans joker possible.
     *
     * Un mot d'une seule lettre (une initiale) n'en donne pas : `LIKE 'J%'` ramènerait une
     * large part de l'arbre sans rien apprendre de plus.
     *
     * @return list<string>
     */
    public function prefixes(IdentiteComparee $saisie): array
    {
        $prefixes = [];

        foreach ([$saisie->nom, $saisie->nomDUsage ?? '', $saisie->prenoms, $saisie->surnom ?? ''] as $valeur) {
            foreach ($this->noms->jetons($valeur) as $jeton) {
                if (\strlen($jeton) >= 2) {
                    $prefixes[] = substr($jeton, 0, self::LONGUEUR_DU_PREFIXE);
                }
            }
        }

        return \array_slice(array_values(array_unique($prefixes)), 0, self::PREFIXES_MAXIMUM);
    }

    /**
     * La fiche **telle que le lecteur a le droit de la comparer**.
     *
     * Pour un vivant, la naissance est réduite aux composantes que la réciprocité accorde ;
     * si l'année même n'est pas vue, `reduite()` rend `null` et la date devient « inconnue »
     * — non comparable — en gardant son lieu, que la fiche montre (voir l'en-tête). Pour un
     * défunt, `naissance()` rend tout, et la date passe entière.
     */
    private function identiteVisible(Person $personne, CercleDuLecteur $cercle, bool $vivante): IdentiteComparee
    {
        $naissance = $personne->getBirth();
        $vue = $naissance->reduite($cercle->naissance($personne->getId(), $vivante))
            ?? UncertainDate::inconnue($naissance->getPlace());

        return new IdentiteComparee(
            $personne->getGivenNames(),
            $personne->getSurname(),
            $personne->getMarriedName(),
            $personne->getNickname(),
            $personne->getSex(),
            $vue,
            $personne->getDeath(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function ligne(Person $personne, Ressemblance $ressemblance, bool $vivante, CercleDuLecteur $cercle): array
    {
        $lieu = trim($personne->getBirth()->getPlace() ?? '');

        return [
            'personId' => $personne->getId(),
            'displayName' => $personne->getDisplayName(),
            'sex' => $personne->getSex()->value,
            'vivant' => $vivante,
            'generation' => $personne->getGeneration(),
            'branch' => $personne->getBranch()?->value,
            // La ligne de dates de la recherche, au caractère près : la naissance d'un vivant
            // y passe par la réciprocité (ADR 0016), comme sur sa fiche.
            'ligneDates' => $this->dates->ligneDeFiche(
                $personne->getBirth(),
                $personne->getDeath(),
                $cercle->naissance($personne->getId(), $vivante),
            ),
            // Le lieu tel que la fiche le montre — la fiche d'un vivant visible le garde même
            // quand sa date est masquée (`naissancePourFiche()`), on n'en montre pas plus.
            'lieuNaissance' => '' === $lieu ? null : $lieu,
            'criteres' => $ressemblance->criteres,
            'raisons' => $ressemblance->raisons,
            'score' => $ressemblance->score,
        ];
    }
}
