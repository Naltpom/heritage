<?php

declare(strict_types=1);

namespace App\Module\Core\Service;

/**
 * **La** proximité de deux noms dans ce projet : « le même nom, à une faute de frappe
 * près ». Une seule définition, partagée par trois lecteurs qui n'ont pas le droit de se
 * connaître (ADR 0002, règle 3) :
 *
 * | Lecteur | Ce qu'il en fait |
 * |---|---|
 * | `Membership\Service\NormalisationDeNom` | la recherche de rattachement d'un compte (§ 4.2) |
 * | `Tree\Serializer\NormaliseurDeRessemblance` | « cette personne est peut-être déjà dans l'arbre » |
 * | `Proposal\Service\RessemblanceDesPropositions` | « une proposition semblable attend déjà » |
 *
 * ## Pourquoi elle est remontée dans `Core`
 *
 * Elle vivait dans `NormalisationDeNom`, et la ressemblance en a eu besoin deux fois de
 * plus. La recopier aurait refait exactement la faute que {@see CleDeRecherche} existe pour
 * réparer : deux tables qui divergent d'un caractère, et un « Tilleule » que l'un des
 * écrans rapproche de « Tilleul » quand l'autre ne le voit pas — sans erreur, sans test
 * rouge. Même geste que pour `UncertainDate`, `Sex` et la clé de recherche : un outil
 * partagé par plusieurs modules appartient au noyau.
 *
 * ## Le seuil appartient à l'appelant, le défaut est ici
 *
 * La tolérance — **une** distance d'édition, et seulement à partir de cinq lettres — est
 * aujourd'hui la même partout. Mais pour la recherche de rattachement, elle est une
 * **décision de sécurité** : chaque cran de tolérance élargit ce qu'un porteur de jeton
 * peut faire correspondre, donc ce qu'il peut apprendre. C'est pourquoi
 * `NormalisationDeNom` garde sa propre constante et la passe à chaque appel : le jour où
 * l'on voudrait une ressemblance plus large dans l'arbre, on ne doit pas élargir au
 * passage ce qu'un inconnu muni d'une invitation peut sonder.
 *
 * ## `levenshtein()` ne voit que des clés repliées
 *
 * `levenshtein()` compte des **octets**, pas des caractères : sur « Génévrolle » brut, un
 * `é` vaut deux octets, et la distance entre « Génévrolle » et « Genevrolle » serait 4. On
 * ne lui passe donc jamais une chaîne venue de l'utilisateur : {@see self::proche()} la
 * replie d'abord par {@see CleDeRecherche::replier()}, qui rend `[A-Z0-9 ]` et rien
 * d'autre — un octet par caractère, par construction. Le repliement est idempotent, si
 * bien qu'un appelant qui passe une clé déjà repliée obtient exactement le même résultat.
 *
 * **Rien de ce qui sort d'ici n'est un affichage.** Une clé repliée — sans accent, en
 * majuscules — n'est jamais une façon acceptable d'écrire un nom à l'écran.
 */
final readonly class ProximiteDeNoms
{
    /**
     * En deçà de cinq lettres, une distance d'édition de 1 ne corrige pas une faute de
     * frappe : elle change le nom. `Thym` et `Thyn` sont deux familles, `Bon` et `Bou`
     * aussi. Au-delà, `Romarin` et `Romari` sont bien la même, et `Tilleul` et `Tilleule`
     * également — c'est le cas réel qui a fixé le seuil.
     */
    public const int LONGUEUR_MINIMALE_POUR_TOLERANCE = 5;

    /**
     * Défaut instancié : ce service est autowiré en production, mais construit par `new`
     * dans les tests et par `NormalisationDeNom`, dont la signature est restée celle
     * d'avant pour ne réécrire aucun appel.
     */
    public function __construct(private CleDeRecherche $repliement = new CleDeRecherche())
    {
    }

    /**
     * La clé de comparaison : `[A-Z0-9 ]`, accents repliés, ponctuation réduite.
     *
     * La casse n'a pas d'importance en soi — ce qui compte est que les **deux** côtés de
     * chaque comparaison passent par ici. C'est la seule propriété qui rende la
     * comparaison fiable, et c'est celle que `CleDeRecherche` garantit sur toutes les
     * machines.
     */
    public function normaliser(string $valeur): string
    {
        return $this->repliement->replier($valeur);
    }

    /** @return list<string> les jetons d'une suite de prénoms, repliés, vides retirés */
    public function jetons(string $valeur): array
    {
        $normalise = $this->normaliser($valeur);

        if ('' === $normalise) {
            return [];
        }

        return array_values(array_filter(explode(' ', $normalise), static fn (string $jeton): bool => '' !== $jeton));
    }

    /**
     * Les jetons d'une suite de prénoms **avec leur graphie d'origine** : `[clé repliée,
     * forme écrite]`, pour qu'un écran puisse dire « prénom Pyroxène en commun » plutôt
     * que « PYROXENE ».
     *
     * Le découpage d'origine se fait sur tout ce qui n'est ni lettre ni chiffre, exactement
     * là où {@see CleDeRecherche::replier()} pose ses espaces : « Jean-Marie » donne bien
     * deux jetons des deux côtés. Un mot dont le repliement se scinderait malgré tout
     * (un caractère hors table au milieu) garde sa forme écrite pour chacun de ses
     * morceaux — l'affichage reste lisible, la clé reste juste.
     *
     * @return list<array{0: string, 1: string}>
     */
    public function jetonsAvecGraphie(string $valeur): array
    {
        $mots = preg_split('/[^\p{L}\p{N}]+/u', $valeur, -1, \PREG_SPLIT_NO_EMPTY);

        if (false === $mots) {
            return [];
        }

        $jetons = [];

        foreach ($mots as $mot) {
            foreach ($this->jetons($mot) as $cle) {
                $jetons[] = [$cle, $mot];
            }
        }

        return $jetons;
    }

    /**
     * Deux valeurs désignent-elles le même nom, à une faute de frappe près ?
     *
     * Les deux côtés sont repliés ici, quoi que l'appelant ait passé : c'est ce qui
     * garantit que `levenshtein()` ne compte jamais d'octets d'accent.
     */
    public function proche(string $a, string $b, int $longueurMinimale = self::LONGUEUR_MINIMALE_POUR_TOLERANCE): bool
    {
        $a = $this->normaliser($a);
        $b = $this->normaliser($b);

        if ('' === $a || '' === $b) {
            return false;
        }

        if ($a === $b) {
            return true;
        }

        if (min(\strlen($a), \strlen($b)) < $longueurMinimale) {
            return false;
        }

        return levenshtein($a, $b) <= 1;
    }

    /**
     * Le premier couple de prénoms qui se répondent, ou `null`.
     *
     * **Un seul suffit, et c'est voulu.** L'état civil empile les prénoms — « Marie
     * Joséphine Augustine » — dont l'usage n'en retient qu'un, souvent pas le premier.
     * Exiger la suite complète refuserait précisément les personnes qu'on cherche.
     *
     * Un couple **identique** est préféré à un couple seulement proche, même s'il vient
     * plus loin dans la liste : « Marne Dolomite » contre « Marne Dolomie » doit dire
     * « Marne en commun », pas « Dolomite / Dolomie ».
     *
     * @param list<string> $soumis clés repliées (ou non : chaque comparaison replie)
     * @param list<string> $fiche  idem
     *
     * @return array{0: int, 1: int, 2: bool}|null les rangs du couple dans chaque liste,
     *                                              et `true` s'il est identique
     */
    public function prenomEnCommun(array $soumis, array $fiche, int $longueurMinimale = self::LONGUEUR_MINIMALE_POUR_TOLERANCE): ?array
    {
        // Repliés une fois pour toutes : la boucle compare chaque couple, et un repliement
        // par comparaison referait le même travail autant de fois qu'il y a de couples.
        $soumis = array_map($this->normaliser(...), $soumis);
        $fiche = array_map($this->normaliser(...), $fiche);
        $proche = null;

        foreach ($soumis as $i => $unSoumis) {
            foreach ($fiche as $j => $unFiche) {
                if ('' !== $unSoumis && $unSoumis === $unFiche) {
                    return [$i, $j, true];
                }

                if (null === $proche && $this->proche($unSoumis, $unFiche, $longueurMinimale)) {
                    $proche = [$i, $j, false];
                }
            }
        }

        return $proche;
    }
}
