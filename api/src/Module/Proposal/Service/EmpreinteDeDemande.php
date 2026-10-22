<?php

declare(strict_types=1);

namespace App\Module\Proposal\Service;

use App\Module\Proposal\Entity\ChangeRequest;
use App\Module\Proposal\Enum\ValueKind;
use App\Module\Proposal\Exception\Conflit;
use App\Module\Proposal\Exception\DonneesInvalides;
use App\Module\Proposal\Repository\DraftLinkRepository;
use App\Module\Proposal\Repository\DraftPersonRepository;

/**
 * L'**empreinte d'une demande** : « est-ce encore celle que j'ai sous les yeux ? »
 * (§ 6.5, § 6.6 ter, § 6.7 bis et § 6.8 du contrat ; ADR 0017).
 *
 * ── Pourquoi elle existe ────────────────────────────────────────────────────────
 *
 * Jusqu'au 10/10/2026, une demande enregistrée ne changeait plus : son auteur pouvait la
 * soumettre ou la retirer, l'administrateur la trancher, et c'était tout. Depuis, elle se
 * **complète sur place** — par son auteur, par un administrateur — et se **fusionne** avec
 * un complément. Deux personnes peuvent donc écrire dans la même fiche à quelques minutes
 * d'intervalle : l'administrateur ouvre la proposition, l'auteur y ajoute une date de
 * décès, l'administrateur valide ce qu'il a lu… et valide en fait autre chose. Ou il
 * complète la fiche à son tour, et efface sans le voir ce que l'auteur venait d'écrire —
 * le `PUT` remplace tous les champs.
 *
 * Le verrou n° 1 de l'arbre (`#[ORM\Version]`) ne sert à rien ici : `prp_change_request`
 * n'a pas de version, et en ajouter une demanderait une migration pour un besoin que la
 * donnée couvre déjà. L'empreinte est donc **calculée**, jamais stockée : un sha256 de ce
 * que l'écran montre et de ce qu'une écriture changerait.
 *
 * ── Ce qui entre dans le calcul, et pourquoi chaque morceau ─────────────────────
 *
 * - le **statut** : une demande tranchée entre-temps n'est plus celle qu'on avait lue ;
 * - `updated_at`, **à la seconde** : la colonne est un `TIMESTAMP(0)`, et un horodatage
 *   gardé en mémoire avec ses microsecondes rendrait, juste après une écriture, une
 *   empreinte que la relecture ne retrouverait plus. Il attrape tout ce qui `toucher()` la
 *   demande sans changer ses fiches — un libellé rafraîchi, un repointage ;
 * - **chaque fiche**, champ par champ, dates comprises sous leur forme JSON normalisée — la
 *   même que celle du contrôle préalable ({@see AnalyseDeDemande}), indépendante de l'ordre
 *   des clés que rend la base. C'est ce qui distingue deux écritures de la même seconde ;
 * - **chaque lien** par son rang et ses extrémités : un lien ajouté change ce qu'une
 *   validation créerait. Et, pour une union, par ce qu'elle écrira sur la famille — sa
 *   nature (`married`, ADR 0020), sa rupture et leurs dates : deux liens « conjoint de X »
 *   dont l'un dit « mariés » et l'autre « union libre » ne valident pas la même chose.
 *
 * Ni le message, ni les ops : ils ne changent jamais après le dépôt.
 *
 * ── Ce qu'elle n'est pas ────────────────────────────────────────────────────────
 *
 * Ce n'est **pas** l'empreinte de l'arbre (§ 2.3), ni celle du calque
 * (`LectureDesPropositions::empreinteDesDemandes()`) : celles-ci invalident un cache de
 * lecture ; celle-ci protège une écriture. Elle est **facultative** à l'envoi — un client
 * qui ne la passe pas écrit comme avant, et c'est ce qui rend son ajout compatible.
 */
final readonly class EmpreinteDeDemande
{
    public function __construct(
        private DraftPersonRepository $brouillons,
        private DraftLinkRepository $liens,
        private NormaliseurDeValeur $normaliseur,
    ) {
    }

    public function de(ChangeRequest $demande): string
    {
        $fiches = [];

        foreach ($this->brouillons->pourDemande($demande) as $fiche) {
            $fiches[] = [
                $fiche->getId(),
                $fiche->getLocalRef(),
                $fiche->getGivenNames(),
                $fiche->getSurname(),
                $fiche->getMarriedName(),
                $fiche->getNickname(),
                $fiche->getSex()->value,
                $fiche->isDeceased(),
                $this->normaliseur->versJson(ValueKind::DateIncertaine, $fiche->getBirth()),
                $this->normaliseur->versJson(ValueKind::DateIncertaine, $fiche->getDeath()),
                $fiche->getNote(),
            ];
        }

        $liens = [];

        foreach ($this->liens->pourDemande($demande) as $lien) {
            $liens[] = [
                $lien->getSeq(),
                $lien->getKind()->value,
                $lien->getSubjectPersonId(), $lien->getSubjectDraftRef(), $lien->getSubjectDraftPersonId(),
                $lien->getOtherPersonId(), $lien->getOtherDraftRef(), $lien->getOtherDraftPersonId(),
                $lien->getOther2PersonId(), $lien->getOther2DraftRef(), $lien->getOther2DraftPersonId(),
                $lien->getFamilyId(),
                $this->normaliseur->versJson(ValueKind::DateIncertaine, $lien->getMarriage()),
                $lien->isMarried(),
                $lien->isDivorced(),
                $this->normaliseur->versJson(ValueKind::DateIncertaine, $lien->getDivorce()),
            ];
        }

        return hash('sha256', json_encode([
            $demande->getId(),
            $demande->getStatus()->value,
            $demande->getUpdatedAt()->getTimestamp(),
            $fiches,
            $liens,
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE));
    }

    /**
     * Refuse l'écriture si l'empreinte **fournie** ne correspond plus — et ne fait rien si
     * le client n'en a pas fourni (ajout compatible).
     *
     * L'appelant la place **après** ses propres `409` de statut : une demande tranchée
     * entre-temps doit se dire `demande_deja_decidee`, qui est plus précis que « modifiée ».
     *
     * @throws DonneesInvalides si `empreinte` n'est ni une chaîne ni `null`
     * @throws Conflit          `demande_modifiee_entre_temps`
     */
    public function exiger(ChangeRequest $demande, mixed $fournie, string $champ = 'empreinte'): void
    {
        if (null === $fournie) {
            return;
        }

        if (!\is_string($fournie) || '' === $fournie) {
            throw DonneesInvalides::pourChamp($champ, 'L\'empreinte est la chaîne reçue avec la demande, ou null.');
        }

        $courante = $this->de($demande);

        if (!hash_equals($courante, $fournie)) {
            throw Conflit::demandeModifieeEntreTemps($demande->getId() ?? 0, $courante);
        }
    }
}
