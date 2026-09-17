<?php

declare(strict_types=1);

namespace App\Module\Proposal\Service;

use App\Module\Core\Contract\Lecteur;
use App\Module\Core\Enum\SubjectKind;
use App\Module\Proposal\Contract\ApplicateurDOperation;
use App\Module\Proposal\Contract\DemandeEnCours;
use App\Module\Proposal\Contract\EcritureDeRevision;
use App\Module\Proposal\Contract\NoteAcreer;
use App\Module\Proposal\Contract\OpEnAttente;
use App\Module\Proposal\Enum\OpKind;
use App\Module\Proposal\Enum\RevisionOperation;
use App\Module\Proposal\Service\Port\PortArbre;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * `ajout_note` — l'application à la validation.
 *
 * Le frère de {@see ApplicateurDOperationCitation}, greffé par le même tag et écrivant
 * par le même port.
 *
 * ## Pourquoi deux classes et non une qui réclamerait les deux natures
 *
 * `ValidationDeDemande::appliquerLesAutresOps()` **groupe les ops par nature** et appelle
 * un applicateur par groupe : une classe unique fonctionnerait, avec deux appels et un
 * `match` à l'intérieur. On ne le fait pas, pour trois raisons qui tiennent au motif du
 * projet plutôt qu'au goût :
 *
 * - `natures()` devient une **liste de ce qu'on sait faire**, pas une liste de ce qu'on
 *   accepte de recevoir. `ApplicateurDOperationMedia` en rend une seule ; deux natures
 *   dans un même `natures()` obligeraient chaque lecteur à vérifier, dans le corps, que
 *   les deux sont réellement traitées ;
 * - une note et une citation n'ont **rien de commun** à l'exécution : formes de valeur
 *   différentes, méthode de port différente, agrégats pour l'une et pas pour l'autre,
 *   auteur porté par l'une et pas par l'autre. La factorisation ne partagerait qu'une
 *   boucle `foreach` ;
 * - `retrait_citation`, `retrait_media`, `ajout_lien` et `retrait_lien` restent à
 *   brancher. Une classe fourre-tout par module deviendrait le fourre-tout de six
 *   natures, et c'est exactement ce que le point de greffe existe pour éviter.
 *
 * ## L'auteur inscrit sur la note est le demandeur, pas le valideur
 *
 * C'est le seul endroit de ce lot où `DemandeEnCours::$auteurUserId` et
 * `$auteurLibelle` servent, et ce n'est pas accessoire : `tree_note.author_label` est
 * **rendu sur la fiche** par le normaliseur de personne. Le `Lecteur` reçu ici est
 * l'administrateur qui valide ; inscrire son nom sous le raisonnement d'un autre serait
 * faux deux fois — il n'a pas écrit la note, et le membre qui l'a écrite disparaîtrait.
 * `null` ne conviendrait pas davantage : pour cette colonne, `null` veut dire « importée
 * du GEDCOM » (§ 3.5).
 *
 * ## Le sujet de la révision est la note
 *
 * `prp_revision` reçoit `subject_kind = 'note'`. La fiche n'a pas changé : ses colonnes
 * éditables sont intactes, sa `version` n'a pas bougé, et `tree_note` ne porte même pas
 * de `#[ORM\Version]` — une note n'est pas une cible de demande (§ 9.6). Aucun verrou
 * optimiste ne s'exerce donc, et documenter une fiche ne peut pas entrer en conflit avec
 * sa correction : c'est le même raisonnement que pour la citation et pour le média.
 *
 * `SubjectKind::Note` n'est pas dans `ciblesDeDemande()`, et il n'a pas à y être : c'est
 * la portée d'une **révision**, pas la cible d'une demande. La demande, elle, vise la
 * personne.
 */
final readonly class ApplicateurDOperationNote implements ApplicateurDOperation
{
    public function __construct(private PortArbre $arbre)
    {
    }

    public function natures(): array
    {
        return [OpKind::AjoutNote];
    }

    public function appliquer(DemandeEnCours $demande, array $ops, Lecteur $lecteur): array
    {
        $ecritures = [];

        foreach ($ops as $op) {
            $creee = $this->arbre->creerNote($this->aCreer($op, $demande));

            $ecritures[] = new EcritureDeRevision(
                SubjectKind::Note,
                $creee->id,
                $creee->libelle,
                RevisionOperation::Creation,
                $this->diff($op),
            );
        }

        return $ecritures;
    }

    /**
     * L'op relue en ordre de création.
     *
     * La valeur d'op fait foi : la ligne n'existe pas encore, il n'y a donc rien à relire,
     * et `newValue` est à la fois ce que l'auteur a proposé et ce que l'administrateur a
     * lu dans le diff avant de valider.
     */
    private function aCreer(OpEnAttente $op, DemandeEnCours $demande): NoteAcreer
    {
        $valeur = $op->newValue;

        if (!\is_array($valeur)) {
            throw new \LogicException(\sprintf('L\'op %d de nature « ajout_note » ne porte pas d\'objet en newValue : %s.', $op->seq, get_debug_type($valeur)));
        }

        if (null === $op->subjectId) {
            // Second garde-fou, structurel : les ops s'appliquent avant que
            // `ApplicateurDeCreation` n'ait résolu les brouillons. Voir le constructeur,
            // qui refuse déjà au dépôt — et qui rappelle qu'une création porte sa note.
            throw new \LogicException(\sprintf('L\'op %d vise le brouillon « %s » : une note ne peut pas être rattachée à une personne que la même demande n\'a pas encore créée.', $op->seq, (string) $op->subjectDraftRef));
        }

        $corps = $valeur['body'] ?? null;

        if (!\is_string($corps) || '' === trim($corps)) {
            // Le constructeur l'a déjà refusé au dépôt. Si on arrive ici avec un corps
            // vide, c'est qu'une op a été fabriquée ailleurs : on ne pose pas une note
            // muette sur une fiche, on le dit.
            throw new \LogicException(\sprintf('L\'op %d de nature « ajout_note » ne porte aucun texte.', $op->seq));
        }

        return new NoteAcreer(
            $op->subjectId,
            $corps,
            \is_string($valeur['scope'] ?? null) ? $valeur['scope'] : 'generale',
            $demande->auteurUserId,
            $demande->auteurLibelle,
        );
    }

    /**
     * Le diff de `prp_revision`, dans la forme de `core_audit_log.changes` (§ 5.5).
     *
     * `avant` vaut `null` partout : c'est une création. Le corps y figure **en entier** et
     * non tronqué — `prp_revision.changes` est du `JSONB` sans borne, et c'est le seul
     * endroit où l'historique garde ce qui a été ajouté. C'est `subject_label`, borné à
     * 255 caractères, qui porte la version courte.
     *
     * @return array<string, mixed>
     */
    private function diff(OpEnAttente $op): array
    {
        /** @var array<string, mixed> $valeur */
        $valeur = \is_array($op->newValue) ? $op->newValue : [];

        return [
            'body' => ['avant' => null, 'apres' => $valeur['body'] ?? null],
            'scope' => ['avant' => null, 'apres' => $valeur['scope'] ?? null],
        ];
    }
}
