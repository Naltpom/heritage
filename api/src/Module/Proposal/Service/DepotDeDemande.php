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
use App\Module\Proposal\Exception\ErreurDeDemande;
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
     * **Le dépôt à blanc** : le corps passerait-il {@see deposer()} avec `soumettre: true` ?
     *
     * Écrit pour l'assistant de proposition, qui doit rendre au membre des demandes
     * « prêtes à déposer » et s'est engagé à ne jamais en rendre une cassée. Il ne
     * recopie **aucune** règle : ce sont les mêmes constructeurs, le même contrôle de
     * nature, le même contrôle de complétude à la soumission. Une seconde liste de
     * contrôles, tenue à côté, divergerait de la première à la prochaine livraison — et
     * l'écart ne se verrait qu'au clic du membre, sous forme d'une `422` sur une demande
     * qu'on lui avait présentée comme valide.
     *
     * ── Pourquoi on détache au lieu de ne pas persister ─────────────────────────────
     *
     * Les constructeurs appellent `persist()` sur les ops et les brouillons qu'ils
     * construisent : c'est leur contrat, et le contrôle « au moins un lien » du
     * constructeur de création lit justement l'unité de travail pour compter ce qui est
     * en attente d'insertion. On les laisse donc faire, puis **on détache tout ce qui est
     * entré dans l'unité de travail pendant l'appel**, qu'il ait réussi ou non. Sans ce
     * ménage, le premier `flush()` suivant de la même requête — celui du journal d'audit,
     * par exemple — écrirait en base une demande fantôme que personne n'a déposée.
     *
     * La demande elle-même n'est jamais persistée : elle ne sert que de porteur.
     *
     * @param array<string, mixed> $corps
     *
     * @throws ErreurDeDemande exactement celles que le dépôt lèverait
     */
    public function verifierSansEcrire(array $corps, Lecteur $lecteur): void
    {
        $this->verifierQuIlPeutEcrire($lecteur);

        $kind = $this->nature($corps);
        $constructeur = $this->constructeurPour($kind);

        $demande = new ChangeRequest($kind, $lecteur->userId, $lecteur->libelle);
        $demande->setMessage($this->message($corps));

        $unite = $this->em->getUnitOfWork();
        $deja = [];
        foreach ($unite->getScheduledEntityInsertions() as $entite) {
            $deja[spl_object_id($entite)] = true;
        }

        try {
            $constructeur->construire($demande, $corps, $lecteur);
            $constructeur->verifierAvantSoumission($demande);
        } finally {
            foreach ($unite->getScheduledEntityInsertions() as $entite) {
                if (!isset($deja[spl_object_id($entite)])) {
                    $this->em->detach($entite);
                }
            }
        }
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
