import { createSSRApp, h } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { describe, expect, it } from 'vitest'
import type { BrouillonDemande, LienDemande } from '@/lib/api'
import FicheProposee from './FicheProposee.vue'

/**
 * ═══════════════════════════════════════════════════════════════════════════
 *  CE QUE L'ÉCRAN DE VALIDATION IMPRIME VRAIMENT.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * `liens.test.ts` vérifie ce que la logique **décide** ; ce fichier vérifie que
 * le gabarit l'**imprime**. Les deux se cassent séparément, et c'est le second
 * qui a coûté : `FicheProposee.vue` portait depuis toujours un
 * `v-if="brouillon.note"` et un `v-if="lien.note"` — sur des clés que le serveur
 * n'envoyait pas. Le gabarit avait raison, la charge était muette, et **aucun
 * test ne regardait la jonction des deux**.
 *
 * Le cas éprouvé ici est celui qui a été mesuré : une branche de plusieurs
 * fiches, dont les liens se rapportent les uns aux autres. Une demande à une
 * seule personne ne révèle aucun de ces défauts — c'est pour cela qu'ils ont
 * tenu.
 *
 * Rendu en SSR, comme `personne/fiche-rendu.test.ts` : pas de DOM, pas de
 * dépendance de test supplémentaire.
 */

/**
 * Le balisage rendu, **commentaires retirés**. Ce fichier commente abondamment
 * ses gabarits ; laisser les commentaires dans la chaîne ferait échouer une
 * assertion de voisinage pour une raison qui n'a rien à voir avec l'écran.
 */
async function rendre(brouillons: BrouillonDemande[], liens: LienDemande[]): Promise<string> {
  const app = createSSRApp({ render: () => h(FicheProposee, { brouillons, liens }) })
  return (await renderToString(app)).replace(/<!--[\s\S]*?-->/g, '')
}

function fiche(localRef: string, prenom: string, note: string | null): BrouillonDemande {
  return {
    localRef,
    noeudId: `#12/${localRef.slice(1)}`,
    givenNames: prenom,
    surname: 'Chanterelle',
    sex: 'inconnu',
    deceased: true,
    note,
    resolvedPersonId: null,
  }
}

const BROUILLONS: BrouillonDemande[] = [
  fiche('#p1', 'Girolle', 'Épouse en secondes noces, d’après le livret.'),
  fiche('#p2', 'Morille', 'Homonymie avec son père : c’est bien le fils.'),
  fiche('#p3', 'Cèpe', null),
]

const LIENS: LienDemande[] = [
  {
    seq: 1,
    kind: 'conjoint_de',
    subjectDraftRef: '#p1',
    subjectLabel: 'Girolle Chanterelle (proposée)',
    otherPersonId: 55,
    otherLabel: 'Marne Dolomite Sureau',
    note: 'Union relevée sur le registre paroissial.',
  },
  {
    seq: 2,
    kind: 'enfant_de',
    subjectDraftRef: '#p2',
    subjectLabel: 'Morille Chanterelle (proposée)',
    otherDraftRef: '#p1',
    otherLabel: 'Girolle Chanterelle (proposée)',
    other2PersonId: 55,
    other2Label: 'Marne Dolomite Sureau',
  },
  {
    seq: 3,
    kind: 'enfant_de',
    subjectDraftRef: '#p3',
    subjectLabel: 'Cèpe Chanterelle (proposée)',
    familyId: 28,
    familyLabel: 'Mica Chanterelle × Ocre Chanterelle',
  },
]

describe('l’écran de validation d’une branche proposée', () => {
  it('nomme le sujet de chaque lien, et non sa référence provisoire', async () => {
    const html = await rendre(BROUILLONS, LIENS)

    // Le défaut exact : trois liens, tous rendus « Enfant de Personne proposée
    // #p1 », sans que rien ne dise de qui chacun parle.
    expect(html).not.toContain('Personne proposée #')
    expect(html).toContain('Morille Chanterelle (proposée)')
    expect(html).toContain('Cèpe Chanterelle (proposée)')
  })

  it('nomme le second parent d’une filiation', async () => {
    const html = await rendre(BROUILLONS, LIENS)

    // Le lien 2 dit « Morille, enfant de Girolle **et** Marne » : c'est le couple
    // entier, et c'est ce que l'administrateur juge.
    expect(html).toContain('Marne Dolomite Sureau')
    expect(html).toMatch(/Girolle Chanterelle \(proposée\)[\s\S]{0,120}et[\s\S]{0,120}Marne Dolomite Sureau/)
  })

  it('nomme le couple d’ancrage au lieu de son numéro', async () => {
    const html = await rendre(BROUILLONS, LIENS)

    expect(html).toContain('Mica Chanterelle × Ocre Chanterelle')
    expect(html).not.toContain('une personne que cette charge ne nomme pas')
  })

  it('imprime les notes de chaque fiche et de chaque lien', async () => {
    const html = await rendre(BROUILLONS, LIENS)

    expect(html).toContain('Homonymie avec son père')
    expect(html).toContain('Épouse en secondes noces')
    expect(html).toContain('Union relevée sur le registre paroissial.')
  })

  it('rend autant de lignes de lien et de fiches qu’il en reçoit', async () => {
    // L'assertion de taille sur les ensembles parcourus : un gabarit qui perdrait
    // une ligne en silence est exactement le défaut que cette tâche corrige.
    const html = await rendre(BROUILLONS, LIENS)

    expect(html.split('<li').length - 1).toBe(LIENS.length)
    expect(html.split('<article').length - 1).toBe(BROUILLONS.length)
  })

  /**
   * **Les mots ne se collent pas.** Le compilateur de gabarit replie les blancs :
   * un espace posé entre deux balises disparaît, et l'écran de production
   * affichait « Conjoint·e deJargon Sarriette ». L'espace est donc dans la
   * **valeur** de l'interpolation, où rien ne le rogne — une gouttière CSS
   * ferait illusion sans réparer la chaîne, qu'un lecteur d'écran lit et qu'une
   * copie emporte.
   *
   * L'assertion porte sur le **texte** débarrassé de ses balises : c'est ce que
   * l'œil et le lecteur d'écran reçoivent, et le seul endroit où le défaut se
   * voyait.
   */
  it('sépare la nature du lien du nom qui la suit', async () => {
    const texte = (await rendre(BROUILLONS, LIENS)).replace(/<[^>]*>/g, '')

    expect(texte).toContain('Conjoint·e de Marne Dolomite Sureau')
    expect(texte).toContain('Enfant de Girolle Chanterelle (proposée) et Marne Dolomite Sureau')
    expect(texte).not.toMatch(/de[A-ZÀ-Ý]/)
  })

  it('n’invente rien pour une fiche sans note', async () => {
    const html = await rendre([fiche('#p3', 'Cèpe', null)], [])

    // Absent et vide ont deux sens (§ 1.3) : pas de bloc de note, pas de tiret.
    expect(html).not.toContain('border-l-2')
  })

  /**
   * Une demande `ajout_lien` ne porte **aucune fiche** : elle relie deux
   * personnes qui existent. L'écran ne doit pas pour autant devenir muet — c'est
   * ce qu'il faisait, faute de regarder les liens autant que les brouillons.
   */
  it('reste lisible pour une demande qui ne propose aucune fiche', async () => {
    const html = await rendre([], [LIENS[0] as LienDemande])

    expect(html).toContain('Girolle Chanterelle (proposée)')
    expect(html).toContain('Marne Dolomite Sureau')
    expect(html).not.toContain('Aucun lien proposé')
  })
})
