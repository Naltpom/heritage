<?php

declare(strict_types=1);

namespace App\Module\Proposal\Service;

use App\Module\Core\Enum\SubjectKind;
use App\Module\Core\Security\CercleDeVisibiliteInterface;
use App\Module\Proposal\Service\Port\PortArbre;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 *  UNE DEMANDE PEUT DÉSORMAIS VISER UN VIVANT HORS DU CERCLE DE SON AUTEUR.
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Jusqu'à l'assistant de proposition, c'était impossible en pratique : le formulaire ne
 * montre pas une fiche restreinte, donc ne permet pas d'en modifier un champ, et un op
 * ne portait jamais qu'un `oldValue` que son auteur avait déjà lu. Le commanditaire a
 * décidé que l'assistant **peut** proposer sur toute personne (la demande reste soumise à
 * un administrateur, qui voit tout). Le corps complet est alors gardé et déposé par le
 * serveur — mais la demande déposée se relit ensuite par `GET /api/demandes/{id}`, et
 * ses ops y portaient l'`oldValue` lu en base : **une date de naissance que l'auteur n'a
 * pas le droit de connaître**, servie par la route « mes demandes ».
 *
 * Ce service répond à une seule question — *cette valeur d'avant peut-elle être rendue
 * à l'auteur ?* — pour les deux sorties côté membre qui en portent : la vue auteur
 * ({@see SerialiseurDeDemande::pourAuteur()}) et la reprise ({@see RefaireDemande}).
 * La vue d'administration n'est pas concernée : l'administrateur voit tout.
 *
 * ── La règle, et pourquoi elle lit deux sources ─────────────────────────────────
 *
 * Masquée ⇔ la cible est une **personne**, **non décédée** sur sa fiche, et **hors du
 * cercle** du lecteur (`Core\Security\CercleDeVisibiliteInterface`, dont la règle
 * « vivant » est celle du graphe). Le drapeau `deceased` est lu d'abord : une personne
 * décédée est un fait historique, visible de tout compte connecté, et c'est l'immense
 * majorité des cibles — la question du cercle ne se pose alors même pas.
 */
final readonly class ValeursMasquees
{
    public function __construct(
        private CercleDeVisibiliteInterface $cercle,
        private PortArbre $arbre,
    ) {
    }

    public function masquee(SubjectKind $kind, ?int $id): bool
    {
        if (SubjectKind::Personne !== $kind || null === $id) {
            return false;
        }

        $cible = $this->arbre->chargerCible($kind, $id);

        if (null === $cible || true === (bool) ($cible->valeurs['deceased'] ?? false)) {
            return false;
        }

        return !$this->cercle->voitPersonne($id);
    }
}
