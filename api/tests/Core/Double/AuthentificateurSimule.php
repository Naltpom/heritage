<?php

declare(strict_types=1);

namespace App\Tests\Core\Double;

use CBOR\ByteStringObject;
use CBOR\MapObject;
use CBOR\NegativeIntegerObject;
use CBOR\TextStringObject;
use CBOR\UnsignedIntegerObject;
use ParagonIE\ConstantTime\Base64UrlSafe;

/**
 * Un **authentificateur de plateforme simulé** — ce que font Face ID, l'empreinte
 * Android ou Windows Hello, en PHP, pour les tests des clés d'accès.
 *
 * Il fabrique de vraies réponses WebAuthn, octet pour octet : une paire de clés P-256
 * (ES256), un `authenticatorData` (empreinte de l'identifiant de partie de confiance,
 * drapeaux, compteur), un `attestationObject` CBOR au format `none`, et des signatures
 * ECDSA sur `authenticatorData ‖ SHA-256(clientDataJSON)`. La bibliothèque serveur les
 * vérifie **sans le savoir** : aucun contrôle n'est contourné dans les tests, c'est tout
 * l'intérêt de ce double plutôt qu'une doublure du service.
 *
 * Les leviers d'erreur — signature corrompue, compteur imposé, vérification de
 * l'utilisateur absente, autre origine — sont ceux qu'un attaquant ou un appareil
 * défaillant produirait réellement.
 *
 * ## OpenSSL sous Windows
 *
 * `openssl_pkey_new()` échoue sans fichier de configuration sur le PHP Windows de
 * développement. Même repli que `Core\Service\ClesVapid` : le `openssl.cnf` livré avec
 * PHP. Le conteneur et la CI (Linux) n'en ont pas besoin.
 */
final class AuthentificateurSimule
{
    /** AAGUID du « Trousseau iCloud » : vérifie au passage le nom deviné de la clé. */
    public const string AAGUID_TROUSSEAU_ICLOUD = 'fbfc3007-154e-4ecc-8c0b-6e020557d7bd';

    private ?\OpenSSLAsymmetricKey $clePrivee = null;
    private ?string $credentialId = null;
    private ?string $userHandle = null;
    private int $compteur = 0;

    public function __construct(
        private readonly string $origine = 'http://localhost:8080',
        private readonly string $aaguid = self::AAGUID_TROUSSEAU_ICLOUD,
    ) {
    }

    /**
     * La réponse de `navigator.credentials.create()` aux options rendues par l'API, telle
     * que `PublicKeyCredential.toJSON()` la produit.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function creer(array $options, bool $verificationUtilisateur = true, ?string $origine = null): array
    {
        $rp = $options['rp'] ?? null;
        $user = $options['user'] ?? null;
        \assert(\is_array($rp) && \is_string($rp['id'] ?? null) && \is_array($user) && \is_string($user['id'] ?? null));
        \assert(\is_string($options['challenge'] ?? null));

        $this->clePrivee = self::nouvelleCle();
        $this->credentialId = random_bytes(32);
        $this->userHandle = Base64UrlSafe::decodeNoPadding($user['id']);

        $details = openssl_pkey_get_details($this->clePrivee);
        \assert(\is_array($details));
        $x = str_pad($details['ec']['x'], 32, "\0", \STR_PAD_LEFT);
        $y = str_pad($details['ec']['y'], 32, "\0", \STR_PAD_LEFT);

        // La clé publique COSE : kty EC2 (2), alg ES256 (-7), crv P-256 (1), x, y.
        $cose = (string) MapObject::create()
            ->add(UnsignedIntegerObject::create(1), UnsignedIntegerObject::create(2))
            ->add(UnsignedIntegerObject::create(3), NegativeIntegerObject::create(-7))
            ->add(NegativeIntegerObject::create(-1), UnsignedIntegerObject::create(1))
            ->add(NegativeIntegerObject::create(-2), ByteStringObject::create($x))
            ->add(NegativeIntegerObject::create(-3), ByteStringObject::create($y));

        $donneesAttestees = self::uuidEnOctets($this->aaguid)
            .pack('n', \strlen($this->credentialId))
            .$this->credentialId
            .$cose;

        // UP (0x01), UV (0x04) si demandé, AT (0x40) : des données attestées suivent.
        $drapeaux = 0x01 | 0x40 | ($verificationUtilisateur ? 0x04 : 0);
        $authData = hash('sha256', $rp['id'], true).\chr($drapeaux).pack('N', $this->compteur).$donneesAttestees;

        $attestation = (string) MapObject::create()
            ->add(TextStringObject::create('fmt'), TextStringObject::create('none'))
            ->add(TextStringObject::create('attStmt'), MapObject::create())
            ->add(TextStringObject::create('authData'), ByteStringObject::create($authData));

        $clientData = $this->clientData('webauthn.create', $options['challenge'], $origine);

        return [
            'id' => Base64UrlSafe::encodeUnpadded($this->credentialId),
            'rawId' => Base64UrlSafe::encodeUnpadded($this->credentialId),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => Base64UrlSafe::encodeUnpadded($clientData),
                'attestationObject' => Base64UrlSafe::encodeUnpadded($attestation),
                'transports' => ['internal', 'hybrid'],
            ],
            'clientExtensionResults' => [],
            'authenticatorAttachment' => 'platform',
        ];
    }

    /**
     * La réponse de `navigator.credentials.get()`. Le compteur avance d'un à chaque appel,
     * sauf si `$compteur` l'impose (recul simulé d'un clone).
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function obtenir(
        array $options,
        ?int $compteur = null,
        bool $signatureCorrompue = false,
        bool $verificationUtilisateur = true,
    ): array {
        \assert(null !== $this->clePrivee && null !== $this->credentialId && null !== $this->userHandle, 'Créer la clé d’abord.');
        \assert(\is_string($options['rpId'] ?? null) && \is_string($options['challenge'] ?? null));

        $this->compteur = $compteur ?? $this->compteur + 1;

        $drapeaux = 0x01 | ($verificationUtilisateur ? 0x04 : 0);
        $authData = hash('sha256', $options['rpId'], true).\chr($drapeaux).pack('N', $this->compteur);
        $clientData = $this->clientData('webauthn.get', $options['challenge']);

        $signature = '';
        openssl_sign($authData.hash('sha256', $clientData, true), $signature, $this->clePrivee, \OPENSSL_ALGO_SHA256);
        \assert(\is_string($signature));

        if ($signatureCorrompue) {
            // Un octet du milieu de la signature DER : la structure reste lisible, la
            // signature ne vaut plus rien.
            $milieu = intdiv(\strlen($signature), 2);
            $signature[$milieu] = \chr(\ord($signature[$milieu]) ^ 0xFF);
        }

        return [
            'id' => Base64UrlSafe::encodeUnpadded($this->credentialId),
            'rawId' => Base64UrlSafe::encodeUnpadded($this->credentialId),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => Base64UrlSafe::encodeUnpadded($clientData),
                'authenticatorData' => Base64UrlSafe::encodeUnpadded($authData),
                'signature' => Base64UrlSafe::encodeUnpadded($signature),
                'userHandle' => Base64UrlSafe::encodeUnpadded($this->userHandle),
            ],
            'clientExtensionResults' => [],
            'authenticatorAttachment' => 'platform',
        ];
    }

    /** L'identifiant de la clé, en base64url — tel que `core_passkey.credential_id`. */
    public function credentialId(): string
    {
        \assert(null !== $this->credentialId);

        return Base64UrlSafe::encodeUnpadded($this->credentialId);
    }

    private function clientData(string $type, string $defi, ?string $origine = null): string
    {
        return json_encode([
            'type' => $type,
            'challenge' => $defi,
            'origin' => $origine ?? $this->origine,
            'crossOrigin' => false,
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
    }

    private static function nouvelleCle(): \OpenSSLAsymmetricKey
    {
        $options = ['curve_name' => 'prime256v1', 'private_key_type' => \OPENSSL_KEYTYPE_EC];
        $cle = @openssl_pkey_new($options);

        if (false === $cle) {
            $configuration = \dirname(\PHP_BINARY).'/extras/ssl/openssl.cnf';
            $cle = is_file($configuration) ? @openssl_pkey_new($options + ['config' => $configuration]) : false;
        }

        if (false === $cle) {
            throw new \RuntimeException('OpenSSL ne génère pas de clé EC ici : poser OPENSSL_CONF (…/php/extras/ssl/openssl.cnf).');
        }

        return $cle;
    }

    private static function uuidEnOctets(string $uuid): string
    {
        $binaire = hex2bin(str_replace('-', '', $uuid));
        \assert(\is_string($binaire) && 16 === \strlen($binaire));

        return $binaire;
    }
}
