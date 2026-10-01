import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { createSSRApp, h } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { describe, expect, it } from 'vitest'
import type { QuotaDeCompte } from '@/lib/api'
import { ErreurApi } from '@/lib/api/erreurs'
import EditeurDeQuotas from './EditeurDeQuotas.vue'
import QuotasDuCompte from './QuotasDuCompte.vue'
import {
  LIMITE_MAXIMALE,
  brouillonDesQuotas,
  descriptionDuQuota,
  erreursDuServeur,
  lireLeBrouillon,
  quotaAtteint,
  texteDuQuota,
} from './quotas'

/**
 * Les quotas d'usage dans l'écran Utilisateurs : la lecture « consommé /
 * limite », la marque d'une limite personnalisée, et l'éditeur où un champ
 * vide veut dire « le défaut ».
 */

const ANALYSES: QuotaDeCompte = {
  cle: 'assistant.analyses',
  libelle: 'Analyses IA',
  fenetre: 'par 24 h',
  consomme: 3,
  limite: 20,
  limiteParDefaut: 20,
  personnalisee: false,
  exempte: false,
}

const QUESTIONS: QuotaDeCompte = {
  cle: 'assistant.questions',
  libelle: 'Questions IA',
  fenetre: 'par 24 h',
  consomme: 12,
  limite: 15,
  limiteParDefaut: 60,
  personnalisee: true,
  exempte: false,
}

const MEDIAS: QuotaDeCompte = {
  cle: 'media.televersements',
  libelle: 'Médias',
  fenetre: 'par heure',
  consomme: 0,
  limite: 20,
  limiteParDefaut: 20,
  personnalisee: false,
  exempte: true,
}

describe('la lecture d’un quota', () => {
  it('se lit « consommé / limite », sur la limite effective', () => {
    expect(texteDuQuota(QUESTIONS)).toBe('12 / 15')
    expect(texteDuQuota(ANALYSES)).toBe('3 / 20')
  })

  it('est atteinte à la limite, et toujours à 0', () => {
    expect(quotaAtteint(ANALYSES)).toBe(false)
    expect(quotaAtteint({ ...ANALYSES, consomme: 20 })).toBe(true)
    expect(quotaAtteint({ ...ANALYSES, consomme: 0, limite: 0, personnalisee: true })).toBe(true)
    // Limite baissée sous ce qui était déjà consommé.
    expect(quotaAtteint({ ...QUESTIONS, consomme: 40 })).toBe(true)
  })

  it('dit le défaut quand la limite est personnalisée, et l’exemption', () => {
    expect(descriptionDuQuota(QUESTIONS)).toBe('Questions IA : 12 sur 15 par 24 h, limite personnalisée (défaut 60)')
    expect(descriptionDuQuota(ANALYSES)).toBe('Analyses IA : 3 sur 20 par 24 h, limite par défaut')
    expect(descriptionDuQuota(MEDIAS)).toBe('Médias : non compté pour un administrateur')
  })
})

describe('le brouillon de l’éditeur', () => {
  it('laisse vide ce qui suit le défaut', () => {
    expect(brouillonDesQuotas([ANALYSES, QUESTIONS])).toEqual({
      'assistant.analyses': '',
      'assistant.questions': '15',
    })
  })

  it('n’envoie que ce qui change', () => {
    const quotas = [ANALYSES, QUESTIONS]
    expect(lireLeBrouillon(quotas, brouillonDesQuotas(quotas))).toEqual({ limites: {}, erreurs: {} })
    expect(lireLeBrouillon(quotas, { 'assistant.analyses': '30', 'assistant.questions': '15' })).toEqual({
      limites: { 'assistant.analyses': 30 },
      erreurs: {},
    })
  })

  it('rend le défaut quand on vide le champ d’une limite personnalisée', () => {
    expect(lireLeBrouillon([QUESTIONS], { 'assistant.questions': '  ' }).limites).toEqual({
      'assistant.questions': null,
    })
  })

  it('accepte 0, qui ferme l’usage, et la borne haute — en texte comme en nombre', () => {
    expect(lireLeBrouillon([ANALYSES], { 'assistant.analyses': '0' }).limites).toEqual({ 'assistant.analyses': 0 })
    expect(lireLeBrouillon([ANALYSES], { 'assistant.analyses': LIMITE_MAXIMALE }).limites).toEqual({
      'assistant.analyses': LIMITE_MAXIMALE,
    })
  })

  it('refuse ce que le serveur refuserait, sans rien envoyer pour ce champ', () => {
    for (const valeur of ['-1', '1.5', 'douze', String(LIMITE_MAXIMALE + 1)]) {
      const lecture = lireLeBrouillon([ANALYSES], { 'assistant.analyses': valeur })
      expect(lecture.limites, valeur).toEqual({})
      expect(lecture.erreurs['assistant.analyses'], valeur).toContain('vide pour le défaut')
    }
  })

  it('ramène les messages du serveur à la clé du quota', () => {
    expect(
      erreursDuServeur({ 'limites.assistant.analyses': 'Un entier de 0 à 10000.', autre: 'x' }),
    ).toEqual({ 'assistant.analyses': 'Un entier de 0 à 10000.' })
    expect(erreursDuServeur(null)).toEqual({})
  })
})

describe('QuotasDuCompte (rendu)', () => {
  async function rendre(quotas: QuotaDeCompte[]): Promise<string> {
    return renderToString(createSSRApp({ render: () => h(QuotasDuCompte, { quotas }) }))
  }

  it('montre chaque quota, sa consommation sur sa limite, et marque la limite personnalisée', async () => {
    const html = await rendre([ANALYSES, QUESTIONS, MEDIAS])

    expect(html).toContain('Analyses IA')
    expect(html).toContain('3 / 20')
    expect(html).toContain('Questions IA')
    expect(html).toContain('12 / 15')
    expect(html.match(/data-quota-personnalise/g)).toHaveLength(1)
    expect(html).toContain('limite personnalisée (défaut 60)')
  })

  it('dit « non compté » pour un quota dont l’administrateur est exempté', async () => {
    const html = await rendre([MEDIAS])

    expect(html).toContain('non compté')
    expect(html).not.toContain('0 / 20')
  })
})

describe('EditeurDeQuotas (rendu)', () => {
  async function rendre(erreur: unknown = null): Promise<string> {
    return renderToString(
      createSSRApp({
        render: () =>
          h(EditeurDeQuotas, {
            edition: { titre: 'Quotas du compte', precision: 'Opale — opale@example.net', quotas: [ANALYSES, QUESTIONS, MEDIAS] },
            erreur,
          }),
      }),
    )
  }

  it('un champ numérique par quota, le défaut rappelé, Enregistrer et Annuler', async () => {
    const html = await rendre()

    expect(html.match(/type="number"/g)).toHaveLength(3)
    expect(html).toContain('Analyses IA (par 24 h)')
    expect(html).toContain('Vide = défaut (60 par 24 h)')
    expect(html).toContain('placeholder="20"')
    expect(html).toContain('Enregistrer')
    expect(html).toContain('Annuler')
  })

  it('éteint le champ d’un quota dont l’administrateur est exempté', async () => {
    const html = await rendre()

    expect(html).toContain('Non compté pour un administrateur')
    // L'attribut, pas la classe `disabled:opacity-60` du champ.
    expect(html.match(/\sdisabled(?=[\s>])/g)).toHaveLength(1)
  })

  it('affiche le message du serveur sous le champ concerné', async () => {
    const html = await rendre(
      new ErreurApi(422, 'donnees_invalides', 'Données invalides.', {
        'limites.assistant.questions': 'Un entier de 0 à 10000, ou null pour revenir au défaut (60).',
      }),
    )

    expect(html).toContain('Un entier de 0 à 10000, ou null pour revenir au défaut (60).')
    expect(html).toContain('aria-invalid="true"')
  })
})

describe('l’écran Utilisateurs', () => {
  const source = readFileSync(
    fileURLToPath(new URL('../../views/administration/UtilisateursView.vue', import.meta.url)),
    'utf8',
  )

  it('a sa colonne « Quotas », son action en icône, et l’éditeur', () => {
    expect(source).toContain('<QuotasDuCompte :quotas="utilisateur.quotas" />')
    expect(source).toContain('libelle="Modifier les quotas"')
    expect(source).toContain('<EditeurDeQuotas')
  })

  it('garde le défilement horizontal du tableau sur téléphone', () => {
    expect(source).toContain('<div v-else class="overflow-x-auto">')
  })
})
