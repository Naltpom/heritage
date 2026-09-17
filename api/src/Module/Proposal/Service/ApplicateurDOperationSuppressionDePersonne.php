<?php

declare(strict_types=1);

namespace App\Module\Proposal\Service;

use App\Module\Core\Contract\Lecteur;
use App\Module\Core\Enum\SubjectKind;
use App\Module\Proposal\Contract\ApplicateurDOperation;
use App\Module\Proposal\Contract\DemandeEnCours;
use App\Module\Proposal\Contract\EcritureDeRevision;
use App\Module\Proposal\Contract\InventaireDeSuppression;
use App\Module\Proposal\Contract\OpEnAttente;
use App\Module\Proposal\Enum\OpKind;
use App\Module\Proposal\Enum\RevisionOperation;
use App\Module\Proposal\Service\Port\PortArbre;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * `suppression_personne` — l'application à la validation.
 *
 * Le frère de {@see ApplicateurDOperationNote} et de {@see ApplicateurDOperationCitation},
 * greffé par le même tag et écrivant par le même port. Et le premier qui **retire** au lieu
 * d'ajouter, ce qui change trois choses.
 *
 * ## 1. La révision est tout ce qui restera de la fiche
 *
 * Les deux autres applicateurs écrivent une ligne, et `prp_revision` dit qu'elle a été
 * écrite ; si l'historique était perdu, la ligne serait toujours là. Ici, `prp_revision`
 * est la **seule** trace qui survit : `subject_id` désigne une clé primaire qui n'existe
 * plus, et `changes` porte l'inventaire complet de ce qui est parti. C'est pour cela que
 * `prp_revision` n'a pas de clé étrangère vers `tree_person` — la colonne est un entier nu,
 * délibérément, et c'est ce qui permet à l'historique de survivre à son sujet.
 *
 * Le diff est donc écrit dans l'autre sens que d'habitude : `avant` porte l'inventaire,
 * `apres` vaut `null` partout. C'est l'exact miroir d'une création, où `avant` vaut `null`.
 *
 * ## 2. L'inventaire journalisé est celui mesuré **dans la transaction**
 *
 * Pas celui du dépôt, qui date de trois jours, ni celui de l'écran d'administration, qui
 * date du dernier rafraîchissement. `PortArbre::supprimerPersonne()` rend ce qu'il vient
 * réellement d'effacer, et c'est cela qu'on inscrit : un historique qui recopierait la
 * valeur d'op affirmerait avoir effacé trois notes alors qu'il en a effacé quatre, et
 * personne ne pourrait jamais le contredire.
 *
 * Les deux nombres **coïncident**, parce qu'un écart aurait déjà rendu la demande caduque
 * au contrôle préalable. Le journal ne se fie pourtant pas à cette coïncidence : c'est
 * précisément le genre d'invariant qui tient jusqu'au jour où il ne tient plus.
 *
 * ## 3. Le sujet de la révision est la personne, et sa version n'a pas d'« après »
 *
 * `versionAvant` porte la dernière version connue de la fiche ; `versionApres` reste
 * `null`, faute de ligne à versionner. `RevisionOperation::Suppression` existe dans
 * l'énumération depuis le premier jour et n'avait jamais été employée — aucun chemin
 * applicatif ne supprimait quoi que ce soit.
 *
 * ## Ce que cet applicateur ne fait pas : il ne décide pas
 *
 * Les deux refus de sûreté — la descendance détachée et la preuve orpheline — sont
 * prononcés au **dépôt** par {@see ConstructeurDeSuppressionDePersonne}, et re-vérifiés au
 * **contrôle préalable** par {@see AnalyseDeDemande}, qui en fait une caducité. Ici, on
 * n'est plus dans une transaction où l'on peut dire non poliment : on écrit, ou on annule
 * tout. Le garde-fou qui reste est donc une levée, portée par `Tree` lui-même.
 */
final readonly class ApplicateurDOperationSuppressionDePersonne implements ApplicateurDOperation
{
    public function __construct(private PortArbre $arbre)
    {
    }

    public function natures(): array
    {
        return [OpKind::SuppressionPersonne];
    }

    public function appliquer(DemandeEnCours $demande, array $ops, Lecteur $lecteur): array
    {
        $ecritures = [];

        foreach ($ops as $op) {
            $personneId = $this->personneVisee($op);

            $inventaire = $this->arbre->supprimerPersonne($personneId);

            $ecritures[] = new EcritureDeRevision(
                SubjectKind::Personne,
                $personneId,
                $inventaire->displayName,
                RevisionOperation::Suppression,
                $this->diff($inventaire),
                $op->baseVersion,
            );
        }

        return $ecritures;
    }

    /**
     * La fiche visée, qui est forcément une ligne existante.
     *
     * Une suppression ne peut pas viser un brouillon : la ligne n'existe pas encore, et
     * une demande qui créerait une personne pour l'effacer dans le même acte n'aurait
     * aucun sens. Le constructeur n'accepte d'ailleurs qu'un `targetId` — arriver ici avec
     * une référence provisoire signifie qu'une op a été fabriquée ailleurs.
     */
    private function personneVisee(OpEnAttente $op): int
    {
        if (null === $op->subjectId) {
            throw new \LogicException(\sprintf('L\'op %d de nature « suppression_personne » vise le brouillon « %s » : on ne supprime pas une fiche que la même demande n\'a pas encore créée.', $op->seq, (string) $op->subjectDraftRef));
        }

        if (SubjectKind::Personne->value !== $op->subjectKind) {
            throw new \LogicException(\sprintf('L\'op %d de nature « suppression_personne » vise un sujet « %s » : ce circuit ne supprime que des fiches de personne.', $op->seq, $op->subjectKind));
        }

        return $op->subjectId;
    }

    /**
     * Le diff de `prp_revision`, dans la forme de `core_audit_log.changes` (§ 5.5) — **le
     * miroir d'une création**.
     *
     * `apres` vaut `null` partout, parce qu'après une suppression il n'y a rien. `avant`
     * porte l'inventaire, clé par clé, plutôt qu'un seul bloc : `changes` est fait pour
     * être **lu** (§ 6.11), et « notes : 3 → rien » se lit, là où un objet imbriqué sous
     * une clé unique demande d'être déplié.
     *
     * `displayName` y figure aussi, et ce n'est pas une redite de `subject_label` : ce
     * dernier est un `VARCHAR(255)` destiné à l'affichage d'une liste, alors que `changes`
     * est du `JSONB` sans borne et reste la seule description de ce que la fiche était.
     *
     * @return array<string, mixed>
     */
    private function diff(InventaireDeSuppression $inventaire): array
    {
        $changes = [];

        foreach ($inventaire->versJson() as $cle => $valeur) {
            if ('personneId' === $cle) {
                // Redondant avec `subject_id`, et le seul champ dont « avant / après »
                // n'apprendrait rien : un identifiant ne change pas, il cesse d'exister.
                continue;
            }

            $changes[$cle] = ['avant' => $valeur, 'apres' => null];
        }

        // La phrase que l'écran d'historique affiche sans avoir à recomposer les
        // décomptes — la même que celle qui a été montrée à l'administrateur avant qu'il
        // clique, pour que les deux se relisent ensemble six mois plus tard.
        $changes['resume'] = ['avant' => $inventaire->enUneLigne(), 'apres' => null];

        return $changes;
    }
}
