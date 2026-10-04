<?php

declare(strict_types=1);

namespace App\Module\Statistiques\Service;

use App\Module\Core\Security\CercleDeVisibiliteInterface;
use App\Module\Core\Security\ReciprociteInterface;
use App\Module\Core\ValueObject\UncertainDate;
use App\Module\Statistiques\Contract\PersonneDuReleve;
use App\Module\Statistiques\Contract\ReleveDeLArbre;
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
 *
 * Et, **avant les trois** (révision du 2026-10-04 de l'ADR 0003, « un arbre par
 * famille ») : l'arbre d'un autre compte n'entre dans **aucun** calcul — ni total, ni
 * moyenne. Ses fiches, ses familles, ses faits et ses citations sont retirés du relevé
 * avant toute chose ({@see self::dansLePerimetre()}) : « 274 personnes » ne doit pas
 * devenir « 312 » le jour où un ami saisit sa famille.
 */
final class StatistiquesVisibles
{
    public function __construct(
        private readonly ReleveEnCache $releve,
        private readonly CercleDeVisibiliteInterface $cercle,
        private readonly ClockInterface $horloge,
        // ADR 0016 : âges, doyens et décennies des vivants ne se calculent que sur les
        // composantes de naissance que le lecteur a le droit de voir.
        private readonly ReciprociteInterface $reciprocite,
    ) {
    }

    /** La population du relevé, avec ce que le lecteur courant voit. */
    public function population(): Population
    {
        $releve = $this->dansLePerimetre($this->releve->releve());
        $identifiants = array_map(static fn (PersonneDuReleve $p): int => $p->id, $releve->personnes);
        $visibles = array_fill_keys($this->cercle->filtrerPersonnes($identifiants), true);

        return new Population($this->aTraversLaReciprocite($releve), $visibles, $this->horloge->now());
    }

    /**
     * Le relevé borné au périmètre du lecteur : son arbre et les arbres sans lien connu.
     * Une famille, un fait ou une citation suit les personnes qu'il touche — une famille
     * appartient tout entière à un seul arbre.
     */
    private function dansLePerimetre(ReleveDeLArbre $releve): ReleveDeLArbre
    {
        $dedans = [];
        $personnes = [];

        foreach ($releve->personnes as $p) {
            if ($this->cercle->dansLePerimetre($p->id)) {
                $dedans[$p->id] = true;
                $personnes[] = $p;
            }
        }

        if (\count($personnes) === \count($releve->personnes)) {
            return $releve;
        }

        $familles = [];
        foreach ($releve->familles as $f) {
            foreach ([$f->mariId, $f->epouseId, ...$f->enfants] as $id) {
                if (null !== $id && isset($dedans[$id])) {
                    $familles[$f->id] = $f;
                    break;
                }
            }
        }

        $garde = static fn (?int $personId, ?int $familyId): bool => (null !== $personId && isset($dedans[$personId]))
            || (null !== $familyId && isset($familles[$familyId]));

        return new ReleveDeLArbre(
            $personnes,
            array_values($familles),
            array_values(array_filter($releve->faits, static fn ($f): bool => $garde($f->personId, $f->familyId))),
            array_values(array_filter($releve->appuis, static fn ($a): bool => $garde($a->personId, $a->familyId))),
            $releve->lieux,
            null !== $releve->racineId && isset($dedans[$releve->racineId]) ? $releve->racineId : null,
        );
    }

    /**
     * Le relevé, la naissance des vivants **dégradée** à ce que la réciprocité accorde
     * (ADR 0016) — sans l'année, une date inconnue. Le cache reste partagé : le filtre
     * s'applique après sa lecture, comme le cercle.
     */
    private function aTraversLaReciprocite(ReleveDeLArbre $releve): ReleveDeLArbre
    {
        $personnes = [];
        $change = false;

        foreach ($releve->personnes as $p) {
            $permises = $p->vivant ? $this->reciprocite->naissance($p->id) : null;

            if (null === $permises || $permises->estComplete()) {
                $personnes[] = $p;
                continue;
            }

            $change = true;
            $personnes[] = new PersonneDuReleve(
                $p->id,
                $p->displayName,
                $p->prenoms,
                $p->nom,
                $p->sex,
                $p->naissance->reduite($permises) ?? UncertainDate::inconnue($p->naissance->getPlace()),
                $p->deces,
                $p->vivant,
                $p->decede,
                $p->branch,
                $p->generation,
                $p->bestQuay,
                $p->citationCount,
                $p->creeLe,
            );
        }

        return $change
            ? new ReleveDeLArbre($personnes, $releve->familles, $releve->faits, $releve->appuis, $releve->lieux, $releve->racineId)
            : $releve;
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
