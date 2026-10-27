<?php

declare(strict_types=1);

namespace App\Module\Tree\Serializer;

use App\Module\Core\Security\CercleDeVisibiliteInterface;
use App\Module\Core\ValueObject\ComposantesDeDate;
use App\Module\Core\ValueObject\UncertainDate;
use App\Module\Tree\Entity\Person;

/**
 * Le tableau des personnes — § 5.5 ter du contrat : toutes les fiches que le lecteur a le
 * droit de lire, une ligne chacune, pour l'écran Explorer › Tableau (filtres, tri, export
 * CSV côté client).
 *
 * ═══════════════════════════════════════════════════════════════════════════════
 *  UN TABLEAU EST UNE ÉNUMÉRATION : IL SUIT LA RÈGLE DE LA RECHERCHE, PAS CELLE DU GRAPHE.
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Une personne vivante hors du cercle du lecteur **n'a pas de ligne** — ni réduite, ni
 * masquée : elle est absente, exactement comme dans {@see NormaliseurDeRecherche}. Une
 * ligne « Personne vivante » dirait combien de vivants on cache, et dans quelle branche.
 * Une personne hors du périmètre du lecteur (l'arbre d'une autre famille) est absente
 * elle aussi, défunte comprise ({@see CercleDuLecteur::connait()}).
 *
 * Il n'y a donc **qu'une forme de ligne**, la complète, et `total` se compte après
 * filtrage : il dit combien de lignes la réponse porte, rien de ce qui a été retiré.
 *
 * ## Les noms cités dans une ligne suivent le cercle
 *
 * Parents et conjoints sont des **références** : le même principe que sur la fiche
 * ({@see NormaliseurDePersonne::reference()}). Un vivant hors cercle cité comme parent
 * d'une personne visible garde sa place dans la liste, sous
 * {@see CercleDeVisibiliteInterface::NOM_MASQUE} ; un proche hors périmètre n'est pas cité.
 * `enfants` compte de la même façon : les enfants du périmètre, masqués compris (la fiche
 * les liste sous le masque), jamais ceux d'un autre arbre.
 *
 * ## La naissance d'un vivant passe par la réciprocité (ADR 0016)
 *
 * `naissance` et sa clé de tri ne portent que les composantes que le lecteur voit : une
 * année seule si seule l'année est partagée, rien si rien ne l'est. Le lieu reste — la
 * fiche le garde aussi dans un bloc masqué.
 */
final readonly class NormaliseurDuTableau
{
    public function __construct(
        private NormaliseurDeDate $dates,
        private ParenteDuLecteur $parente,
    ) {
    }

    /**
     * @param list<Person> $personnes
     * @param array{
     *     parents: array<int, list<int>>,
     *     conjoints: array<int, list<int>>,
     *     enfants: array<int, list<int>>,
     *     professions: array<int, list<string>>,
     * } $liens
     * @param array<int, true>|null $dansLaPortee `null` : toute la base ; sinon les identifiants
     *                                            de l'arbre du lecteur
     *
     * @return array{elements: list<array<string, mixed>>, total: int}
     */
    public function tableau(
        array $personnes,
        array $liens,
        LecteurDeLArbre $lecteur,
        CercleDuLecteur $cercle,
        EtatVivant $vivants,
        ?array $dansLaPortee = null,
    ): array {
        $parId = [];
        foreach ($personnes as $personne) {
            if (null !== $personne->getId()) {
                $parId[$personne->getId()] = $personne;
            }
        }

        $elements = [];

        foreach ($personnes as $personne) {
            $id = $personne->getId();

            if (null === $id || (null !== $dansLaPortee && !isset($dansLaPortee[$id]))) {
                continue;
            }

            $vivante = $vivants->estVivante($personne);

            if (!$cercle->connait($id) || !$cercle->voit($id, $vivante)) {
                // **Absente**, et non réduite : voir l'en-tête.
                continue;
            }

            $naissance = $cercle->naissance($id, $vivante);

            $elements[] = [
                'personId' => $id,
                'gedcomXref' => $personne->getGedcomXref(),
                'displayName' => $personne->getDisplayName(),
                'surname' => $personne->getSurname(),
                'givenNames' => $personne->getGivenNames(),
                'marriedName' => $personne->getMarriedName(),
                'nickname' => $personne->getNickname(),
                'sex' => $personne->getSex()->value,
                'vivant' => $vivante,
                'generation' => $personne->getGeneration(),
                'branch' => $personne->getBranch()?->value,
                'parente' => $this->parente->pour($lecteur, $id),
                'naissance' => $this->dates->compacte($personne->getBirth(), $naissance),
                'naissanceTri' => self::cleDeTri($personne->getBirth(), $naissance),
                'lieuNaissance' => $personne->getBirth()->getPlace(),
                'deces' => $this->dates->compacte($personne->getDeath()),
                'decesTri' => self::cleDeTri($personne->getDeath()),
                'lieuDeces' => $personne->getDeath()->getPlace(),
                'professions' => $liens['professions'][$id] ?? [],
                'parents' => $this->references($liens['parents'][$id] ?? [], $parId, $cercle, $vivants),
                'conjoints' => $this->references($liens['conjoints'][$id] ?? [], $parId, $cercle, $vivants),
                'enfants' => \count(array_filter(
                    $liens['enfants'][$id] ?? [],
                    static fn (int $enfant): bool => isset($parId[$enfant]) && $cercle->connait($enfant),
                )),
                'citationCount' => $personne->getCitationCount(),
                'bestQuay' => $personne->getBestQuay(),
                'createdAt' => $personne->getCreatedAt()->format(\DATE_ATOM),
                'updatedAt' => $personne->getUpdatedAt()->format(\DATE_ATOM),
            ];
        }

        return ['elements' => $elements, 'total' => \count($elements)];
    }

    /**
     * La clé de tri d'une date (`AAAA-MM-JJ`, sa borne basse) — **après** réduction aux
     * composantes permises : trier sur la vraie date d'un vivant dont on ne voit que
     * l'année rangerait les lignes dans l'ordre de leurs jours de naissance, c'est-à-dire
     * qu'on la lirait dans l'ordre du tableau.
     */
    private static function cleDeTri(UncertainDate $date, ?ComposantesDeDate $permises = null): ?string
    {
        $vue = null === $permises ? $date : $date->reduite($permises);

        return ($vue?->getMin() ?? $vue?->getMax())?->format('Y-m-d');
    }

    /**
     * @param list<int>          $ids
     * @param array<int, Person> $parId
     *
     * @return list<array{personId: int, displayName: string}>
     */
    private function references(array $ids, array $parId, CercleDuLecteur $cercle, EtatVivant $vivants): array
    {
        $sortie = [];

        foreach ($ids as $id) {
            $proche = $parId[$id] ?? null;

            if (null === $proche || !$cercle->connait($id)) {
                continue;
            }

            $sortie[] = [
                'personId' => $id,
                'displayName' => $cercle->voit($id, $vivants->estVivante($proche))
                    ? $proche->getDisplayName()
                    : CercleDeVisibiliteInterface::NOM_MASQUE,
            ];
        }

        return $sortie;
    }
}
