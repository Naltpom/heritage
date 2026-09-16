<?php

declare(strict_types=1);

namespace App\Module\Media\Service\Proposition;

use App\Module\Core\Contract\Lecteur;
use App\Module\Proposal\Contract\ApplicateurDOperation;
use App\Module\Proposal\Contract\DemandeEnCours;
use App\Module\Proposal\Enum\OpKind;

/**
 * Applique les ops `ajout_media` d'une demande validée.
 *
 * ## Il fait désormais le travail, et c'est un changement de `Proposal`
 *
 * Il ne faisait rien, et ne le pouvait pas : `ValidationDeDemande` **résolvait** un
 * applicateur pour chaque op non-`champ` — afin d'échouer bruyamment si aucun n'était
 * branché — puis passait à l'op suivante sans jamais l'appeler. Le travail réel avait
 * donc été déporté dans un `ApplicateurDeDemandeMedia`, greffé sur l'autre point, le
 * seul qui fût appelé.
 *
 * `Proposal` appelle maintenant ce point-ci. Le contournement a été **supprimé**, et pas
 * seulement rendu inutile : garder les deux ferait valider chaque média **deux fois**,
 * une fois par étage. C'est l'ordre d'exécution qui l'impose — les ops passent avant la
 * nature de la demande.
 *
 * ## Il ne voit plus aucune entité de `Proposal`
 *
 * La signature prenait `Entity\ChangeRequest` et `Entity\ChangeRequestOp`, et obligeait
 * ce module à importer `ChangeRequestOpRepository` pour relire ses propres ops. Elle
 * prend maintenant deux objets de transfert en lecture seule (arbitrage HH, ADR 0002) :
 * `Media` n'a plus accès ni à `marquerApplique()`, ni à `decider()`, ni au dépôt d'un
 * autre module — et n'en avait jamais eu besoin. Deux identifiants lui suffisent.
 */
final readonly class ApplicateurDOperationMedia implements ApplicateurDOperation
{
    public function __construct(private ValidationDesMediasDeDemande $validation)
    {
    }

    public function natures(): array
    {
        return [OpKind::AjoutMedia];
    }

    public function appliquer(DemandeEnCours $demande, array $ops, Lecteur $lecteur): array
    {
        return $this->validation->appliquer($demande, $ops);
    }
}
