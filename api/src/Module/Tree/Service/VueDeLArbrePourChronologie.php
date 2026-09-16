<?php

declare(strict_types=1);

namespace App\Module\Tree\Service;

use App\Module\Timeline\Contract\ArbreDeLaChronologie;
use App\Module\Timeline\Contract\FaitDeLaChronologie;
use App\Module\Timeline\Contract\PersonneDeLaChronologie;
use App\Module\Timeline\Contract\PortChronologieArbre;
use App\Module\Tree\Entity\Fact;
use App\Module\Tree\Entity\Person;
use Doctrine\ORM\EntityManagerInterface;

/**
 * L'implémentation, côté `Tree`, de {@see PortChronologieArbre} — ce que la vue
 * chronologique lit de l'arbre pour dessiner ses barres et ses jalons.
 *
 * C'est une **traduction, pas un calcul**, exactement comme
 * {@see VueDeLArbrePourMembership} dont ce fichier est le jumeau. Les deux choses
 * demandées existent déjà : les personnes dans `tree_person`, l'état vivant dans
 * {@see ReglePersonneVivante}, les faits datés dans `tree_fact`. Ce fichier les met dans
 * le vocabulaire de `Timeline`, et rien d'autre.
 *
 * ## La règle qu'il ne faut surtout pas « améliorer »
 *
 * **L'arbre rendu est COMPLET, sans aucun filtre de confidentialité.** C'est
 * contre-intuitif pour un port qui alimente une vue soumise au cercle, et c'est pourtant
 * la seule forme juste : le filtrage a lieu **une seule fois, dans la sérialisation**
 * (§ 6.2 et § 8 du dossier de mission), chez `Timeline\Service\NormaliseurDeChronologie`.
 * Filtrer ici priverait au passage la bascule « voir comme un membre » de son objet, qui
 * suppose un jeu de départ complet.
 *
 * ## Les dates traversent en `UncertainDate`, jamais en années
 *
 * Le port pourrait rendre `?int $anneeNaissance` ; ce serait l'aplatissement que le § 4 du
 * dossier interdit. Marne Dolomite Sureau porte `BET 1901 AND 1909`, et sa fourchette doit
 * arriver intacte jusqu'à `{min: 1901, max: 1909, certain: false}`. **Ne pas introduire
 * ici de conversion en année** : `Timeline` la fait, une seule fois, en clair, dans son
 * `IntervalleDAnnees`.
 *
 * ## L'état vivant passe par `ReglePersonneVivante`, jamais par `deceased`
 *
 * `tree_person.deceased` dit seulement qu'un `DEAT` figurait dans le GEDCOM — 78 personnes
 * n'en ont aucun. « Vivant » est la propriété **calculée** du § 9.2, avec son seuil et sa
 * médiane par génération, et *« un décès inconnu vaut vivant, donc masqué »*. Il n'existe
 * qu'une définition de « vivant » dans le produit ; une seconde, écrite ici, déciderait un
 * jour autrement de ce que la sérialisation masque.
 *
 * ## Les faits de famille sont hors du port
 *
 * `tree_fact` admet exactement un sujet (`ck_tree_fact_sujet_unique`). Le § 9.1 fait de
 * `personId` une clé obligatoire du jalon : un fait de famille n'a pas de `personId` à y
 * mettre, et lui attribuer le premier époux serait imputer à quelqu'un un événement qui ne
 * le nomme pas. Le `WHERE f.person IS NOT NULL` ci-dessous est donc une décision, pas une
 * commodité de requête.
 *
 * ## Mémorisé pour la durée de la requête
 *
 * Une seule requête HTTP peut sérialiser la vue une fois ; la mémorisation est là pour le
 * jour où elle le ferait deux fois, et pour que le coût reste une lecture quoi qu'il
 * arrive.
 */
final class VueDeLArbrePourChronologie implements PortChronologieArbre
{
    private ?ArbreDeLaChronologie $memoire = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ReglePersonneVivante $regleVivante,
        private readonly ReglagesDeLArbre $reglages,
        private readonly EtiquetteDeDateGedcom $dates = new EtiquetteDeDateGedcom(),
    ) {
    }

    public function vue(): ArbreDeLaChronologie
    {
        return $this->memoire ??= $this->charger();
    }

    private function charger(): ArbreDeLaChronologie
    {
        $personnes = $this->chargeLesPersonnes();

        // Toute la population d'un coup : le repli du § 9.2 passe par la médiane des
        // années de naissance de chaque génération, qui est un calcul global.
        $vivants = $this->regleVivante->pourTous(
            $personnes,
            $this->reglages->seuilVivantAnnees(),
            new \DateTimeImmutable(),
        );

        $traduites = [];

        foreach ($personnes as $personne) {
            $id = $personne->getId();

            if (null === $id) {
                continue;
            }

            $traduites[] = new PersonneDeLaChronologie(
                id: $id,
                displayName: $personne->getDisplayName(),
                branch: $personne->getBranch(),
                generation: $personne->getGeneration(),
                sex: $personne->getSex(),
                // « Un décès inconnu vaut vivant, donc masqué » : c'est la règle qui
                // tranche, jamais l'absence de date lue ici.
                vivant: $vivants[$id] ?? true,
                birth: $personne->getBirth(),
                death: $personne->getDeath(),
                // La forme **compacte** du § 14.4, composée ici parce que sa grammaire
                // appartient à `Tree` : `1901-1909`, `26.9.2012`, `~1889`, `<1920`. On la
                // fait traverser plutôt que de laisser `Timeline` la réécrire — deux
                // portages d'une même grammaire de sept cas divergent en silence.
                //
                // On ne compose PAS la ligne entière ici : l'assemblage (`n. `, `† `, le
                // demi-cadratin, `—`) diffère entre `ligneDates` et `etiquetteDates`, et
                // c'est l'appelant qui sait laquelle des deux il sert.
                etiquetteNaissance: $this->dates->etiquette($personne->getBirth()),
                etiquetteDeces: $this->dates->etiquette($personne->getDeath()),
            );
        }

        return new ArbreDeLaChronologie($traduites, $this->chargeLesFaits());
    }

    /** @return list<Person> */
    private function chargeLesPersonnes(): array
    {
        /** @var list<Person> $personnes */
        $personnes = $this->em->createQueryBuilder()
            ->select('p')
            ->from(Person::class, 'p')
            ->orderBy('p.id', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();

        return $personnes;
    }

    /**
     * Les faits datés de sujet `person`.
     *
     * L'ordre reprend celui de `idx_tree_fact_person` — la date puis `sort_order` —, qui
     * est déjà l'ordre attendu à l'écran pour la frise d'une fiche. Le rendre ici évite au
     * front de retrier ce que la base sait trier.
     *
     * La jointure sur `f.person` est explicite : sans elle, chaque `getPerson()->getId()`
     * déclencherait une requête — 87 lignes dans `tree_fact`, recomptées, donc autant
     * d'allers-retours pour une seule réponse.
     *
     * @return list<FaitDeLaChronologie>
     */
    private function chargeLesFaits(): array
    {
        /** @var list<Fact> $faits */
        $faits = $this->em->createQueryBuilder()
            ->select('f', 'p')
            ->from(Fact::class, 'f')
            ->innerJoin('f.person', 'p')
            ->where('f.happened.min IS NOT NULL OR f.happened.max IS NOT NULL')
            ->orderBy('f.happened.min', \SortDirection::Ascending)
            ->addOrderBy('f.sortOrder', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();

        $traduits = [];

        foreach ($faits as $fait) {
            $personneId = $fait->getPerson()?->getId();

            if (null === $personneId) {
                continue;
            }

            $traduits[] = new FaitDeLaChronologie(
                personId: $personneId,
                type: $fait->getType()->value,
                typeLabel: $fait->getType()->label(),
                label: $fait->getLabel(),
                happened: $fait->getHappened(),
            );
        }

        return $traduits;
    }
}
