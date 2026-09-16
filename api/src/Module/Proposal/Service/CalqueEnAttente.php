<?php

declare(strict_types=1);

namespace App\Module\Proposal\Service;

use App\Module\Core\Contract\Lecteur;
use App\Module\Core\Enum\SubjectKind;
use App\Module\Proposal\Entity\ChangeRequestOp;
use App\Module\Proposal\Repository\ChangeRequestOpRepository;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * LE CALQUE « EN ATTENTE » — la contrepartie de la promesse faite à l'utilisateur.
 *
 * > L'API renvoie les valeurs **validées**, plus un calque des valeurs **proposées**,
 * > et ce calque n'est servi **qu'à l'auteur de la demande et aux administrateurs**.
 * > Les autres membres voient les données validées, point.
 * > (§ 6.3 du dossier de mission, ADR 0004, § 13 du contrat d'API.)
 *
 * C'est ce qui rend l'attente indolore pour le demandeur — il voit sa contribution à
 * son écran immédiatement, ce qui est justement ce qu'un wiki apportait —, et c'est ce
 * qui permet d'assumer le coût d'une file de validation.
 *
 * ── Trois règles de forme, et pourquoi elles ne sont pas négociables ─────────────
 *
 * 1. **La valeur validée reste dans le corps principal ; le calque est à côté, jamais
 *    à la place.** Un client qui ignore `enAttente` affiche l'arbre validé — le
 *    comportement correct par défaut. Une substitution ferait qu'un client mal fini
 *    présente des données proposées comme validées.
 * 2. **La clé du calque est littéralement la clé du corps** (§ 1.2) : `enAttente`
 *    porte `surname`, parce que la fiche porte `surname`. Pas de table de
 *    correspondance entre deux jeux de noms — « l'endroit où les champs se perdent en
 *    silence quand on en ajoute un ».
 * 3. **`actuel` est redondant avec la fiche, et c'est voulu.** Il vaut l'`old_value` de
 *    l'op, c'est-à-dire la valeur **au moment de la soumission**. S'il diffère de
 *    `fiche[champ]`, c'est qu'une caducité s'annonce (§ 6.8). Le front peut l'afficher ;
 *    il ne doit pas supposer qu'ils sont égaux.
 *
 * ── Qui reçoit quoi (§ 13.3) ─────────────────────────────────────────────────────
 *
 * | Lecteur                                   | Ce qu'il obtient                          |
 * |-------------------------------------------|-------------------------------------------|
 * | Auteur d'une demande en attente            | les ops de **ses propres** demandes        |
 * | Administrateur                             | les ops de **toutes** les demandes         |
 * | Administrateur sous « voir comme un membre »| ce que verrait le membre emprunté          |
 * | Tout autre membre                          | `{}`                                       |
 * | Compte `en_attente`                        | `{}` — il ne peut pas déposer de demande   |
 *
 * ── Ce que ce service ne fait pas, et qui ne doit pas être oublié ────────────────
 *
 * **Il n'applique pas le cercle de visibilité.** Le calque et le cercle sont deux
 * filtres distincts, **composés** dans le même normaliseur (ADR 0004) : ils ne se
 * remplacent pas. Une personne vivante hors cercle n'a **pas de clé `enAttente` du
 * tout** — pas un calque vide, pas de clé (§ 11.1, § 13.3). C'est au normaliseur de
 * `Tree` de ne pas appeler ce service dans ce cas, et c'est écrit ici parce que
 * l'erreur inverse — servir `{}` — serait invisible en relecture et signalerait quand
 * même l'existence de la fiche.
 */
final readonly class CalqueEnAttente
{
    public function __construct(
        private ChangeRequestOpRepository $ops,
        private NormaliseurDeValeur $normaliseur,
    ) {
    }

    /**
     * Le calque d'une ligne. **Toujours un tableau**, vide quand il n'y a rien : la clé
     * `enAttente` est toujours présente sur une fiche, « parce qu'un front qui doit
     * tester `"enAttente" in fiche` avant de tester son contenu écrira un jour le
     * second test sans le premier » (§ 13.1).
     *
     * @return array<string, array{propose: mixed, actuel: mixed, demandeId: int, auteur: string, depuis: string|null, autresDemandes: list<int>}>
     */
    public function pour(SubjectKind $kind, int $id, Lecteur $lecteur): array
    {
        return $this->pourPlusieurs($kind, [$id], $lecteur)[$id] ?? [];
    }

    /**
     * Le calque de plusieurs lignes en une requête — une liste de recherche, une
     * fratrie, les nœuds d'un graphe.
     *
     * @param list<int> $ids
     *
     * @return array<int, array<string, array{propose: mixed, actuel: mixed, demandeId: int, auteur: string, depuis: string|null, autresDemandes: list<int>}>>
     */
    public function pourPlusieurs(SubjectKind $kind, array $ids, Lecteur $lecteur): array
    {
        // Le garde-fou du § 13.3, en tête et en une ligne : un compte en attente de
        // rattachement ne dépose pas de demande, il n'a donc aucun calque à recevoir.
        if ($lecteur->regimeEnAttente || [] === $ids) {
            return [];
        }

        // **C'est ici que se joue « invisible d'un autre membre ».** `null` veut dire
        // « toutes les demandes » et n'est passé que pour un administrateur hors
        // bascule ; tout autre lecteur est restreint à ses propres demandes, et un
        // membre qui n'en a aucune obtient donc un calque vide sans qu'aucune branche
        // particulière ait à le prévoir.
        $auteur = $lecteur->voitToutesLesDemandes() ? null : $lecteur->identiteEffective();

        $calques = [];

        foreach ($this->ops->champsEnAttente($kind, $ids, $auteur) as $op) {
            $champ = $op->getField();
            $sujet = $op->getSubjectId();

            if (null === $champ || null === $sujet) {
                continue;
            }

            $demande = $op->getRequest();
            $demandeId = $demande->getId();

            if (null === $demandeId) {
                continue;
            }

            // La requête rend les ops triés par `submittedAt` croissant : la **première**
            // vue pour un champ donné est donc celle de la demande la plus ancienne, et
            // c'est elle qui tient l'entrée. Les suivantes ne font qu'allonger
            // `autresDemandes` (§ 13.1) — un tableau à côté plutôt qu'à la place, pour
            // ne pas changer l'arité que le § 9.4 du modèle documente et sur laquelle le
            // front est écrit.
            if (isset($calques[$sujet][$champ])) {
                $calques[$sujet][$champ]['autresDemandes'][] = $demandeId;

                continue;
            }

            $calques[$sujet][$champ] = [
                'propose' => $this->normaliseur->pourAffichage($op->getValueKind(), $op->getNewValue()),
                'actuel' => $this->normaliseur->pourAffichage($op->getValueKind(), $op->getOldValue()),
                'demandeId' => $demandeId,
                // Sans l'auteur, la pastille ne dit pas de qui vient la proposition, et
                // l'écran d'administration devrait rouvrir chaque demande pour le savoir.
                'auteur' => $demande->getAuthorLabel(),
                'depuis' => $demande->getSubmittedAt()?->format(\DateTimeInterface::ATOM),
                'autresDemandes' => [],
            ];
        }

        return $calques;
    }

    /**
     * Les champs d'une ligne actuellement disputés, sans le détail — de quoi poser une
     * pastille sur un nœud de graphe sans transporter deux valeurs par champ.
     *
     * @param list<int> $ids
     *
     * @return array<int, list<string>>
     */
    public function champsDisputes(SubjectKind $kind, array $ids, Lecteur $lecteur): array
    {
        $champs = [];

        foreach ($this->pourPlusieurs($kind, $ids, $lecteur) as $sujet => $calque) {
            $champs[$sujet] = array_keys($calque);
        }

        return $champs;
    }

    /**
     * Les ops de champ d'une demande, indexés par champ — utilitaire partagé avec le
     * diff d'administration, pour que les deux lisent la même chose de la même façon.
     *
     * @param list<ChangeRequestOp> $ops
     *
     * @return array<string, ChangeRequestOp>
     */
    public static function indexerParChamp(array $ops): array
    {
        $index = [];

        foreach ($ops as $op) {
            $champ = $op->getField();

            if (null !== $champ) {
                $index[$champ] = $op;
            }
        }

        return $index;
    }
}
