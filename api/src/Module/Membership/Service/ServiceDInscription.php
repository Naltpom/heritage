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
use App\Module\Membership\Contract\PortraitsDesComptes;
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
use Doctrine\DBAL\LockMode;
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
 * Jeton inconnu, mal formé, expiré, révoqué ou épuisé : **`404`**, corps d'erreur
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
 *
 * ── Depuis le 1er octobre 2026 : l'inscription sans fiche (ADR 0013) ─────────────
 *
 * Un candidat qui ne se trouve pas peut créer son compte quand même, en décrivant sa
 * famille. Les quatre règles ci-dessus **ne bougent pas** : la route de recherche rend la
 * même réponse qu'avant, et le bouton « Je ne me trouve pas » est le même quelle que soit
 * la cause d'un résultat vide — plafond, date fausse ou personne absente. Ce qui change,
 * c'est que la recherche n'est plus le seul passage : elle n'a donc plus rien à garder
 * sur les **défunts**, que tout compte en attente voit (voir {@see RechercheDeRattachement}).
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
        private PortraitsDesComptes $portraits,
        private StockageDesMessagesVocaux $messagesVocaux,
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
            'expiresAt' => $invitation->getExpiresAt()?->format(\DateTimeInterface::ATOM),
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
     * ## Deux formes de corps, exclusives
     *
     * `{choix}` — la fiche retenue à l'étape 1b, comme depuis le premier jour ; ou
     * `{declaration}` — « Je ne me trouve pas » : l'identité et la description de la famille,
     * pour un compte **sans fiche** (ADR 0013, {@see self::creerLeCompteSansFiche()}). L'ordre
     * des contrôles est celui du § 3.1 du contrat d'inscription sans fiche : limiteur par IP
     * (déclaration seulement), jeton, présence d'une des deux formes, exclusivité, champs,
     * courriel déjà pris.
     *
     * ## Le message vocal (addendum du 2 octobre 2026)
     *
     * `$messageVocal` est le fichier `messageVocal` d'un corps `multipart` — `null` en JSON.
     * Il n'accompagne qu'une **déclaration**, et il est contrôlé avec elle : ses refus
     * (`declaration.messageVocal`) entrent dans le même `422` que les autres champs, et un
     * vocal **valide** rend le texte facultatif ({@see DeclarationDeFamille::lire()}). Rien
     * n'est écrit avant que tout soit valide — ni la ligne, ni le fichier. Le jeton est
     * contrôlé avant, comme pour le reste du corps : sans jeton valide, le fichier n'est
     * même pas lu.
     *
     * @param array<string, mixed> $corps
     *
     * @throws NotFoundException        jeton ou `choix` invalide — « on ne confirme rien sur le
     *                                  contenu de l'étape précédente » (§ 4.3)
     * @throws ConflictException        `courriel_deja_pris`, `personne_deja_rattachee`
     * @throws ValidationException      courriel, mot de passe ou déclaration refusés
     * @throws TooManyRequestsException plus de cinq comptes sans fiche par heure depuis cette IP
     */
    public function creerLeCompte(string $jeton, array $corps, Request $requete, mixed $messageVocal = null): User
    {
        $avecDeclaration = \array_key_exists('declaration', $corps) && null !== $corps['declaration'];

        // 1 — le limiteur par IP de l'inscription sans fiche, **avant** le jeton : il ne
        // dit rien du jeton, et il protège le reste du chemin d'une création en série.
        if ($avecDeclaration) {
            $this->consommerLeLimiteurParIp($this->limiteurs->inscriptionSansFicheParIp(), $requete);
        }

        // 2 — le jeton.
        $invitation = $this->invitationValide($jeton);

        $choix = $corps['choix'] ?? null;
        $email = $corps['email'] ?? null;
        $motDePasse = $corps['password'] ?? null;
        [$telephone, $refusTelephone] = self::lireTelephone($corps['telephone'] ?? null);
        $avecChoix = \is_string($choix) && '' !== $choix;

        // 3 — ni l'un ni l'autre : le même `404` qu'avant l'inscription sans fiche.
        if (!$avecChoix && !$avecDeclaration) {
            throw new NotFoundException();
        }

        // 4 — les deux : on ne devine pas lequel le candidat voulait dire.
        if ($avecChoix && $avecDeclaration) {
            throw new ValidationException(['choix' => 'Choisissez une fiche ou décrivez votre famille, pas les deux.']);
        }

        // 5 — un seul `422` pour toutes les erreurs de champ.
        $champs = [];

        if (!\is_string($email) || false === filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            $champs['email'] = 'Adresse de courriel invalide.';
        }
        if (!\is_string($motDePasse) || mb_strlen($motDePasse) < self::LONGUEUR_MINIMALE_MOT_DE_PASSE) {
            $champs['password'] = \sprintf('%d caractères minimum.', self::LONGUEUR_MINIMALE_MOT_DE_PASSE);
        }
        if (null !== $refusTelephone) {
            $champs['telephone'] = $refusTelephone;
        }

        $declaration = null;
        $vocal = null;

        if ($avecDeclaration) {
            $brute = $corps['declaration'];
            // Le vocal d'abord : c'est sa validité qui dit si le texte est obligatoire.
            [$vocal, $refusDuVocal] = MessageVocalDeDeclaration::lire(
                $messageVocal,
                \is_array($brute) ? ($brute['dureeMessageVocal'] ?? null) : null,
            );
            [$declaration, $erreurs] = DeclarationDeFamille::lire($brute, avecMessageVocal: null !== $vocal);
            $champs = [...$champs, ...$erreurs, ...$refusDuVocal];
        } elseif (null !== $messageVocal) {
            // Un vocal sans déclaration n'aurait nulle part où vivre : il décrit une famille,
            // et un compte rattaché à une fiche n'en a pas à décrire.
            $champs['messageVocal'] = 'Un message vocal n’accompagne qu’une description de famille.';
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

        if (null !== $declaration) {
            return $this->creerLeCompteSansFiche($invitation, $email, $motDePasse, $declaration, $telephone, $vocal);
        }

        \assert(\is_string($choix));

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
        //
        // Haché hors transaction : c'est volontairement lent, et le verrou ci-dessous
        // ferait attendre d'autant chaque inscription concurrente sur le même lien.
        $utilisateur = new User($email);
        $utilisateur
            ->setTelephone($telephone)
            ->setStatus($actif ? UserStatus::Actif : UserStatus::EnAttente)
            ->setPassword($this->hacheur->hashPassword($utilisateur, $motDePasse));

        // **Le plafond se garde sous verrou.** Deux inscriptions à la dernière place ont
        // toutes deux passé `invitationValide()` ; seule la ligne verrouillée (`FOR
        // UPDATE`) et relue dit laquelle arrive la seconde. Compte, membre et compteur
        // s'écrivent dans la même transaction : un compte sans usage compté, ou
        // l'inverse, n'existe jamais.
        /** @var Member $membre */
        $membre = $this->em->wrapInTransaction(function () use ($invitation, $utilisateur, $email, $personne, $nature, $actif): Member {
            $this->em->refresh($invitation, LockMode::PESSIMISTIC_WRITE);

            if (null !== $invitation->getRevokedAt() || $invitation->estEpuisee() || $invitation->estEchue()) {
                throw new NotFoundException();
            }

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

            $invitation->enregistrerUnUsage($identifiant, $email);

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
                    'useCount' => $invitation->getUseCount(),
                ],
                $utilisateur,
            );

            return $membre;
        });

        if (!$actif) {
            $this->notifications->compteEnAttente($membre);
        }

        return $utilisateur;
    }

    /**
     * Étape 2, **sans fiche** — le candidat ne s'est pas trouvé et a décrit sa famille
     * (ADR 0013).
     *
     * Le compte naît `en_attente`, rattaché à **aucune** personne : `mbr_member.person_id`
     * est nul, `match_kind` vaut `a_rattacher`. Il a le régime `Aucun` de tout compte en
     * attente — il voit les défunts, n'écrit rien — et c'est un administrateur qui lui
     * trouvera, ou lui créera, sa fiche.
     *
     * Même transaction, même verrou et même compteur d'usages que le chemin avec fiche :
     * un lien plafonné à dix comptes compte ceux-ci comme les autres.
     *
     * **Ce que le journal ne reçoit pas** : un mot de la déclaration. L'audit
     * `invitation_utilisee` porte la nature `a_rattacher` et les compteurs, rien de ce que
     * le candidat a écrit ; la notification des administrateurs ne nomme que le courriel.
     */
    /**
     * Le téléphone **facultatif** : absent, `null` ou vide, il n'y en a pas. Sinon on
     * garde chiffres, `+`, espaces, points, tirets et parenthèses, et il faut de 6 à 15
     * chiffres (E.164 en compte 15 au plus). Il ne sert qu'à l'annuaire de
     * l'administrateur : pas de vérification par SMS, pas de format imposé.
     *
     * @return array{0: string|null, 1: string|null} le numéro nettoyé, et le refus
     */
    public static function lireTelephone(mixed $brut): array
    {
        if (null === $brut || (\is_string($brut) && '' === trim($brut))) {
            return [null, null];
        }
        if (!\is_string($brut) || 1 !== preg_match('/^[0-9+().\s-]+$/', trim($brut))) {
            return [null, 'Numéro de téléphone invalide.'];
        }
        $propre = trim((string) preg_replace('/\s+/', ' ', $brut));
        $chiffres = (string) preg_replace('/\D/', '', $propre);
        if (\strlen($chiffres) < 6 || \strlen($chiffres) > 15 || mb_strlen($propre) > 32) {
            return [null, 'Numéro de téléphone invalide.'];
        }

        return [$propre, null];
    }

    private function creerLeCompteSansFiche(
        Invitation $invitation,
        string $email,
        string $motDePasse,
        DeclarationDeFamille $declaration,
        ?string $telephone = null,
        ?MessageVocalDeDeclaration $vocal = null,
    ): User {
        $utilisateur = new User($email);
        $utilisateur
            ->setTelephone($telephone)
            ->setStatus(UserStatus::EnAttente)
            ->setPassword($this->hacheur->hashPassword($utilisateur, $motDePasse));

        // Le fichier est déposé **avant** la transaction, et retiré si elle échoue : une
        // copie disque dans une transaction ne se défait pas d'elle-même, et la ligne ne
        // doit jamais désigner un fichier absent. Le sens inverse — un fichier sans ligne,
        // sur une panne entre les deux — est le moindre mal : il n'est servi par rien.
        $nomDuVocal = null !== $vocal ? $this->messagesVocaux->deposer($vocal->chemin, $vocal->mime) : null;

        try {
            $membre = $this->creerLeMembreSansFiche($invitation, $utilisateur, $email, $declaration, $vocal, $nomDuVocal);
        } catch (\Throwable $echec) {
            $this->messagesVocaux->supprimer($nomDuVocal);

            throw $echec;
        }

        $this->notifications->compteARattacher($membre);

        return $utilisateur;
    }

    private function creerLeMembreSansFiche(
        Invitation $invitation,
        User $utilisateur,
        string $email,
        DeclarationDeFamille $declaration,
        ?MessageVocalDeDeclaration $vocal,
        ?string $nomDuVocal,
    ): Member {
        /** @var Member $membre */
        $membre = $this->em->wrapInTransaction(function () use ($invitation, $utilisateur, $email, $declaration, $vocal, $nomDuVocal): Member {
            $this->em->refresh($invitation, LockMode::PESSIMISTIC_WRITE);

            if (null !== $invitation->getRevokedAt() || $invitation->estEpuisee() || $invitation->estEchue()) {
                throw new NotFoundException();
            }

            $this->em->persist($utilisateur);
            $this->em->flush();

            $identifiant = $utilisateur->getId();
            \assert(null !== $identifiant);

            $membre = Member::aRattacher(
                $identifiant,
                $email,
                $declaration->nom,
                $declaration->prenoms,
                $declaration->naissance,
                $declaration->description,
            );
            $membre->setInvitation($invitation);

            if (null !== $vocal && null !== $nomDuVocal) {
                $membre->joindreUnMessageVocal($nomDuVocal, $vocal->mime, $vocal->taille, $vocal->secondes);
            }

            $this->em->persist($membre);

            $invitation->enregistrerUnUsage($identifiant, $email);

            $this->audit->enregistrer(
                AuditAction::InvitationUtilisee,
                Invitation::class,
                (string) $invitation->getId(),
                null,
                [
                    'tokenPrefix' => $invitation->getTokenPrefix(),
                    'matchKind' => MatchKind::ARattacher->value,
                    'memberStatus' => $membre->getStatus()->value,
                    'useCount' => $invitation->getUseCount(),
                ],
                $utilisateur,
            );

            return $membre;
        });

        return $membre;
    }

    /**
     * Étape 3 — `GET /api/inscription/etat`.
     *
     * **Aucune clé `personId` ni `personLabel`** tant que `status` vaut `en_attente`. « Le
     * candidat a vu `P. Romarin, 1980` à l'étape 1b ; il ne reverra pas ce libellé ici, et
     * surtout pas le `display_name` réel de la fiche, qui peut porter un nom d'usage ou un
     * nom d'épouse qu'il ignore » (§ 4.4).
     *
     * **Plus aucun chiffre de l'arbre** (révision du 2026-10-01, ADR 0003). La réponse
     * portait `cequejevois` — le nombre de défunts et le nombre total de fiches — pour
     * « donner du sens à l'attente ». Un compte en attente ne lit plus rien de l'arbre,
     * et c'est peut-être un inconnu inscrit sous un faux nom avec le lien du groupe :
     * même deux nombres sont de trop.
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

        // La photo déposée à l'inscription — un booléen, pas l'image : l'écran d'attente
        // n'en a besoin que pour proposer « Ajouter votre photo » à qui l'a passée. La clé
        // n'existe que tant que le compte attend : une fois rattachée, la photo est un
        // portrait de fiche comme un autre, et la charge de session en parle déjà.
        if (MemberStatus::EnAttente === $membre->getStatus()) {
            $etat['portraitEnAttente'] = null !== $this->portraits->portraitEnAttente($membre->getUserId());
        }

        return $etat;
    }

    /**
     * L'invitation derrière un jeton, ou `404`.
     *
     * **Cinq refus, une seule réponse.** Forme invalide, empreinte inconnue, échéance
     * passée, révocation, plafond atteint : le même `404`, le même corps, le même message.
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

        if (null !== $invitation->getRevokedAt() || $invitation->estEpuisee()) {
            throw new NotFoundException();
        }

        if ($invitation->estEchue()) {
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
}
