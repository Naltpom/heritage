/**
 * Le message vocal de « Décrivez votre famille » — ce qui se décide sans
 * micro : le format demandé au navigateur, les bornes, les messages quand le
 * micro manque, le chrono, et la forme exacte du corps `multipart`.
 */
import { describe, expect, it } from 'vitest'
import { corpsAvecMessageVocal } from '@/lib/api'
import {
  DUREE_MAX_SECONDES,
  TAILLE_MAX_OCTETS,
  choisirTypeMime,
  disponibiliteMicro,
  erreurMessageVocal,
  extensionDuType,
  formaterChrono,
  messageDErreurMicro,
  messageIndisponible,
  tailleLisible,
  typeDuFichier,
} from './messageVocal'

describe('le format demandé à MediaRecorder', () => {
  it('WebM/Opus d’abord — Chrome, Edge, Firefox, Android', () => {
    expect(choisirTypeMime(() => true)).toBe('audio/webm;codecs=opus')
  })

  it('MP4 pour Safari, iPhone compris', () => {
    expect(choisirTypeMime((mime) => mime === 'audio/mp4')).toBe('audio/mp4')
  })

  it('rien de connu : on laisse le navigateur choisir', () => {
    expect(choisirTypeMime(() => false)).toBe('')
  })

  it('un moteur qui lève sur la question est traité comme un refus', () => {
    expect(
      choisirTypeMime((mime) => {
        if (mime.startsWith('audio/webm')) throw new Error('inconnu')
        return mime === 'audio/mp4'
      }),
    ).toBe('audio/mp4')
  })

  it('le type du fichier, sans paramètres — et `video/mp4` ramené à de l’audio', () => {
    expect(typeDuFichier('audio/webm;codecs=opus')).toBe('audio/webm')
    expect(typeDuFichier('video/mp4')).toBe('audio/mp4')
    expect(typeDuFichier('')).toBe('audio/webm')
    expect(extensionDuType('audio/mp4')).toBe('m4a')
    expect(extensionDuType('audio/ogg')).toBe('ogg')
    expect(extensionDuType('audio/webm')).toBe('webm')
  })
})

describe('le chrono', () => {
  it('compte en minutes et secondes', () => {
    expect(formaterChrono(0)).toBe('0:00')
    expect(formaterChrono(7)).toBe('0:07')
    expect(formaterChrono(65.9)).toBe('1:05')
    expect(formaterChrono(DUREE_MAX_SECONDES)).toBe('5:00')
  })

  it('ne rend jamais de nombre négatif ni de NaN', () => {
    expect(formaterChrono(-3)).toBe('0:00')
    expect(formaterChrono(Number.NaN)).toBe('0:00')
  })
})

describe('les bornes d’un enregistrement', () => {
  it('cinq minutes et 10 Mo', () => {
    expect(DUREE_MAX_SECONDES).toBe(300)
    expect(TAILLE_MAX_OCTETS).toBe(10 * 1024 * 1024)
  })

  it('accepte un message ordinaire', () => {
    expect(erreurMessageVocal(400_000, 95)).toBeNull()
  })

  it('refuse un message vide, trop lourd ou trop long', () => {
    expect(erreurMessageVocal(0, 3)).toMatch(/vide/)
    expect(erreurMessageVocal(TAILLE_MAX_OCTETS + 1, 60)).toMatch(/10 Mo/)
    expect(erreurMessageVocal(1000, DUREE_MAX_SECONDES + 60)).toMatch(/cinq minutes/)
  })

  it('« 540 ko », « 1,2 Mo »', () => {
    expect(tailleLisible(540 * 1024)).toBe('540 ko')
    expect(tailleLisible(1.2 * 1024 * 1024)).toBe('1,2 Mo')
    expect(tailleLisible(10)).toBe('1 ko')
  })
})

describe('quand le micro n’est pas offert', () => {
  const COMPLET = { contexteSecurise: true, aUnEnregistreur: true, aUnMicro: true }

  it('le dit — page non sécurisée, pas de micro, pas d’enregistreur', () => {
    expect(disponibiliteMicro(COMPLET)).toBe('disponible')
    expect(disponibiliteMicro({ ...COMPLET, contexteSecurise: false })).toBe('non_securise')
    expect(disponibiliteMicro({ ...COMPLET, aUnMicro: false })).toBe('sans_micro')
    expect(disponibiliteMicro({ ...COMPLET, aUnEnregistreur: false })).toBe('sans_enregistreur')
  })

  it('et rappelle chaque fois que le texte reste possible', () => {
    for (const etat of ['non_securise', 'sans_micro', 'sans_enregistreur'] as const) {
      expect(messageIndisponible(etat)).toMatch(/écrire votre réponse/)
    }
    for (const nom of ['NotAllowedError', 'NotFoundError', 'NotReadableError', 'AutreChose']) {
      expect(messageDErreurMicro(new DOMException('x', nom))).toMatch(/écrivez votre réponse/)
    }
  })

  it('un refus d’autorisation dit comment revenir dessus', () => {
    expect(messageDErreurMicro(new DOMException('x', 'NotAllowedError'))).toMatch(/Autorisez-le/)
  })
})

describe('le corps envoyé avec un message vocal', () => {
  it('porte le JSON habituel dans « donnees » et le fichier dans « messageVocal »', async () => {
    const fichier = new Blob([new Uint8Array([0x1a, 0x45, 0xdf, 0xa3])], { type: 'audio/webm' })
    const corps = { jeton: 'j', declaration: { surname: 'Dupont', description: '' }, email: 'a@b.c', password: 'x' }

    const formulaire = corpsAvecMessageVocal(corps, { fichier, type: 'audio/webm', dureeSecondes: 12 })

    expect(JSON.parse(String(formulaire.get('donnees')))).toEqual(corps)
    const envoye = formulaire.get('messageVocal')
    expect(envoye).toBeInstanceOf(Blob)
    expect((envoye as File).name).toBe('message-vocal.webm')
    expect((envoye as Blob).size).toBe(4)
    expect([...formulaire.keys()].sort()).toEqual(['donnees', 'messageVocal'])
  })

  it('nomme un MP4 de Safari en .m4a — à titre indicatif, le serveur lit les octets', () => {
    const formulaire = corpsAvecMessageVocal({}, { fichier: new Blob(['x']), type: 'audio/mp4', dureeSecondes: 1 })
    expect((formulaire.get('messageVocal') as File).name).toBe('message-vocal.m4a')
  })
})
