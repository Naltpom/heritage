<?php

declare(strict_types=1);

namespace App\Module\Tree\Serializer;

use App\Module\Core\Enum\CoteDeRecherche;
use App\Module\Core\Security\CercleDeVisibiliteInterface;
use App\Module\Core\ValueObject\ComposantesDeDate;
use App\Module\Core\ValueObject\UncertainDate;
use App\Module\Tree\Entity\Child;
use App\Module\Tree\Entity\Family;
use App\Module\Tree\Entity\Person;
use App\Module\Tree\Entity\ResearchClosure;
use App\Module\Tree\Enum\ChildRelation;
use App\Module\Tree\Service\ReglagesDeLArbre;

/**
 * L'arbre d'une **affiche** : un couple, toute son ascendance connue, et sa descendance sur
 * six générations — § 5.4 bis du contrat.
 *
 * L'affiche se dessine dans le navigateur (`app/src/affiche/`). Le graphe ne suffit pas à la
 * nourrir : il ne porte que l'étiquette mesurée (« Marne Sureau », le premier prénom) et une
 * ligne de dates compacte, ni les prénoms entiers, ni les dates en clair, ni les mariages. Et
 * lire une fiche par personne, c'est deux cents requêtes pour une affiche. D'où cette route,
 * qui sert en une fois **l'extrait du graphe** dont l'affiche a besoin, avec ce que les fiches
 * en diraient.
 *
 * ## Elle ne montre rien de plus que les fiches et le graphe
 *
 * C'est la règle qui décide de chaque clé, et elle s'applique ici avec les mêmes outils :
 *
 * - **le périmètre** ({@see CercleDuLecteur::connait()}) : une personne d'un autre arbre
 *   n'est pas servie du tout, et la remontée s'arrête à elle ;
 * - **le cercle** ({@see CercleDuLecteur::voit()}) : un vivant hors cercle — une fiche
 *   confidentielle, pour un membre — figure **à sa place**, comme son nœud dans le graphe,
 *   sous le masque, avec son sexe et rien d'autre (cinq clés, une liste blanche littérale) ;
 * - **la réciprocité** (ADR 0016) : la naissance d'un vivant visible ne sort que réduite aux
 *   composantes que le lecteur obtient, par {@see UncertainDate::reduite()} — le seul endroit
 *   où une date se dégrade —, ou en bloc `masquee` quand l'année n'est pas partagée ;
 * - **les dates d'union restent visibles**, y compris quand un époux est masqué : elles
 *   appartiennent à la famille (§ 5.4).
 *
 * L'option « masquer les dates des vivants » de l'affiche n'est **pas** un filtre de
 * confidentialité : c'est une mise en page, appliquée au dessin, sur des dates que le lecteur
 * voit déjà sur les fiches. Elle vit donc dans le front.
 *
 * ## Deux choix faits ici plutôt qu'au front, et pourquoi
 *
 * 1. **La filiation retenue** d'un ancêtre qui en a plusieurs : `naissance` d'abord, puis la
 *    famille au plus petit identifiant — la règle de l'affiche d'origine (`commun.py`). Elle
 *    est prise ici parce que remonter les autres filiations ferait sortir des familles que
 *    l'affiche ne dessine pas.
 * 2. **L'ordre des enfants** : naissance, puis rang dans la fratrie, puis identifiant. Le
 *    front ne peut pas le calculer : la naissance d'un vivant peut lui arriver réduite, et
 *    un enfant sans date passerait en dernier. L'ordre relatif d'une fratrie est déjà lisible
 *    dans la topologie du graphe (ADR 0016, « Conséquences ») : il n'apprend rien de plus.
 *
 * ## Ce qu'elle ne sert pas
 *
 * Ni les fiches proposées (le calque) : une affiche se tire de l'arbre validé. Ni les
 * autres unions des ancêtres, ni celles des conjoints des descendants : l'affiche ne les
 * dessine pas.
 */
final readonly class NormaliseurDAffiche
{
    /**
     * Les générations de descendants servies : les enfants du couple sont la première, et
     * la sixième n'a plus d'unions. C'est la profondeur de l'affiche d'origine
     * (`descendance(…, niveaux=6)`), et le front la relit dans la charge.
     */
    public const int GENERATIONS_DE_DESCENDANCE = 6;

    public function __construct(
        private NormaliseurDeDate $dates,
        private LectureDeLArbre $lecture,
        private ReglagesDeLArbre $reglages,
    ) {
    }

    /**
     * `signature` : le nom écrit au pied (`tree.signature_affiches`), `null` s'il n'est pas réglé.
     *
     * @return array{famille: int|null, generationsDeDescendance: int, signature: string|null, personnes: list<array<string, mixed>>, familles: list<array<string, mixed>>}
     */
    public function affiche(Family $couple, CercleDuLecteur $cercle, EtatVivant $vivants): array
    {
        /** @var array<int, Person> $personnes */
        $personnes = [];
        /** @var array<int, Family> $familles */
        $familles = [];
        /** @var array<int, array<int, Child>> $enfants les lignes `tree_child` servies, par famille puis par personne */
        $enfants = [];

        $connue = static fn (?Person $p): bool => null !== $p && null !== $p->getId() && $cercle->connait($p->getId());

        // ── L'ascendance : génération par génération, jusqu'au bout de ce que l'arbre sait.
        //
        // Une personne n'est remontée qu'une fois, à sa première rencontre : un implexe ne
        // fait pas recharger deux fois la même branche, et une boucle dans les données ne
        // fait pas tourner la remontée sans fin.
        $generation = [];
        foreach ([$couple->getHusband(), $couple->getWife()] as $epoux) {
            if ($connue($epoux)) {
                \assert(null !== $epoux && null !== $epoux->getId());
                $personnes[$epoux->getId()] = $epoux;
                $generation[] = $epoux;
            }
        }

        while ([] !== $generation) {
            $suivante = [];

            foreach ($this->filiationsRetenues($this->lecture->filiationsDesPersonnes($generation)) as $ligne) {
                $famille = $ligne->getFamily();
                $familleId = $famille->getId();
                $enfantId = $ligne->getPerson()->getId();
                \assert(null !== $familleId && null !== $enfantId);

                $familles[$familleId] = $famille;
                $enfants[$familleId][$enfantId] = $ligne;

                foreach ([$famille->getHusband(), $famille->getWife()] as $parent) {
                    if (!$connue($parent)) {
                        continue;
                    }
                    \assert(null !== $parent && null !== $parent->getId());
                    if (!isset($personnes[$parent->getId()])) {
                        $personnes[$parent->getId()] = $parent;
                        $suivante[] = $parent;
                    }
                }
            }

            $generation = $suivante;
        }

        // ── La descendance : les enfants du couple, leurs unions, les enfants de celles-ci…
        //
        // Une famille déjà servie ne se reparcourt pas : c'est le garde-fou des boucles.
        $coupleId = $couple->getId();
        \assert(null !== $coupleId);
        $familles[$coupleId] = $couple;
        $niveau = [$couple];

        for ($rang = 1; [] !== $niveau && $rang <= self::GENERATIONS_DE_DESCENDANCE; ++$rang) {
            $lignes = $this->lecture->enfantsDe($niveau);
            $descendants = [];

            foreach ($niveau as $famille) {
                $familleId = $famille->getId();
                \assert(null !== $familleId);

                foreach ($lignes[$familleId] ?? [] as $ligne) {
                    $enfant = $ligne->getPerson();
                    if (!$connue($enfant)) {
                        continue;
                    }
                    \assert(null !== $enfant->getId());
                    $enfants[$familleId][$enfant->getId()] = $ligne;
                    $personnes[$enfant->getId()] ??= $enfant;
                    $descendants[$enfant->getId()] = $enfant;
                }
            }

            // La dernière génération servie n'a pas d'unions : l'affiche s'y arrête.
            if ($rang === self::GENERATIONS_DE_DESCENDANCE) {
                break;
            }

            $niveau = [];
            foreach ($this->lecture->unionsDesPersonnes(array_values($descendants)) as $union) {
                $unionId = $union->getId();
                \assert(null !== $unionId);
                if (isset($familles[$unionId])) {
                    continue;
                }
                $familles[$unionId] = $union;
                $niveau[] = $union;

                foreach ([$union->getHusband(), $union->getWife()] as $conjoint) {
                    if ($connue($conjoint)) {
                        \assert(null !== $conjoint && null !== $conjoint->getId());
                        $personnes[$conjoint->getId()] ??= $conjoint;
                    }
                }
            }
        }

        ksort($personnes);
        ksort($familles);

        // Les fins de recherche de toute l'affiche, en une requête (ADR 0023).
        $fins = $this->lecture->finsDeRechercheDes(array_keys($personnes));

        $sortiePersonnes = [];
        foreach ($personnes as $personne) {
            $sortiePersonnes[] = $this->personne($personne, $cercle, $vivants, $fins[(int) $personne->getId()] ?? []);
        }

        $sortieFamilles = [];
        foreach ($familles as $familleId => $famille) {
            $sortieFamilles[] = $this->famille($famille, $enfants[$familleId] ?? [], $personnes);
        }

        return [
            'famille' => $coupleId,
            'generationsDeDescendance' => self::GENERATIONS_DE_DESCENDANCE,
            'signature' => $this->reglages->signatureDesAffiches(),
            'personnes' => $sortiePersonnes,
            'familles' => $sortieFamilles,
        ];
    }

    /**
     * Une filiation par personne : `naissance` d'abord, puis la famille au plus petit
     * identifiant — `_famille_parents.setdefault()` de l'affiche d'origine, sur des lignes
     * triées par `(relation != 'naissance', famille)`.
     *
     * @param list<Child> $lignes
     *
     * @return list<Child>
     */
    private function filiationsRetenues(array $lignes): array
    {
        $retenues = [];

        foreach ($lignes as $ligne) {
            $personneId = $ligne->getPerson()->getId();
            if (null === $personneId) {
                continue;
            }
            $actuelle = $retenues[$personneId] ?? null;
            if (null === $actuelle || $this->cleDeFiliation($ligne) < $this->cleDeFiliation($actuelle)) {
                $retenues[$personneId] = $ligne;
            }
        }

        return array_values($retenues);
    }

    /** @return array{int, int} */
    private function cleDeFiliation(Child $ligne): array
    {
        return [ChildRelation::Naissance === $ligne->getRelation() ? 0 : 1, (int) $ligne->getFamily()->getId()];
    }

    /**
     * Une personne de l'affiche : complète, ou réduite à **cinq clés** si le cercle ne la
     * montre pas — liste blanche littérale, écrite à part, jamais une forme complète dont on
     * retirerait des clés (même règle que {@see NormaliseurDePersonne}).
     *
     * @param array{pere?: ResearchClosure, mere?: ResearchClosure} $fins les fins de recherche de la personne, lues en lot
     *
     * @return array<string, mixed>
     */
    private function personne(Person $personne, CercleDuLecteur $cercle, EtatVivant $vivants, array $fins = []): array
    {
        $id = $personne->getId();
        $vivante = $vivants->estVivante($personne);

        if (!$cercle->voit($id, $vivante)) {
            return [
                'id' => $id,
                'visibilite' => NiveauDeVisibilite::Restreinte->value,
                'displayName' => CercleDeVisibiliteInterface::NOM_MASQUE,
                'sex' => $personne->getSex()->value,
                // Toujours `true` : une personne décédée n'atteint jamais cette branche.
                'vivant' => true,
            ];
        }

        $complete = [
            'id' => $id,
            'visibilite' => NiveauDeVisibilite::Complete->value,
            'givenNames' => $personne->getGivenNames(),
            'surname' => $personne->getSurname(),
            'sex' => $personne->getSex()->value,
            // `deceased` est stocké, `vivant` calculé (§ 9.2) : l'affiche lit `vivant` pour
            // masquer les dates, et `deceased` pour la croix d'un défunt sans date de décès.
            'deceased' => $personne->isDeceased(),
            'vivant' => $vivante,
            'birth' => $this->naissance($personne->getBirth(), $cercle->naissance($id, $vivante)),
            'death' => $this->dates->pourFiche($personne->getDeath()),
        ];

        // Le résumé `{reason, beforeYear}`, **absent** quand le côté n'est pas clos, et
        // jamais sur une personne restreinte — la règle des notes (ADR 0023).
        foreach (CoteDeRecherche::cases() as $cote) {
            $ligne = $fins[$cote->value] ?? null;

            if (null !== $ligne) {
                $complete[$cote->champ()] = ['reason' => $ligne->getReason()->value, 'beforeYear' => $ligne->getBeforeYear()];
            }
        }

        return $complete;
    }

    /**
     * La naissance, à travers la réciprocité (ADR 0016) — sous la forme que l'affiche sait
     * dessiner.
     *
     * - **tout permis** : le bloc ordinaire, `raw` compris ;
     * - **l'année permise** : la date **réduite** ({@see UncertainDate::reduite()}), sans `raw`,
     *   bornes élargies à l'année ou au mois, qualificatif gardé — « vers 1934 » reste un
     *   « vers ». C'est la forme que lisent déjà les statistiques et la chronologie, et elle
     *   ne dit rien de plus que le `texte` de la fiche. Le bloc `masquee` de la fiche ne
     *   suffirait pas : ses `composantes` ne portent que le **certain**, et « vers 1934 » n'y
     *   laisse rien ;
     * - **sans l'année** : le bloc `masquee` de la fiche, tel quel — émis même quand la date
     *   est inconnue, pour qu'« inconnue » et « non partagée » ne se distinguent pas.
     *
     * @return array<string, mixed>
     */
    private function naissance(UncertainDate $date, ComposantesDeDate $permises): array
    {
        $reduite = $date->reduite($permises);

        return null !== $reduite
            ? $this->dates->pourFiche($reduite)
            : $this->dates->naissancePourFiche($date, $permises);
    }

    /**
     * Une famille de l'affiche. Ses époux et ses enfants sont désignés par identifiant, et
     * **seulement s'ils sont servis** : la charge est close, aucun identifiant n'y renvoie à
     * une personne qu'elle ne porte pas.
     *
     * `divorced` et `divorce` font dire au trait d'union comment l'union a fini (« div. »,
     * « sép. ») ; l'affiche n'écrit jamais la date du divorce, mais la fiche la montre, et la
     * charge la porte pour la même raison que le mariage.
     *
     * @param array<int, Child>  $lignes    les enfants servis de cette famille
     * @param array<int, Person> $personnes les personnes servies
     *
     * @return array<string, mixed>
     */
    private function famille(Family $famille, array $lignes, array $personnes): array
    {
        $servie = static fn (?Person $p): ?int => null !== $p && null !== $p->getId() && isset($personnes[$p->getId()]) ? $p->getId() : null;

        $lignes = array_values($lignes);
        usort($lignes, $this->ordreDesEnfants(...));

        $enfants = [];
        foreach ($lignes as $ligne) {
            $enfants[] = [
                'personId' => $ligne->getPerson()->getId(),
                'relation' => $ligne->getRelation()->value,
            ];
        }

        return [
            'id' => $famille->getId(),
            'husband' => $servie($famille->getHusband()),
            'wife' => $servie($famille->getWife()),
            'marriage' => $this->dates->pourFiche($famille->getMarriage()),
            // La nature de l'union (ADR 0020), comme sur la fiche d'une famille : émise même
            // à `null` — « on ne sait pas » —, et toujours `true` sous une date ou un lieu de
            // mariage. Elle est à la famille comme ses dates : visible, époux masqué ou non.
            'married' => $famille->isMarried(),
            'divorced' => $famille->isDivorced(),
            'divorce' => $famille->isDivorced() || $famille->getDivorce()->isKnown()
                ? $this->dates->pourFiche($famille->getDivorce())
                : null,
            'enfants' => $enfants,
        ];
    }

    /**
     * L'ordre de l'affiche d'origine : `(naissance.tri or '9999', rang, identifiant)`, où
     * `tri` est la borne basse de la naissance, sinon la haute. Sur la **vraie** date : voir
     * l'en-tête de la classe.
     */
    private function ordreDesEnfants(Child $a, Child $b): int
    {
        return [$this->tri($a->getPerson()), $a->getSiblingOrder(), (int) $a->getPerson()->getId()]
            <=> [$this->tri($b->getPerson()), $b->getSiblingOrder(), (int) $b->getPerson()->getId()];
    }

    private function tri(Person $personne): string
    {
        $naissance = $personne->getBirth();

        return ($naissance->getMin() ?? $naissance->getMax())?->format('Y-m-d') ?? '9999';
    }
}
