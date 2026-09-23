<?php

declare(strict_types=1);

namespace App\Module\Question\Service\Port;

use App\Module\Question\Contract\PortMediasPourQuestions;

/**
 * Le repli de {@see PortMediasPourQuestions} : **aucun compte**.
 *
 * ═══════════════════════════════════════════════════════════════════════════════
 *  C'EST LE SEUL REPLI DE CE MODULE DONT L'EFFET NAÏF SERAIT D'**OUVRIR** DES
 *  QUESTIONS AU LIEU D'EN FERMER.
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Un tableau vide se lit « zéro média pour tout le monde ». Pris tel quel, il
 * déclencherait la lacune `aucune_photo` sur **les 274 fiches**, et la page demanderait
 * à toute la famille une photo de gens dont l'arbre a déjà le portrait. C'est la forme
 * la plus coûteuse d'erreur possible ici : elle est massive, elle est fausse, et elle
 * arrive sur la page d'accueil de la contribution.
 *
 * D'où la règle, portée par le **détecteur** et non par ce fichier : la nature
 * `aucune_photo` est désactivée tant que ce port n'est pas branché. Elle est portée là
 * parce qu'un repli ne sait pas qu'il est un repli — il répond honnêtement à ce qu'on
 * lui demande, et « je ne connais aucun média » est une réponse honnête. C'est à
 * l'appelant de savoir à qui il parle.
 *
 * Le détecteur reconnaît le repli par son type, pas par un tableau vide : un arbre dont
 * aucune fiche n'a de média rendrait le même tableau vide, et ce cas-là est une vraie
 * lacune sur 274 fiches, qu'il faut bien pouvoir signaler.
 */
final class MediasNonBranchesPourQuestions implements PortMediasPourQuestions
{
    public function comptesParPersonne(): array
    {
        return [];
    }
}
