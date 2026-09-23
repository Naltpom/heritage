<?php

declare(strict_types=1);

namespace App\Module\Question\Contract;

use App\Module\Core\ValueObject\UncertainDate;

/**
 * Une fiche de l'arbre, **telle que ce module a besoin de la lire** — et rien de plus.
 *
 * Objet valeur, sans entité, sans dépôt et sans comportement : c'est ce qui traverse
 * {@see PortArbrePourQuestions}, et un contrat qui transporterait `Tree\Person`
 * rouvrirait la frontière que l'ADR 0002 ferme.
 *
 * ## Un seul objet pour deux usages, et c'est délibéré
 *
 * Il sert à la fois à **détecter les lacunes** (des booléens suffiraient) et à
 * **composer le corpus** envoyé au modèle de langage (qui veut les noms, les dates,
 * les lieux et la parenté). Deux objets distincts auraient été plus étroits ; ils
 * auraient aussi produit **deux lectures de l'arbre** là où une suffit, et surtout deux
 * définitions de « ce qu'on sait d'une personne » qui auraient divergé. La seconde est
 * le vrai coût : le jour où la détection dit « le lieu de décès manque » et où le corpus
 * envoie un lieu de décès, la question posée est fausse et personne ne comprend pourquoi.
 *
 * ## `vivante` vient de la règle, jamais de la colonne
 *
 * `tree_person.deceased` est une donnée d'import, et le § 9.2 du modèle définit l'état
 * vivant par une **règle** — seuil d'années, médiane des années de naissance par
 * génération. L'adaptateur, chez `Tree`, renseigne ce booléen depuis
 * `ReglePersonneVivante` et depuis elle seule. Lire la colonne à la place ferait sortir
 * des dates de personnes vivantes dont le décès n'a jamais été saisi — et « on ne sait
 * pas si elle est morte » n'est pas une autorisation de publier ses dates.
 *
 * ## Les dates traversent entières
 *
 * `UncertainDate` et non une année : un bloc date porte cinq valeurs solidaires, et
 * l'aplatir au bord du port referait exactement le défaut que le § 9.1 du modèle
 * interdit. C'est aussi ce qui permet à la détection de distinguer « aucune borne » —
 * la lacune — de « entre 1901 et 1909 », qui n'en est pas une.
 *
 * ## Ce qui n'y est pas : le compte des médias
 *
 * La lacune « aucune photo » a pourtant besoin de lui. Il n'est pas ici parce qu'il ne
 * vient pas d'ici : `med_media` appartient à `Media`, et `Tree` n'a pas le droit de le
 * lire (règle 5 de l'ADR 0002). Le glisser dans cet objet aurait obligé l'adaptateur de
 * `Tree` à franchir une frontière pour remplir un champ qu'on lui demandait — une
 * infraction produite par la forme du contrat, ce qui est la pire espèce.
 *
 * Le compte arrive donc par {@see PortMediasPourQuestions}, et c'est le détecteur qui
 * rapproche les deux. Deux ports, deux modules, une seule jointure — faite en PHP, sur
 * 274 lignes.
 */
final readonly class FicheEtudiee
{
    /**
     * @param int       $id          ⇢ `tree_person.id`
     * @param string    $nomAffiche  `display_name`, recalculé par `Tree`
     * @param string    $prenoms     `given_names` — **vide** est la lacune, pas `null`
     * @param string    $nom         `surname` — idem
     * @param string    $sexe        la *valeur* de `Core\Enum\Sex`, en chaîne
     * @param bool      $vivante     issu de `ReglePersonneVivante`, jamais de la colonne
     * @param int|null  $pereId      `null` = la lacune « père inconnu »
     * @param int|null  $mereId      `null` = la lacune « mère inconnue »
     * @param list<int> $conjointIds pour situer la personne dans le corpus
     * @param list<int> $enfantIds   idem
     */
    public function __construct(
        public int $id,
        public string $nomAffiche,
        public string $prenoms,
        public string $nom,
        public string $sexe,
        public bool $vivante,
        public UncertainDate $naissance,
        public UncertainDate $deces,
        public ?int $pereId,
        public ?int $mereId,
        public array $conjointIds,
        public array $enfantIds,
    ) {
    }
}
