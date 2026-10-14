/**
 * La barre de recherche du bas des arbres, sur téléphone (`ChampRechercheArbre`),
 * montée en mémoire (`test-utils/rendu-memoire.ts`).
 *
 * Le composant ne cherche rien : il dit l'état qu'on lui passe. Ce que ces cas
 * protègent : le motif « combobox » (la liste existe toujours, l'option du
 * curseur est désignée) et **une panne qui ne se dit jamais « Personne ne
 * correspond. »**, avec son « Réessayer ».
 *
 * Noms inventés.
 */
import { h } from 'vue'
import { afterEach, describe, expect, it } from 'vitest'
import type { ResultatDeRecherche } from '@/lib/api'
import type { EtatRecherche } from '@/composables/useRecherchePersonnes'
import { boutonNomme, cliquer, demonterTout, monterEnMemoire, texte, tous, un, type Noeud } from '@/test-utils/rendu-memoire'
import ChampRechercheArbre from './ChampRechercheArbre.vue'

function resultat(personId: number, displayName: string): ResultatDeRecherche {
  return { personId, displayName, sex: 'feminin', generation: 2, branch: 'tr', vivant: false, visibilite: 'complete' }
}

const deux = [resultat(1, 'Opale Sureau'), resultat(2, 'Marne Sureau')]

async function rendre(
  etat: EtatRecherche,
  autres: { resultats?: ResultatDeRecherche[]; curseur?: number; message?: string; onReessayer?: () => void } = {},
): Promise<{ racine: Noeud; champ: Noeud }> {
  const racine = await monterEnMemoire({
    render: () =>
      h(ChampRechercheArbre, {
        texte: 'su',
        etat,
        resultats: autres.resultats ?? [],
        tronque: false,
        message: autres.message ?? '',
        curseur: autres.curseur ?? 0,
        ouverte: true,
        ...(autres.onReessayer ? { onReessayer: autres.onReessayer } : {}),
      }),
  })
  const champ = un(racine, (n) => n.tag === 'input')
  if (champ === null) throw new Error('Champ absent.')
  return { racine, champ }
}

afterEach(demonterTout)

describe('ChampRechercheArbre', () => {
  it('désigne l’option du curseur, liste dépliée', async () => {
    const { racine, champ } = await rendre('resultats', { resultats: deux, curseur: 1 })

    expect(champ.attributs['role']).toBe('combobox')
    expect(champ.attributs['aria-autocomplete']).toBe('list')
    expect(champ.attributs['aria-expanded']).toBe(true)
    expect(champ.attributs['aria-controls']).toBe('recherche-arbre-liste')
    expect(champ.attributs['aria-activedescendant']).toBe('resultat-histoire-1')
    const options = tous(racine, (n) => n.attributs['role'] === 'option')
    expect(options.map((o) => o.attributs['aria-selected'])).toEqual([false, true])
    expect(texte(racine)).toContain('Tronc')
  })

  it('garde la liste dans le document, repliée, sans rien à chercher', async () => {
    const { racine, champ } = await rendre('inactif')

    expect(champ.attributs['aria-expanded']).toBe(false)
    expect(champ.attributs['aria-activedescendant']).toBeUndefined()
    expect(un(racine, (n) => n.attributs['id'] === 'recherche-arbre-liste')?.attributs['role']).toBe('listbox')
  })

  it('dit la panne et offre « Réessayer », jamais « Personne ne correspond. »', async () => {
    let relances = 0
    const { racine } = await rendre('erreur', {
      message: "Le serveur n'a pas répondu. Vérifiez votre connexion.",
      onReessayer: () => relances++,
    })

    expect(texte(racine)).toContain("Le serveur n'a pas répondu.")
    expect(texte(racine)).not.toContain('Personne ne correspond.')
    await cliquer(un(racine, boutonNomme('Réessayer')))
    expect(relances).toBe(1)
  })

  it('dit « Recherche… » en attendant, « Personne ne correspond. » après la réponse', async () => {
    expect(texte((await rendre('chargement')).racine)).toContain('Recherche…')
    expect(texte((await rendre('vide')).racine)).toContain('Personne ne correspond.')
  })
})
