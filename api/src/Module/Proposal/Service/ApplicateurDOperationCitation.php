<?php

declare(strict_types=1);

namespace App\Module\Proposal\Service;

use App\Module\Core\Contract\Lecteur;
use App\Module\Core\Enum\SubjectKind;
use App\Module\Proposal\Contract\ApplicateurDOperation;
use App\Module\Proposal\Contract\CitationAcreer;
use App\Module\Proposal\Contract\DemandeEnCours;
use App\Module\Proposal\Contract\EcritureDeRevision;
use App\Module\Proposal\Contract\OpEnAttente;
use App\Module\Proposal\Enum\OpKind;
use App\Module\Proposal\Enum\RevisionOperation;
use App\Module\Proposal\Service\Port\PortArbre;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * `ajout_citation` — l'application à la validation.
 *
 * **C'est le greffon qui manquait.** `OpKind::AjoutCitation` existait depuis le premier
 * jour ; aucun applicateur ne le réclamait. Une demande qui aurait porté une op de cette
 * nature n'aurait pas été perdue en silence — `ValidationDeDemande::applicateurPour()`
 * lève, et c'est précisément pour cela qu'elle lève — mais elle aurait échoué
 * bruyamment à la validation, sur un `LogicException` nommant la nature absente.
 * Le seul applicateur enregistré dans toute l'application était celui des médias.
 *
 * ## Il écrit par le port, et non dans `Tree`
 *
 * Contrairement à `Media\Service\Proposition\ApplicateurDOperationMedia`, qui vit chez
 * `Media` et bascule le statut d'une de ses propres lignes, celui-ci vit chez `Proposal`
 * et passe par {@see PortArbre}. La différence n'est pas d'humeur :
 *
 * - une citation est une ligne de `tree_citation`, et **`Proposal` n'a pas le droit d'y
 *   toucher directement** (règle 5 de l'ADR 0002) ;
 * - `PortArbre` se présente comme « **le seul chemin** par lequel une validation touche
 *   l'arbre ». Un applicateur logé chez `Tree` qui manipulerait `Citation` en écrirait un
 *   second, et cette phrase-là deviendrait fausse sans que rien ne le dise ;
 * - `Proposal\Service\ApplicateurDeCreation` fait déjà exactement cela pour les personnes
 *   et les familles — il vit ici, et il crée par le port. C'est le motif du module, et
 *   c'est celui-ci qu'on suit.
 *
 * Le mécanisme d'enregistrement, lui, est **le même que celui des médias** : le tag
 * `app.proposal.applicateur_d_operation` posé par `#[AutoconfigureTag]` sur l'interface,
 * aucune ligne de `services.yaml`. Rien à câbler, et rien à oublier de câbler.
 *
 * ## Le sujet de la révision est la citation, pas la personne
 *
 * `prp_revision` reçoit `subject_kind = 'citation'`. La personne n'a pas changé de nom ni
 * de date : ses colonnes éditables sont intactes et sa `version` n'a pas bougé. Écrire une
 * révision sur elle ferait apparaître dans son historique une modification qui n'a pas eu
 * lieu. C'est le raisonnement de l'applicateur des médias, mot pour mot, et il tient ici
 * pour la même raison.
 *
 * **Deux colonnes de la personne bougent quand même** — `best_quay` et `citation_count`.
 * Ce sont des colonnes **dérivées**, que `Tree` recalcule dans `creerCitation()` : elles
 * ne sont pas proposables, elles ne sont pas dans la surface éditable, et elles ne
 * produisent pas de révision. Une fiche qui reçoit sa première source passe donc de
 * « aucune source » à « appuyée sur un acte » sans qu'aucun op ne l'ait demandé — c'est
 * exactement ce qu'on veut, et c'est pourquoi ces colonnes sont dérivées.
 *
 * ## Aucun verrou optimiste ne s'exerce ici
 *
 * Conséquence directe et voulue de ce qui précède : joindre une source à une fiche ne peut
 * pas entrer en conflit avec une correction de cette fiche, puisque les deux n'écrivent
 * pas au même endroit. Deux cousins peuvent sourcer une naissance pendant qu'un troisième
 * corrige un prénom, et les trois demandes passent. Le `base_version` des ops est `null`
 * pour cette raison — voir {@see ConstructeurDAjoutDeCitation}.
 *
 * La **disparition** de la personne, elle, reste couverte, et en amont : `AnalyseDeDemande`
 * charge la cible de toute op qui porte un `subject_id`, quelle que soit sa nature. Une
 * fiche effacée entre le dépôt et la validation rend `cible_disparue` — une caducité, avec
 * son bouton « refaire » — **avant** que cet applicateur ne soit appelé.
 */
final readonly class ApplicateurDOperationCitation implements ApplicateurDOperation
{
    public function __construct(private PortArbre $arbre)
    {
    }

    public function natures(): array
    {
        return [OpKind::AjoutCitation];
    }

    public function appliquer(DemandeEnCours $demande, array $ops, Lecteur $lecteur): array
    {
        $ecritures = [];

        foreach ($ops as $op) {
            $creee = $this->arbre->creerCitation($this->aCreer($op));

            $ecritures[] = new EcritureDeRevision(
                SubjectKind::Citation,
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
     * **La valeur d'op fait foi ici, et c'est le contraire de ce que fait `Media`.**
     * L'applicateur des médias retrouve son fichier par `subjectId` et ignore `newValue`,
     * parce que la ligne existe déjà et qu'elle a pu changer depuis. Une citation, elle,
     * **n'existe pas encore** : il n'y a aucune ligne à relire, et `newValue` est la seule
     * description de ce que l'auteur a proposé. C'est aussi ce que l'administrateur a lu
     * dans le diff avant de valider — écrire autre chose que ce qu'il a lu serait un
     * mensonge d'interface, pas une fraîcheur de donnée.
     *
     * Les deux identifiants qu'elle porte sont vérifiés une seconde fois par `Tree`, qui
     * lève si l'une des deux lignes a disparu depuis le dépôt.
     */
    private function aCreer(OpEnAttente $op): CitationAcreer
    {
        $valeur = $op->newValue;

        if (!\is_array($valeur)) {
            throw new \LogicException(\sprintf('L\'op %d de nature « ajout_citation » ne porte pas d\'objet en newValue : %s.', $op->seq, get_debug_type($valeur)));
        }

        if (null === $op->subjectId) {
            // Le constructeur refuse déjà un `subjectDraftRef` au dépôt. Ce garde-fou-ci
            // est le second, et il existe parce que la cause est structurelle et non
            // cosmétique : `ValidationDeDemande::ecrire()` applique les ops **avant** la
            // nature de la demande, donc avant que `ApplicateurDeCreation` n'ait inséré
            // les brouillons. À cet instant, `#p1` ne désigne encore aucune ligne. Si
            // l'ordre des passes change un jour, c'est ce message qu'on lira.
            throw new \LogicException(\sprintf('L\'op %d vise le brouillon « %s » : une citation ne peut pas être rattachée à une personne que la même demande n\'a pas encore créée.', $op->seq, (string) $op->subjectDraftRef));
        }

        return new CitationAcreer(
            $op->subjectId,
            $this->entier($valeur['sourceId'] ?? null, 'sourceId', $op->seq),
            $this->texte($valeur['page'] ?? null),
            $this->quay($valeur['quay'] ?? null, $op->seq),
            $this->texte($valeur['dataText'] ?? null),
            $this->texte($valeur['scope'] ?? null) ?? 'generale',
            $this->texte($valeur['url'] ?? null),
        );
    }

    /**
     * Le diff de `prp_revision`, dans la forme de `core_audit_log.changes` (§ 5.5).
     *
     * `avant` vaut `null` partout : c'est une création, et le § 5.5 le veut ainsi. Les
     * clés sont celles de la surface éditable d'une citation — `page`, `quay`,
     * `dataText` — plus la source, pour que l'historique d'une fiche dise **quelle** pièce
     * a été versée et pas seulement qu'une citation est apparue.
     *
     * @return array<string, mixed>
     */
    private function diff(OpEnAttente $op): array
    {
        /** @var array<string, mixed> $valeur */
        $valeur = \is_array($op->newValue) ? $op->newValue : [];

        return [
            'source' => ['avant' => null, 'apres' => $valeur['sourceTitle'] ?? null],
            'page' => ['avant' => null, 'apres' => $valeur['page'] ?? null],
            'quay' => ['avant' => null, 'apres' => $valeur['quay'] ?? null],
            'dataText' => ['avant' => null, 'apres' => $valeur['dataText'] ?? null],
            'scope' => ['avant' => null, 'apres' => $valeur['scope'] ?? null],
            'url' => ['avant' => null, 'apres' => $valeur['url'] ?? null],
        ];
    }

    private function entier(mixed $valeur, string $cle, int $seq): int
    {
        if (!\is_int($valeur)) {
            throw new \LogicException(\sprintf('L\'op %d de nature « ajout_citation » porte un « %s » qui n\'est pas un entier : %s.', $seq, $cle, get_debug_type($valeur)));
        }

        return $valeur;
    }

    private function texte(mixed $valeur): ?string
    {
        return \is_string($valeur) ? $valeur : null;
    }

    /**
     * Le `QUAY`, qui traverse le port en **entier** — l'exception assumée du § 2.7.
     *
     * `JSONB` et SQLite rendent parfois un entier stocké sous forme de chaîne ; on
     * accepte les deux à la relecture et on n'en écrit qu'une. `null` reste `null` :
     * « non renseigné » n'est pas `0`, qui affirme une recopie non vérifiée.
     */
    private function quay(mixed $valeur, int $seq): ?int
    {
        if (null === $valeur) {
            return null;
        }

        if (\is_int($valeur)) {
            return $valeur;
        }

        if (\is_string($valeur) && 1 === preg_match('/^\d+$/', $valeur)) {
            return (int) $valeur;
        }

        throw new \LogicException(\sprintf('L\'op %d de nature « ajout_citation » porte un QUAY illisible : %s.', $seq, get_debug_type($valeur)));
    }
}
