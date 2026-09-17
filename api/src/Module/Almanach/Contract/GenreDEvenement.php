<?php

declare(strict_types=1);

namespace App\Module\Almanach\Contract;

/**
 * Le **vocabulaire des pastilles** : cinq genres, et c'est tout ce que l'écran propose de
 * cocher ou décocher.
 *
 * ## Pourquoi cinq et non onze
 *
 * {@see TypeDEvenement} en compte onze. Onze pastilles sur un écran de téléphone, ce sont
 * onze cibles de moins de quarante pixels, et une légende qu'on ne lit pas. Les cinq
 * genres sont ce qu'un lecteur distingue réellement en regardant une carte familiale :
 * qui est né, qui est mort, qui s'est uni, qui a été baptisé, et le reste.
 *
 * **Le regroupement se fait ici et non dans le client.** Deux raisons, et la seconde est
 * celle qu'on oublie :
 *
 * 1. `resume.parGenre` compte **après le cercle de visibilité** ; un client qui
 *    regrouperait lui-même devrait recevoir la ventilation fine pour la recomposer, donc
 *    recevoir plus que ce qu'il affiche ;
 * 2. deux regroupements écrits à deux endroits divergent. Le jour où une douzième nature
 *    arrive, elle tomberait dans `autre` côté serveur et nulle part côté client — une
 *    pastille dont le compte ne correspond plus à ce qu'elle montre.
 *
 * ## Les deux regroupements qui ne vont pas de soi
 *
 * - **`union` réunit `mariage` et `divorce`.** Ce sont les deux dates de la même ligne
 *   `tree_family`, elles portent les mêmes sujets, et une carte qui les séparerait
 *   donnerait deux pastilles pour deux événements sur 103 unions. Le `type` reste distinct
 *   dans la charge : un client qui veut faire la différence l'a.
 * - **`bapteme` réunit `bapteme` et `bapteme_religieux`.** La distinction est un héritage
 *   de la norme GEDCOM (`BAPM` contre `CHR`) qui n'apprend rien à un lecteur d'arbre
 *   familial ; les deux tags désignent le même moment dans la vie d'un enfant.
 *
 * `inhumation` **ne rejoint pas `deces`**, et c'est délibéré : ce sont deux dates
 * distinctes, parfois à des semaines d'écart et dans deux lieux différents. Les confondre
 * poserait une bulle de décès sur un cimetière.
 */
enum GenreDEvenement: string
{
    case Naissance = 'naissance';
    case Deces = 'deces';

    /** Mariage **et** divorce : les deux dates de la même union. */
    case Union = 'union';

    /** Baptême civil et baptême religieux confondus. */
    case Bapteme = 'bapteme';

    /** Profession, résidence, inhumation, recensement, et le reste. */
    case Autre = 'autre';

    public function label(): string
    {
        return match ($this) {
            self::Naissance => 'Naissances',
            self::Deces => 'Décès',
            self::Union => 'Unions',
            self::Bapteme => 'Baptêmes',
            self::Autre => 'Autres',
        };
    }

    /**
     * Les cinq genres dans l'ordre des pastilles, pour initialiser un compteur.
     *
     * Un `resume.parGenre` construit par accumulation ne porterait que les genres
     * **présents**, et le front devrait deviner qu'une clé absente vaut zéro. Il porte
     * donc les cinq, toujours, et un genre à zéro se lit comme une pastille vide plutôt
     * que comme une pastille manquante.
     *
     * @return array<string, int> les cinq valeurs en clés, toutes à zéro
     */
    public static function compteurVide(): array
    {
        $compteur = [];

        foreach (self::cases() as $genre) {
            $compteur[$genre->value] = 0;
        }

        return $compteur;
    }
}
