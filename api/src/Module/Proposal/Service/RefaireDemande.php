<?php

declare(strict_types=1);

namespace App\Module\Proposal\Service;

use App\Module\Proposal\Contract\RetraitDeLien;
use App\Module\Proposal\Entity\ChangeRequest;
use App\Module\Proposal\Enum\LinkKind;
use App\Module\Proposal\Enum\OpKind;
use App\Module\Proposal\Enum\RequestStatus;
use App\Module\Proposal\Enum\ValueKind;
use App\Module\Proposal\Exception\Conflit;
use App\Module\Proposal\Repository\ChangeRequestOpRepository;
use App\Module\Proposal\Repository\DraftLinkRepository;
use App\Module\Proposal\Repository\DraftPersonRepository;
use App\Module\Proposal\Service\Port\PortArbre;

/**
 * « Refaire cette demande » — **lecture seule, aucune écriture**.
 *
 * Rend un corps prêt à être posté sur `POST /api/demandes`, pré-rempli sur les
 * **valeurs courantes** : `oldValue` et `baseVersion` sont rafraîchis, `newValue` est
 * conservé (§ 6.10 du contrat d'API).
 *
 * **Ce n'est pas un agrément d'interface.** Une caducité sans ce bouton est une
 * contribution perdue, et un membre dont la première contribution se perd n'en dépose
 * pas de seconde (§ 9.6 du modèle de données). C'est aussi pour cela que le service
 * n'écrit rien : il n'y a **pas** d'action « rebaser la demande » à la main — le rejeu
 * automatique couvre exactement le cas qu'un rebasage aurait traité, et le reste est une
 * caducité qu'un humain doit rouvrir. « Deux mécanismes pour un cas, ce serait deux
 * écrans et deux sources de bogue » (ADR 0004).
 *
 * `soumettre` vaut `false` dans le corps rendu : on ne resoumet pas à l'insu de
 * l'auteur une demande qu'il doit d'abord relire contre les nouvelles valeurs.
 *
 * **`complementDe` n'est pas repris** (ADR 0017), et c'est une décision, pas un oubli :
 * un complément refusé se refait en **création ordinaire**. La proposition qu'il complétait
 * a pu être validée, fusionnée ou refusée depuis ; rattacher la reprise à l'aveugle la
 * ferait refuser au dépôt (`proposition_plus_en_attente`) ou, pire, la verserait dans une
 * fiche que son auteur ne voit plus. S'il veut compléter de nouveau, la ressemblance le lui
 * proposera, sur l'état du jour.
 */
final readonly class RefaireDemande
{
    public function __construct(
        private ChangeRequestOpRepository $ops,
        private DraftPersonRepository $brouillons,
        private DraftLinkRepository $liens,
        private PortArbre $arbre,
        private NormaliseurDeValeur $normaliseur,
        private ValeursMasquees $masques,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function corpsPreRempli(ChangeRequest $demande): array
    {
        // Disponible sur `caduque` et `refusee` : les deux fins de course qu'un auteur
        // peut vouloir reprendre. Sur `validee` ou `en_attente`, il n'y a rien à
        // refaire — dans le premier cas c'est fait, dans le second c'est en cours.
        if (!\in_array($demande->getStatus(), [RequestStatus::Caduque, RequestStatus::Refusee], true)) {
            throw Conflit::nonRefaisable($demande->getStatus());
        }

        $ops = [];
        $cibles = [];

        foreach ($this->ops->pourDemande($demande) as $op) {
            $sujet = $op->getSubjectId();
            $champ = $op->getField();

            if (null === $sujet || null === $champ) {
                continue;
            }

            $cle = $op->getSubjectKind()->value.':'.$sujet;
            $cibles[$cle] ??= $this->arbre->chargerCible($op->getSubjectKind(), $sujet);
            $cible = $cibles[$cle];

            // Une cible disparue depuis : on ne peut plus pré-remplir sur ses valeurs
            // courantes, et un op sans `oldValue` fiable serait refusé au dépôt. On
            // l'omet plutôt que de proposer une reprise qu'on sait fausse.
            if (null === $cible || !\array_key_exists($champ, $cible->valeurs)) {
                continue;
            }

            // Une valeur que l'auteur n'a pas le droit de lire — l'op d'une demande de
            // l'assistant sur un vivant hors de son cercle — ne se pré-remplit pas : la
            // reprise la lui servirait fraîchement relue. L'op est omis, comme celui d'une
            // cible disparue ; le reste de la demande se refait normalement.
            if ($this->masques->cibleFermee($op->getSubjectKind(), $sujet)) {
                continue;
            }

            $ops[] = [
                'seq' => $op->getSeq(),
                'opKind' => $op->getOpKind()->value,
                'subjectKind' => $op->getSubjectKind()->value,
                'subjectId' => $sujet,
                'field' => $champ,
                'valueKind' => $op->getValueKind()->value,
                // Rafraîchis : c'est tout l'objet de la reprise.
                'oldValue' => $this->normaliseur->versJson($op->getValueKind(), $cible->valeurs[$champ]),
                'newValue' => $op->getNewValue(),
                'baseVersion' => $cible->version,
            ];
        }

        return [
            'kind' => $demande->getKind()->value,
            'message' => $demande->getMessage(),
            'soumettre' => false,
            'targetKind' => $demande->getTargetKind()?->value,
            'targetId' => $demande->getTargetId(),
            'ops' => $ops,
            // Une création refusée se refait aussi : sa fiche et ses liens repartent tels
            // quels, puisque rien de ce qu'ils décrivent n'existe encore et que rien
            // n'a donc pu bouger sous eux. C'est le pendant exact du rafraîchissement
            // des `oldValue` ci-dessus — là où il y a une valeur courante, on la relit ;
            // là où il n'y en a pas, on reprend ce qui était proposé.
            'brouillons' => $this->brouillonsRefaits($demande),
            'liens' => $this->liensRefaits($demande),
            // Un retrait de lien se refait **sans sa mesure** : le corps de dépôt ne porte
            // que les liens désignés, et c'est le serveur qui remesurera ce qu'ils font —
            // sur l'arbre d'aujourd'hui, qui est la raison même de la reprise.
            'retraits' => $this->retraitsRefaits($demande),
            // `origine` est rendu tel quel dans le `POST` suivant, qui le stocke dans
            // `message` : il n'y a pas de colonne pour le chaînage d'une demande refaite,
            // et il n'en faut pas — `duplicate_of_id` sert au rapprochement de doublons,
            // pas à l'historique d'une reprise (§ 6.10).
            'origine' => [
                'demandeId' => $demande->getId(),
                'statut' => $demande->getStatus()->value,
            ],
        ];
    }

    /**
     * Les liens qu'une demande `retrait_lien` défaisait, dans la forme du corps de dépôt.
     *
     * @return list<array<string, mixed>>
     */
    private function retraitsRefaits(ChangeRequest $demande): array
    {
        $retraits = [];

        foreach ($this->ops->pourDemande($demande) as $op) {
            $mesure = $op->getOldValue();

            if (OpKind::RetraitLien !== $op->getOpKind() || !\is_array($mesure)) {
                continue;
            }

            /** @var array<string, mixed> $mesure */
            $lien = RetraitDeLien::depuisJson($mesure)->lien();
            $retrait = ['kind' => $lien->nature->value, 'personId' => $lien->personneId, 'familyId' => $lien->familleId];

            if (null !== $lien->parentId) {
                $retrait['parentId'] = $lien->parentId;
            }

            $retraits[] = $retrait;
        }

        return $retraits;
    }

    /**
     * Les fiches proposées, dans la forme du corps de `POST /api/demandes` — **cinq
     * clés** pour chaque bloc date, `texte` retiré.
     *
     * C'est la seule manipulation que le § 14.1 impose à qui reposte un bloc reçu, et
     * elle est faite ici pour que le front n'ait pas à la connaître : il reposte ce
     * corps tel quel.
     *
     * @return list<array<string, mixed>>
     */
    private function brouillonsRefaits(ChangeRequest $demande): array
    {
        $refaits = [];

        foreach ($this->brouillons->pourDemande($demande) as $brouillon) {
            $refaits[] = [
                'localRef' => $brouillon->getLocalRef(),
                'givenNames' => $brouillon->getGivenNames(),
                'surname' => $brouillon->getSurname(),
                'marriedName' => $brouillon->getMarriedName(),
                'nickname' => $brouillon->getNickname(),
                'sex' => $brouillon->getSex()->value,
                'deceased' => $brouillon->isDeceased(),
                'birth' => $this->normaliseur->versJson(ValueKind::DateIncertaine, $brouillon->getBirth()),
                'death' => $this->normaliseur->versJson(ValueKind::DateIncertaine, $brouillon->getDeath()),
                'note' => $brouillon->getNote(),
            ];
        }

        return $refaits;
    }

    /**
     * Les liens, dans la même forme.
     *
     * `resolvedFamilyId` n'est **pas** repris : c'est le résultat d'une validation
     * passée, et une reprise redécrit une intention, pas un résultat. Le service
     * déduira de nouveau la famille au moment de valider — sur l'état réel de l'arbre,
     * qui a pu changer.
     *
     * ── Une référence à une fiche proposée ailleurs est reprise **telle quelle** ────
     *
     * Et c'est le point de passage obligé : elle sera **revérifiée au dépôt** par
     * {@see ConstructeurDeCreation}, qui refusera une demande morte et **résoudra en
     * personne réelle** une demande validée depuis. Filtrer ici ce que le constructeur
     * sait mieux trancher là-bas ferait deux règles pour un cas — et la reprise
     * retirerait silencieusement une extrémité que le dépôt aurait acceptée.
     *
     * @return list<array<string, mixed>>
     */
    private function liensRefaits(ChangeRequest $demande): array
    {
        $refaits = [];

        foreach ($this->liens->pourDemande($demande) as $lien) {
            $refait = array_filter([
                'seq' => $lien->getSeq(),
                'kind' => $lien->getKind()->value,
                'subjectPersonId' => $lien->getSubjectPersonId(),
                'subjectDraftRef' => $lien->getSubjectDraftRef(),
                'subjectDraftPersonId' => $lien->getSubjectDraftPersonId(),
                'otherPersonId' => $lien->getOtherPersonId(),
                'otherDraftRef' => $lien->getOtherDraftRef(),
                'otherDraftPersonId' => $lien->getOtherDraftPersonId(),
                'other2PersonId' => $lien->getOther2PersonId(),
                'other2DraftRef' => $lien->getOther2DraftRef(),
                'other2DraftPersonId' => $lien->getOther2DraftPersonId(),
                'familyId' => $lien->getFamilyId(),
                'siblingOrder' => $lien->getSiblingOrder(),
                'note' => $lien->getNote(),
            ], static fn (mixed $valeur): bool => null !== $valeur);

            if (LinkKind::ConjointDe === $lien->getKind()) {
                $refait['marriage'] = $this->normaliseur->versJson(ValueKind::DateIncertaine, $lien->getMarriage());
                // Repris même à `null` — « on ne sait pas » est une réponse (ADR 0020).
                $refait['married'] = $lien->isMarried();
                $refait['divorced'] = $lien->isDivorced();
                $refait['divorce'] = $this->normaliseur->versJson(ValueKind::DateIncertaine, $lien->getDivorce());
            }

            $refaits[] = $refait;
        }

        return $refaits;
    }
}
