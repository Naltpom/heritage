<?php

declare(strict_types=1);

namespace App\Module\Proposal\Service;

use App\Module\Core\Contract\AncragePropose;
use App\Module\Core\Contract\CalqueEnAttenteInterface;
use App\Module\Core\Contract\Lecteur;
use App\Module\Core\Contract\NoeudPropose;
use App\Module\Core\Contract\ProjectionEnAttente;
use App\Module\Core\Enum\SubjectKind;
use App\Module\Proposal\Entity\ChangeRequest;
use App\Module\Proposal\Entity\DraftPerson;
use App\Module\Proposal\Enum\LinkKind;
use App\Module\Proposal\Repository\ChangeRequestRepository;
use App\Module\Proposal\Repository\DraftLinkRepository;
use App\Module\Proposal\Repository\DraftPersonRepository;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * L'implémentation de {@see CalqueEnAttenteInterface} — **la seule**.
 *
 * Elle est délibérément mince : le calque est déjà tenu par {@see CalqueEnAttente}, et
 * ce service ne fait que l'exposer sous le contrat publié, plus la projection des nœuds
 * en attente. Un second chemin de calcul serait un second endroit où la règle « visible
 * de l'auteur et des administrateurs, invisible d'un autre membre » pourrait être
 * fausse.
 *
 * **L'alias est en YAML (`config/services/proposal.yaml`) et non en `#[AsAlias]`**, et
 * ce choix a été inversé au moment où le contrat est remonté dans `Core`. Le motif
 * d'origine — « l'attribut ne peut pas être contourné par un ordre de chargement » — se
 * retourne ici : `Core` déclare un **repli fermé** (`Core\Contract\CalqueFerme`) dans
 * `config/services/core.yaml`, or les attributs sont traités avec `_autoload.yaml`,
 * chargé **avant** `core.yaml`. Un `#[AsAlias]` serait donc écrasé par le repli, et
 * l'application tournerait sans calque — sans erreur, sans test rouge. `proposal.yaml`
 * étant chargé après `core.yaml`, l'alias YAML l'emporte, ce qui est l'ordre voulu.
 */
final readonly class LectureDesPropositions implements CalqueEnAttenteInterface
{
    public function __construct(
        private CalqueEnAttente $calque,
        private ChangeRequestRepository $demandes,
        private DraftPersonRepository $brouillons,
        private DraftLinkRepository $liens,
        private NormaliseurDeValeur $normaliseur,
    ) {
    }

    public function calque(SubjectKind $kind, array $ids, Lecteur $lecteur): array
    {
        return $this->calque->pourPlusieurs($kind, $ids, $lecteur);
    }

    public function demandesSur(SubjectKind $kind, int $id, Lecteur $lecteur): array
    {
        if ($lecteur->regimeEnAttente) {
            return [];
        }

        $auteur = $lecteur->voitToutesLesDemandes() ? null : $lecteur->identiteEffective();
        $identifiants = [];

        foreach ($this->demandes->enAttentePourCible($kind, $id, $auteur) as $demande) {
            $demandeId = $demande->getId();

            if (null !== $demandeId) {
                $identifiants[] = $demandeId;
            }
        }

        return $identifiants;
    }

    public function empreinteDesDemandes(Lecteur $lecteur): string
    {
        if ($lecteur->regimeEnAttente) {
            return '';
        }

        $auteur = $lecteur->voitToutesLesDemandes() ? null : $lecteur->identiteEffective();
        $graines = [];

        foreach ($this->demandes->enAttenteDuLecteur($auteur) as $demande) {
            // `updated_at` et pas seulement l'identifiant : une demande qui passe de
            // `en_attente` à `refusee` sort de la liste, mais une demande dont le
            // brouillon est modifié y reste — et son nœud a changé. L'horodatage attrape
            // les deux.
            $graines[] = $demande->getId().'@'.$demande->getUpdatedAt()->getTimestamp();
        }

        // Chaîne vide et non un hachage du vide : le § 5.2 veut que l'`ETag` d'un
        // lecteur sans demande se réduise à l'empreinte de l'arbre, pour que deux
        // lecteurs sans calque partagent la même entrée de cache.
        return [] === $graines ? '' : hash('sha256', implode('|', $graines));
    }

    public function projection(Lecteur $lecteur): ProjectionEnAttente
    {
        // Le même garde-fou qu'au calque, et pour la même raison : un compte en attente
        // de rattachement ne dépose pas de demande, il n'a donc aucun nœud en attente à
        // recevoir — et surtout aucune donnée de personne vivante (§ 16.3).
        if ($lecteur->regimeEnAttente) {
            return ProjectionEnAttente::vide();
        }

        // `null` = toutes les demandes, et il n'est passé que pour un administrateur
        // hors bascule « voir comme un membre ». Tout autre lecteur est borné aux
        // siennes.
        $auteur = $lecteur->voitToutesLesDemandes() ? null : $lecteur->identiteEffective();

        $identifiants = [];
        $noeuds = [];
        $ancrages = [];

        foreach ($this->demandes->enAttenteAvecProjection($auteur) as $demande) {
            $demandeId = $demande->getId();

            if (null === $demandeId) {
                continue;
            }

            $identifiants[] = $demandeId;

            foreach ($this->brouillons->pourDemande($demande) as $brouillon) {
                $noeuds[] = $this->noeud($demande, $demandeId, $brouillon);
            }

            foreach ($this->ancragesDe($demande, $demandeId) as $ancrage) {
                $ancrages[] = $ancrage;
            }
        }

        return new ProjectionEnAttente($identifiants, $noeuds, $ancrages);
    }

    private function noeud(ChangeRequest $demande, int $demandeId, DraftPerson $brouillon): NoeudPropose
    {
        $reference = ltrim($brouillon->getLocalRef(), '#');

        return new NoeudPropose(
            $this->noeudDeBrouillon($demandeId, $brouillon->getLocalRef()),
            $demandeId,
            $reference,
            $this->displayName($brouillon),
            $brouillon->getGivenNames(),
            $brouillon->getSurname(),
            $brouillon->getMarriedName(),
            $brouillon->getSex()->value,
            $brouillon->isDeceased(),
            $this->normaliseur->ligneDates($brouillon->getBirth(), $brouillon->getDeath()),
            $demande->getAuthorLabel(),
        );
    }

    /**
     * Les ancrages d'une demande, avec allocation des familles provisoires.
     *
     * **Le losange n'existe nulle part**, et c'est le point que le rendu avait manqué :
     * un conjoint proposé sans famille existante laisse une arête sans extrémité. On en
     * alloue donc un en mémoire, numéroté dans l'ordre des liens — `#12/f1`, `#12/f2` —
     * et uniquement quand il en faut un : si le demandeur a désigné un **couple
     * existant** (`familyId`), le losange existe déjà dans l'arbre validé.
     *
     * @return list<AncragePropose>
     */
    private function ancragesDe(ChangeRequest $demande, int $demandeId): array
    {
        $ancrages = [];
        $rang = 0;

        foreach ($this->liens->pourDemande($demande) as $lien) {
            $familleId = $lien->getFamilyId();

            // Une filiation vers un couple existant se raccroche au losange déjà placé ;
            // tout autre lien en demande un nouveau.
            $provisoire = null === $familleId
                ? '#'.$demandeId.'/f'.(++$rang)
                : null;

            $ancrages[] = new AncragePropose(
                $demandeId,
                $lien->getSeq(),
                $lien->getKind()->value,
                $this->extremite($demandeId, $lien->getSubjectPersonId(), $lien->getSubjectDraftRef()) ?? '',
                $this->extremite($demandeId, $lien->getOtherPersonId(), $lien->getOtherDraftRef()),
                $this->extremite($demandeId, $lien->getOther2PersonId(), $lien->getOther2DraftRef()),
                $familleId,
                $provisoire,
                LinkKind::ConjointDe === $lien->getKind() && $lien->isDivorced(),
            );
        }

        return $ancrages;
    }

    /**
     * Un identifiant de nœud pour une extrémité de lien : `p55` si la personne existe,
     * `#12/p1` si elle est proposée.
     *
     * Les deux colonnes sont mutuellement exclusives en base (`ck_prp_link_cible`) ; on
     * rend `null` quand aucune n'est renseignée, ce qui est le cas légitime d'une
     * filiation désignant un couple existant.
     */
    private function extremite(int $demandeId, ?int $personId, ?string $draftRef): ?string
    {
        if (null !== $personId) {
            return 'p'.$personId;
        }

        return null === $draftRef ? null : $this->noeudDeBrouillon($demandeId, $draftRef);
    }

    /**
     * `#<demandeId>/<localRef sans dièse>` — la seule façon de nommer un nœud
     * provisoire.
     *
     * La composition est ici, et nulle part ailleurs : `local_ref` n'est unique que dans
     * sa demande, et un appelant qui concaténerait de son côté finirait par oublier le
     * préfixe le jour où il ne lit qu'une demande à la fois — exactement le cas où la
     * collision ne se voit pas.
     */
    private function noeudDeBrouillon(int $demandeId, string $localRef): string
    {
        return '#'.$demandeId.'/'.ltrim($localRef, '#');
    }

    /**
     * Un libellé de repli, **jamais la vérité** : `tree_person.display_name` est une
     * colonne dérivée que `Tree` compose selon sa propre règle, et c'est elle qui doit
     * s'appliquer pour qu'un nœud proposé soit nommé comme ses voisins.
     */
    private function displayName(DraftPerson $brouillon): string
    {
        $parties = array_filter([$brouillon->getGivenNames(), $brouillon->getSurname()], static fn (string $part): bool => '' !== trim($part));

        return '' === ($nom = implode(' ', $parties)) ? '(sans nom)' : $nom;
    }
}
