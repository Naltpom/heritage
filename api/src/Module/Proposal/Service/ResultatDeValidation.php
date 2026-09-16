<?php

declare(strict_types=1);

namespace App\Module\Proposal\Service;

use App\Module\Proposal\Entity\ChangeRequest;
use App\Module\Proposal\Entity\Revision;

/**
 * Ce que rend une validation réussie — **deux issues, toutes deux des succès**.
 *
 * `rejouee` n'est pas une demi-validation : la demande est appliquée, son statut est
 * `validee`. Ce que le mot ajoute, c'est qu'**aucun humain n'a réexaminé cette écriture
 * champ par champ** : le verrou s'était déclenché, toutes les anciennes valeurs
 * correspondaient encore, et le service a rejoué tout seul sur la version courante.
 *
 * C'est tracé au journal sous `demande_rejouee` précisément pour cela, et le front doit
 * le dire : « un front qui affiche “validée” tout court efface cette distinction »
 * (§ 6.8 du contrat d'API).
 */
final readonly class ResultatDeValidation
{
    /**
     * @param 'validee'|'rejouee'                                                                                                 $resultat
     * @param list<Revision>                                                                                                      $revisions
     * @param array{motif: string, versionALaSoumission: int|null, versionAppliquee: int|null, champsVerifies: list<string>}|null $rejeu
     */
    public function __construct(
        public string $resultat,
        public ChangeRequest $demande,
        public array $revisions,
        public string $empreinte,
        public ?array $rejeu = null,
    ) {
    }

    public function estUnRejeu(): bool
    {
        return 'rejouee' === $this->resultat;
    }
}
