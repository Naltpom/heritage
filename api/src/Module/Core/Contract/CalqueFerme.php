<?php

declare(strict_types=1);

namespace App\Module\Core\Contract;

use App\Module\Core\Enum\SubjectKind;

/**
 * L'implémentation **par défaut** de {@see CalqueEnAttenteInterface} : aucun calque,
 * aucun nœud proposé, aucune demande.
 *
 * Même arbitrage que `Core\Security\CercleFerme`, et il vaut d'être relu ici parce que
 * la conséquence n'est pas la même : sans adaptateur branché, ce n'est pas une fuite qui
 * menace, c'est une **fonctionnalité qui disparaît**. Le demandeur ne voit plus sa
 * modification avant validation — la promesse que le commanditaire a placée en priorité
 * 2. C'est visible, c'est gênant, et c'est infiniment préférable au défaut inverse :
 * un calque ouvert par erreur montrerait à un membre les propositions d'un autre sur des
 * personnes vivantes, sans message d'erreur et sans que personne ne l'apprenne.
 *
 * **L'absence de fonctionnalité, jamais une fuite.**
 *
 * `empreinteDesDemandes()` rend la chaîne vide : c'est la valeur que le contrat prévoit
 * pour « ce lecteur n'a aucune demande en attente », et elle laisse l'`ETag` du graphe
 * reposer sur la seule empreinte de l'arbre — exactement ce qu'il faut quand il n'y a
 * rien à ajouter.
 */
final class CalqueFerme implements CalqueEnAttenteInterface
{
    public function calque(SubjectKind $kind, array $ids, Lecteur $lecteur): array
    {
        return [];
    }

    public function projection(Lecteur $lecteur): ProjectionEnAttente
    {
        return ProjectionEnAttente::vide();
    }

    public function demandesSur(SubjectKind $kind, int $id, Lecteur $lecteur): array
    {
        return [];
    }

    public function empreinteDesDemandes(Lecteur $lecteur): string
    {
        return '';
    }
}
