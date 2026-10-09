<?php

declare(strict_types=1);

namespace App\Module\Core\ValueObject;

use App\Module\Core\Enum\Sex;

/**
 * Une identité **telle qu'on a le droit de la comparer** — d'un côté ce qu'un membre est
 * en train de saisir, de l'autre une fiche de l'arbre ou une fiche proposée.
 *
 * ## `null` veut dire « non comparable », et c'est toute la sécurité de cet objet
 *
 * Ce n'est pas « inconnu » : c'est « on ne te dit rien là-dessus ». Une naissance à `null`
 * n'exclut aucune fiche, ne rapporte aucun point et ne produit aucune raison
 * ({@see \App\Module\Core\Service\ComparaisonDIdentites}). C'est ce qui empêche la
 * ressemblance de devenir un **oracle de date de naissance** : si l'on comparait la
 * naissance entière d'un vivant dont le lecteur ne voit pas l'année, il suffirait de
 * saisir 1934, puis 1935, puis 1936, et de regarder quand la fiche disparaît.
 *
 * C'est donc **à celui qui construit l'objet** de n'y mettre que ce que le lecteur voit :
 *
 * - `Tree` y met, pour un vivant, la naissance **réduite** aux composantes que la
 *   réciprocité accorde (`UncertainDate::reduite()`, ADR 0016) — et `null` pour la date
 *   quand l'année même n'est pas vue (voir {@see self::$naissance}) ;
 * - `Proposal` y met, pour la proposition d'un vivant faite par un autre membre, **ni
 *   date ni lieu** ({@see self::sansDatesNiLieux()}) : seuls le nom, les prénoms et le
 *   sexe se comparent.
 *
 * ## Les dates portent leur lieu
 *
 * Le lieu de naissance est le `place` du bloc naissance, comme partout dans le modèle
 * (§ 0.5). Une date dont on ne peut rien dire mais dont le lieu est visible s'écrit donc
 * `UncertainDate::inconnue($lieu)` : la date ne se compare pas (elle n'est pas connue), le
 * lieu si. C'est le cas d'un vivant dont la naissance n'est pas partagée — sa fiche montre
 * le lieu (`naissancePourFiche()` le conserve dans le bloc masqué), on peut donc le
 * comparer sans rien apprendre de plus.
 */
final readonly class IdentiteComparee
{
    /**
     * @param string             $prenoms   la suite des prénoms, telle qu'écrite ; `''` = aucun
     * @param string             $nom       le nom de naissance, tel qu'écrit ; `''` = aucun
     * @param string|null        $nomDUsage le nom d'usage (`marriedName`)
     * @param string|null        $surnom    le surnom (`nickname`), comparé avec les prénoms :
     *                                      c'est lui qu'affiche une fiche qui en porte un
     * @param Sex                $sexe      `Inconnu` = non comparable
     * @param UncertainDate|null $naissance `null` = ni la date ni le lieu ne se comparent ;
     *                                      une date `inconnue` = seul son lieu se compare
     * @param UncertainDate|null $deces     même règle
     */
    public function __construct(
        public string $prenoms = '',
        public string $nom = '',
        public ?string $nomDUsage = null,
        public ?string $surnom = null,
        public Sex $sexe = Sex::Inconnu,
        public ?UncertainDate $naissance = null,
        public ?UncertainDate $deces = null,
    ) {
    }

    /**
     * La même identité, **réduite au nom, aux prénoms et au sexe** : la forme discrète des
     * propositions (§ 6.6 quater du contrat). Ni date ni lieu ne passent — ni ceux de la
     * fiche, ni ceux de la saisie, pour qu'aucune réponse ne dépende de ce qu'on a tapé
     * dans le champ « naissance ».
     */
    public function sansDatesNiLieux(): self
    {
        return new self($this->prenoms, $this->nom, $this->nomDUsage, $this->surnom, $this->sexe);
    }
}
