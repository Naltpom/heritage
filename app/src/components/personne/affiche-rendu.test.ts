import { createSSRApp, h } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { describe, expect, it } from 'vitest'
import type { FichePersonneComplete } from '@/lib/api'
import AfficheFiche from './AfficheFiche.vue'

/**
 * Le bouton « Générer l'affiche », tel qu'il se dessine — rendu serveur, comme le reste de
 * la suite. Il n'attend aucun droit d'écriture (une affiche ne modifie rien) ; il attend un
 * **couple** : une union dont le conjoint est connu.
 */

function rendre(fiche: FichePersonneComplete): Promise<string> {
  return renderToString(createSSRApp({ render: () => h(AfficheFiche, { fiche }) }))
}

function fiche(unions: FichePersonneComplete['unions']): FichePersonneComplete {
  return {
    visibilite: 'complete',
    id: 17,
    gedcomXref: null,
    displayName: 'Marne Sureau',
    givenNames: 'Marne',
    surname: 'Sureau',
    marriedName: null,
    nickname: null,
    sex: 'feminin',
    deceased: true,
    vivant: false,
    birth: null,
    death: null,
    ligneDates: '—',
    branch: 'pm',
    generation: -3,
    bestQuay: null,
    citationCount: 0,
    forcePrivacy: false,
    privacyNote: null,
    version: 1,
    parente: null,
    portrait: null,
    parents: [],
    fratrie: [],
    unions,
    faits: [],
    citations: [],
    notes: [],
    medias: [],
    enAttente: {},
    demandesEnAttente: [],
  }
}

const union = (conjoint: { personId: number; displayName: string } | null): FichePersonneComplete['unions'][number] => ({
  familyId: 9,
  conjoint,
  marriage: null,
  married: null,
  divorced: false,
  divorce: null,
  version: 1,
  enfants: [],
})

describe('« Générer l’affiche »', () => {
  it('s’offre sur la fiche d’une personne qui a un conjoint connu, sans monter la modale', async () => {
    const html = await rendre(fiche([union({ personId: 52, displayName: 'Cobalt Romarin' })]))
    expect(html).toContain('data-geste="affiche"')
    expect(html).toContain('Générer l’affiche')
    // Rien n'est lu ni dessiné avant le clic : la modale n'existe pas encore.
    expect(html).not.toContain('data-modale-affiche')
  })

  it('ne s’offre pas sans couple : ni union, ni union au conjoint inconnu', async () => {
    expect(await rendre(fiche([]))).not.toContain('data-geste="affiche"')
    expect(await rendre(fiche([union(null)]))).not.toContain('data-geste="affiche"')
  })
})
