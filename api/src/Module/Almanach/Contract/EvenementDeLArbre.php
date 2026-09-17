<?php

declare(strict_types=1);

namespace App\Module\Almanach\Contract;

use App\Module\Core\ValueObject\UncertainDate;

/**
 * Un événement daté de l'arbre, dans la forme qu'`Almanach` sait rendre — une naissance,
 * un décès, un mariage, un divorce ou un `tree_fact`.
 *
 * ## `cle` : `"<origine>:<id>"`, unique **par construction**
 *
 * `naissance:17`, `deces:17`, `mariage:42`, `divorce:42`, `fait:88`. Le préfixe vient de
 * {@see TypeDEvenement::origine()}, l'identifiant est celui de la ligne d'où la date est
 * lue — `tree_person.id`, `tree_family.id`, `tree_fact.id`.
 *
 * L'unicité n'est pas une convention qu'on se promet de tenir : elle **tombe de la
 * forme**. Deux événements de même origine ont deux identifiants de ligne différents ;
 * deux événements de même identifiant ont deux origines différentes. C'est exactement ce
 * qui manquait à `Timeline`, dont le jalon est identifié par un `personId` — d'où sa
 * contrainte de n'admettre que les faits de sujet `person`
 * (`src/Module/Timeline/Contract/FaitDeLaChronologie.php`, § « Pourquoi seulement les
 * faits de sujet `person` ») :
 *
 * > lui en inventer un — le premier époux, par exemple — serait attribuer à quelqu'un un
 * > événement qui ne le nomme pas.
 *
 * **Cette clé n'invente aucun `personId`.** Elle nomme une ligne, pas une personne, et
 * c'est ce qui laisse passer les 103 unions et les faits de famille.
 *
 * ## `type` est l'**énumération**, jamais sa valeur de *backing*
 *
 * `Timeline` transporte un `string` parce que `FactType` vit dans `Tree\Enum\`,
 * c'est-à-dire dans l'interne de `Tree` : le typer l'obligerait à un `use` illicite. C'est
 * une contrainte subie, et son en-tête le dit.
 *
 * **Ici on n'a pas cette excuse** : {@see TypeDEvenement} est déclarée par `Almanach`,
 * dans son propre `Contract/`. Transporter `$type->value` serait donc perdre un type pour
 * rien — et l'ADR 0002 nomme précisément ce geste comme celui qui rend un seuil
 * inatteignable par construction :
 *
 * > Un port qui rendrait la **valeur de *backing*** d'une énumération au lieu de
 * > l'énumération permettrait à son appelant d'en avoir besoin sans jamais l'écrire.
 *
 * ## `date` reste une `UncertainDate`, **jamais aplatie**
 *
 * Le port ne décide pas de l'année, ni du jour. C'est la consigne de l'en-tête
 * d'`UncertainDate` — « le calcul doit être visible à l'endroit où il est fait, pas caché
 * dans un accesseur qu'on appellerait sans y penser » — et elle a ici deux conséquences
 * concrètes : la carte lit `getMin() ?? getMax()` en clair dans son normaliseur, et le
 * calendrier passe par {@see \App\Module\Almanach\Service\JourAnniversaire}, qui refuse
 * tout ce qui n'est pas une date exacte.
 *
 * Son `getPlace()` porte le `PLAC` verbatim. **Le port ne résout pas le lieu** : il
 * transporte la chaîne, et le rapprochement avec {@see LieuResolu} se fait au rendu.
 *
 * ## `sujets` : de un à deux, et **jamais zéro**
 *
 * Un pour une personne, deux pour une union dont les deux époux sont connus, un pour les
 * 3 familles qui n'ont qu'un époux. La liste vide est **exprimable** dans ce type — un
 * `array` n'a pas de cardinalité minimale — et c'est pourquoi
 * {@see \App\Module\Almanach\Service\FluxVisible} la traite comme un refus : un événement
 * sans sujet ne peut pas avoir été autorisé par un cercle, donc il ne sort pas.
 *
 * ## `familyId`, et pourquoi il n'est pas relu dans `cle`
 *
 * L'union de la clé `mariage:42` porte `familyId = 42`, et un fait de famille porte le
 * sien alors que sa clé est `fait:88`. On pourrait redécouper la chaîne ; ce serait
 * reconstruire par analyse de texte une donnée qu'on avait, et le cas du fait de famille
 * montre que la reconstruction serait **fausse**. Il est donc transporté tel quel.
 */
final readonly class EvenementDeLArbre
{
    /**
     * @param string                  $cle      `"<origine>:<id>"` — voir l'en-tête
     * @param list<SujetDeLEvenement> $sujets   1 pour une personne, 1–2 pour une union
     * @param UncertainDate           $date     jamais aplatie ; son `place` porte le `PLAC`
     * @param string|null             $libelle  la valeur textuelle d'un fait (« Tisserand »)
     * @param int|null                $familyId `tree_family.id` pour une union ou un fait de famille
     */
    public function __construct(
        public string $cle,
        public TypeDEvenement $type,
        public array $sujets,
        public UncertainDate $date,
        public ?string $libelle = null,
        public ?int $familyId = null,
    ) {
    }

    /**
     * Les identifiants de ses sujets — ce que {@see
     * \App\Module\Core\Security\CercleDeVisibiliteInterface::filtrerPersonnes()} attend.
     *
     * @return list<int>
     */
    public function identifiantsDesSujets(): array
    {
        return array_map(static fn (SujetDeLEvenement $sujet): int => $sujet->personId, $this->sujets);
    }

    public function genre(): GenreDEvenement
    {
        return $this->type->genre();
    }
}
