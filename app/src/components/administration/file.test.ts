import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import type { Demande } from '@/lib/api'
import type { NatureDemande } from '@/lib/api'
import { LIBELLES_NATURE } from '@/components/proposition/mes-demandes'
import {
  CONTEXTE_PAR_DEFAUT,
  NATURES_DE_L_ONGLET,
  autresSurLaMemeFiche,
  contexteDepuisRequete,
  demandeSuivante,
  ligneAuteur,
  natureDeLOnglet,
  nouvellesPersonnes,
  ordreDeLaFile,
  personneDeLaDemande,
  requeteDeContexte,
  trierDemandes,
} from './file'

/**
 * Ce que ces tests protègent : **la file à plat, par numéro** (2026-10-04), ses
 * onglets par nature, et ce que l'ancien regroupement par fiche protégeait — deux
 * demandes sur la même fiche s'influencent, et chaque ligne le dit.
 */

function demande(champs: Partial<Demande> & { id: number }): Demande {
  return {
    kind: 'modification_personne',
    status: 'en_attente',
    authorLabel: 'Opale Romarin',
    targetKind: 'personne',
    targetId: 17,
    targetLabel: 'Marne Dolomite Sureau',
    message: null,
    decisionMessage: null,
    autoValidated: false,
    duplicateOfId: null,
    submittedAt: '2026-09-14T11:02:00+02:00',
    createdAt: '2026-09-14T10:58:00+02:00',
    ops: [],
    brouillons: [],
    liens: [],
    revisions: [],
    ...champs,
  }
}

describe('les onglets', () => {
  it('rangent chaque nature dans un onglet, et une seule fois', () => {
    const rangees = Object.values(NATURES_DE_L_ONGLET).flatMap((n) => n ?? [])
    expect([...rangees].sort()).toEqual((Object.keys(LIBELLES_NATURE) as NatureDemande[]).sort())
  })

  it('donnent au serveur une liste séparée par des virgules, et rien pour « Toutes »', () => {
    expect(natureDeLOnglet('toutes')).toBeUndefined()
    expect(natureDeLOnglet('personnes')).toBe('creation_personne')
    expect(natureDeLOnglet('liens')).toBe('ajout_lien,retrait_lien')
  })
})

describe('les autres demandes sur la même fiche', () => {
  it('se comptent pour chaque demande en attente, sans se compter elle-même', () => {
    const autres = autresSurLaMemeFiche([
      demande({ id: 1 }),
      demande({ id: 2, targetId: 55 }),
      demande({ id: 3 }),
      demande({ id: 4, status: 'validee' }),
    ])

    expect(autres.get(1)).toBe(1)
    expect(autres.get(3)).toBe(1)
    expect(autres.has(2)).toBe(false)
    expect(autres.has(4)).toBe(false)
  })

  it('ne confondent pas une personne et une famille du même numéro, ni les créations entre elles', () => {
    const autres = autresSurLaMemeFiche([
      demande({ id: 1, targetKind: 'personne', targetId: 17 }),
      demande({ id: 2, targetKind: 'famille', targetId: 17 }),
      demande({ id: 3, kind: 'creation_personne', targetKind: null, targetId: null }),
      demande({ id: 4, kind: 'creation_personne', targetKind: null, targetId: null }),
    ])

    expect(autres.size).toBe(0)
  })
})

describe('les personnes ajoutées', () => {
  it('se lisent par leur nom, séparées par des virgules', () => {
    expect(nouvellesPersonnes(demande({ id: 1, nouvellesPersonnes: ['Jeanne Dupont', 'Paul Dupont'] }))).toBe(
      'Jeanne Dupont, Paul Dupont',
    )
    expect(nouvellesPersonnes(demande({ id: 2 }))).toBe('')
  })
})

describe('le tri, par numéro', () => {
  it('met le plus petit numéro d’abord par défaut', () => {
    expect(trierDemandes([demande({ id: 12 }), demande({ id: 3 }), demande({ id: 7 })], 'anciennes').map((d) => d.id)).toEqual([
      3, 7, 12,
    ])
  })

  it('sait inverser quand on le lui demande', () => {
    expect(trierDemandes([demande({ id: 3 }), demande({ id: 12 })], 'recentes').map((d) => d.id)).toEqual([12, 3])
  })

  it('ne regroupe rien : deux demandes sur la même fiche restent à leur numéro', () => {
    const file = [demande({ id: 1, targetId: 17 }), demande({ id: 2, targetId: 18 }), demande({ id: 3, targetId: 17 })]
    expect(ordreDeLaFile(file, 'anciennes').map((d) => d.id)).toEqual([1, 2, 3])
  })

  it('ne modifie pas le tableau qu’on lui donne', () => {
    // Il vient d'une `ref` que le composant relit : le trier sur place ferait
    // dépendre l'affichage de l'ordre de réveil des `computed`.
    const entree = [demande({ id: 2 }), demande({ id: 1 })]
    trierDemandes(entree, 'anciennes')

    expect(entree.map((d) => d.id)).toEqual([2, 1])
  })
})

/**
 * **L'aperçu de fiche, et les trois cas où il ne s'ouvre pas.**
 *
 * La file dit « Modification — Jean Voulgre ». Pour juger la correction
 * proposée, il fallait ouvrir la fiche dans un autre écran, donc perdre le
 * filtre, le tri et sa place dans la file. Un bouton ouvre désormais l'aperçu
 * sur place.
 *
 * Le piège est dans la condition. Une cible existe aussi pour une **famille**,
 * une **citation** ou une **source** — et `GET /api/arbre/personnes/{id}` y
 * répondrait `404`, ou pire, rendrait la fiche d'un homonyme numérique : la
 * famille n° 17 n'est pas la personne n° 17. Un bouton qui ouvre une panne
 * apparente vaut moins qu'un bouton absent.
 */
describe('l’aperçu de la fiche visée', () => {
  it('ouvre la personne que la demande vise', () => {
    expect(personneDeLaDemande(demande({ id: 1 }))).toBe(17)
  })

  it('ne s’ouvre pas sur une création : la personne n’existe pas encore', () => {
    /* Et son contenu proposé est déjà sous les yeux, dans le diff. */
    expect(personneDeLaDemande(demande({ id: 1, kind: 'creation_personne', targetKind: null, targetId: null }))).toBeNull()
  })

  it('ne confond pas une famille avec la personne du même numéro', () => {
    expect(personneDeLaDemande(demande({ id: 1, kind: 'modification_famille', targetKind: 'famille', targetId: 17 }))).toBeNull()
  })

  it('ne confond pas non plus une source avec une personne', () => {
    expect(personneDeLaDemande(demande({ id: 1, kind: 'modification_citation', targetKind: 'citation', targetId: 17 }))).toBeNull()
  })

  it('rend un identifiant, jamais un booléen : « ouvert sur personne » n’existe pas', () => {
    /* La vue tient `apercu: number | null`. Un drapeau séparé rendrait
       représentable l'état « ouverte, mais sur qui ? ». */
    const ouvert = personneDeLaDemande(demande({ id: 1, targetId: 55 }))

    expect(typeof ouvert).toBe('number')
    expect(ouvert).toBe(55)
  })
})

/**
 * **La virgule se colle au mot qui la précède.**
 *
 * Chaque ligne de la file affichait « par quelqu'un **,** le 16 septembre 2026 » :
 * le gabarit ouvrait un `<template v-if>` sur la ligne suivant le nom, et la
 * condensation des blancs de Vue réduisait ce retour à la ligne à une espace,
 * juste avant la virgule. Le défaut était sur **toutes** les lignes, ce qui est
 * la raison de le corriger : une faute de typographie répétée se lit comme une
 * négligence de l'application entière.
 */
describe('la mention d’auteur d’une entrée de file', () => {
  it('colle la virgule au nom, sans espace avant', () => {
    const ligne = ligneAuteur('opale@heritage.test', '16 septembre 2026')

    expect(ligne).toBe('par opale@heritage.test, le 16 septembre 2026')
    // La faute exacte qui était à l'écran, nommée pour qu'elle ne revienne pas.
    expect(ligne).not.toContain(' ,')
  })

  it('ne laisse pas de virgule orpheline sur une demande jamais soumise', () => {
    // Un brouillon n'a pas de `submittedAt` : la mention s'arrête au nom.
    const ligne = ligneAuteur('opale@heritage.test', '')

    expect(ligne).toBe('par opale@heritage.test')
    expect(ligne).not.toContain(',')
  })

  it('n’introduit aucune espace double, quel que soit le bout qui manque', () => {
    for (const date of ['16 septembre 2026', '']) {
      expect(ligneAuteur('opale@heritage.test', date)).not.toContain('  ')
    }
  })
})

/**
 * Le gabarit doit **réellement** passer par `ligneAuteur`.
 *
 * Tester la fonction seule prouverait une chaîne que personne n'affiche. Une
 * interpolation unique est ce qui rend la fonction suffisante : la condensation
 * des blancs de Vue agit **entre** les nœuds d'un gabarit, jamais à l'intérieur
 * d'un `{{ }}`. Ce test vérifie donc les deux moitiés de cette garantie — la vue
 * appelle la fonction, et elle ne rassemble plus la mention à la main.
 *
 * Même procédé que le balayage de `min`/`max` dans `diff.test.ts` : on lit la
 * source, parce que la faute était dans la mise en page du gabarit et que rien
 * d'autre ne la voit.
 */
describe('le gabarit de la file compose la mention par la fonction', () => {
  const VUE = '../../views/administration/FileDemandesView.vue'

  const gabarit = (): string =>
    readFileSync(fileURLToPath(new URL(VUE, import.meta.url)), 'utf8')
      .replace(/<!--[\s\S]*?-->/g, ' ')
      .replace(/\/\*[\s\S]*?\*\//g, ' ')

  it('appelle ligneAuteur', () => {
    expect(gabarit()).toContain('ligneAuteur(demande.authorLabel, dateLisible(demande.submittedAt))')
  })

  it('ne recompose plus « par … » suivi d’une virgule détachée', () => {
    // Le motif exact d'avant : `par {{ … }}` puis, plus loin, une virgule qui
    // ouvre une ligne. C'est le retour à la ligne entre les deux qui devenait
    // l'espace parasite.
    expect(gabarit()).not.toMatch(/par \{\{ demande\.authorLabel \}\}/)
    expect(gabarit()).not.toMatch(/^\s*,\s*le /m)
  })
})

/**
 * Le mode administration (handoff « mode Administration », 1c) : les six
 * onglets d'`OngletsAdministration` ont disparu, la navigation de
 * l'application en tient lieu. Les noms de route restent un contrat — liens
 * profonds, favoris et notifications en dépendent.
 */
describe('la navigation de l’administration', () => {
  const lire = (chemin: string): string =>
    readFileSync(fileURLToPath(new URL(chemin, import.meta.url)), 'utf8')

  it('nomme les quatre écrans du mode admin, sans construire d’URL', () => {
    const source = lire('../../composables/modeAdmin.ts')

    for (const nom of [
      'administration.a-traiter',
      'administration.utilisateurs',
      'administration.invitations',
      'administration.sources',
    ]) {
      expect(source).toContain(`'${nom}'`)
    }
    expect(source).not.toContain("'/administration")
  })

  it('n’a plus d’onglets dans les écrans ni dans le cadre', () => {
    for (const vue of ['FileDemandesView', 'InvitationsView', 'UtilisateursView', 'SourcesView', 'ATraiterView']) {
      expect(lire(`../../views/administration/${vue}.vue`), vue).not.toContain('OngletsAdministration')
    }
    expect(lire('../../views/AdministrationView.vue')).not.toContain('<nav')
  })

  it('met l’état, l’auteur et le tri de l’historique derrière un seul bouton et son feuillet', () => {
    const source = lire('../../views/administration/FileDemandesView.vue')

    expect(source).toContain('data-ouvrir-filtres')
    expect(source).toContain('<Feuillet :ouvert="filtresOuverts"')
    expect(source).toContain('@click="auteur = a.userId"')
    expect(source).toContain('@choisir="tri = $event"')
  })
})

/* ------------------------------------------------------------------ */
/* « Valider & suivant » — la demande suivante, dans l'ordre affiché    */
/* ------------------------------------------------------------------ */

describe('le contexte de la file voyage dans l’adresse', () => {
  it('le défaut donne une adresse nue — celle des notifications', () => {
    expect(requeteDeContexte(CONTEXTE_PAR_DEFAUT)).toEqual({})
    expect(contexteDepuisRequete({})).toEqual(CONTEXTE_PAR_DEFAUT)
  })

  it('fait l’aller-retour, « toutes » comprise', () => {
    const contexte = { etat: '' as const, auteur: 7, tri: 'recentes' as const, onglet: 'personnes' as const, recherche: '42' }
    const requete = requeteDeContexte(contexte)

    expect(requete).toEqual({ etat: 'toutes', auteur: '7', tri: 'recentes', onglet: 'personnes', q: '42' })
    expect(contexteDepuisRequete(requete)).toEqual(contexte)
  })

  it('ramène une adresse bricolée au défaut plutôt qu’à une file vide', () => {
    expect(contexteDepuisRequete({ etat: 'n_importe', auteur: '-3', tri: 'hasard', onglet: 'nulle_part' })).toEqual(CONTEXTE_PAR_DEFAUT)
    expect(contexteDepuisRequete({ etat: ['refusee', 'validee'] }).etat).toBe('refusee')
  })
})

describe('la demande suivante', () => {
  // Trois fiches : 17 (deux demandes), 18, et une création sans cible.
  const A = demande({ id: 1, targetId: 17, submittedAt: '2026-09-10T10:00:00+02:00' })
  const B = demande({ id: 2, targetId: 18, submittedAt: '2026-09-11T10:00:00+02:00' })
  const C = demande({ id: 3, targetId: 17, submittedAt: '2026-09-12T10:00:00+02:00' })
  const D = demande({ id: 4, targetKind: null, targetId: null, submittedAt: '2026-09-13T10:00:00+02:00' })

  it('suit l’ordre affiché : par numéro, à plat', () => {
    // À l'écran : A, B, C, D. Après A vient B.
    expect(ordreDeLaFile([C, A, D, B], 'anciennes').map((d) => d.id)).toEqual([1, 2, 3, 4])
    expect(demandeSuivante([B, C, D], A, 'anciennes')).toBe(2)
  })

  it('replace la courante, que le filtre « en attente » a retirée de la file relue', () => {
    expect(demandeSuivante([A, B, D], C, 'anciennes')).toBe(4)
  })

  it('respecte le tri choisi', () => {
    // Numéro décroissant : D, C, B, A. Après D vient C.
    expect(demandeSuivante([A, B, C], D, 'recentes')).toBe(3)
  })

  it('saute ce qui n’attend plus de décision — caduques et validées sous « toutes »', () => {
    const cCaduque = { ...C, status: 'caduque' as const }
    const bValidee = { ...B, status: 'validee' as const }
    expect(demandeSuivante([{ ...A, status: 'validee' }, cCaduque, bValidee, D], A, 'anciennes')).toBe(4)
  })

  it('repart du début quand il n’y a plus rien après la courante', () => {
    expect(demandeSuivante([A, B, C], D, 'anciennes')).toBe(1)
  })

  it('rend null quand la file n’a plus rien à décider', () => {
    expect(demandeSuivante([], A, 'anciennes')).toBeNull()
    expect(demandeSuivante([{ ...A, status: 'validee' }], A, 'anciennes')).toBeNull()
  })
})

describe('la file transmet son contexte à la demande ouverte', () => {
  const lire = (chemin: string): string =>
    readFileSync(fileURLToPath(new URL(chemin, import.meta.url)), 'utf8')

  it('le lien de chaque demande porte le filtre et le tri', () => {
    expect(lire('../../views/administration/FileDemandesView.vue')).toContain(
      ":to=\"{ name: 'demande.detail', params: { id: demande.id }, query: requeteDeLaFile }\"",
    )
  })

  it('le détail enchaîne avec le même filtre, et remet un composant neuf à chaque demande', () => {
    const vue = lire('../../views/DemandeView.vue')
    expect(vue).toContain('demandeSuivante(file.elements, courante, tri)')
    // En fin de file : « À traiter » si l'on venait de la file par défaut.
    expect(vue).toContain("name: 'administration.a-traiter', query: { type: 'demande', ...trace }")
    expect(vue).toContain(':key="demande.id"')
    expect(vue).toContain('@decidee="apresDecision"')
  })
})
