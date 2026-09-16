<?php

declare(strict_types=1);

namespace App\Module\Proposal\Service;

use App\Module\Core\Contract\Lecteur;
use App\Module\Proposal\Entity\ChangeRequest;
use App\Module\Proposal\Enum\RequestKind;
use App\Module\Proposal\Enum\RequestStatus;
use App\Module\Proposal\Exception\AccesRefuse;
use App\Module\Proposal\Exception\Conflit;
use App\Module\Proposal\Exception\DonneesInvalides;
use App\Module\Proposal\Service\Port\NotificateurDeDemandes;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Déposer, soumettre et retirer une demande.
 *
 * **Un membre ne modifie jamais directement.** Il crée une `ChangeRequest` portant des
 * `ChangeRequestOp`, et la table de l'objet visé n'est pas touchée tant que ce n'est pas
 * validé (§ 6.3 du dossier de mission). Ce service est le seul point d'entrée en
 * écriture côté membre, et il ne sait pas appliquer quoi que ce soit à l'arbre — c'est
 * {@see ValidationDeDemande} qui le sait, et seulement sur décision d'un administrateur.
 *
 * ── Une demande d'administrateur n'est pas auto-validée ──────────────────────────
 *
 * Elle entre dans la file comme les autres (§ 6.1). L'auto-validation est un **acte
 * distinct** : valider sa propre demande, ou éditer directement. Les confondre ici
 * ferait disparaître de la file les demandes qu'un administrateur dépose pour se les
 * faire relire — et il n'y a qu'un administrateur, donc personne pour s'en apercevoir.
 */
final readonly class DepotDeDemande
{
    /**
     * @param iterable<ConstructeurDeDemande> $constructeurs découverts par le tag
     *                                                       `app.proposal.constructeur_de_demande`
     */
    public function __construct(
        private EntityManagerInterface $em,
        #[AutowireIterator('app.proposal.constructeur_de_demande')]
        private iterable $constructeurs,
        private NotificateurDeDemandes $notificateur,
    ) {
    }

    /**
     * @param array<string, mixed> $corps
     */
    public function deposer(array $corps, Lecteur $lecteur): ChangeRequest
    {
        $this->verifierQuIlPeutEcrire($lecteur);

        $kind = $this->nature($corps);
        $constructeur = $this->constructeurPour($kind);

        $demande = new ChangeRequest($kind, $lecteur->userId, $lecteur->libelle);
        $demande->setMessage($this->message($corps));

        $this->em->persist($demande);
        $constructeur->construire($demande, $corps, $lecteur);

        // Défaut `true` : le cas courant est de proposer, pas d'enregistrer un brouillon.
        // Un défaut inverse ferait que les premières contributions d'un membre restent
        // invisibles de l'administrateur sans que personne ne comprenne pourquoi.
        $soumettre = $corps['soumettre'] ?? true;

        if (!\is_bool($soumettre)) {
            throw DonneesInvalides::pourChamp('soumettre', 'Cette valeur doit être true ou false.');
        }

        if ($soumettre) {
            $constructeur->verifierAvantSoumission($demande);
            $demande->soumettre();
        }

        $this->em->flush();

        if ($soumettre) {
            $this->notificateur->demandeSoumise($demande);
        }

        return $demande;
    }

    /**
     * Soumettre un brouillon : il quitte l'écran de son auteur pour la file
     * d'administration.
     *
     * C'est **ici** que se fait le contrôle de complétude, et non à la création : « on
     * peut enregistrer un brouillon incomplet » (§ 6.3). Une demande de création sans
     * lien rend `422 demande_sans_lien`.
     */
    public function soumettre(ChangeRequest $demande, Lecteur $lecteur): ChangeRequest
    {
        $this->verifierQuIlPeutEcrire($lecteur);
        $this->verifierQuIlEstLAuteur($demande, $lecteur);

        if (RequestStatus::Brouillon !== $demande->getStatus()) {
            throw Conflit::dejaSoumise($demande->getStatus());
        }

        $this->constructeurPour($demande->getKind())->verifierAvantSoumission($demande);

        $demande->soumettre();
        $this->em->flush();

        $this->notificateur->demandeSoumise($demande);

        return $demande;
    }

    /**
     * Retirer sa demande avant décision.
     *
     * `annulee` est un statut définitif : la demande reste consultable, comme les
     * refusées et les caduques. **Une demande n'est jamais supprimée** — elle est
     * l'historique de la discussion familiale autour d'une fiche (ADR 0004).
     */
    public function retirer(ChangeRequest $demande, Lecteur $lecteur): ChangeRequest
    {
        $this->verifierQuIlPeutEcrire($lecteur);
        $this->verifierQuIlEstLAuteur($demande, $lecteur);

        if ($demande->getStatus()->estDefinitif()) {
            throw Conflit::dejaDecidee($demande->getStatus());
        }

        $demande->setStatus(RequestStatus::Annulee)->toucher();
        $this->em->flush();

        return $demande;
    }

    /**
     * Le garde-fou commun à toute écriture du module.
     *
     * Deux refus, pour deux raisons sans rapport : un compte `en_attente` de
     * rattachement n'a pas de cercle et ne contribue pas (§ 12) ; un administrateur
     * sous la bascule « voir comme un membre » n'écrit pas, sans quoi une
     * `prp_revision` porterait son nom pendant que l'écran montre quelqu'un d'autre
     * (§ 10.4, point 9 du § 18).
     */
    private function verifierQuIlPeutEcrire(Lecteur $lecteur): void
    {
        if ($lecteur->regimeEnAttente) {
            throw AccesRefuse::regimeEnAttente();
        }

        if (null !== $lecteur->membreEmprunteUserId) {
            throw Conflit::vueCommeMembreActive($lecteur->membreEmprunteUserId);
        }
    }

    private function verifierQuIlEstLAuteur(ChangeRequest $demande, Lecteur $lecteur): void
    {
        // Un administrateur n'échappe pas à cette règle : soumettre ou retirer la
        // demande d'un autre reviendrait à décider à sa place sans laisser de trace de
        // décision. Pour trancher, il a `valider` et `refuser`, qui en laissent une.
        if ($demande->getAuthorUserId() !== $lecteur->userId) {
            throw AccesRefuse::demandeDAutrui();
        }
    }

    /** @param array<string, mixed> $corps */
    private function nature(array $corps): RequestKind
    {
        $kind = $corps['kind'] ?? null;

        if (!\is_string($kind) || null === ($nature = RequestKind::tryFrom($kind))) {
            throw DonneesInvalides::pourChamp('kind', 'Nature de demande inconnue.');
        }

        return $nature;
    }

    /** @param array<string, mixed> $corps */
    private function message(array $corps): ?string
    {
        $message = $corps['message'] ?? null;

        if (null !== $message && !\is_string($message)) {
            throw DonneesInvalides::pourChamp('message', 'Le mot du demandeur est une chaîne.');
        }

        $message = null === $message ? null : trim($message);

        return '' === $message ? null : $message;
    }

    private function constructeurPour(RequestKind $kind): ConstructeurDeDemande
    {
        foreach ($this->constructeurs as $constructeur) {
            if (\in_array($kind, $constructeur->natures(), true)) {
                return $constructeur;
            }
        }

        // Les trois natures restantes — `creation_personne`, `ajout_lien`,
        // `ajout_media` — attendent leur constructeur (voir ConstructeurDeDemande). Le
        // dire franchement vaut mieux qu'une 500 : le front saura que la route existe,
        // que la nature est prévue, et qu'elle n'est pas encore branchée.
        throw new DonneesInvalides(['kind' => \sprintf('La nature « %s » n\'est pas encore prise en charge par cette livraison.', $kind->value)], 'Cette nature de demande n\'est pas encore disponible.');
    }
}
