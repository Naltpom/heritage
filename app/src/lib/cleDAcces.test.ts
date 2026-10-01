/**
 * Les clés d'accès côté navigateur — conversions, mots, erreurs.
 *
 * Ce qui compte ici :
 *
 * 1. **Le base64url fait l'aller-retour sans perte**, sans `=`, et tolère ce
 *    qu'un serveur pourrait envoyer d'un peu différent (remplissage, alphabet
 *    classique) — une connexion ne doit pas échouer pour une affaire d'alphabet ;
 * 2. **les options du serveur deviennent des `ArrayBuffer`** aux trois endroits
 *    binaires (défi, identifiant de l'utilisateur, clés à exclure / permises),
 *    par la fonction native quand elle existe, par la nôtre sinon ;
 * 3. **la réponse du navigateur redevient du JSON** que le serveur lit, avec ou
 *    sans `toJSON()` natif (Safari d'iPhone ancien) ;
 * 4. **les mots** : on ne promet Face ID que si l'appareil a de quoi.
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  base64urlVersOctets,
  issueDeLErreur,
  libelleConnexionCleDAcces,
  messageDeLIssue,
  nomParDefautDeLAppareil,
  octetsVersBase64url,
  optionsDeCreation,
  optionsDeRequete,
  reponseEnJson,
  systemeDeLAgent,
  type IdentifiantBrut,
} from './cleDAcces'

const octets = (...valeurs: number[]): ArrayBuffer => new Uint8Array(valeurs).buffer
const lire = (b: ArrayBuffer | BufferSource): number[] =>
  Array.from(b instanceof ArrayBuffer ? new Uint8Array(b) : new Uint8Array((b as ArrayBufferView).buffer))

const AGENT_IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 Version/17.5 Mobile/15E148 Safari/604.1'
const AGENT_ANDROID = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 Chrome/126.0 Mobile Safari/537.36'
const AGENT_WINDOWS = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/126.0 Safari/537.36'
const AGENT_MAC = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 Version/17.5 Safari/605.1.15'

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('base64url ↔ octets', () => {
  it('fait l’aller-retour sans perte, sur toutes les valeurs d’octet', () => {
    const tous = new Uint8Array(256).map((_, i) => i)
    const texte = octetsVersBase64url(tous)
    expect(lire(base64urlVersOctets(texte))).toEqual(Array.from(tous))
  })

  it('écrit l’alphabet URL, sans remplissage', () => {
    // 0xFB 0xFF → « +/8= » en base64 classique.
    expect(octetsVersBase64url(octets(0xfb, 0xff))).toBe('-_8')
    expect(octetsVersBase64url(octets(1))).toBe('AQ')
    expect(octetsVersBase64url(new Uint8Array(0))).toBe('')
  })

  it('lit une vue partielle d’un tampon plus grand sans déborder', () => {
    const tampon = new Uint8Array([9, 9, 1, 2, 3, 9])
    expect(octetsVersBase64url(tampon.subarray(2, 5))).toBe(octetsVersBase64url(octets(1, 2, 3)))
  })

  it('tolère le remplissage et l’alphabet classique', () => {
    expect(lire(base64urlVersOctets('-_8='))).toEqual([0xfb, 0xff])
    expect(lire(base64urlVersOctets('+/8'))).toEqual([0xfb, 0xff])
  })

  it('refuse une chaîne illisible', () => {
    expect(() => base64urlVersOctets('ab$c')).toThrow(TypeError)
    expect(() => base64urlVersOctets('A')).toThrow(TypeError)
  })
})

/** Ce que rend `POST /api/cles-d-acces/options-d-enregistrement`. */
const CREATION: PublicKeyCredentialCreationOptionsJSON = {
  rp: { id: 'heritage.naltpom.fr', name: 'Heritage' },
  user: { id: 'AQID', name: 'cobalt@exemple.test', displayName: 'Cobalt Romarin' },
  challenge: 'BAUG',
  pubKeyCredParams: [{ type: 'public-key', alg: -7 }],
  timeout: 300000,
  excludeCredentials: [{ id: 'BwgJ', type: 'public-key', transports: ['internal'] }],
  authenticatorSelection: { residentKey: 'required', userVerification: 'required' },
  attestation: 'none',
}

describe('options de création', () => {
  it('convertit le défi, l’utilisateur et les clés à exclure, et garde le reste', () => {
    const o = optionsDeCreation(CREATION)
    expect(lire(o.challenge)).toEqual([4, 5, 6])
    expect(lire(o.user.id)).toEqual([1, 2, 3])
    expect(o.user.name).toBe('cobalt@exemple.test')
    expect(lire(o.excludeCredentials![0]!.id)).toEqual([7, 8, 9])
    expect(o.excludeCredentials![0]!.transports).toEqual(['internal'])
    expect(o.rp).toEqual({ id: 'heritage.naltpom.fr', name: 'Heritage' })
    expect(o.authenticatorSelection).toEqual({ residentKey: 'required', userVerification: 'required' })
    expect(o.attestation).toBe('none')
  })

  it('passe par `parseCreationOptionsFromJSON` quand le navigateur l’a', () => {
    const natif = vi.fn(() => ({ natif: true }) as unknown as PublicKeyCredentialCreationOptions)
    vi.stubGlobal('PublicKeyCredential', Object.assign(function () {}, { parseCreationOptionsFromJSON: natif }))
    expect(optionsDeCreation(CREATION)).toEqual({ natif: true })
    expect(natif).toHaveBeenCalledWith(CREATION)
  })

  it('se replie sur la conversion maison si la fonction native lève', () => {
    const natif = vi.fn(() => {
      throw new TypeError('extension inconnue')
    })
    vi.stubGlobal('PublicKeyCredential', Object.assign(function () {}, { parseCreationOptionsFromJSON: natif }))
    expect(lire(optionsDeCreation(CREATION).challenge)).toEqual([4, 5, 6])
  })
})

describe('options de requête (connexion)', () => {
  it('convertit le défi ; `allowCredentials` vide reste vide (clé découvrable)', () => {
    const o = optionsDeRequete({
      challenge: 'BAUG',
      rpId: 'heritage.naltpom.fr',
      allowCredentials: [],
      userVerification: 'required',
    })
    expect(lire(o.challenge)).toEqual([4, 5, 6])
    expect(o.allowCredentials).toEqual([])
    expect(o.rpId).toBe('heritage.naltpom.fr')
    expect(o.userVerification).toBe('required')
  })
})

describe('la réponse du navigateur, en JSON', () => {
  it('enregistrement, sans `toJSON` : tout le binaire en base64url, et les transports', () => {
    const brut: IdentifiantBrut = {
      id: 'AQID',
      rawId: octets(1, 2, 3),
      type: 'public-key',
      authenticatorAttachment: 'platform',
      getClientExtensionResults: () => ({ credProps: { rk: true } }),
      response: {
        clientDataJSON: octets(10),
        attestationObject: octets(11),
        getTransports: () => ['internal', 'hybrid'],
        getPublicKeyAlgorithm: () => -7,
        getPublicKey: () => octets(12),
        getAuthenticatorData: () => octets(13),
      },
    }
    expect(reponseEnJson(brut)).toEqual({
      id: 'AQID',
      rawId: 'AQID',
      type: 'public-key',
      authenticatorAttachment: 'platform',
      clientExtensionResults: { credProps: { rk: true } },
      response: {
        clientDataJSON: 'Cg',
        attestationObject: 'Cw',
        transports: ['internal', 'hybrid'],
        publicKeyAlgorithm: -7,
        publicKey: 'DA',
        authenticatorData: 'DQ',
      },
    })
  })

  it('connexion, sans `toJSON` : signature, données de l’authentificateur, et `userHandle`', () => {
    const json = reponseEnJson({
      id: 'BwgJ',
      rawId: octets(7, 8, 9),
      type: 'public-key',
      response: {
        clientDataJSON: octets(1),
        authenticatorData: octets(2),
        signature: octets(3),
        userHandle: octets(4),
      },
    })
    expect(json.response).toEqual({ clientDataJSON: 'AQ', authenticatorData: 'Ag', signature: 'Aw', userHandle: 'BA' })
    expect(json.authenticatorAttachment).toBeNull()
    expect(json.clientExtensionResults).toEqual({})
  })

  it('omet un `userHandle` nul plutôt que d’envoyer une chaîne vide', () => {
    const json = reponseEnJson({
      id: 'x',
      rawId: octets(1),
      type: 'public-key',
      response: { clientDataJSON: octets(1), authenticatorData: octets(2), signature: octets(3), userHandle: null },
    })
    expect(json.response).not.toHaveProperty('userHandle')
  })

  it('prend le `toJSON()` natif quand il rend une réponse complète', () => {
    const natif = { id: 'n', rawId: 'n', type: 'public-key', clientExtensionResults: {}, response: { clientDataJSON: 'Zm' } }
    expect(reponseEnJson({ id: 'n', rawId: octets(1), type: 'public-key', response: {}, toJSON: () => natif })).toBe(natif)
  })

  it('ignore un `toJSON()` incomplet (extension de gestionnaire de mots de passe)', () => {
    const json = reponseEnJson({
      id: 'AQ',
      rawId: octets(1),
      type: 'public-key',
      toJSON: () => ({}),
      response: { clientDataJSON: octets(1), authenticatorData: octets(2), signature: octets(3) },
    })
    expect(json.response['signature']).toBe('Aw')
  })
})

describe('les mots', () => {
  it('devine le système, pour les mots seulement', () => {
    expect(systemeDeLAgent(AGENT_IPHONE)).toBe('apple')
    expect(systemeDeLAgent(AGENT_MAC)).toBe('apple')
    expect(systemeDeLAgent(AGENT_ANDROID)).toBe('android')
    expect(systemeDeLAgent(AGENT_WINDOWS)).toBe('windows')
    expect(systemeDeLAgent('')).toBe('autre')
  })

  it('ne promet Face ID / empreinte que si l’appareil a un authentificateur', () => {
    expect(libelleConnexionCleDAcces(true, 'apple')).toBe('Se connecter avec Face ID / empreinte')
    expect(libelleConnexionCleDAcces(true, 'android')).toBe('Se connecter avec Face ID / empreinte')
    expect(libelleConnexionCleDAcces(true, 'windows')).toBe('Se connecter avec Windows Hello')
    expect(libelleConnexionCleDAcces(false, 'apple')).toBe('Se connecter avec une clé d’accès')
    expect(libelleConnexionCleDAcces(false, 'windows')).toBe('Se connecter avec une clé d’accès')
  })

  it('propose un nom d’appareil reconnaissable, ou rien', () => {
    expect(nomParDefautDeLAppareil(AGENT_IPHONE)).toBe('iPhone')
    expect(nomParDefautDeLAppareil(AGENT_ANDROID)).toBe('Téléphone Android')
    expect(nomParDefautDeLAppareil(AGENT_WINDOWS)).toBe('PC Windows')
    expect(nomParDefautDeLAppareil(AGENT_MAC)).toBe('Mac')
    expect(nomParDefautDeLAppareil('Robot/1.0')).toBeNull()
  })
})

describe('ce que veulent dire les erreurs du navigateur', () => {
  it('classe les `DOMException` par leur nom', () => {
    expect(issueDeLErreur(new DOMException('x', 'NotAllowedError'))).toBe('annulee')
    expect(issueDeLErreur(new DOMException('x', 'InvalidStateError'))).toBe('deja-presente')
    expect(issueDeLErreur(new DOMException('x', 'AbortError'))).toBe('interrompue')
    expect(issueDeLErreur(new DOMException('x', 'SecurityError'))).toBe('impossible')
    expect(issueDeLErreur(new DOMException('x', 'NotSupportedError'))).toBe('impossible')
  })

  it('laisse les erreurs du serveur à `MessageErreur`', () => {
    const erreurServeur = Object.assign(new Error('Identifiants invalides.'), { name: 'ErreurApi' })
    expect(issueDeLErreur(erreurServeur)).toBeNull()
    expect(issueDeLErreur('texte')).toBeNull()
    expect(issueDeLErreur(null)).toBeNull()
  })

  it('dit l’annulation sans alarme, et rien pour une interruption voulue', () => {
    expect(messageDeLIssue('annulee', 'connexion')).toContain('mot de passe')
    expect(messageDeLIssue('annulee', 'ajout')).toContain('Rien n’a changé')
    expect(messageDeLIssue('interrompue', 'connexion')).toBeNull()
  })
})
