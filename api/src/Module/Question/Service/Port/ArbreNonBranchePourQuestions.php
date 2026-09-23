<?php

declare(strict_types=1);

namespace App\Module\Question\Service\Port;

use App\Module\Question\Contract\PortArbrePourQuestions;

/**
 * Le repli de {@see PortArbrePourQuestions} : **aucune fiche**.
 *
 * Conséquence en chaîne : aucune lacune, donc aucune question, donc une page qui dit
 * « rien à demander pour l'instant ». C'est le bon échec.
 *
 * ## Pourquoi vide plutôt que partiel, et pourquoi pas une exception
 *
 * **Vide plutôt que partiel** : un flux incomplet produirait des lacunes *fantômes*.
 * « Cette personne n'a pas de mère » parce que la mère n'était pas dans le flux, et l'on
 * demanderait à une petite-fille le nom d'une aïeule que l'arbre connaît déjà. Une
 * question fausse est pire qu'une question absente : elle coûte du crédit, et le crédit
 * est ce qui décide s'il y aura une seconde contribution.
 *
 * **Pas une exception**, contrairement à {@see DepotNonBranche} : une page vide est un
 * état acceptable du produit — c'est aussi celui d'un arbre sans aucune lacune —, alors
 * qu'une réponse qu'on ferait semblant d'enregistrer serait une contribution perdue
 * sans que personne ne l'apprenne. Les deux ports n'échouent pas de la même façon parce
 * que leurs pannes ne coûtent pas la même chose.
 *
 * **Ne pas l'« arranger »** en lui faisant lire quoi que ce soit : `Question` n'a pas le
 * droit de toucher à `tree_person` (règle 3 de l'ADR 0002). Ce fichier est la preuve
 * que le module démarre sans `Tree`, pas un bouchon à remplir.
 */
final class ArbreNonBranchePourQuestions implements PortArbrePourQuestions
{
    public function fiches(): array
    {
        return [];
    }

    public function libelleDe(int $personneId): ?string
    {
        return null;
    }
}
