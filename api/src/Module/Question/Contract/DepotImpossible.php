<?php

declare(strict_types=1);

namespace App\Module\Question\Contract;

/**
 * Le dépôt d'une réponse a été refusé par le circuit de validation.
 *
 * ## Pourquoi elle vit dans `Contract\` et non dans `Exception\`
 *
 * Parce que c'est **l'implémenteur qui la lève** : l'adaptateur vit chez `Proposal`, et
 * la règle 3 de l'ADR 0002 ne l'autorise à nommer que `Core\…` et
 * `<Autre>\Contract\…`. Une `Question\Exception\ReponseRefusee` aurait été une
 * infraction de frontière produite par le rangement des fichiers — c'est-à-dire la
 * pire espèce, celle qu'on « corrige » en ajoutant une exemption.
 *
 * Une exception fait partie de la signature d'une méthode au même titre que son type de
 * retour. Sa place est donc là où vit la signature.
 *
 * ## Ce qu'elle porte, et ce qu'elle ne porte pas
 *
 * Un message **destiné au membre**, en français, affichable tel quel. Pas un code
 * d'erreur, pas une trace, pas le nom d'un champ Doctrine.
 *
 * `$champ` est renseigné quand le refus vise une valeur précise (« cette valeur est
 * déjà celle de la fiche ») et `null` quand il vise la réponse entière (« votre compte
 * n'est pas encore activé »). Le front en fait deux affichages différents : sous le
 * champ, ou en tête de la question.
 *
 * Ce qu'elle ne porte pas : **le détail de la cause technique**. Un conflit de version,
 * une cible disparue et une valeur inchangée se ressemblent pour qui lit ; les
 * distinguer à l'écran donnerait trois messages dont deux n'appellent aucune action de
 * la part du membre.
 */
final class DepotImpossible extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $champ = null,
        ?\Throwable $precedente = null,
    ) {
        parent::__construct($message, 0, $precedente);
    }
}
