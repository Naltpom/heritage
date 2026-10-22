import { describe, expect, it } from 'vitest'
import type { AreteGraphe, Graphe, NoeudFamille, NoeudPersonne } from '@/lib/api'
import { disposerEnGroupes } from '@/arbre2/groupes'
import { disposerEnRevue } from '@/arbre-review/disposition'
import { auFormat } from './cartes'
import { effectifEnAttente, sansDemandesEnAttente } from './enAttente'
import { noeudsSansLien } from './sansLien'

/**
 * Ce que ces tests protègent : la bascule « En attente de validation » (demande du
 * 22/10/2026). Allumée, le calque est dessiné tel que le serveur l'a servi ; éteinte,
 * il ne reste que les données validées — aucune personne proposée, aucun lien à valider,
 * aucun trait qui pende dans le vide —, et une personne validée n'est jamais retirée.
 */

function arbre(): { graphe: Graphe; id: Record<string, string> } {
  const noeuds: Graphe['noeuds'] = []
  const aretes: AreteGraphe[] = []
  const id: Record<string, string> = {}
  let n = 1
  const personne = (cle: string, proposee: { demande: number; ref: string } | null = null): string => {
    const noeud: NoeudPersonne = {
      id: proposee ? `#${proposee.demande}/${proposee.ref}` : `p${n}`,
      type: 'personne',
      personId: proposee ? null : n,
      ...(proposee ? { enAttente: true as const, demandeId: proposee.demande, brouillonRef: proposee.ref } : {}),
      x: 0,
      y: 0,
      w: 140,
      h: 56,
      displayName: cle,
      etiquetteNom: `${cle} Nom`,
      sex: 'inconnu',
      generation: 0,
      branch: 'tr',
      vivant: false,
      visibilite: 'complete',
    }
    n++
    noeuds.push(noeud)
    id[cle] = noeud.id
    return noeud.id
  }
  const famille = (cle: string, epoux: string[], enfants: string[], provisoire: number | null = null): void => {
    const fam: NoeudFamille = {
      id: provisoire !== null ? `#${provisoire}/f1` : `f${n}`,
      type: 'famille',
      familyId: provisoire !== null ? null : n,
      ...(provisoire !== null ? { enAttente: true as const, demandeId: provisoire } : {}),
      x: 0,
      y: 0,
      w: 10,
      h: 10,
      divorced: false,
    }
    n++
    noeuds.push(fam)
    id[cle] = fam.id
    // Comme le serveur : une arête qui touche un identifiant provisoire est en attente.
    const attente = (a: string, b: string) => (a.startsWith('#') || b.startsWith('#') ? { enAttente: true as const } : {})
    for (const e of epoux) aretes.push({ de: e, vers: fam.id, genre: 'union', ...attente(e, fam.id) })
    for (const e of enfants) aretes.push({ de: fam.id, vers: e, genre: 'filiation', ...attente(fam.id, e) })
  }

  const ego = personne('ego')
  famille('fParents', [personne('pere'), personne('mere')], [ego, personne('soeur')])
  // Demande 12 : une épouse et un fils proposés pour l'ego, dans une famille validée.
  const epouse = personne('epouse', { demande: 12, ref: 'p1' })
  famille('fEgo', [ego], [])
  aretes.push({ de: epouse, vers: id['fEgo']!, genre: 'union', enAttente: true })
  const fils = personne('fils', { demande: 12, ref: 'p2' })
  aretes.push({ de: id['fEgo']!, vers: fils, genre: 'filiation', enAttente: true })
  // Demande 13 (`ajout_lien`) : « elle s'est remariée avec lui », un losange provisoire
  // entre la sœur et un homme validé que rien d'autre ne relie à l'arbre.
  famille('fRemariage', [id['soeur']!, personne('second')], [], 13)

  return {
    graphe: {
      empreinte: '0'.repeat(64),
      ego,
      boite: { x: 0, y: 0, w: 1, h: 1 },
      calque: { actif: true, demandes: [12, 13], proposes: 2, places: 2, nonPlaces: [] },
      noeuds,
      aretes,
    },
    id,
  }
}

describe('sansDemandesEnAttente', () => {
  const { graphe, id } = arbre()
  const valide = sansDemandesEnAttente(graphe)
  const ids = new Set(valide.noeuds.map((n) => n.id))

  it('retire les personnes proposées, les losanges provisoires et les liens à valider', () => {
    for (const cle of ['epouse', 'fils', 'fRemariage']) expect(ids.has(id[cle]!), cle).toBe(false)
    expect(valide.noeuds.some((n) => n.enAttente === true)).toBe(false)
    expect(valide.aretes.some((a) => a.enAttente === true)).toBe(false)
  })

  it('garde toutes les personnes validées, et leurs liens validés', () => {
    for (const cle of ['ego', 'pere', 'mere', 'soeur', 'second', 'fParents', 'fEgo']) expect(ids.has(id[cle]!), cle).toBe(true)
    const validees = graphe.aretes.filter((a) => a.enAttente !== true)
    expect(valide.aretes).toEqual(validees)
  })

  it('ne laisse aucun trait pendre dans le vide', () => {
    for (const a of valide.aretes) {
      expect(ids.has(a.de), `${a.de} → ${a.vers}`).toBe(true)
      expect(ids.has(a.vers), `${a.de} → ${a.vers}`).toBe(true)
    }
  })

  it('une personne validée que seule une demande reliait devient « sans lien connu »', () => {
    expect(noeudsSansLien(graphe).has(id['second']!)).toBe(false)
    expect(noeudsSansLien(valide).has(id['second']!)).toBe(true)
  })

  it('un losange validé que seules des demandes reliaient s’en va avec elles', () => {
    const seul: NoeudFamille = { id: 'f-seul', type: 'famille', familyId: 404, x: 0, y: 0, w: 10, h: 10, divorced: false }
    const avec: Graphe = {
      ...graphe,
      noeuds: [...graphe.noeuds, seul],
      aretes: [...graphe.aretes, { de: id['epouse']!, vers: seul.id, genre: 'union', enAttente: true }],
    }
    expect(sansDemandesEnAttente(avec).noeuds.some((n) => n.id === seul.id)).toBe(false)
  })

  it('dit un calque vide, ses compteurs présents', () => {
    expect(valide.calque).toEqual({ actif: false, demandes: [], proposes: 0, places: 0, nonPlaces: [] })
  })

  it('rend le même objet quand rien n’attend, et le même filtrage pour le même graphe', () => {
    expect(sansDemandesEnAttente(valide)).toBe(valide)
    expect(sansDemandesEnAttente(graphe)).toBe(valide)
  })
})

describe('effectifEnAttente', () => {
  it('compte les personnes proposées et les liens à valider', () => {
    const { graphe } = arbre()
    expect(effectifEnAttente(graphe)).toEqual({ personnes: 2, liens: 4 })
    expect(effectifEnAttente(sansDemandesEnAttente(graphe))).toEqual({ personnes: 0, liens: 0 })
  })
})

describe('les deux moteurs de /arbre, la bascule éteinte', () => {
  it('ne dessinent que les données validées : ni fiche proposée, ni trait en attente', () => {
    const { graphe, id } = arbre()
    const valide = auFormat(sansDemandesEnAttente(graphe), 'compact')
    for (const d of [disposerEnGroupes(valide), disposerEnRevue(valide)]) {
      expect(d.fiches.some((f) => f.noeud.enAttente === true)).toBe(false)
      expect(d.traces.attente).toBe('')
      expect(d.parId.has(id['ego']!)).toBe(true)
    }
    // Allumée, les deux dessinent le calque.
    for (const d of [disposerEnGroupes(auFormat(graphe, 'compact')), disposerEnRevue(auFormat(graphe, 'compact'))]) {
      expect(d.parId.has(id['epouse']!) && d.parId.has(id['fils']!)).toBe(true)
      expect(d.traces.attente).not.toBe('')
    }
  })
})
