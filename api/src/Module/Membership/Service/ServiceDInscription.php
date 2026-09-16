<?php

declare(strict_types=1);

namespace App\Module\Membership\Service;

use App\Module\Core\Entity\User;
use App\Module\Core\Enum\AuditAction;
use App\Module\Core\Enum\UserStatus;
use App\Module\Core\Exception\ConflictException;
use App\Module\Core\Exception\NotFoundException;
use App\Module\Core\Exception\TooManyRequestsException;
use App\Module\Core\Exception\ValidationException;
use App\Module\Core\Repository\UserRepository;
use App\Module\Core\Service\AuditService;
use App\Module\Membership\Entity\ClaimAttempt;
use App\Module\Membership\Entity\Invitation;
use App\Module\Membership\Entity\Member;
use App\Module\Membership\Enum\ClaimResult;
use App\Module\Membership\Enum\InvitationStatus;
use App\Module\Membership\Enum\MatchKind;
use App\Module\Membership\Enum\MemberStatus;
use App\Module\Membership\Repository\InvitationRepository;
use App\Module\Membership\Repository\MemberRepository;
use App\Module\Membership\Service\Port\PersonneDeLArbre;
use App\Module\Membership\Service\Port\PortArbre;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * L'inscription en trois étapes — § 4 du contrat d'API.
 *
 * ═══════════════════════════════════════════════════════════════════════════════
 *  C'EST LE POINT LE PLUS DÉLICAT DU PRODUIT. Rien de ce qui suit n'est négociable.
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Un lien d'invitation ne doit **jamais** devenir un moyen d'énumérer les personnes
 * vivantes de l'arbre (§ 6.1 du dossier, ADR 0003). Quatre règles portent cette propriété,
 * et trois d'entre elles sont contre-intuitives — un développeur raisonnable écrit
 * spontanément l'inverse des trois.
 *
 * ── 1. `404`, jamais `403`, sur les trois routes publiques ───────────────────────
 *
 * Jeton inconnu, mal formé, expiré, révoqué ou déjà consommé : **`404`**, corps d'erreur
 * identique dans les cinq cas, même message — `"Ressource introuvable."`, jamais « jeton
 * expiré », qui distinguerait un jeton ayant existé d'un jeton inventé. Un `403`
 * confirmerait que la route existe et que le jeton a une forme valide.
 *
 * ── 2. Le plafond par jeton rend `200 { "candidats": [] }` ───────────────────────
 *
 * **C'est la seule exception de tout le projet à la convention « dépassement ⇒ 429 »**
 * (§ 1.9, § 4.2, § 17.1 divergence 1), et elle est motivée : une `429` annonce au porteur
 * que la limite existe, donc que ses requêtes précédentes portaient, donc que le lien est
 * un oracle exploitable. Pas de `429`, **pas de `Retry-After`**, pas d'en-tête de quota,
 * et `tentativesRestantes` reste `null` pour tout le monde.
 *
 * Le limiteur **par IP**, lui, rend bien `429` : il ne dit rien de la validité d'un jeton
 * ni de l'existence d'une personne, seulement qu'une adresse s'acharne. Les deux
 * coexistent et ne se répondent pas de la même façon — c'est voulu.
 *
 * ── 3. On cherche, PUIS on jette ─────────────────────────────────────────────────
 *
 * Divergence 3 du § 17.1 : « un back raisonnable court-circuite au plafond — il teste le
 * limiteur, puis sort. La réponse arrive alors plus vite qu'une vraie recherche, et
 * l'indistinguabilité obtenue à la règle 2 est perdue au chronomètre. » La recherche est
 * donc exécutée **dans tous les cas**, et son résultat jeté au plafond. Sur 274 lignes le
 * surcoût est nul ; le canal auxiliaire, lui, serait permanent.
 *
 * ── 4. Le pré-lien n'ouvre aucun accès en lecture ────────────────────────────────
 *
 * `mbr_invitation.person_id` désigne peut-être déjà la personne. **Confirmer une date de
 * naissance n'est pas la lire** (§ 4.5 du modèle, § 12.1 du contrat) : ni l'étape 1a, ni
 * l'étape 1b, ni la charge de session du compte créé ne rendent le nom de cette personne.
 * Sans cette règle, le garde-fou de l'étape 1 serait contourné par l'étape qui le suit.
 *
 * ── Et une décision, prise faute de source (§ 17.1, manque 1) ────────────────────
 *
 * Une date fausse en mode confirmation **consomme une tentative** — sinon le plafond ne
 * protège rien, on teste 365 dates par an gratuitement — et rend `candidats: []`, jamais
 * un message qui rapprocherait de la bonne date.
 */
final readonly class ServiceDInscription
{
    /** § 6.1 du dossier de mission : 12 caractères minimum. */
    public const int LONGUEUR_MINIMALE_MOT_DE_PASSE = 12;

    /** Les trois champs demandés, **dans les deux modes** (§ 4.1). */
    private const array CHAMPS_DEMANDES = ['surname', 'givenNames', 'birthDate'];

    public function __construct(
        private EntityManagerInterface $em,
        private InvitationRepository $invitations,
        private MemberRepository $membres,
        private UserRepository $utilisateurs,
        private PortArbre $arbre,
        private ReglagesDeMembership $reglages,
        private GenerateurDeJeton $jetons,
        private RechercheDeRattachement $recherche,
        private ReferencesDeCandidats $references,
        private LimiteursDInscription $limiteurs,
        private NormalisationDeNom $noms,
        private NotificationDesAdministrateurs $notifications,
        private UserPasswordHasherInterface $hacheur,
        private AuditService $audit,
    ) {
    }

    /**
     * Étape 1a — `POST /api/inscription/verifier`.
     *
     * **Ce que la réponse ne contient pas** : ni `personId`, ni `personLabel`, ni aucun
     * indice sur la personne pré-liée — pas même une initiale. `mode` dit seulement quel
     * formulaire afficher. On peut objecter que `mode: "confirmation"` révèle qu'un
     * administrateur a pré-lié quelqu'un : c'est exact, et c'est le minimum indispensable
     * pour que le formulaire soit le bon. Le porteur du jeton l'a reçu nominativement ; il
     * apprend qu'on l'attendait, pas **qui** on attendait.
     *
     * @return array<string, mixed>
     */
    public function verifier(string $jeton, Request $requete): array
    {
        $this->consommerLeLimiteurParIp($this->limiteurs->verificationParIp(), $requete);

        $invitation = $this->invitationValide($jeton);

        return [
            'mode' => null !== $invitation->getPersonId() ? 'confirmation' : 'recherche',
            'expiresAt' => $invitation->getExpiresAt()->format(\DateTimeInterface::ATOM),
            'candidatsMax' => $this->reglages->candidatsMax(),
            // Les deux modes demandent les mêmes trois champs. C'est délibéré : un
            // formulaire qui ne demanderait que la date en mode confirmation dirait au
            // porteur « nous savons déjà ton nom » (§ 4.1).
            'champsDemandes' => self::CHAMPS_DEMANDES,
        ];
    }

    /**
     * Étape 1b — `POST /api/inscription/rattachement`. **La route la plus sensible du
     * produit.**.
     *
     * La forme de la réponse est **la même dans tous les cas** : candidats trouvés, aucun
     * candidat, date non confirmée, plafond horaire atteint. Quatre situations, une seule
     * réponse observable.
     *
     * `tentativesRestantes` existe dans le contrat **uniquement pour être toujours
     * `null`** : sans cette clé, un back l'ajouterait un jour « pour aider
     * l'utilisateur ». Elle est là, à `null`, avec ce commentaire (§ 4.2).
     *
     * @param array<string, mixed> $corps
     *
     * @return array<string, mixed>
     */
    public function rattachement(string $jeton, array $corps, Request $requete): array
    {
        // Le limiteur par IP passe en premier : il ne trahit rien, et il protège le reste
        // du chemin — y compris la lecture de l'invitation — d'un balayage distribué.
        $this->consommerLeLimiteurParIp($this->limiteurs->rattachementParIp(), $requete);

        // **Les critères sont validés AVANT de regarder le jeton, et l'ordre est une
        // mesure de sécurité.** Le tableau du § 4 ne prévoit que `404` et `429` sur cette
        // route : si un corps incomplet rendait `422` *après* le contrôle du jeton, ce
        // `422` dirait au porteur que son jeton, lui, est valide — il suffirait d'omettre
        // un champ pour transformer la route en test de validité de jeton. Validé
        // d'abord, le `422` ne parle que du corps.
        $criteres = CriteresDeRattachement::depuisLeCorps($corps);

        $invitation = $this->invitationValide($jeton);
        $empreinteDuJeton = $this->jetons->empreinte($jeton);

        // Le plafond est consommé **avant** la recherche, et sa réponse n'est lue
        // qu'après : la consommation est ce qui compte, pas la sortie anticipée. Voir la
        // règle 3 de l'en-tête — répondre ici ferait du temps de réponse le canal que le
        // reste du fichier vient de fermer.
        $souslePlafond = $this->limiteurs
            ->rattachementParJeton()
            ->create($empreinteDuJeton)
            ->consume()
            ->isAccepted();

        $preLie = $invitation->getPersonId();
        $vue = $this->arbre->vue();

        $candidats = null !== $preLie
            ? $this->recherche->confirmer($vue, $preLie, $criteres)
            : $this->recherche->chercher($vue, $criteres, $this->reglages->candidatsMax());

        $nature = null !== $preLie ? MatchKind::PreLie : MatchKind::RechercheConfirmee;
        $issue = $this->issue($souslePlafond, $candidats, null !== $preLie);

        // « On cherche, puis on jette. » Le travail a eu lieu ; le résultat n'est pas rendu.
        if (!$souslePlafond) {
            $candidats = [];
        }

        $reponse = $this->deposerLesReferences($empreinteDuJeton, $candidats, $nature);

        $this->journaliser($invitation, $criteres, $issue, \count($candidats), $requete);

        return [
            'candidats' => $reponse,
            // Toujours `null`. Voir l'en-tête de méthode : la clé existe pour interdire
            // qu'on la remplisse.
            'tentativesRestantes' => null,
        ];
    }

    /**
     * Étape 2 — `POST /api/inscription/compte`.
     *
     * Rend l'utilisateur créé ; c'est au contrôleur d'ouvrir la session et de composer la
     * charge du § 2.2. Le service ne connaît pas le pare-feu.
     *
     * @param array<string, mixed> $corps
     *
     * @throws NotFoundException   jeton ou `choix` invalide — « on ne confirme rien sur le
     *                             contenu de l'étape précédente » (§ 4.3)
     * @throws ConflictException   `courriel_deja_pris`, `personne_deja_rattachee`
     * @throws ValidationException courriel ou mot de passe refusés
     */
    public function creerLeCompte(string $jeton, array $corps, Request $requete): User
    {
        $invitation = $this->invitationValide($jeton);

        $choix = $corps['choix'] ?? null;
        $email = $corps['email'] ?? null;
        $motDePasse = $corps['password'] ?? null;

        if (!\is_string($choix) || '' === $choix) {
            throw new NotFoundException();
        }

        $champs = [];

        if (!\is_string($email) || false === filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            $champs['email'] = 'Adresse de courriel invalide.';
        }
        if (!\is_string($motDePasse) || mb_strlen($motDePasse) < self::LONGUEUR_MINIMALE_MOT_DE_PASSE) {
            $champs['password'] = \sprintf('%d caractères minimum.', self::LONGUEUR_MINIMALE_MOT_DE_PASSE);
        }
        if ([] !== $champs) {
            throw new ValidationException($champs);
        }

        \assert(\is_string($email) && \is_string($motDePasse));

        // Le courriel est contrôlé **avant** de consommer la référence, et l'ordre rend un
        // service concret : `consommer()` efface le jeu de candidats quel que soit le
        // résultat (usage unique). Un candidat qui se trompe de courriel refait sinon
        // toute la recherche — et la refaire consomme une tentative sur un plafond de cinq.
        if (null !== $this->utilisateurs->findOneBy(['email' => $email])) {
            // « Le seul endroit de l'API qui admet de révéler l'existence d'un compte, et
            // c'est inévitable : `uniq_core_user_email` refusera de toute façon. Le risque
            // est borné — il faut un jeton d'invitation valide pour poser la question. »
            throw new ConflictException('courriel_deja_pris', 'Un compte existe déjà pour ce courriel.');
        }

        $retenu = $this->references->consommer($this->jetons->empreinte($jeton), $choix);

        if (null === $retenu) {
            // `404` et non `422` : un `422` dirait au porteur que sa recherche avait bien
            // rendu quelque chose et qu'il a seulement laissé passer le délai.
            throw new NotFoundException();
        }

        $personId = $retenu['personId'];

        if (null !== $this->membres->pourPersonne($personId)) {
            throw new ConflictException('personne_deja_rattachee', 'Cette personne est déjà rattachée à un compte.');
        }

        $personne = $this->arbre->vue()->personne($personId);

        if (null === $personne) {
            // La personne a disparu entre l'étape 1b et maintenant — fusion, suppression.
            // Rien à confirmer, et surtout pas de message qui dirait laquelle.
            throw new NotFoundException();
        }

        $nature = $retenu['nature'];
        $actif = $nature->activeLeCompte();

        // `displayName` reste **vide** : le § 2.2 point 3 interdit de le renseigner depuis
        // le libellé de la personne avant activation, et le socle de `Core` le fait
        // retomber sur le courriel. Le renseigner ici donnerait à un compte non validé le
        // nom de la personne qu'il prétend être.
        $utilisateur = new User($email);
        $utilisateur
            ->setStatus($actif ? UserStatus::Actif : UserStatus::EnAttente)
            ->setPassword($this->hacheur->hashPassword($utilisateur, $motDePasse));

        $this->em->persist($utilisateur);
        $this->em->flush();

        $identifiant = $utilisateur->getId();
        \assert(null !== $identifiant);

        $membre = new Member($identifiant, $email, $personne->id, $personne->libelle, $nature);
        $membre->setInvitation($invitation);

        if ($actif) {
            // Activation automatique : pas d'auteur, `activated_by_user_id` reste nul
            // (§ 4.5 du modèle).
            $membre->activer();
        }

        $this->em->persist($membre);

        $invitation->marquerUtilisee($identifiant, $email);

        $this->audit->enregistrer(
            AuditAction::InvitationUtilisee,
            Invitation::class,
            (string) $invitation->getId(),
            // Pas de libellé de personne : le sujet du journal est l'invitation, et
            // `subject_label` est soumis à la porte de sortie n° 8 comme le reste.
            null,
            [
                'tokenPrefix' => $invitation->getTokenPrefix(),
                'matchKind' => $nature->value,
                'memberStatus' => $membre->getStatus()->value,
            ],
            $utilisateur,
        );

        $this->em->flush();

        if (!$actif) {
            $this->notifications->compteEnAttente($membre);
        }

        return $utilisateur;
    }

    /**
     * Étape 3 — `GET /api/inscription/etat`.
     *
     * **Aucune clé `personId` ni `personLabel`** tant que `status` vaut `en_attente`. « Le
     * candidat a vu `P. Romarin, 1980` à l'étape 1b ; il ne reverra pas ce libellé ici, et
     * surtout pas le `display_name` réel de la fiche, qui peut porter un nom d'usage ou un
     * nom d'épouse qu'il ignore » (§ 4.4).
     *
     * `cequejevois` donne du sens à l'attente sans rien exposer : **deux nombres, pas une
     * liste**. C'est la mise en œuvre du motif du commanditaire — « un écran vide pousse à
     * l'abandon ».
     *
     * @return array<string, mixed>
     */
    public function etat(User $utilisateur): array
    {
        $userId = $utilisateur->getId();
        $membre = null !== $userId ? $this->membres->pourUtilisateur($userId) : null;

        if (null === $membre) {
            throw new NotFoundException();
        }

        $enAttente = MemberStatus::Actif !== $membre->getStatus();

        $etat = [
            'status' => $membre->getStatus()->value,
            'depuis' => $membre->getLinkedAt()->format(\DateTimeInterface::ATOM),
            'matchKind' => $membre->getMatchKind()->value,
            'regime' => $enAttente ? 'aucun' : 'cercle',
        ];

        if ($enAttente) {
            $etat['cequejevois'] = $this->deuxNombres();
        }

        return $etat;
    }

    /**
     * L'invitation derrière un jeton, ou `404`.
     *
     * **Cinq refus, une seule réponse.** Forme invalide, empreinte inconnue, échéance
     * passée, révocation, consommation : le même `404`, le même corps, le même message.
     * C'est l'unique méthode par laquelle les trois routes publiques lisent une invitation,
     * et c'est ce qui garantit qu'aucune n'inventera un sixième cas plus bavard.
     */
    private function invitationValide(string $jeton): Invitation
    {
        if (!$this->jetons->formeValide($jeton)) {
            throw new NotFoundException();
        }

        $invitation = $this->invitations->parEmpreinte($this->jetons->empreinte($jeton));

        if (null === $invitation) {
            throw new NotFoundException();
        }

        if (null !== $invitation->getUsedAt() || null !== $invitation->getRevokedAt()) {
            throw new NotFoundException();
        }

        if ($invitation->getExpiresAt() <= new \DateTimeImmutable()) {
            // Le statut dénormalisé est mis à jour au passage — c'est la pose
            // « paresseuse » du § 4.1 du modèle. La réponse, elle, ne change pas.
            $invitation->setStatus(InvitationStatus::Expiree);
            $this->em->flush();

            throw new NotFoundException();
        }

        if (InvitationStatus::Active !== $invitation->getStatus()) {
            throw new NotFoundException();
        }

        return $invitation;
    }

    /**
     * Le limiteur par IP, qui rend bien `429` **avec** `Retry-After` — contrairement au
     * limiteur par jeton. Voir la règle 2 de l'en-tête de classe.
     */
    private function consommerLeLimiteurParIp(
        \Symfony\Component\RateLimiter\RateLimiterFactory $fabrique,
        Request $requete,
    ): void {
        $limite = $fabrique->create($requete->getClientIp() ?? 'inconnue')->consume();

        if (!$limite->isAccepted()) {
            throw new TooManyRequestsException(max(1, $limite->getRetryAfter()->getTimestamp() - time()));
        }
    }

    /**
     * L'issue consignée dans `mbr_claim_attempt`.
     *
     * Les cinq valeurs de {@see ClaimResult} vivent **ici, côté serveur, et seulement ici**.
     * `aucun_candidat` et `limite_atteinte` sont deux lignes de journal distinctes et une
     * seule réponse HTTP : c'est exactement la propriété que la table existe pour
     * documenter sans la trahir.
     *
     * @param list<PersonneDeLArbre> $candidats
     */
    private function issue(bool $souslePlafond, array $candidats, bool $modeConfirmation): ClaimResult
    {
        if (!$souslePlafond) {
            return ClaimResult::LimiteAtteinte;
        }

        if ([] !== $candidats) {
            return $modeConfirmation ? ClaimResult::Confirme : ClaimResult::CandidatsProposes;
        }

        return $modeConfirmation ? ClaimResult::EchecDate : ClaimResult::AucunCandidat;
    }

    /**
     * @param list<PersonneDeLArbre> $candidats
     *
     * @return list<array{ref: string, libelle: string}>
     */
    private function deposerLesReferences(string $empreinteDuJeton, array $candidats, MatchKind $nature): array
    {
        $parReference = [];
        $reponse = [];

        foreach ($candidats as $candidat) {
            $reference = $this->references->reference();
            $parReference[$reference] = $candidat->id;
            $reponse[] = ['ref' => $reference, 'libelle' => $candidat->libelleMinimal()];
        }

        $this->references->deposer($empreinteDuJeton, $parReference, $nature);

        return $reponse;
    }

    /**
     * **Chaque appel écrit une ligne**, y compris au plafond, y compris en échec (§ 4.2).
     *
     * Deux écritures, et elles ne font pas double emploi : `mbr_claim_attempt` est le
     * journal *de la recherche* — c'est lui qui permet de répondre, trois semaines plus
     * tard, à « qu'est-ce que ce jeton a essayé de chercher » —, et `core_audit_log` est le
     * journal *des décisions*, où `rattachement_tente` figure parce que c'est « l'oracle à
     * surveiller ».
     */
    private function journaliser(
        Invitation $invitation,
        CriteresDeRattachement $criteres,
        ClaimResult $issue,
        int $rendus,
        Request $requete,
    ): void {
        $this->em->persist(new ClaimAttempt(
            $invitation,
            // Les critères ne sont jamais stockés en clair : ils désignent une personne
            // vivante, souvent réelle, et ce journal serait sinon exactement la fuite
            // qu'il est censé documenter (§ 4.3 du modèle).
            $criteres->empreinte($this->noms),
            $issue,
            $rendus,
            $requete->getClientIp(),
        ));

        $this->audit->enregistrer(
            AuditAction::RattachementTente,
            Invitation::class,
            (string) $invitation->getId(),
            null,
            ['tokenPrefix' => $invitation->getTokenPrefix(), 'resultat' => $issue->value, 'candidats' => $rendus],
        );

        $this->em->flush();
    }

    /**
     * « Deux nombres, pas une liste » (§ 4.4).
     *
     * `personnesDecedees` se compte sur la vue de l'arbre, donc sur la propriété calculée
     * du § 9.2 — pas sur une colonne `deceased`, qui dirait autre chose : une personne
     * sans décès connu et née il y a quarante ans est *vivante* pour la règle et
     * *non décédée* pour la colonne.
     *
     * @return array{personnesDecedees: int, personnesTotales: int}
     */
    private function deuxNombres(): array
    {
        $vue = $this->arbre->vue();
        $decedees = 0;

        foreach ($vue->personnes as $personne) {
            if (!$personne->vivante) {
                ++$decedees;
            }
        }

        return [
            'personnesDecedees' => $decedees,
            'personnesTotales' => \count($vue->personnes),
        ];
    }
}
