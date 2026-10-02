<?php

declare(strict_types=1);

namespace App\Module\Statistiques\Service;

use App\Module\Core\Security\CercleDeVisibiliteInterface;
use App\Module\Statistiques\Contract\PersonneDuReleve;
use Psr\Clock\ClockInterface;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 *  LE POINT UNIQUE OÙ LE CERCLE DE VISIBILITÉ S'APPLIQUE DANS CE MODULE.
 * ═══════════════════════════════════════════════════════════════════════════════.
 *
 * Même architecture que `Almanach\Service\FluxVisible` : le relevé arrive complet, le
 * cercle est interrogé **une fois** (`filtrerPersonnes()` sur tous les identifiants), et
 * l'ensemble obtenu est remis aux calculs sous la forme d'une {@see Population}. Aucun
 * calcul, aucun contrôleur ne parle au cercle. Un test balaye le module et exige une
 * seule occurrence du contrat (`StatistiquesTest::testLeCercleNEstConsulteQuAUnSeulEndroit`).
 *
 * ── Les règles de confidentialité des statistiques, en un seul endroit ──────────
 *
 * Décision du commanditaire (02/10/2026) : **les vivants comptent partout**, mais un membre
 * ne voit jamais le nom ni les dates d'une personne hors de son cercle de visibilité
 * (depuis la révision du 01/10/2026 de l'ADR 0003, ce sont les seules fiches à
 * confidentialité forcée, `force_privacy`, hors du cercle généalogique du lecteur ; la
 * bascule « voir comme un membre » y est prise en compte, puisque c'est le cercle qui la
 * porte). D'où trois traitements :
 *
 * 1. **Totaux et comptes simples** (personnes, familles, branches, part vérifiée) : tout
 *    le monde. Un compte de fiches ne dit rien d'une fiche — le graphe publie d'ailleurs
 *    déjà chaque nœud, confidentiel compris (ADR 0003, « la topologie reste visible »).
 * 2. **Moyennes et palmarès anonymes** (âges, mois, communes, métiers, pays) : tout le
 *    monde, sous le **seuil de discrétion** d'{@see Echantillon} — une case de moins de
 *    cinq contributions perd ses fiches invisibles.
 * 3. **Ce qui nomme** (records, prénoms, noms de famille, patronyme d'une branche) : les
 *    seules fiches visibles. Un record dont le titulaire est invisible **passe au suivant**
 *    (voir {@see Records}).
 */
final class StatistiquesVisibles
{
    public function __construct(
        private readonly ReleveEnCache $releve,
        private readonly CercleDeVisibiliteInterface $cercle,
        private readonly ClockInterface $horloge,
    ) {
    }

    /** La population du relevé, avec ce que le lecteur courant voit. */
    public function population(): Population
    {
        $releve = $this->releve->releve();
        $identifiants = array_map(static fn (PersonneDuReleve $p): int => $p->id, $releve->personnes);
        $visibles = array_fill_keys($this->cercle->filtrerPersonnes($identifiants), true);

        return new Population($releve, $visibles, $this->horloge->now());
    }

    /**
     * Les quatre sections ouvertes à tout membre (la cinquième, le chantier, est servie
     * par une route d'administration : {@see ChantierDeLArbre}).
     *
     * @return array<string, mixed>
     */
    public function pourLeLecteur(): array
    {
        $population = $this->population();
        $arbre = new ArbreEnChiffres();

        return [
            'calculeLe' => $population->maintenant->format(\DATE_ATOM),
            'seuilDeDiscretion' => Echantillon::SEUIL,
            'arbre' => $arbre->calculer($population),
            'vie' => (new VieDAvant())->calculer($population),
            'records' => (new Records())->calculer($population, $arbre),
            'prenomsEtNoms' => (new PrenomsEtNoms())->calculer($population),
            'lieux' => (new LieuxEtMetiers())->calculer($population),
        ];
    }
}
