import { createSSRApp, h, type Component } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { createMemoryHistory, createRouter } from 'vue-router'
import { describe, expect, it } from 'vitest'
import type { ResumeDeDemarche, StatutDeDemarche } from '@/lib/api'
import CarteDemarche from './CarteDemarche.vue'
import FiltreStatuts from './FiltreStatuts.vue'
import FormulaireDemarche from './FormulaireDemarche.vue'
import {
  STATUTS,
  STATUTS_EN_ATTENTE,
  TOUS_LES_STATUTS,
  aujourdhuiIso,
  basculerStatut,
  compteDe,
  compterParStatut,
  descriptionPourRefaire,
  ecrireStatuts,
  lireStatuts,
  peutEtreRefaite,
  correspondARecherche,
  dateLisible,
  descriptionAEnvoyer,
  erreursDuMessage,
  libelleDAttente,
  messageAEnvoyer,
  messageVide,
} from './demarches'

/** Telle que `SerialiseurDeDemarche::resume()` la rend — relue dans son code. */
function resume(surcharge: Partial<ResumeDeDemarche> = {}): ResumeDeDemarche {
  return {
    id: 3,
    nature: 'mariage',
    natureLibelle: 'Acte de mariage',
    forme: 'copie_integrale',
    formeLibelle: 'Copie intégrale',
    intitule: 'Mariage SUREAU × SARRIETTE, Charmille, 1925',
    commune: 'Charmille',
    dateDeLActe: 'vers 1925',
    destinataire: 'Mairie de Charmille',
    statut: 'envoyee',
    statutLibelle: 'Envoyée, en attente',
    enAttente: true,
    attendUneReponse: true,
    resultat: null,
    personnes: [{ personId: 4, libelle: 'Marne Dolomite Sureau' }],
    auteur: { libelle: 'Opale', estMoi: true },
    correspondants: ['opale@example.invalid', 'etat-civil@charmille.invalid'],
    nombreDeMessages: 1,
    dernierEnvoiLe: '2026-09-30',
    dernierMessageLe: '2026-09-30',
    joursDAttente: 0,
    aRelancer: false,
    precedente: null,
    suites: [],
    peutSupprimer: true,
    creeeLe: '2026-09-30T12:00:00+02:00',
    modifieeLe: '2026-09-30T12:00:00+02:00',
    ...surcharge,
  }
}

async function rendre(demarche: ResumeDeDemarche): Promise<string> {
  return rendreComposant(CarteDemarche, { demarche })
}

async function rendreComposant(composant: Component, proprietes: Record<string, unknown>): Promise<string> {
  const routeur = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:p(.*)*', name: 'personne.detail', component: { render: () => null } }] })
  const app = createSSRApp({ render: () => h(composant, proprietes) })
  app.use(routeur)
  return renderToString(app)
}

/** Les boutons rendus : libellé nettoyé, et état `aria-pressed`. */
function boutons(html: string): Array<{ libelle: string; presse: string | null }> {
  return [...html.matchAll(/<button([^>]*)>([\s\S]*?)<\/button>/g)].map((m) => ({
    libelle: (m[2] ?? '').replace(/<!--[\s\S]*?-->/g, '').replace(/<[^>]+>/g, '').replace(/\s+/g, ' ').trim(),
    presse: /aria-pressed="(true|false)"/.exec(m[1] ?? '')?.[1] ?? null,
  }))
}

describe('l’attente d’une démarche', () => {
  it('se dit en jours, au singulier comme au pluriel', () => {
    expect(libelleDAttente(null)).toBeNull()
    expect(libelleDAttente(0)).toBe('envoyée aujourd’hui')
    expect(libelleDAttente(1)).toBe('en attente depuis 1 jour')
    expect(libelleDAttente(12)).toBe('en attente depuis 12 jours')
  })

  it('s’affiche sur la carte, avec l’avertissement quand le serveur dit de relancer', async () => {
    const html = await rendre(resume({ joursDAttente: 41, aRelancer: true }))
    expect(html).toContain('Mariage SUREAU × SARRIETTE, Charmille, 1925')
    expect(html).toContain('en attente depuis 41 jours')
    expect(html).toContain('à relancer')
    expect(html).toContain('30 septembre 2026')
  })

  it('ne dit rien de l’attente sur une démarche reçue', async () => {
    const html = await rendre(resume({ statut: 'recue', statutLibelle: 'Reçue', enAttente: false, joursDAttente: null }))
    expect(html).toContain('Reçue')
    expect(html).not.toContain('en attente depuis')
  })
})

describe('expédiée par courrier', () => {
  it('attend encore la pièce, mais n’est jamais « à relancer » : c’est le serveur qui le dit', async () => {
    const html = await rendre(
      resume({
        statut: 'expediee',
        statutLibelle: 'Expédiée par courrier, en attente de réception',
        attendUneReponse: false,
        joursDAttente: 41,
        aRelancer: false,
      }),
    )
    expect(html).toContain('Expédiée par courrier, en attente de réception')
    expect(html).toContain('en attente depuis 41 jours')
    expect(html).not.toContain('à relancer')
  })

  it('se propose dans « Changer le statut » avec son libellé, et compte parmi ce qui attend', () => {
    expect(STATUTS.find((s) => s.valeur === 'expediee')?.libelle).toBe('Expédiée par courrier, en attente de réception')
    expect(STATUTS_EN_ATTENTE).toContain('expediee')
  })
})

describe('le filtre par statut', () => {
  const liste = [
    resume({ id: 1, statut: 'envoyee' }),
    resume({ id: 2, statut: 'envoyee' }),
    resume({ id: 3, statut: 'relancee' }),
    resume({ id: 4, statut: 'expediee' }),
    resume({ id: 5, statut: 'recue' }),
    resume({ id: 6, statut: 'refusee' }),
  ]

  it('compte chaque statut, et les raccourcis additionnent', () => {
    const comptes = compterParStatut(liste)
    expect(comptes.total).toBe(6)
    expect(comptes.parStatut).toEqual({ envoyee: 2, relancee: 1, expediee: 1, recue: 1, refusee: 1, sans_suite: 0 })
    expect(compteDe(comptes, STATUTS_EN_ATTENTE)).toBe(4)
    expect(compteDe(comptes, TOUS_LES_STATUTS)).toBe(6)
  })

  it('se lit dans l’adresse : absent = ce qui attend, « toutes », une liste, ou rien', () => {
    expect(lireStatuts(undefined)).toEqual(['envoyee', 'relancee', 'expediee'])
    expect(lireStatuts('toutes')).toEqual([...TOUS_LES_STATUTS])
    expect(lireStatuts('refusee,recue')).toEqual(['recue', 'refusee'])
    expect(lireStatuts(['sans_suite'])).toEqual(['sans_suite'])
    expect(lireStatuts('')).toEqual([])
    expect(lireStatuts('recue,inconnu')).toEqual(['recue'])
    expect(lireStatuts('n_importe_quoi')).toEqual(['envoyee', 'relancee', 'expediee'])
  })

  it('s’écrit dans l’adresse, nue pour le défaut, et se relit à l’identique', () => {
    expect(ecrireStatuts(['expediee', 'envoyee', 'relancee'])).toBeUndefined()
    expect(ecrireStatuts([...TOUS_LES_STATUTS])).toBe('toutes')
    expect(ecrireStatuts(['refusee', 'recue'])).toBe('recue,refusee')
    expect(ecrireStatuts([])).toBe('')
    const selections: StatutDeDemarche[][] = [['expediee'], ['recue', 'sans_suite'], [], [...TOUS_LES_STATUTS]]
    for (const selection of selections) expect(lireStatuts(ecrireStatuts(selection))).toEqual(selection)
  })

  it('coche et décoche un statut, dans l’ordre de la vie d’une démarche', () => {
    expect(basculerStatut(['envoyee', 'relancee', 'expediee'], 'relancee')).toEqual(['envoyee', 'expediee'])
    expect(basculerStatut(['refusee'], 'envoyee')).toEqual(['envoyee', 'refusee'])
  })

  it('montre chaque statut avec son compteur, et dit lesquels sont cochés', async () => {
    const html = await rendreComposant(FiltreStatuts, {
      selection: ['envoyee', 'relancee', 'expediee'],
      comptes: compterParStatut(liste),
    })
    expect(boutons(html)).toEqual([
      { libelle: 'En attente (4)', presse: 'true' },
      { libelle: 'Toutes (6)', presse: 'false' },
      { libelle: 'Envoyée (2)', presse: 'true' },
      { libelle: 'Relancée (1)', presse: 'true' },
      { libelle: 'Expédiée par courrier (1)', presse: 'true' },
      { libelle: 'Reçue (1)', presse: 'false' },
      { libelle: 'Refusée (1)', presse: 'false' },
      { libelle: 'Abandonnée (0)', presse: 'false' },
    ])
  })

  it('« Toutes » est pressé quand tout est coché, et les compteurs attendent la liste', async () => {
    const rendus = boutons(await rendreComposant(FiltreStatuts, { selection: [...TOUS_LES_STATUTS], comptes: null }))
    expect(rendus[0]).toEqual({ libelle: 'En attente', presse: 'false' })
    expect(rendus[1]).toEqual({ libelle: 'Toutes', presse: 'true' })
    expect(rendus.slice(2).every((b) => b.presse === 'true')).toBe(true)
  })
})

describe('refaire autrement', () => {
  const refusee = resume({
    id: 2,
    statut: 'refusee',
    statutLibelle: 'Refusée',
    enAttente: false,
    attendUneReponse: false,
    joursDAttente: null,
    resultat: 'La mairie ne traite pas les demandes par courriel.',
    personnes: [
      { personId: 4, libelle: 'Marne Dolomite Sureau' },
      { personId: 9, libelle: 'Basalte Sarriette' },
    ],
  })

  it('se propose sur une démarche refusée ou abandonnée, et seulement là', async () => {
    expect(peutEtreRefaite('refusee')).toBe(true)
    expect(peutEtreRefaite('sans_suite')).toBe(true)
    expect(peutEtreRefaite('expediee')).toBe(false)
    expect(await rendre(refusee)).toContain('Refaire autrement')
    expect(await rendre(resume({ statut: 'sans_suite', statutLibelle: 'Abandonnée', enAttente: false }))).toContain(
      'Refaire autrement',
    )
    expect(await rendre(resume())).not.toContain('Refaire autrement')
  })

  it('reprend l’acte, les personnes et le service de la démarche refaite', () => {
    expect(descriptionPourRefaire(refusee)).toEqual({
      nature: 'mariage',
      forme: 'copie_integrale',
      intitule: 'Mariage SUREAU × SARRIETTE, Charmille, 1925',
      commune: 'Charmille',
      dateDeLActe: 'vers 1925',
      destinataire: 'Mairie de Charmille',
      personIds: [4, 9],
    })
  })

  it('ouvre le formulaire prérempli, avec le lien « suite de »', async () => {
    const html = await rendreComposant(FormulaireDemarche, { modele: refusee })
    expect(html).toContain('Suite de la démarche n° 2 (refusée, demandée à Mairie de Charmille)')
    expect(html).toMatch(/<option value="mariage"[^>]*selected/)
    expect(html).toMatch(/<option value="copie_integrale"[^>]*selected/)
    expect(html).toContain('value="Mariage SUREAU × SARRIETTE, Charmille, 1925"')
    expect(html).toContain('value="Charmille"')
    expect(html).toContain('value="vers 1925"')
    expect(html).toContain('value="Mairie de Charmille"')
    expect(html).toContain('Marne Dolomite Sureau')
    expect(html).toContain('Basalte Sarriette')
  })

  it('un formulaire de création ordinaire ne dit rien d’une suite', async () => {
    expect(await rendreComposant(FormulaireDemarche, {})).not.toContain('Suite de la démarche')
  })
})

describe('les liens entre démarches', () => {
  it('« Suite de la démarche n° X » mène à la carte X', async () => {
    const html = await rendre(resume({ id: 7, precedente: { id: 2, statut: 'refusee', demandeeA: 'Mairie de Charmille' } }))
    expect(html).toMatch(/Suite de la\s*<a href="#demarche-2"[^>]*>démarche n° 2<\/a>/)
    expect(html).toContain('(refusée, demandée à Mairie de Charmille)')
    expect(html).toContain('n° 7')
  })

  it('« Refaite : démarche n° Y » sur celle qui a été reprise', async () => {
    const html = await rendre(
      resume({ id: 2, statut: 'refusee', statutLibelle: 'Refusée', enAttente: false, suites: [{ id: 7, statut: 'envoyee' }] }),
    )
    expect(html).toMatch(/Refaite :\s*(<!--[^>]*-->\s*)*<a href="#demarche-7"[^>]*>démarche n° 7<\/a>/)
    expect(html).toContain('(envoyée)')
  })

  it('rien quand la démarche n’a pas de lien', async () => {
    const html = await rendre(resume())
    expect(html).not.toContain('Suite de la')
    expect(html).not.toContain('Refaite')
  })
})

describe('ce qui part au serveur', () => {
  it('rend `null` les champs facultatifs laissés vides, sans toucher au texte', () => {
    const message = { ...messageVide(), de: '  ', a: 'mairie@charmille.invalid ', texte: '  Bonjour,\n\nMerci.  ' }
    const envoye = messageAEnvoyer(message)
    expect(envoye.de).toBeNull()
    expect(envoye.a).toBe('mairie@charmille.invalid')
    expect(envoye.objet).toBeNull()
    expect(envoye.texte).toBe('  Bonjour,\n\nMerci.  ')
  })

  it('nettoie la description', () => {
    const d = descriptionAEnvoyer({
      nature: 'deces',
      forme: 'copie_integrale',
      intitule: ' Décès ',
      commune: '',
      dateDeLActe: ' 1987 ',
      destinataire: ' Mairie ',
      personIds: [4],
    })
    expect(d).toMatchObject({ intitule: 'Décès', commune: null, dateDeLActe: '1987', destinataire: 'Mairie' })
  })

  it('rend au formulaire du premier message ses erreurs sans préfixe', () => {
    expect(erreursDuMessage({ intitule: 'x', 'premierMessage.date': 'y', 'premierMessage.texte': 'z' })).toEqual({
      date: 'y',
      texte: 'z',
    })
  })
})

describe('les dates', () => {
  it('se lisent en français, sans glisser d’un jour selon le fuseau', () => {
    expect(dateLisible('2026-09-30')).toBe('30 septembre 2026')
    expect(dateLisible('1er mars')).toBe('1er mars')
  })

  it('« aujourd’hui » est le jour du lecteur, pas celui de Greenwich', () => {
    expect(aujourdhuiIso(new Date(2026, 8, 30, 0, 30))).toBe('2026-09-30')
  })
})

describe('retrouver une démarche quand la réponse arrive', () => {
  it('par un nom, sans se soucier des accents ni de la casse', () => {
    expect(correspondARecherche(resume(), 'SUREAU')).toBe(true)
    expect(correspondARecherche(resume(), 'marne dolomite')).toBe(true)
    expect(correspondARecherche(resume(), 'Sûreau')).toBe(true)
  })

  it('par l’adresse de la mairie qui répond', () => {
    expect(correspondARecherche(resume(), 'etat-civil@charmille')).toBe(true)
  })

  it('chaque mot doit se trouver, dans n’importe quel ordre', () => {
    expect(correspondARecherche(resume(), 'charmille mariage')).toBe(true)
    expect(correspondARecherche(resume(), 'charmille deces')).toBe(false)
  })

  it('une recherche vide garde tout', () => {
    expect(correspondARecherche(resume(), '   ')).toBe(true)
  })
})
