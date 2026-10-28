<?php

declare(strict_types=1);

namespace App\Module\Proposal\Service;

use App\Module\Core\Contract\Lecteur;
use App\Module\Core\Enum\SubjectKind;
use App\Module\Core\Exception\DomainException;
use App\Module\Core\Security\CercleDeVisibiliteInterface;
use App\Module\Proposal\Contract\RecueilDErreurs;
use App\Module\Proposal\Entity\ChangeRequest;
use App\Module\Proposal\Enum\RequestKind;
use App\Module\Proposal\Enum\RequestStatus;
use App\Module\Proposal\Exception\AccesRefuse;
use App\Module\Proposal\Exception\Conflit;
use App\Module\Proposal\Exception\DonneesInvalides;
use App\Module\Proposal\Exception\ErreurDeDemande;
use App\Module\Proposal\Exception\Introuvable;
use App\Module\Proposal\Repository\ChangeRequestRepository;
use App\Module\Proposal\Service\Port\NotificateurDeDemandes;
use App\Module\Proposal\Service\Port\PortArbre;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
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
        private CercleDeVisibiliteInterface $cercle,
        private PortArbre $arbre,
        private ChangeRequestRepository $demandes,
    ) {
    }

    /**
     * Avec `$cle`, la clé et l'empreinte sont posées sur la demande, et une course perdue sur
     * `uniq_prp_request_idempotence` rend `409 depot_concurrent`. **Ce service ne cherche pas
     * de rejeu** : c'est {@see demandeDejaDeposee()}, que le contrôleur appelle avant.
     *
     * @param array<string, mixed> $corps
     */
    public function deposer(array $corps, Lecteur $lecteur, ?CleDIdempotence $cle = null): ChangeRequest
    {
        self::verifierQuIlPeutEcrire($lecteur);

        $kind = $this->nature($corps);
        self::refuserUnComplementHorsCreation($kind, $corps);
        $constructeur = $this->constructeurPour($kind);

        $demande = new ChangeRequest($kind, $lecteur->userId, $lecteur->libelle);
        $demande->setMessage($this->message($corps));

        if (null !== $cle) {
            $demande->poserLaCleDIdempotence($cle->valeur, $cle->empreinte);
        }

        $this->em->persist($demande);
        $constructeur->construire($demande, $corps, $lecteur);
        $this->verifierDansLePerimetre($demande);

        $soumettre = self::soumettreDuCorps($corps);

        if ($soumettre) {
            $constructeur->verifierAvantSoumission($demande);
            $demande->soumettre();
        }

        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException $e) {
            // Deux dépôts de même clé au même instant : PostgreSQL a laissé passer le premier
            // et refuse le second, dont la transaction est annulée. Rien n'est écrit, et la
            // notification, qui suit, ne part pas. L'EntityManager est fermé : on ne demande
            // à la base que l'identifiant du gagnant, par la connexion seule. Sans clé, ou
            // sans gagnant, c'est une autre contrainte qui a parlé : la panne repart telle quelle.
            $gagnante = null !== $cle ? $this->demandes->idParCleDIdempotence($lecteur->userId, $cle->valeur) : null;

            if (null === $gagnante) {
                throw $e;
            }

            throw Conflit::depotConcurrent($gagnante);
        }

        if ($soumettre) {
            $this->notificateur->demandeSoumise($demande);
        }

        return $demande;
    }

    /**
     * Le rejeu (ADR 0022) : la demande que ce lecteur a déjà déposée sous cette clé, ou
     * `null`. Les garde-fous d'écriture passent d'abord : un rejeu est une écriture différée.
     * La clé d'un autre compte n'est pas trouvée, puisque la recherche porte sur le lecteur.
     *
     * @throws DonneesInvalides `idempotence_corps_different` si la clé a servi pour un autre corps
     */
    public function demandeDejaDeposee(CleDIdempotence $cle, Lecteur $lecteur): ?ChangeRequest
    {
        self::verifierQuIlPeutEcrire($lecteur);

        $deja = $this->demandes->parCleDIdempotence($lecteur->userId, $cle->valeur);

        if (null === $deja) {
            return null;
        }

        if ($deja->getIdempotencyFingerprint() !== $cle->empreinte) {
            throw DonneesInvalides::cleDIdempotenceDejaEmployee((int) $deja->getId());
        }

        return $deja;
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
     * ── Le statut est celui du dépôt ────────────────────────────────────────────────
     *
     * Les contrôles se jouent dans l'ordre de `deposer()`. Une `422` est notée et la
     * vérification continue. Une autre erreur arrête tout. La première rencontrée décide
     * donc du statut, comme au dépôt.
     *
     * **Une `422` les porte toutes** : une par champ de l'enveloppe, une par élément des
     * listes (voir {@see RecueilDErreurs}). Une seule erreur sort intacte : l'assistant lit
     * la même première clé qu'avant.
     *
     * **`$lireSoumettre`** vaut `false` par défaut, pour l'assistant : on contrôle comme si
     * `soumettre` valait `true`, et la clé est ignorée. La route `POST /api/demandes/verifier`
     * passe `true` : `soumettre` est lu comme au dépôt, et `false` dispense du contrôle de
     * complétude.
     *
     * @param array<string, mixed> $corps
     *
     * @throws ErreurDeDemande la première que le dépôt lèverait ; si c'est une 422, avec toutes celles qu'une passe établit
     */
    public function verifierSansEcrire(array $corps, Lecteur $lecteur, bool $lireSoumettre = false): void
    {
        self::verifierQuIlPeutEcrire($lecteur);

        $recueil = new RecueilDErreurs();
        $kind = null;
        $constructeur = null;
        $message = null;

        try {
            $kind = $this->nature($corps);
        } catch (DomainException $e) {
            RecueilDErreurs::recueillir($recueil, $e);
        }

        if (null !== $kind) {
            try {
                self::refuserUnComplementHorsCreation($kind, $corps);
            } catch (DomainException $e) {
                RecueilDErreurs::recueillir($recueil, $e);
            }

            try {
                $constructeur = $this->constructeurPour($kind);
            } catch (DomainException $e) {
                RecueilDErreurs::recueillir($recueil, $e);
            }
        }

        try {
            $message = $this->message($corps);
        } catch (DomainException $e) {
            RecueilDErreurs::recueillir($recueil, $e);
        }

        if (null !== $kind && null !== $constructeur) {
            $demande = new ChangeRequest($kind, $lecteur->userId, $lecteur->libelle);
            $demande->setMessage($message);

            $unite = $this->em->getUnitOfWork();
            $deja = [];
            foreach ($unite->getScheduledEntityInsertions() as $entite) {
                $deja[spl_object_id($entite)] = true;
            }

            try {
                $construite = false;

                try {
                    $constructeur->construire($demande, $corps, $lecteur, new RecueilDErreurs());
                    $this->verifierDansLePerimetre($demande);
                    $construite = true;
                } catch (DomainException $e) {
                    RecueilDErreurs::recueillir($recueil, $e);
                }

                // Lu après la construction, comme au dépôt : une 404 de cible passe avant.
                $soumettre = true;

                if ($lireSoumettre) {
                    try {
                        $soumettre = self::soumettreDuCorps($corps);
                    } catch (DomainException $e) {
                        RecueilDErreurs::recueillir($recueil, $e);
                        $soumettre = false;
                    }
                }

                if ($construite && $soumettre) {
                    try {
                        $constructeur->verifierAvantSoumission($demande);
                    } catch (DomainException $e) {
                        RecueilDErreurs::recueillir($recueil, $e);
                    }
                }
            } finally {
                foreach ($unite->getScheduledEntityInsertions() as $entite) {
                    if (!isset($deja[spl_object_id($entite)])) {
                        $this->em->detach($entite);
                    }
                }
            }
        } elseif ($lireSoumettre) {
            try {
                self::soumettreDuCorps($corps);
            } catch (DomainException $e) {
                RecueilDErreurs::recueillir($recueil, $e);
            }
        }

        $recueil->leverSiNonVide();
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
        self::verifierQuIlPeutEcrire($lecteur);
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
        self::verifierQuIlPeutEcrire($lecteur);
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
    /**
     * La cible est-elle dans le **périmètre** de l'auteur — son arbre, ou un arbre sans
     * lien connu (révision du 2026-10-04 de l'ADR 0003) ? Sinon, la même `404` qu'une
     * cible inexistante : proposer sur l'arbre d'une autre famille ne doit ni réussir, ni
     * dire que la fiche existe. Une famille est dans l'arbre de ses époux.
     */
    private function verifierDansLePerimetre(ChangeRequest $demande): void
    {
        $kind = $demande->getTargetKind();
        $id = $demande->getTargetId();

        if (null === $kind || null === $id) {
            return;
        }

        $personnes = match ($kind) {
            SubjectKind::Personne => [$id],
            SubjectKind::Famille => $this->arbre->epouxDeFamille($id),
            default => [],
        };

        if ([] === $personnes) {
            return;
        }

        foreach ($personnes as $personne) {
            if ($this->cercle->dansLePerimetre($personne)) {
                return;
            }
        }

        throw Introuvable::cible($kind->value, $id);
    }

    /**
     * **Partagé** depuis le 10/10/2026 : la complétion sur place
     * ({@see CompletionDeProposition}) et la fusion d'un complément
     * ({@see FusionDeComplement}) sont des écritures du module, et refusent les mêmes
     * lecteurs pour les mêmes raisons. Une méthode statique et pure plutôt qu'une copie de
     * plus : deux copies d'un garde-fou finissent par en faire deux.
     */
    public static function verifierQuIlPeutEcrire(Lecteur $lecteur): void
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

    /**
     * `complementDe` **hors d'une `creation_personne`** : refusé ici, pour toutes les
     * natures, avant le constructeur (ADR 0017).
     *
     * Seul {@see ConstructeurDeCreation} lit cette clé. Sans ce contrôle, une
     * `modification_personne` qui la porterait passerait par un autre constructeur, qui
     * l'ignorerait — et le client croirait avoir déposé un complément que personne ne
     * fusionnera. `ck_prp_request_complement` le refuserait aussi en base, mais trop tard :
     * la colonne n'aurait jamais été posée. Une clé ignorée en silence est la pire des deux
     * réponses.
     *
     * @param array<string, mixed> $corps
     */
    private static function refuserUnComplementHorsCreation(RequestKind $kind, array $corps): void
    {
        if (null !== ($corps['complementDe'] ?? null) && RequestKind::CreationPersonne !== $kind) {
            throw DonneesInvalides::pourChamp('complementDe', 'Seule une création de personne complète une proposition.');
        }
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

    /**
     * Défaut `true` : le cas courant est de proposer, pas d'enregistrer un brouillon.
     * Un défaut inverse ferait que les premières contributions d'un membre restent
     * invisibles de l'administrateur sans que personne ne comprenne pourquoi.
     *
     * `soumettreDuCorps()` et non `soumettre()` : le nom est pris par la méthode publique qui
     * soumet un brouillon, et PHP ne surcharge pas.
     *
     * @param array<string, mixed> $corps
     */
    private static function soumettreDuCorps(array $corps): bool
    {
        $soumettre = $corps['soumettre'] ?? true;

        if (!\is_bool($soumettre)) {
            throw DonneesInvalides::pourChamp('soumettre', 'Cette valeur doit être true ou false.');
        }

        return $soumettre;
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
