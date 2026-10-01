<?php

declare(strict_types=1);

namespace App\Module\Core\Service;

use App\Module\Core\Entity\Passkey;
use App\Module\Core\Entity\User;
use App\Module\Core\Exception\ConflictException;
use App\Module\Core\Exception\ValidationException;
use App\Module\Core\Repository\PasskeyRepository;
use Cose\Algorithm\Manager;
use Cose\Algorithm\Signature\ECDSA\ES256;
use Cose\Algorithm\Signature\RSA\RS256;
use Cose\Algorithms;
use Doctrine\ORM\EntityManagerInterface;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Uid\Uuid;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AttestationStatement\PackedAttestationStatementSupport;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\CredentialRecord;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;
use Webauthn\TrustPath\EmptyTrustPath;

/**
 * Les **clés d'accès** (passkeys, WebAuthn) : les deux cérémonies, et rien d'autre.
 * Voir `docs/securite-comptes.md` § « Clés d'accès » et `docs/api-contract.md` § 2.4.
 *
 * La cryptographie est entièrement celle de `web-auth/webauthn-lib` — vérifier une
 * attestation, une signature COSE, le hachage de l'identifiant de partie de confiance,
 * l'origine, les drapeaux de présence et de vérification de l'utilisateur. Rien de tout
 * cela ne se réécrit à la main. Cette classe ne fait que trois choses : **fixer la
 * politique** (quelle partie de confiance, quelles exigences), **garder les défis**, et
 * **faire le pont** entre la bibliothèque et la table `core_passkey`.
 *
 * ## La politique, et pourquoi
 *
 * - **Partie de confiance** : l'hôte de `HERITAGE_URL_PUBLIQUE` ({@see UrlPublique}),
 *   jamais celui de la requête — même raisonnement que pour les liens d'invitation : un
 *   `Host` falsifié ne doit rien choisir. `heritage.naltpom.fr` en production,
 *   `localhost` en développement.
 * - **`userVerification: required`** : Face ID, empreinte ou code de l'appareil, à
 *   chaque fois. Une clé qui se contente de « quelqu'un a touché le téléphone » ne vaut
 *   pas un mot de passe.
 * - **`residentKey: required`** : la clé est *découvrable* — c'est elle qui dit à quel
 *   compte elle appartient, et l'on se connecte sans taper son courriel.
 * - **`attestation: none`** : on ne demande pas au fabricant de certifier l'appareil. Une
 *   application familiale n'a pas de liste d'appareils agréés, et l'attestation ne
 *   ferait qu'ajouter un refus possible sans rien protéger.
 *
 * ## Les défis : en session, à usage unique, cinq minutes
 *
 * Un défi est un aléa de 32 octets que l'appareil signe. Il est gardé **en session**
 * avec les options qu'il accompagne, et **consommé à la première réponse** — bonne ou
 * mauvaise : rejouer une réponse capturée ne sert donc à rien. Au-delà de cinq minutes,
 * il ne vaut plus rien.
 *
 * Plusieurs défis peuvent coexister dans une même session (au plus
 * {@see self::DEFIS_EN_SESSION}) et la réponse désigne le sien : l'écran de connexion
 * pose un défi pour l'autoremplissage dès son affichage, puis un second si l'on clique
 * sur le bouton. Un seul emplacement ferait échouer le premier au profit du second, en
 * silence.
 */
final class ClesDAcces
{
    /** Le nom que l'appareil affiche : « Enregistrer une clé d'accès pour Heritage ». */
    public const string NOM_DE_LA_PARTIE = 'Heritage';

    /** Durée de vie d'un défi, en secondes. */
    public const int DUREE_DU_DEFI = 300;

    /** Combien de défis d'un même type une session garde au plus. */
    private const int DEFIS_EN_SESSION = 5;

    private const string SESSION_ENREGISTREMENT = '_heritage_cles_d_acces_enregistrement';
    private const string SESSION_CONNEXION = '_heritage_cles_d_acces_connexion';

    /** Les noms connus des authentificateurs courants, par AAGUID. */
    private const array APPAREILS = [
        'fbfc3007-154e-4ecc-8c0b-6e020557d7bd' => 'Trousseau iCloud',
        'dd4ec289-e01d-41c9-bb89-70fa845d4bf2' => 'Trousseau iCloud',
        'ea9b8d66-4d01-1d21-3ce4-b6b48cb575d4' => 'Gestionnaire de mots de passe Google',
        'adce0002-35bc-c60a-648b-0b25f1f05503' => 'Chrome sur Mac',
        '08987058-cadc-4b81-b6e1-30de50dcbe96' => 'Windows Hello',
        '9ddd1817-af5a-4672-a2b9-3e3dd95000a9' => 'Windows Hello',
        '6028b017-b1d4-4c02-b4b3-afcdafc96bb2' => 'Windows Hello',
        '53414d53-554e-4700-0000-000000000000' => 'Samsung Pass',
        'bada5566-a7aa-401f-bd96-45619a55120d' => '1Password',
        'd548826e-79b4-db40-a3d8-11116f7e8349' => 'Bitwarden',
        '531126d6-e717-415c-9320-3d9aa6981239' => 'Dashlane',
    ];

    private const string AAGUID_INCONNU = '00000000-0000-0000-0000-000000000000';

    private readonly SerializerInterface $serialiseur;
    private readonly AuthenticatorAttestationResponseValidator $validateurDEnregistrement;
    private readonly AuthenticatorAssertionResponseValidator $validateurDeConnexion;

    public function __construct(
        private readonly UrlPublique $urlPublique,
        private readonly PasskeyRepository $cles,
        private readonly EntityManagerInterface $em,
        private readonly RequestStack $requetes,
        private readonly ClockInterface $horloge,
    ) {
        // ES256 (P-256) : Apple, Google, Windows Hello récents. RS256 : les Windows Hello
        // plus anciens. EdDSA n'est pas proposé : il exigerait `ext-sodium`, et aucun
        // authentificateur de plateforme ne le choisit quand ES256 est offert.
        $algorithmes = Manager::create()->add(ES256::create(), RS256::create());

        // `none` est ce que les navigateurs rendent quand on demande `attestation: none`.
        // `packed` reste accepté : quelques authentificateurs livrent malgré tout une
        // auto-attestation, et la refuser ne protégerait de rien (on ne vérifie aucune
        // chaîne de certificats).
        $attestations = new AttestationStatementSupportManager([
            new NoneAttestationStatementSupport(),
            new PackedAttestationStatementSupport($algorithmes),
        ]);
        $this->serialiseur = (new WebauthnSerializerFactory($attestations))->create();

        $fabrique = new CeremonyStepManagerFactory();
        $fabrique->setAlgorithmManager($algorithmes);
        $fabrique->setAttestationStatementSupportManager($attestations);
        // Aucune origine listée : l'origine est contrôlée contre l'identifiant de partie
        // de confiance — même hôte exactement, sous-domaines refusés, HTTPS exigé sauf
        // pour `localhost` et `127.0.0.1` (le développement). C'est la règle du § 7.1 de
        // WebAuthn, et elle ne dépend que de `HERITAGE_URL_PUBLIQUE`.
        $fabrique->setAllowedOrigins([]);
        $fabrique->setSecuredRelyingPartyId(['localhost', '127.0.0.1']);

        $this->validateurDEnregistrement = AuthenticatorAttestationResponseValidator::create($fabrique->creationCeremony());
        $this->validateurDeConnexion = AuthenticatorAssertionResponseValidator::create($fabrique->requestCeremony());
    }

    /** L'identifiant de la partie de confiance : l'hôte de l'adresse publique. */
    public function idDeLaPartie(): string
    {
        return $this->urlPublique->hote();
    }

    // -------------------------------------------------------------------------
    //  Enregistrement — compte connecté
    // -------------------------------------------------------------------------

    /**
     * Les options de `navigator.credentials.create()`, en JSON base64url. Le défi est
     * gardé en session.
     *
     * @return array<string, mixed>
     */
    public function optionsDEnregistrement(User $compte): array
    {
        $existantes = array_map(
            static fn (Passkey $cle): PublicKeyCredentialDescriptor => PublicKeyCredentialDescriptor::create(
                PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
                Base64UrlSafe::decodeNoPadding($cle->getCredentialId()),
                $cle->getTransports(),
            ),
            $this->cles->duCompte((int) $compte->getId()),
        );

        $options = PublicKeyCredentialCreationOptions::create(
            PublicKeyCredentialRpEntity::create(self::NOM_DE_LA_PARTIE, $this->idDeLaPartie()),
            PublicKeyCredentialUserEntity::create(
                $compte->getEmail(),
                $this->identifiantOpaque($compte),
                '' !== $compte->getDisplayName() ? $compte->getDisplayName() : $compte->getEmail(),
            ),
            random_bytes(32),
            [
                PublicKeyCredentialParameters::createPk(Algorithms::COSE_ALGORITHM_ES256),
                PublicKeyCredentialParameters::createPk(Algorithms::COSE_ALGORITHM_RS256),
            ],
            AuthenticatorSelectionCriteria::create(
                null,
                AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,
                AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_REQUIRED,
            ),
            PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
            $existantes,
            self::DUREE_DU_DEFI * 1000,
        );

        $json = $this->serialiseur->serialize($options, 'json');
        $this->garderLeDefi(self::SESSION_ENREGISTREMENT, $options->challenge, $json);

        /* @var array<string, mixed> */
        return json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
    }

    /**
     * Vérifie la réponse de `navigator.credentials.create()` et enregistre la clé.
     *
     * @throws ValidationException sur toute réponse refusée — `422`, un seul message : le
     *                             détail de la bibliothèque n'aide pas la personne, et il
     *                             renseignerait celui qui fabrique des réponses
     * @throws ConflictException   si la clé est déjà enregistrée
     */
    public function enregistrer(User $compte, mixed $reponse, mixed $nom, ?string $userAgent): Passkey
    {
        $nom = $this->nomValide($nom);

        if (!\is_array($reponse)) {
            throw new ValidationException(['reponse' => 'La réponse de l’appareil est attendue.']);
        }

        $optionsJson = $this->consommerLeDefi(self::SESSION_ENREGISTREMENT, $reponse);

        if (null === $optionsJson) {
            throw new ValidationException(['reponse' => 'Le délai est dépassé. Recommencez l’ajout de la clé d’accès.']);
        }

        try {
            $options = $this->serialiseur->deserialize($optionsJson, PublicKeyCredentialCreationOptions::class, 'json');
            $credential = $this->serialiseur->deserialize(json_encode($reponse, \JSON_THROW_ON_ERROR), PublicKeyCredential::class, 'json');

            if (!$options instanceof PublicKeyCredentialCreationOptions
                || !$credential instanceof PublicKeyCredential
                || !$credential->response instanceof AuthenticatorAttestationResponse) {
                throw new \UnexpectedValueException('Réponse d’enregistrement inattendue.');
            }

            // L'options gardée en session nomme ce compte : une réponse obtenue dans la
            // session d'un autre ne passerait pas `CheckChallenge`, faute de défi.
            if ($options->user->id !== $this->identifiantOpaque($compte)) {
                throw new \UnexpectedValueException('Le défi n’a pas été émis pour ce compte.');
            }

            $enregistrement = $this->validateurDEnregistrement->check($credential->response, $options, $this->idDeLaPartie());
        } catch (\Throwable) {
            throw new ValidationException(['reponse' => 'La clé d’accès n’a pas pu être vérifiée. Réessayez.']);
        }

        $credentialId = Base64UrlSafe::encodeUnpadded($enregistrement->publicKeyCredentialId);

        if (null !== $this->cles->parCredentialId($credentialId)) {
            throw new ConflictException('cle_deja_enregistree', 'Cette clé d’accès est déjà enregistrée.');
        }

        $aaguid = $enregistrement->aaguid->toRfc4122();

        $cle = new Passkey(
            $compte,
            $credentialId,
            Base64UrlSafe::encodeUnpadded($enregistrement->credentialPublicKey),
            $enregistrement->counter,
            array_values(array_filter($enregistrement->transports, 'is_string')),
            $aaguid,
            $nom ?? $this->nomParDefaut($aaguid, $userAgent),
            \DateTimeImmutable::createFromInterface($this->horloge->now()),
        );

        $this->em->persist($cle);
        $this->em->flush();

        return $cle;
    }

    public function renommer(Passkey $cle, mixed $nom): Passkey
    {
        $nom = $this->nomValide($nom);

        if (null === $nom) {
            throw new ValidationException(['nom' => 'Le nom est requis.']);
        }

        $cle->renommer($nom);
        $this->em->flush();

        return $cle;
    }

    public function supprimer(Passkey $cle): void
    {
        $this->em->remove($cle);
        $this->em->flush();
    }

    /**
     * Retire **toutes** les clés du compte — au changement de mot de passe (§ 2.1) et à
     * sa réinitialisation par l'administrateur (`UserAdminService`).
     *
     * Une clé d'accès est un moyen de connexion permanent : si quelqu'un en a planté une
     * pendant qu'il tenait la session, changer le mot de passe ferme bien les sessions
     * ({@see User::isEqualTo()}) mais la clé, elle, en rouvrirait une neuve aussitôt.
     * On ne sait pas distinguer la clé plantée des clés légitimes : toutes tombent, et
     * le titulaire réenregistre les siennes — la session qui vient de changer le mot de
     * passe porte une preuve fraîche, l'ajout ne le redemande pas.
     *
     * `remove()` ligne à ligne plutôt qu'un `DELETE` en masse : le souscripteur d'audit
     * journalise chaque suppression, comme une suppression à la main. **Pas de
     * `flush()` ici** : l'appelant écrit le mot de passe et les suppressions d'un seul
     * coup — pas de mot de passe changé avec des clés restées, ni l'inverse.
     *
     * @return int le nombre de clés retirées
     */
    public function retirerToutes(User $compte): int
    {
        $cles = $this->cles->duCompte((int) $compte->getId());

        foreach ($cles as $cle) {
            $this->em->remove($cle);
        }

        return \count($cles);
    }

    // -------------------------------------------------------------------------
    //  Connexion — publique
    // -------------------------------------------------------------------------

    /**
     * Les options de `navigator.credentials.get()`. `allowCredentials` reste **vide** :
     * la clé est découvrable, l'appareil propose lui-même les comptes qu'il connaît, et
     * la route n'a pas à savoir — ni à dire — qui va se connecter.
     *
     * @return array<string, mixed>
     */
    public function optionsDeConnexion(): array
    {
        $options = PublicKeyCredentialRequestOptions::create(
            random_bytes(32),
            $this->idDeLaPartie(),
            [],
            PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_REQUIRED,
            self::DUREE_DU_DEFI * 1000,
        );

        $json = $this->serialiseur->serialize($options, 'json');
        $this->garderLeDefi(self::SESSION_CONNEXION, $options->challenge, $json);

        /* @var array<string, mixed> */
        return json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
    }

    /**
     * La clé que désigne une réponse de connexion, sans rien vérifier encore. Sert à
     * l'authentificateur pour savoir **de quel compte** il s'agit — c'est ce qui permet
     * au limiteur de compter cette tentative avec celles du mot de passe.
     *
     * @param array<array-key, mixed> $reponse
     */
    public function clePresentee(array $reponse): ?Passkey
    {
        $id = $reponse['id'] ?? null;

        if (!\is_string($id) || '' === $id || \strlen($id) > Passkey::CREDENTIAL_ID_MAX) {
            return null;
        }

        return $this->cles->parCredentialId(rtrim($id, '='));
    }

    /**
     * Consomme le défi que la réponse désigne. **À appeler une fois par tentative, avant
     * toute vérification** : le défi est brûlé que la tentative réussisse ou non.
     *
     * @param array<array-key, mixed> $reponse
     *
     * @return string|null les options de connexion gardées, ou `null` (absent, expiré,
     *                     déjà utilisé)
     */
    public function consommerLeDefiDeConnexion(array $reponse): ?string
    {
        return $this->consommerLeDefi(self::SESSION_CONNEXION, $reponse);
    }

    /**
     * Vérifie une réponse de `navigator.credentials.get()` contre la clé gardée : défi,
     * origine, partie de confiance, présence **et** vérification de l'utilisateur,
     * signature, identifiant d'utilisateur, compteur.
     *
     * En cas de succès, la clé est datée et son compteur avancé (non vidé : le
     * gestionnaire de succès vide l'unité de travail). Tout échec rend `false`, sans
     * détail : la route de connexion n'en donne aucun.
     *
     * @param array<array-key, mixed> $reponse
     */
    public function verifierConnexion(Passkey $cle, array $reponse, ?string $optionsJson): bool
    {
        if (null === $optionsJson) {
            return false;
        }

        try {
            $options = $this->serialiseur->deserialize($optionsJson, PublicKeyCredentialRequestOptions::class, 'json');
            $credential = $this->serialiseur->deserialize(json_encode($reponse, \JSON_THROW_ON_ERROR), PublicKeyCredential::class, 'json');

            if (!$options instanceof PublicKeyCredentialRequestOptions
                || !$credential instanceof PublicKeyCredential
                || !$credential->response instanceof AuthenticatorAssertionResponse
                || !hash_equals(Base64UrlSafe::decodeNoPadding($cle->getCredentialId()), $credential->rawId)) {
                return false;
            }

            $enregistrement = $this->validateurDeConnexion->check(
                $this->enregistrementDe($cle),
                $credential->response,
                $options,
                $this->idDeLaPartie(),
                $credential->response->userHandle,
            );
        } catch (\Throwable) {
            return false;
        }

        $cle->utiliser($enregistrement->counter, \DateTimeImmutable::createFromInterface($this->horloge->now()));

        return true;
    }

    // -------------------------------------------------------------------------
    //  Lecture
    // -------------------------------------------------------------------------

    /**
     * La forme publique d'une clé — § 2.4 du contrat. Ni identifiant de clé, ni clé
     * publique : le front n'en a pas l'usage.
     *
     * @return array{id: int|null, nom: string, creeeLe: string, derniereUtilisation: string|null, appareil: string|null}
     */
    public function enTableau(Passkey $cle): array
    {
        return [
            'id' => $cle->getId(),
            'nom' => $cle->getNom(),
            'creeeLe' => $cle->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'derniereUtilisation' => $cle->getLastUsedAt()?->format(\DateTimeInterface::ATOM),
            'appareil' => self::APPAREILS[$cle->getAaguid()] ?? null,
        ];
    }

    // -------------------------------------------------------------------------
    //  Mécanique
    // -------------------------------------------------------------------------

    /**
     * L'identifiant d'utilisateur confié à l'appareil (`user.id`). **Ni le courriel, ni
     * rien de lisible** : la spécification le veut opaque, parce que l'appareil le garde
     * et peut le montrer. Une empreinte de l'identifiant du compte, stable : le même
     * compte réenregistré sur le même appareil remplace sa clé au lieu d'en empiler une
     * seconde.
     */
    private function identifiantOpaque(User $compte): string
    {
        return hash('sha256', 'heritage:compte:'.$compte->getId(), true);
    }

    /** La ligne de `core_passkey`, dans la forme que la bibliothèque vérifie. */
    private function enregistrementDe(Passkey $cle): CredentialRecord
    {
        return CredentialRecord::create(
            Base64UrlSafe::decodeNoPadding($cle->getCredentialId()),
            PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
            $cle->getTransports(),
            PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
            EmptyTrustPath::create(),
            Uuid::fromString($cle->getAaguid()),
            Base64UrlSafe::decodeNoPadding($cle->getPublicKey()),
            $this->identifiantOpaque($cle->getUser()),
            $cle->getSignCount(),
        );
    }

    private function session(): SessionInterface
    {
        return $this->requetes->getSession();
    }

    private function garderLeDefi(string $cleDeSession, string $defi, string $optionsJson): void
    {
        $maintenant = $this->horloge->now()->getTimestamp();
        $defis = array_filter(
            $this->defisEnSession($cleDeSession),
            static fn (array $d): bool => $d['expire'] > $maintenant,
        );

        $defis[Base64UrlSafe::encodeUnpadded($defi)] = [
            'options' => $optionsJson,
            'expire' => $maintenant + self::DUREE_DU_DEFI,
        ];

        // Les plus anciens sortent d'abord : une session qui demande des défis en boucle
        // ne fait pas grossir son cookie de session sans borne.
        $this->session()->set($cleDeSession, \array_slice($defis, -self::DEFIS_EN_SESSION, null, true));
    }

    /**
     * Retire de la session le défi que nomme `clientDataJSON` et rend ses options, s'il
     * était encore valable. Le retrait a lieu **dans tous les cas** : un défi ne sert
     * qu'une fois.
     *
     * @param array<array-key, mixed> $reponse
     */
    private function consommerLeDefi(string $cleDeSession, array $reponse): ?string
    {
        $defi = $this->defiDeLaReponse($reponse);

        if (null === $defi) {
            return null;
        }

        $defis = $this->defisEnSession($cleDeSession);
        $garde = $defis[$defi] ?? null;
        unset($defis[$defi]);
        $this->session()->set($cleDeSession, $defis);

        if (null === $garde || $garde['expire'] <= $this->horloge->now()->getTimestamp()) {
            return null;
        }

        return $garde['options'];
    }

    /** @return array<string, array{options: string, expire: int}> */
    private function defisEnSession(string $cleDeSession): array
    {
        $defis = $this->session()->get($cleDeSession);

        if (!\is_array($defis)) {
            return [];
        }

        $propres = [];
        foreach ($defis as $defi => $garde) {
            if (\is_string($defi) && \is_array($garde) && \is_string($garde['options'] ?? null) && \is_int($garde['expire'] ?? null)) {
                $propres[$defi] = ['options' => $garde['options'], 'expire' => $garde['expire']];
            }
        }

        return $propres;
    }

    /**
     * Le défi tel que l'appareil l'a signé, lu dans `response.clientDataJSON`. La
     * bibliothèque le relira et le comparera elle-même : ceci ne sert qu'à **choisir**
     * les options gardées, pas à les valider.
     *
     * @param array<array-key, mixed> $reponse
     */
    private function defiDeLaReponse(array $reponse): ?string
    {
        $donnees = \is_array($reponse['response'] ?? null) ? $reponse['response']['clientDataJSON'] ?? null : null;

        if (!\is_string($donnees) || '' === $donnees || \strlen($donnees) > 8192) {
            return null;
        }

        try {
            $client = json_decode(Base64UrlSafe::decodeNoPadding(rtrim($donnees, '=')), true, 4, \JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        $defi = \is_array($client) ? ($client['challenge'] ?? null) : null;

        return \is_string($defi) && '' !== $defi ? rtrim($defi, '=') : null;
    }

    /** `null` si absent ou vide ; sinon le nom nettoyé, ou un refus `422`. */
    private function nomValide(mixed $nom): ?string
    {
        if (null === $nom) {
            return null;
        }
        if (!\is_string($nom)) {
            throw new ValidationException(['nom' => 'Le nom doit être un texte.']);
        }

        $nom = trim(preg_replace('/\s+/u', ' ', $nom) ?? '');

        if ('' === $nom) {
            return null;
        }
        if (mb_strlen($nom) > Passkey::NOM_MAX) {
            throw new ValidationException(['nom' => \sprintf('%d caractères au plus.', Passkey::NOM_MAX)]);
        }

        return $nom;
    }

    /**
     * Un nom lisible quand la personne n'en a pas donné : l'authentificateur s'il est
     * connu, sinon l'appareil lu dans le `User-Agent`. C'est une étiquette, pas une
     * donnée de sécurité — elle se renomme dans « Mon compte ».
     */
    private function nomParDefaut(string $aaguid, ?string $userAgent): string
    {
        if (self::AAGUID_INCONNU !== $aaguid && isset(self::APPAREILS[$aaguid])) {
            return self::APPAREILS[$aaguid];
        }

        $ua = (string) $userAgent;

        return match (true) {
            str_contains($ua, 'iPhone') => 'iPhone',
            str_contains($ua, 'iPad') => 'iPad',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Macintosh') => 'Mac',
            str_contains($ua, 'CrOS') => 'Chromebook',
            str_contains($ua, 'Linux') => 'Linux',
            default => 'Clé d’accès',
        };
    }
}
