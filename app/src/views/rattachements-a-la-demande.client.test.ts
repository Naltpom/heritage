/**
 * « À rattacher » — **rien ne part chez l'IA sans un clic** (3 octobre 2026),
 * écran monté et cliqué (`test-utils/rendu-memoire.ts`).
 *
 * Le propriétaire : « si transcription, est-ce que je peux modifier le texte
 * moi avant ? et ne pas lancer la recherche IA directement au chargement de la
 * page, me mettre le bouton lancer la recherche moi-même ». Ce que ce fichier
 * tient, geste par geste :
 *
 *  1. **l'ouverture ne fait que lire** — la liste, le quota, et la suggestion
 *     gardée des seules cartes qui en ont une ; ni transcription, ni recherche ;
 *  2. **« Transcrire le message vocal »** part au clic, et le texte arrive dans
 *     un champ modifiable ;
 *  3. **corriger puis enregistrer** : tant que la correction n'est pas
 *     enregistrée, « Relancer la recherche » attend ; enregistrée, la
 *     suggestion affichée devient périmée ; le clic relance.
 *
 * `rattachements.test.ts` tient ce que la carte imprime, état par état ; ici,
 * on tient ce que les gestes **appellent**.
 */
import { defineComponent, h } from 'vue'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { CompteARattacher, SuggestionDeRattachement, TranscriptionARattacher } from '@/lib/api'
import { boutonNomme, cliquer, monterEnMemoire, parAttribut, texte, un, type Noeud } from '@/test-utils/rendu-memoire'

const etat = vi.hoisted(() => ({
  vueEmpruntee: null as unknown as { value: unknown },
}))

vi.mock('@/composables/useSession', async () => {
  const { shallowRef } = await import('vue')
  etat.vueEmpruntee = shallowRef(null)
  return {
    useSession: () => ({
      session: shallowRef({ utilisateur: { id: 1, displayName: 'Natron' } }),
      administrateur: shallowRef(true),
      vueEmpruntee: etat.vueEmpruntee,
      incarnation: shallowRef(null),
    }),
    rafraichirSession: () => Promise.resolve(),
  }
})

vi.mock('vue-router', () => ({
  useRoute: () => ({ params: {}, query: {} }),
  useRouter: () => ({ push: () => Promise.resolve(), replace: () => Promise.resolve() }),
}))

const api = vi.hoisted(() => ({
  listerRattachements: vi.fn(),
  lireSuggestionDeRattachement: vi.fn(),
  relancerSuggestionDeRattachement: vi.fn(),
  transcrireMessageVocal: vi.fn(),
  corrigerTranscription: vi.fn(),
  listerQuotasGlobaux: vi.fn(),
  quitterVueComme: vi.fn(),
}))

vi.mock('@/lib/api', async (original) => ({
  ...(await original<typeof import('@/lib/api')>()),
  ...api,
}))

import RattachementsView from './administration/RattachementsView.vue'

const LienFactice = defineComponent({
  name: 'RouterLink',
  props: { to: { type: [String, Object], default: '' } },
  setup: (_, { slots }) => () => h('a', { href: '#' }, slots['default']?.()),
})

/* ------------------------------------------------------------------ */
/* Les charges — noms inventés                                         */
/* ------------------------------------------------------------------ */

const TEXTE_DE_GEMINI = 'Je suis Léa Serpolet ; mon père est Jean Serpolait [?].'
const TEXTE_CORRIGE = 'Je suis Léa Serpolet ; mon père est Jean Serpolet.'

function transcription(sur: Partial<TranscriptionARattacher> = {}): TranscriptionARattacher {
  return { statut: 'a_faire', texte: null, erreur: null, le: null, corrigeeLe: null, ...sur }
}

function compte(memberId: number, sur: Partial<CompteARattacher> = {}): CompteARattacher {
  return {
    memberId,
    userId: memberId + 100,
    email: `inscrit${memberId}@example.net`,
    inscritLe: '2026-10-01T09:12:00+02:00',
    invitationId: 3,
    invitationPersonId: null,
    invitationPersonLabel: null,
    declaration: { surname: 'Serpolet', givenNames: `Léa ${memberId}`, birthDate: '1998-04-12', description: null },
    messageVocal: {
      url: `/api/administration/rattachements/${memberId}/message-vocal`,
      type: 'audio/webm',
      taille: 4096,
      duree: 42,
      transcription: transcription(),
    },
    photo: null,
    suggestionCalculeeLe: null,
    ...sur,
  }
}

function suggestion(memberId: number, sur: Partial<SuggestionDeRattachement> = {}): SuggestionDeRattachement {
  return {
    memberId,
    calculeeLe: '2026-10-02T21:05:00+02:00',
    modele: 'gemini-pro-latest',
    verdict: 'indecise',
    resume: 'Aucune fiche ne correspond avec certitude.',
    avertissements: [],
    fiche: null,
    alternatives: [],
    creation: null,
    transcriptionPriseEnCompte: true,
    perimee: false,
    ...sur,
  }
}

const QUOTAS = {
  quotas: [
    {
      cle: 'assistant.rattachements',
      libelle: 'Rattachements assistés',
      fenetre: 'par 24 h',
      consomme: 3,
      limite: 40,
      limiteParDefaut: 40,
      personnalisee: false,
    },
  ],
}

/* ------------------------------------------------------------------ */

function carte(racine: Noeud, memberId: number): Noeud {
  const trouvee = un(racine, (n) => n.tag === 'article' && n.attributs['data-compte'] === memberId)
  if (trouvee === null) throw new Error(`Carte absente : ${memberId}`)
  return trouvee
}

function eteint(bouton: Noeud | null): boolean {
  if (bouton === null) throw new Error('Bouton absent.')
  return Boolean(bouton.attributs['disabled'])
}

/** Tape dans le champ de la transcription, comme un clavier. */
async function taper(champ: Noeud | null, valeur: string): Promise<void> {
  if (champ === null) throw new Error('Champ absent.')
  const ecouteur = champ.ecouteurs['input']
  if (typeof ecouteur !== 'function') throw new Error('Le champ n’écoute pas la saisie.')
  ecouteur({ target: { value: valeur } })
  await new Promise((fin) => setTimeout(fin, 0))
}

function monter(): Promise<Noeud> {
  return monterEnMemoire(RattachementsView, { RouterLink: LienFactice })
}

beforeEach(() => {
  vi.stubGlobal('document', { addEventListener: () => undefined, removeEventListener: () => undefined })
  vi.stubGlobal('window', {
    addEventListener: () => undefined,
    removeEventListener: () => undefined,
    matchMedia: () => ({ matches: false, addEventListener: () => undefined, removeEventListener: () => undefined }),
  })
  Object.values(api).forEach((f) => f.mockReset())
  etat.vueEmpruntee.value = null
  api.listerQuotasGlobaux.mockResolvedValue(QUOTAS)
})

describe('« À rattacher » : l’ouverture ne fait que lire', () => {
  it('ni transcription ni recherche ; la suggestion gardée des seules cartes qui en ont une ; le quota', async () => {
    api.listerRattachements.mockResolvedValue({
      elements: [
        compte(14),
        compte(15, {
          suggestionCalculeeLe: '2026-10-02T21:05:00+02:00',
          messageVocal: {
            url: '/api/administration/rattachements/15/message-vocal',
            type: 'audio/webm',
            taille: 4096,
            duree: 42,
            transcription: transcription({ statut: 'faite', texte: TEXTE_DE_GEMINI, le: '2026-10-02T21:00:00+02:00' }),
          },
        }),
      ],
      total: 2,
      page: 1,
      parPage: 100,
    })
    api.lireSuggestionDeRattachement.mockResolvedValue(suggestion(15))

    const racine = await monter()

    expect(api.transcrireMessageVocal).not.toHaveBeenCalled()
    expect(api.relancerSuggestionDeRattachement).not.toHaveBeenCalled()
    expect(api.lireSuggestionDeRattachement).toHaveBeenCalledTimes(1)
    expect(api.lireSuggestionDeRattachement.mock.calls[0]![0]).toBe(15)

    const premiere = carte(racine, 14)
    expect(un(premiere, boutonNomme('Transcrire le message vocal'))).not.toBeNull()
    expect(eteint(un(premiere, boutonNomme('Lancer la recherche')))).toBe(false)
    expect(texte(premiere)).toContain('37 appels à l’IA restants sur 40')

    const seconde = carte(racine, 15)
    expect(texte(seconde)).toContain('Aucune fiche ne correspond avec certitude.')
    expect(un(seconde, boutonNomme('Relancer la recherche'))).not.toBeNull()
  })

  it('sans quota lisible, la carte n’en invente pas', async () => {
    api.listerRattachements.mockResolvedValue({ elements: [compte(14)], total: 1, page: 1, parPage: 100 })
    api.listerQuotasGlobaux.mockRejectedValue(new Error('Panne.'))

    const racine = await monter()
    expect(un(racine, parAttribut('data-quota'))).toBeNull()
    expect(un(racine, boutonNomme('Lancer la recherche'))).not.toBeNull()
  })
})

describe('« À rattacher » : chaque appel part d’un clic', () => {
  it('« Transcrire le message vocal » transcrit, puis le texte arrive dans un champ modifiable', async () => {
    api.listerRattachements.mockResolvedValue({ elements: [compte(14)], total: 1, page: 1, parPage: 100 })
    api.transcrireMessageVocal.mockResolvedValue({
      memberId: 14,
      ...transcription({ statut: 'faite', texte: TEXTE_DE_GEMINI, le: '2026-10-03T08:00:00+02:00' }),
    })
    const racine = await monter()

    await cliquer(un(carte(racine, 14), boutonNomme('Transcrire le message vocal')))

    expect(api.transcrireMessageVocal).toHaveBeenCalledTimes(1)
    expect(api.transcrireMessageVocal.mock.calls[0]![0]).toBe(14)
    expect(api.relancerSuggestionDeRattachement).not.toHaveBeenCalled()
    const champ = un(carte(racine, 14), parAttribut('data-champ-transcription'))
    expect(champ?.tag).toBe('textarea')
    expect(champ?.attributs['value']).toBe(TEXTE_DE_GEMINI)
    // Le quota est relu après l'appel.
    expect(api.listerQuotasGlobaux).toHaveBeenCalledTimes(2)
  })

  it('corriger : la recherche attend l’enregistrement, la suggestion devient périmée, puis on relance', async () => {
    api.listerRattachements.mockResolvedValue({
      elements: [
        compte(14, {
          suggestionCalculeeLe: '2026-10-02T21:05:00+02:00',
          messageVocal: {
            url: '/api/administration/rattachements/14/message-vocal',
            type: 'audio/webm',
            taille: 4096,
            duree: 42,
            transcription: transcription({ statut: 'faite', texte: TEXTE_DE_GEMINI, le: '2026-10-02T21:00:00+02:00' }),
          },
        }),
      ],
      total: 1,
      page: 1,
      parPage: 100,
    })
    api.lireSuggestionDeRattachement
      .mockResolvedValueOnce(suggestion(14))
      .mockResolvedValueOnce(suggestion(14, { perimee: true }))
    api.corrigerTranscription.mockResolvedValue({
      memberId: 14,
      ...transcription({
        statut: 'faite',
        texte: TEXTE_CORRIGE,
        le: '2026-10-02T21:00:00+02:00',
        corrigeeLe: '2026-10-03T08:30:00+02:00',
      }),
    })
    api.relancerSuggestionDeRattachement.mockResolvedValue(suggestion(14, { resume: 'Avec les noms corrigés.' }))
    const racine = await monter()

    // Rien n'a changé : « Enregistrer » éteint, la recherche peut partir.
    expect(eteint(un(carte(racine, 14), boutonNomme('Enregistrer')))).toBe(true)
    expect(eteint(un(carte(racine, 14), boutonNomme('Relancer la recherche')))).toBe(false)

    // On corrige le nom : la recherche attend que la correction soit enregistrée.
    await taper(un(carte(racine, 14), parAttribut('data-champ-transcription')), `  ${TEXTE_CORRIGE}\n`)
    expect(eteint(un(carte(racine, 14), boutonNomme('Enregistrer')))).toBe(false)
    expect(eteint(un(carte(racine, 14), boutonNomme('Relancer la recherche')))).toBe(true)
    expect(texte(carte(racine, 14))).toContain('Enregistrez (ou annulez) votre correction')

    await cliquer(un(carte(racine, 14), boutonNomme('Enregistrer')))

    expect(api.corrigerTranscription).toHaveBeenCalledWith(14, TEXTE_CORRIGE)
    expect(api.relancerSuggestionDeRattachement).not.toHaveBeenCalled()
    const apres = carte(racine, 14)
    expect(texte(apres)).toContain('Transcription — corrigée à la main')
    expect(un(apres, parAttribut('data-perimee'))).not.toBeNull()
    expect(texte(apres)).toContain('La transcription a changé depuis cette recherche : relancez la recherche.')
    expect(api.lireSuggestionDeRattachement).toHaveBeenCalledTimes(2)

    await cliquer(un(apres, boutonNomme('Relancer la recherche')))

    expect(api.relancerSuggestionDeRattachement).toHaveBeenCalledTimes(1)
    expect(api.relancerSuggestionDeRattachement.mock.calls[0]![0]).toBe(14)
    expect(texte(carte(racine, 14))).toContain('Avec les noms corrigés.')
    expect(un(carte(racine, 14), parAttribut('data-perimee'))).toBeNull()
  })

  it('« L’écrire moi-même » : le champ s’ouvre vide, et l’enregistrement part sans appel au modèle', async () => {
    api.listerRattachements.mockResolvedValue({ elements: [compte(14)], total: 1, page: 1, parPage: 100 })
    api.corrigerTranscription.mockResolvedValue({
      memberId: 14,
      ...transcription({
        statut: 'faite',
        texte: TEXTE_CORRIGE,
        le: '2026-10-03T08:30:00+02:00',
        corrigeeLe: '2026-10-03T08:30:00+02:00',
      }),
    })
    const racine = await monter()

    await cliquer(un(carte(racine, 14), boutonNomme('L’écrire moi-même')))
    const champ = un(carte(racine, 14), parAttribut('data-champ-transcription'))
    expect(champ?.attributs['value']).toBe('')
    expect(eteint(un(carte(racine, 14), boutonNomme('Enregistrer')))).toBe(true)

    await taper(champ, TEXTE_CORRIGE)
    await cliquer(un(carte(racine, 14), boutonNomme('Enregistrer')))

    expect(api.corrigerTranscription).toHaveBeenCalledWith(14, TEXTE_CORRIGE)
    expect(api.transcrireMessageVocal).not.toHaveBeenCalled()
    expect(texte(carte(racine, 14))).toContain('Transcription — corrigée à la main')
  })
})
