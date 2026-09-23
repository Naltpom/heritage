<?php

declare(strict_types=1);

namespace App\Module\Question\Exception;

use App\Module\Question\Enum\EtatDeQuestion;

/**
 * On répond à une question déjà fermée — « je ne sais pas » ou une réponse déposée.
 *
 * **`409` et non `422`** : ce n'est pas la réponse qui est mauvaise, c'est le monde qui a
 * bougé. Un second onglet resté ouvert, un double envoi du formulaire, un retour arrière
 * dans le navigateur. Le conseil à donner est « rechargez », jamais « corrigez » — et
 * c'est exactement la distinction que `Proposal` a payée cher entre `verrou_concurrent`
 * et `demande_caduque` : *« un front écrit contre “409 ⇒ bouton Réessayer” fait boucler
 * sur une demande morte »*.
 *
 * D'où `details.rejouable = false` : recharger montrera la page sans cette question, ce
 * qui est le bon résultat, mais **réessayer le même envoi ne marchera jamais**. Le front
 * branche sur le booléen plutôt que sur la liste des codes.
 *
 * L'état est nommé dans le message parce que les deux se vivent différemment : « vous
 * aviez répondu ne pas savoir » et « votre réponse est déjà partie » n'appellent pas la
 * même réaction, et la seconde est rassurante là où la première peut surprendre.
 */
final class QuestionDejaTranchee extends ErreurDeQuestion
{
    public static function pour(EtatDeQuestion $etat): self
    {
        return new self(
            'question_deja_tranchee',
            \sprintf('Cette question a déjà été traitée (%s). Rechargez la page.', mb_strtolower($etat->label())),
            409,
            null,
            ['rejouable' => false, 'etat' => $etat->value],
        );
    }
}
