<?php

declare(strict_types=1);

namespace App\Module\Question\Service\Port;

use App\Module\Question\Contract\DepotDeReponse;
use App\Module\Question\Contract\DepotImpossible;
use App\Module\Question\Contract\ReponseDeposee;

/**
 * Le repli de {@see DepotDeReponse} : il **lève**, toujours.
 *
 * ## Pourquoi celui-ci n'échoue pas comme les deux autres
 *
 * {@see ArbreNonBranchePourQuestions} rend un flux vide, et c'est acceptable : une page
 * sans question est un état normal du produit. Ici, le silence serait le seul défaut de
 * ce chantier qui ne se rattrape pas.
 *
 * Un membre qui écrit trois lignes sur sa grand-mère, appuie sur « envoyer » et voit un
 * message de confirmation **a donné sa contribution**. Si rien n'est parti, personne ne
 * l'apprendra : ni lui — il a vu le message —, ni l'administrateur — la file est vide et
 * une file vide ne rougit pas —, ni aucun test — il n'y a pas de ligne à ne pas trouver.
 * Le renseignement est perdu, et avec lui la seule chose que cette page existe pour
 * recueillir.
 *
 * Une exception, elle, produit un `500` que quelqu'un voit dans l'heure.
 *
 * ## Ce que ce fichier prouve
 *
 * Que le module se compile et se teste **sans `Proposal`**. C'est ce qui rend le jalon 2
 * — la détection et le repli par règles — livrable et éprouvable avant qu'aucune
 * écriture ne soit branchée.
 */
final class DepotNonBranche implements DepotDeReponse
{
    public function corrigerUnChamp(int $personneId, string $champ, string $valeur, string $message): ReponseDeposee
    {
        throw $this->nonBranche();
    }

    public function annoterUneFiche(int $personneId, string $corps, string $message): ReponseDeposee
    {
        throw $this->nonBranche();
    }

    public function joindreUnMedia(int $personneId, int $mediaId, string $message): ReponseDeposee
    {
        throw $this->nonBranche();
    }

    private function nonBranche(): DepotImpossible
    {
        // Le message part au membre : il dit ce qu'il doit faire (réessayer plus tard),
        // pas ce qui manque au serveur. « Le port de dépôt n'est pas branché » serait un
        // diagnostic exact et inutile pour qui le lit.
        return new DepotImpossible(
            'Votre réponse n\'a pas pu être enregistrée. Réessayez dans un moment.',
        );
    }
}
