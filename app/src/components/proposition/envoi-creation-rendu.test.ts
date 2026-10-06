import { createSSRApp, h } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { describe, expect, it } from 'vitest'
import type { BlocDate, DoublonsProbables, MaDemandeDoublon, PersonneDoublon } from '@/lib/api'
import ModaleEnvoiCreation from './ModaleEnvoiCreation.vue'
import { creationVide, type SaisieCreation } from './creation'
import { saisieVide } from './dates'

/**
 * `doublons.test.ts` vérifie ce que la comparaison décide ; **ce fichier
 * vérifie que la modale l'imprime** — le titre attendu par le commanditaire, la
 * comparaison avec ses états écrits en toutes lettres, et surtout **les bonnes
 * issues** : « mettre à jour » pour ma demande, « proposer une modification »
 * pour une fiche de l'arbre, et rien de tel pour la demande d'un autre.
 *
 * Rendu en SSR, comme `edition-rendu.test.ts` : le `<dialog>` s'imprime avec son
 * contenu dès que `ouverte` est vrai.
 */

function bloc(fragment: Partial<BlocDate> = {}): BlocDate {
  return { raw: null, qualifier: 'inconnue', min: null, max: null, place: null, texte: '', ...fragment }
}

function saisie(): SaisieCreation {
  const s = creationVide()
  s.brouillon.givenNames = 'Marne'
  s.brouillon.surname = 'Sureau'
  s.brouillon.sex = 'feminin'
  s.brouillon.deceased = true
  s.brouillon.naissance = { ...saisieVide(), forme: 'connue', debut: '1905' }
  s.filiation.parents = [{ personId: 55, libelle: 'Jargon Sarriette' }]
  return s
}

const MARNE: PersonneDoublon = {
  personId: 17,
  displayName: 'Marne Dolomite Sureau',
  givenNames: 'Marne Dolomite',
  surname: 'Sureau',
  marriedName: null,
  nickname: null,
  sex: 'feminin',
  deceased: true,
  vivant: false,
  birth: bloc({ raw: 'BET 1901 AND 1909', qualifier: 'entre', min: '1901-01-01', max: '1909-12-31', texte: 'entre 1901 et 1909' }),
  death: bloc({ raw: '1987', qualifier: 'annee', min: '1987-01-01', max: '1987-12-31', texte: '1987' }),
  ligneDates: '1901-1909 – 1987',
  parents: [],
  conjoints: [],
  enfants: [],
  criteres: ['nom', 'prenom', 'naissance', 'sexe'],
}

const MA_DEMANDE: MaDemandeDoublon = {
  demandeId: 12,
  draftPersonId: 7,
  localRef: '#p1',
  noeudId: '#12/p1',
  status: 'en_attente',
  submittedAt: null,
  displayName: 'Marne Sureau',
  givenNames: 'Marne',
  surname: 'Sureau',
  marriedName: null,
  nickname: null,
  sex: 'feminin',
  deceased: true,
  birth: bloc(),
  death: bloc(),
  ligneDates: '—',
  note: null,
  liens: [],
  criteres: ['nom', 'prenom'],
}

const AUCUN: DoublonsProbables = { personnes: [], mesDemandes: [], enCoursAilleurs: false }

function rendre(props: Record<string, unknown>): Promise<string> {
  const app = createSSRApp({ render: () => h(ModaleEnvoiCreation, { ouverte: true, saisie: saisie(), ...props }) })
  app.component('RouterLink', { render: () => h('a') })
  return renderToString(app)
}

describe('sans doublon : le récapitulatif, inchangé dans son principe', () => {
  it('récapitule ce qui part et propose de confirmer', async () => {
    const html = await rendre({ doublons: AUCUN })
    expect(html).toContain('Relire avant d’envoyer')
    expect(html).toContain('data-recapitulatif')
    expect(html).toContain('Jargon Sarriette')
    expect(html).toContain('Confirmer l’envoi')
    expect(html).not.toContain('data-verification-echouee')
    expect(html).not.toContain('Attention')
  })

  it('une vérification échouée ne bloque pas : un mot discret, et le récapitulatif', async () => {
    const html = await rendre({ doublons: null, verificationEchouee: true })
    expect(html).toContain('data-verification-echouee')
    expect(html).toContain('role="status"')
    expect(html).toContain('Confirmer l’envoi')
  })
})

describe('une personne de l’arbre ressemble', () => {
  it('avertit, compare champ par champ, et propose de modifier la fiche', async () => {
    const html = await rendre({ doublons: { ...AUCUN, personnes: [MARNE] } })
    expect(html).toContain('Attention : cette personne existe déjà dans l’arbre')
    expect(html).toMatch(/data-etat="divergent"[^>]*data-champ="givenNames"/)
    expect(html).toMatch(/data-etat="identique"[^>]*data-champ="surname"/)
    expect(html).toMatch(/data-etat="ajoute"[^>]*data-champ="parents"/)
    expect(html).toContain('Ajouté par vous')
    expect(html).toContain('entre 1901 et 1909')
    expect(html).toContain('data-geste="modifier"')
    expect(html).toContain('C’est bien une nouvelle personne')
    expect(html).toContain('Ne rien faire')
    expect(html).not.toContain('data-geste="completer"')
  })

  it('passe d’une correspondance à l’autre quand il y en a plusieurs', async () => {
    const html = await rendre({ doublons: { ...AUCUN, personnes: [MARNE], mesDemandes: [MA_DEMANDE] } })
    expect(html).toContain('data-correspondances')
    expect(html).toContain('aria-pressed="true"')
    expect(html).toContain('votre demande')
  })
})

describe('une de mes demandes ressemble', () => {
  it('propose de la mettre à jour', async () => {
    const html = await rendre({ doublons: { ...AUCUN, mesDemandes: [MA_DEMANDE] } })
    expect(html).toContain('Attention : cette personne est déjà en cours de validation')
    expect(html).toContain('Votre demande n° 12')
    expect(html).toContain('data-geste="completer"')
    expect(html).toContain('Mettre à jour la demande existante')
    expect(html).not.toContain('data-geste="modifier"')
  })
})

describe('la demande d’un autre membre', () => {
  it('ne dit qu’une phrase, et n’offre aucune mise à jour', async () => {
    const html = await rendre({ doublons: { ...AUCUN, enCoursAilleurs: true } })
    expect(html).toContain('Attention : cette personne est déjà en cours de validation')
    expect(html).toContain('data-en-cours-ailleurs')
    expect(html).toContain('Vous ne pouvez pas la modifier')
    expect(html).not.toContain('data-geste="completer"')
    expect(html).not.toContain('data-geste="modifier"')
    expect(html).not.toContain('data-comparaison')
    expect(html).toContain('C’est bien une nouvelle personne')
  })
})

describe('l’accessibilité de la modale', () => {
  it('est un dialogue nommé par son titre et décrit', async () => {
    const html = await rendre({ doublons: { ...AUCUN, personnes: [MARNE] } })
    const titre = /aria-labelledby="([^"]+)"/.exec(html)?.[1]
    const description = /aria-describedby="([^"]+)"/.exec(html)?.[1]
    expect(titre).toBeTruthy()
    expect(html).toContain(`id="${titre}"`)
    expect(html).toContain(`id="${description}"`)
  })
})
