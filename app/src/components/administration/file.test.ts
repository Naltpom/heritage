import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import type { Demande } from '@/lib/api'
import {
  grouperParCible,
  groupesEnConcurrence,
  ligneAuteur,
  personneDuGroupe,
  trierDemandes,
} from './file'

/**
 * Ce que ces tests protègent : **l'ordre du serveur, et le regroupement par
 * fiche.**
 *
 * Le serveur trie la file par date de soumission croissante — une file se prend
 * par le début. Le front regroupe par cible (§ 6.7, ADR 0004), et c'est tout ce
 * qu'il fait : un tri alphabétique ajouté ici défferait silencieusement celui du
 * serveur, et l'administrateur traiterait les demandes récentes avant les
 * anciennes sans jamais s'en apercevoir.
 *
 * Le regroupement lui-même n'est pas décoratif : deux demandes sur la même fiche
 * s'influencent. Valider la première peut rendre la seconde caduque, et les
 * traiter dans deux écrans séparés fait découvrir la caducité **après** le clic.
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

describe('le regroupement par fiche', () => {
  it('rassemble les demandes qui visent la même personne', () => {
    const groupes = grouperParCible([
      demande({ id: 1 }),
      demande({ id: 2, targetId: 55, targetLabel: 'Jargon Sarriette' }),
      demande({ id: 3 }),
    ])

    expect(groupes).toHaveLength(2)
    expect(groupes[0]?.demandes.map((d) => d.id)).toEqual([1, 3])
    expect(groupes[1]?.demandes.map((d) => d.id)).toEqual([2])
  })

  it('nomme le groupe par le libellé de la cible', () => {
    const groupes = grouperParCible([demande({ id: 1 })])

    expect(groupes[0]?.titre).toBe('Marne Dolomite Sureau')
  })

  it('ne confond pas une personne et une famille de même identifiant', () => {
    // La clé porte la nature autant que l'identifiant : `personne:17` et
    // `famille:17` sont deux fiches sans rapport.
    const groupes = grouperParCible([
      demande({ id: 1, targetKind: 'personne', targetId: 17 }),
      demande({ id: 2, targetKind: 'famille', targetId: 17, targetLabel: 'Une famille' }),
    ])

    expect(groupes).toHaveLength(2)
  })
})

describe('les demandes sans cible', () => {
  it('forment leur propre groupe', () => {
    // Une création de personne n'a pas de cible et ne peut pas en avoir : la
    // personne n'existe pas encore, c'est tout son objet.
    const groupes = grouperParCible([
      demande({ id: 1, kind: 'creation_personne', targetKind: null, targetId: null, targetLabel: null }),
    ])

    expect(groupes).toHaveLength(1)
    expect(groupes[0]?.sansCible).toBe(true)
    expect(groupes[0]?.titre).toBe('Personnes proposées')
  })

  it('restent à leur place dans l’ordre d’arrivée', () => {
    // Ni remontées, ni reléguées en fin de liste : ce serait réordonner la file
    // sur un critère que personne n'a demandé.
    const groupes = grouperParCible([
      demande({ id: 1, kind: 'creation_personne', targetKind: null, targetId: null }),
      demande({ id: 2 }),
    ])

    expect(groupes.map((groupe) => groupe.sansCible)).toEqual([true, false])
  })
})

describe('l’ordre du serveur', () => {
  it('est préservé, groupe par groupe', () => {
    const groupes = grouperParCible([
      demande({ id: 10, targetId: 55, targetLabel: 'Zir Sarriette' }),
      demande({ id: 11, targetId: 17, targetLabel: 'Agate Sureau' }),
    ])

    // Le premier groupe est celui de la demande la plus ancienne, pas celui dont
    // le nom vient en premier dans l'alphabet.
    expect(groupes.map((groupe) => groupe.titre)).toEqual(['Zir Sarriette', 'Agate Sureau'])
  })

  it('conserve l’ordre des demandes à l’intérieur d’un groupe', () => {
    const groupes = grouperParCible([demande({ id: 5 }), demande({ id: 3 }), demande({ id: 9 })])

    expect(groupes[0]?.demandes.map((d) => d.id)).toEqual([5, 3, 9])
  })

  it('rend une liste vide sur une file vide', () => {
    expect(grouperParCible([])).toEqual([])
  })
})

describe('le tri, quand on le demande', () => {
  const ancienne = demande({ id: 1, submittedAt: '2026-08-01T10:00:00+02:00' })
  const recente = demande({ id: 2, submittedAt: '2026-09-14T10:00:00+02:00' })
  const brouillon = demande({ id: 3, status: 'brouillon', submittedAt: null })

  it('met les plus anciennes d’abord par défaut — l’ordre du serveur', () => {
    expect(trierDemandes([recente, ancienne], 'anciennes').map((d) => d.id)).toEqual([1, 2])
  })

  it('sait inverser quand on le lui demande', () => {
    expect(trierDemandes([ancienne, recente], 'recentes').map((d) => d.id)).toEqual([2, 1])
  })

  it('range les demandes sans date de soumission en fin, dans les deux sens', () => {
    // Un brouillon n'a jamais été soumis. Le traiter comme daté de 1970 le
    // propulserait en tête du tri « anciennes », alors qu'il n'attend aucune
    // décision de personne.
    expect(trierDemandes([brouillon, recente, ancienne], 'anciennes').map((d) => d.id)).toEqual([
      1, 2, 3,
    ])
    expect(trierDemandes([brouillon, ancienne, recente], 'recentes').map((d) => d.id)).toEqual([
      2, 1, 3,
    ])
  })

  it('ne modifie pas le tableau qu’on lui donne', () => {
    // Il vient d'une `ref` que le composant relit : le trier sur place ferait
    // dépendre l'affichage de l'ordre de réveil des `computed`.
    const entree = [recente, ancienne]
    trierDemandes(entree, 'anciennes')

    expect(entree.map((d) => d.id)).toEqual([2, 1])
  })

  it('survit à une date que le serveur aurait mal écrite', () => {
    const cassee = demande({ id: 4, submittedAt: 'pas une date' })

    expect(() => trierDemandes([cassee, ancienne], 'anciennes')).not.toThrow()
    expect(trierDemandes([cassee, ancienne], 'anciennes')).toHaveLength(2)
  })
})

describe('les groupes en concurrence', () => {
  it('sont ceux qui portent plus d’une demande sur la même fiche', () => {
    const groupes = grouperParCible([demande({ id: 1 }), demande({ id: 2 })])

    expect(groupesEnConcurrence(groupes)).toHaveLength(1)
  })

  it('n’incluent pas le groupe des créations', () => {
    // Deux créations ne se gênent pas : elles ne visent aucune fiche commune.
    // Leur rapprochement passe par `doublonsProbables`, qui compare autre chose.
    const groupes = grouperParCible([
      demande({ id: 1, kind: 'creation_personne', targetKind: null, targetId: null }),
      demande({ id: 2, kind: 'creation_personne', targetKind: null, targetId: null }),
    ])

    expect(groupesEnConcurrence(groupes)).toHaveLength(0)
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
  it('ouvre la personne que le groupe vise', () => {
    const groupes = grouperParCible([demande({ id: 1 })])

    expect(personneDuGroupe(groupes[0]!)).toBe(17)
  })

  it('ne s’ouvre pas sur une création : la personne n’existe pas encore', () => {
    /* Et son contenu proposé est déjà sous les yeux, dans le diff. */
    const groupes = grouperParCible([
      demande({ id: 1, kind: 'creation_personne', targetKind: null, targetId: null }),
    ])

    expect(personneDuGroupe(groupes[0]!)).toBeNull()
  })

  it('ne confond pas une famille avec la personne du même numéro', () => {
    const groupes = grouperParCible([
      demande({ id: 1, kind: 'modification_famille', targetKind: 'famille', targetId: 17 }),
    ])

    expect(personneDuGroupe(groupes[0]!)).toBeNull()
  })

  it('ne confond pas non plus une source avec une personne', () => {
    const groupes = grouperParCible([
      demande({ id: 1, kind: 'modification_citation', targetKind: 'citation', targetId: 17 }),
    ])

    expect(personneDuGroupe(groupes[0]!)).toBeNull()
  })

  it('rend un identifiant, jamais un booléen : « ouvert sur personne » n’existe pas', () => {
    /* La vue tient `apercu: number | null`. Un drapeau séparé rendrait
       représentable l'état « ouverte, mais sur qui ? ». */
    const groupes = grouperParCible([demande({ id: 1, targetId: 55 })])
    const ouvert = personneDuGroupe(groupes[0]!)

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
 * La barre d'écran de l'administration (handoff § 2) : les trois sections en
 * onglets, en tête de barre, à la place de la navigation d'`AdministrationView`.
 * Les noms de route sont un contrat — liens profonds et favoris en dépendent —,
 * et les deux menus de la file restent des `<select>` natifs.
 */
describe('les onglets de l’administration', () => {
  const lire = (chemin: string): string =>
    readFileSync(fileURLToPath(new URL(chemin, import.meta.url)), 'utf8')

  it('nomment les trois routes enfants, sans construire d’URL', () => {
    const source = lire('../../views/administration/OngletsAdministration.vue')

    for (const nom of ['administration.demandes', 'administration.invitations', 'administration.utilisateurs']) {
      expect(source).toContain(`'${nom}'`)
    }
    expect(source).not.toContain("'/administration")
  })

  it('sont en tête de la barre des trois sous-écrans, et plus dans le cadre', () => {
    for (const vue of ['FileDemandesView', 'InvitationsView', 'UtilisateursView']) {
      expect(lire(`../../views/administration/${vue}.vue`), vue).toContain('<OngletsAdministration />')
    }
    expect(lire('../../views/AdministrationView.vue')).not.toContain('<nav')
  })

  it('gardent à la file ses deux menus natifs, auteur et tri', () => {
    const source = lire('../../views/administration/FileDemandesView.vue')

    expect(source).toContain('id="filtre-auteur" v-model.number="auteur"')
    expect(source).toContain('id="tri-file" v-model="tri"')
  })
})
