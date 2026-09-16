<?php

declare(strict_types=1);

namespace App\Tests\Tree\Double;

use App\Module\Core\Contract\CalqueEnAttenteInterface;
use App\Module\Core\Contract\Lecteur;
use App\Module\Core\Contract\ProjectionEnAttente;
use App\Module\Core\Enum\SubjectKind;

/**
 * Un `Proposal` en mémoire, pour éprouver la composition des **deux** filtres — le
 * cercle et le calque — sans monter le module des propositions.
 *
 * Ce n'est pas un pis-aller en attendant le vrai module : c'est le bénéfice direct du
 * port. La règle que ce double sert à vérifier est celle du § 13.3 — « le calque n'est
 * servi qu'à l'auteur de la demande et aux administrateurs » — et elle se vérifie ici en
 * deux lignes, là où il faudrait sinon un jeu de `prp_change_request` complet.
 *
 * Il reproduit **une seule** règle de `Proposal`, celle de l'audience, parce que c'est
 * celle que `Tree` doit respecter en aval. Tout le reste — la forme du calque, la demande
 * la plus ancienne qui tient l'entrée, `autresDemandes` — appartient à `Proposal` et est
 * éprouvé chez lui.
 */
final class CalqueEnMemoire implements CalqueEnAttenteInterface
{
    /** @var array<string, array<string, array{propose: mixed, actuel: mixed, demandeId: int, auteur: string, depuis: string|null, autresDemandes: list<int>}>> */
    private array $calques = [];

    private string $empreinte = '';

    public function __construct(private readonly int $auteurUserId)
    {
    }

    /**
     * @param array<string, array{propose: mixed, actuel: mixed, demandeId: int, auteur: string, depuis: string|null, autresDemandes: list<int>}> $champs
     */
    public function poser(SubjectKind $kind, int $id, array $champs): self
    {
        $this->calques[$kind->value.':'.$id] = $champs;

        return $this;
    }

    public function poserEmpreinte(string $empreinte): self
    {
        $this->empreinte = $empreinte;

        return $this;
    }

    public function calque(SubjectKind $kind, array $ids, Lecteur $lecteur): array
    {
        if (!$this->concerne($lecteur)) {
            return [];
        }

        $sortie = [];

        foreach ($ids as $id) {
            $champs = $this->calques[$kind->value.':'.$id] ?? null;

            if (null !== $champs) {
                $sortie[$id] = $champs;
            }
        }

        return $sortie;
    }

    public function projection(Lecteur $lecteur): ProjectionEnAttente
    {
        return ProjectionEnAttente::vide();
    }

    public function demandesSur(SubjectKind $kind, int $id, Lecteur $lecteur): array
    {
        return $this->concerne($lecteur) && isset($this->calques[$kind->value.':'.$id]) ? [1] : [];
    }

    public function empreinteDesDemandes(Lecteur $lecteur): string
    {
        return $this->concerne($lecteur) ? $this->empreinte : '';
    }

    /**
     * L'audience du § 13.3, en une expression : l'auteur, ou un administrateur hors
     * bascule.
     *
     * `estAuteurDe()` et non une comparaison à `userId` : sous la bascule « voir comme un
     * membre », un administrateur doit voir le calque du **membre emprunté**, sans quoi la
     * bascule montrerait un écran que ce membre ne verra jamais.
     */
    private function concerne(Lecteur $lecteur): bool
    {
        if ($lecteur->regimeEnAttente) {
            return false;
        }

        return $lecteur->voitToutesLesDemandes() || $lecteur->estAuteurDe($this->auteurUserId);
    }
}
