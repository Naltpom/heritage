<?php

declare(strict_types=1);

namespace App\Module\Question\Contract;

/**
 * Combien de médias visent chaque personne — la donnée de la lacune « aucune photo ».
 *
 * ═══════════════════════════════════════════════════════════════════════════════
 * POINT DE BRANCHEMENT DU MODULE `Media` — une seule méthode, en lecture.
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * La lacune « cette personne n'a aucune photo » a besoin d'un compte que `Tree` ne peut
 * pas fournir : `med_media` appartient à `Media` (règle 5 de l'ADR 0002). D'où ce
 * second port, plutôt qu'un champ de plus sur {@see FicheEtudiee} qui aurait obligé
 * l'adaptateur de `Tree` à franchir une frontière pour le remplir.
 *
 * ## En bloc, et pas fiche par fiche
 *
 * `Proposal` pose déjà la question **par personne**
 * (`Proposal\Service\Port\PortMedias::compterLesMediasDe()`), et c'est le bon geste
 * là-bas : il contrôle une suppression, une à la fois. Ici, la détection balaie l'arbre
 * entier — 274 fiches. Une boucle sur une méthode unitaire ferait 274 requêtes pour un
 * `GROUP BY`.
 *
 * ## Tous les statuts sont comptés
 *
 * Y compris `en_attente` et `refuse`. Le motif est le même que chez `Proposal`, vu de
 * l'autre côté : une photo déposée hier et pas encore validée **est** une photo. La
 * compter pour zéro ferait reposer la question à celui qui vient d'y répondre — et
 * redemander à quelqu'un ce qu'il vient de donner est la façon la plus sûre de le
 * perdre.
 *
 * ## Le repli
 *
 * {@see \App\Module\Question\Service\Port\MediasNonBranchesPourQuestions} rend un
 * tableau **vide**, ce qui compte zéro média pour tout le monde. C'est le seul repli de
 * ce module dont l'effet soit d'**ouvrir** une question plutôt que d'en fermer une :
 * non câblé, il demanderait une photo à toute la famille pour toutes les fiches. La
 * nature `aucune_photo` est donc **désactivée** tant que ce port n'est pas branché, et
 * c'est le détecteur qui le décide — pas ce fichier, qui ne saurait pas qu'il est le
 * repli.
 */
interface PortMediasPourQuestions
{
    /**
     * Combien de médias visent chaque personne.
     *
     * Les personnes **sans aucun média sont absentes du tableau**, elles n'y figurent
     * pas avec un zéro : c'est le résultat naturel d'un `GROUP BY`, et exiger la
     * complétude obligerait l'adaptateur à connaître la liste des personnes, qu'il n'a
     * pas. L'appelant lit donc `$comptes[$id] ?? 0`.
     *
     * @return array<int, int> indexé par identifiant de personne
     */
    public function comptesParPersonne(): array;
}
