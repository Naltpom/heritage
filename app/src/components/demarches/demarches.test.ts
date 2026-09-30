import { createSSRApp, h } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { createMemoryHistory, createRouter } from 'vue-router'
import { describe, expect, it } from 'vitest'
import type { ResumeDeDemarche } from '@/lib/api'
import CarteDemarche from './CarteDemarche.vue'
import {
  aRelancer,
  aujourdhuiIso,
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
    resultat: null,
    personnes: [{ personId: 4, libelle: 'Marne Dolomite Sureau' }],
    auteur: { libelle: 'Opale', estMoi: true },
    nombreDeMessages: 1,
    dernierEnvoiLe: '2026-09-30',
    dernierMessageLe: '2026-09-30',
    joursDAttente: 0,
    peutSupprimer: true,
    creeeLe: '2026-09-30T12:00:00+02:00',
    modifieeLe: '2026-09-30T12:00:00+02:00',
    ...surcharge,
  }
}

async function rendre(demarche: ResumeDeDemarche): Promise<string> {
  const routeur = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:p(.*)*', name: 'personne.detail', component: { render: () => null } }] })
  const app = createSSRApp({ render: () => h(CarteDemarche, { demarche }) })
  app.use(routeur)
  return renderToString(app)
}

describe('l’attente d’une démarche', () => {
  it('se dit en jours, au singulier comme au pluriel', () => {
    expect(libelleDAttente(null)).toBeNull()
    expect(libelleDAttente(0)).toBe('envoyée aujourd’hui')
    expect(libelleDAttente(1)).toBe('en attente depuis 1 jour')
    expect(libelleDAttente(12)).toBe('en attente depuis 12 jours')
  })

  it('devient « à relancer » au bout d’un mois, et seulement si elle attend encore', () => {
    expect(aRelancer({ enAttente: true, joursDAttente: 29 })).toBe(false)
    expect(aRelancer({ enAttente: true, joursDAttente: 30 })).toBe(true)
    expect(aRelancer({ enAttente: false, joursDAttente: 90 })).toBe(false)
    expect(aRelancer({ enAttente: true, joursDAttente: null })).toBe(false)
  })

  it('s’affiche sur la carte, avec l’avertissement quand il faut relancer', async () => {
    const html = await rendre(resume({ joursDAttente: 41 }))
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
