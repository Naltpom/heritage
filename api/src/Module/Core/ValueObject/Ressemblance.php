<?php

declare(strict_types=1);

namespace App\Module\Core\ValueObject;

/**
 * Ce qu'une saisie a de commun avec une fiche — le verdict de
 * {@see \App\Module\Core\Service\ComparaisonDIdentites::comparer()}.
 *
 * Il n'existe que pour une fiche **retenue** : une fiche écartée (nom trop éloigné, sexe
 * opposé, dates incompatibles) ne produit pas une ressemblance de score nul, elle n'en
 * produit **aucune**. Un score zéro aurait invité un appelant à la lister quand même.
 *
 * ## Les critères sont des codes, les raisons des phrases
 *
 * `criteres` est la liste des concordances, dans un ordre fixe — `nom`, `prenom`, `sexe`,
 * `naissance`, `deces`, `lieu` — et c'est ce que le front lit pour dessiner ses pastilles.
 * `raisons` est la même chose en français, pour un lecteur : « même nom », « prénom
 * Pyroxène en commun », « né vers 1934, comme dans la fiche ». Les deux ne sont pas
 * redondants : un front qui reconstruirait les phrases depuis les codes devrait connaître
 * les valeurs de la fiche, et c'est exactement ce que la forme discrète lui refuse.
 *
 * ## Le score est le nombre de critères, et rien d'autre
 *
 * Pas de pondération : « deux critères de plus » se lit, « 0,73 » ne se lit pas, et un
 * poids qu'on règle à l'œil finit toujours par classer devant une fiche qui ne le mérite
 * pas. Le nom et un prénom étant exigés, un score vaut **au moins 2** ; au plus 6.
 *
 * `nomExact` départage deux scores égaux : un nom identique passe avant un nom à une faute
 * près. En forme discrète, il vaut toujours `false` — l'ordre des réponses ne doit pas
 * apprendre si le nom d'une proposition masquée est exactement celui qu'on a tapé.
 */
final readonly class Ressemblance
{
    public const string NOM = 'nom';
    public const string PRENOM = 'prenom';
    public const string SEXE = 'sexe';
    public const string NAISSANCE = 'naissance';
    public const string DECES = 'deces';
    public const string LIEU = 'lieu';

    /** L'ordre dans lequel les critères sont toujours rendus. */
    public const array ORDRE = [self::NOM, self::PRENOM, self::SEXE, self::NAISSANCE, self::DECES, self::LIEU];

    /** Le nombre de critères concordants. */
    public int $score;

    /** @var list<string> les codes, dans l'ordre de {@see self::ORDRE}, sans doublon */
    public array $criteres;

    /**
     * @param list<string> $criteres codes parmi {@see self::ORDRE}
     * @param list<string> $raisons  phrases françaises, une par concordance constatée
     */
    public function __construct(
        array $criteres,
        public array $raisons,
        public bool $nomExact,
    ) {
        $this->criteres = array_values(array_filter(
            self::ORDRE,
            static fn (string $code): bool => \in_array($code, $criteres, true),
        ));
        $this->score = \count($this->criteres);
    }
}
