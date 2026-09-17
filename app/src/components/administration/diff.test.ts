import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { createSSRApp, h } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { describe, expect, it } from 'vitest'
import type { OpDemande } from '@/lib/api'
import DiffDemande from './DiffDemande.vue'
import {
  champsQuiOntBouge,
  formaterValeur,
  libelleDeChamp,
  ligneDeDiff,
  lignesDeDiff,
  porteLEtatActuel,
} from './diff'

/**
 * Ce que ces tests protègent : **l'administrateur n'est pas un super-membre.**
 *
 * Il traverse le cercle de visibilité comme tout le monde — le sien est
 * simplement le cercle complet. Ce qu'il voit de plus est de l'**état** : les
 * demandes en attente de tous, et, sur le diff, la valeur actuelle du champ.
 * Mais il la voit **parce que le serveur la lui envoie** (§ 6.7), jamais parce
 * que l'écran teste son rôle avant de dessiner.
 *
 * Un `v-if="administrateur"` qui afficherait une colonne absente de la charge
 * utile serait la version front du `if ($estAdmin) { return $tout; }` qu'il a
 * fallu retirer du serveur. Il n'afficherait d'ailleurs rien d'utile : il
 * rendrait `undefined`, ou un « — » qu'on lirait comme « la valeur actuelle est
 * vide ».
 *
 * Seconde règle vérifiée ici : **une fourchette de dates ne s'aplatit jamais.**
 */

const OP_AUTEUR: OpDemande = {
  seq: 1,
  opKind: 'champ',
  subjectKind: 'personne',
  subjectId: 17,
  field: 'surname',
  valueKind: 'texte',
  oldValue: 'Sureau',
  newValue: 'SUREAU',
  baseVersion: 8,
}

/** Le même op, tel que la route d'administration le rend (§ 6.7). */
const OP_ADMINISTRATION: OpDemande = {
  ...OP_AUTEUR,
  valeurCourante: 'Sureau',
  aBouge: false,
  libelleDiff: 'Nom : Sureau → SUREAU',
}

describe('la colonne « valeur actuelle » suit la charge utile', () => {
  it('est absente quand l’op ne la porte pas', () => {
    const ligne = ligneDeDiff(OP_AUTEUR)

    // `null`, et non `''` : l'appelant ne peut pas confondre « la charge ne le
    // dit pas » avec « la valeur actuelle est vide ».
    expect(ligne.courante).toBeNull()
    expect(ligne.aBouge).toBeNull()
  })

  it('est présente quand l’op la porte', () => {
    const ligne = ligneDeDiff(OP_ADMINISTRATION)

    expect(ligne.courante).toBe('Sureau')
    expect(ligne.aBouge).toBe(false)
  })

  it('distingue « n’a pas bougé » de « on n’en sait rien »', () => {
    // `aBouge: false` est une information — le serveur a comparé. L'absence de
    // la clé n'en est pas une, et les confondre afficherait « inchangé » sur une
    // charge qui n'a rien comparé du tout.
    expect(ligneDeDiff(OP_ADMINISTRATION).aBouge).toBe(false)
    expect(ligneDeDiff(OP_AUTEUR).aBouge).toBeNull()
  })

  it('ne dépend de rien d’autre que de l’op', () => {
    // La signature n'a pas de paramètre pour le lecteur, et c'est le fond du
    // sujet : rien ici ne peut dépendre du rôle de celui qui regarde.
    expect(ligneDeDiff.length).toBe(1)
  })

  it('dit au tableau s’il doit dessiner la colonne', () => {
    expect(porteLEtatActuel(lignesDeDiff([OP_AUTEUR]))).toBe(false)
    expect(porteLEtatActuel(lignesDeDiff([OP_ADMINISTRATION]))).toBe(true)
  })

  it('liste les champs qui ont bougé, et eux seuls', () => {
    const bouge: OpDemande = { ...OP_ADMINISTRATION, field: 'birth', aBouge: true }

    expect(champsQuiOntBouge(lignesDeDiff([OP_ADMINISTRATION, bouge]))).toEqual(['birth'])
    // Un op sans `aBouge` n'est pas « n'a pas bougé » : il est absent de la liste
    // parce qu'on n'en sait rien, pas parce qu'on a conclu.
    expect(champsQuiOntBouge(lignesDeDiff([OP_AUTEUR]))).toEqual([])
  })
})

describe('le rendu du diff', () => {
  function rendre(ops: OpDemande[]): Promise<string> {
    return renderToString(createSSRApp({ render: () => h(DiffDemande, { ops }) }))
  }

  it('ne dessine pas la colonne actuelle sur la charge de l’auteur', async () => {
    const html = await rendre([OP_AUTEUR])

    expect(html).toContain('SUREAU')
    expect(html).not.toMatch(/valeur actuelle/i)
    expect(html).not.toContain('undefined')
  })

  it('la dessine sur la charge d’administration', async () => {
    const html = await rendre([OP_ADMINISTRATION])

    expect(html).toMatch(/valeur actuelle/i)
  })

  it('signale le champ qui a bougé depuis le dépôt', async () => {
    const html = await rendre([{ ...OP_ADMINISTRATION, aBouge: true, valeurCourante: 'SUREAU-DUP' }])

    expect(html).toMatch(/depuis le dépôt|a changé/i)
  })

  it('n’affiche jamais « undefined » ni « [object Object] »', async () => {
    const html = await rendre([OP_AUTEUR, OP_ADMINISTRATION])

    expect(html).not.toContain('undefined')
    expect(html).not.toContain('[object Object]')
  })

  it('ne prend pas le rôle du lecteur en entrée', () => {
    const declarees = (DiffDemande as unknown as { props?: Record<string, unknown> }).props
    const entrees = Object.keys(declarees ?? {}).sort()

    // Une entrée `administrateur` serait la porte par laquelle la dérogation
    // reviendrait. Il n'y en a pas.
    expect(entrees).toEqual(['ops'])
  })
})

describe('les dates ne s’aplatissent pas', () => {
  it('affiche le `texte` du serveur quand il existe', () => {
    expect(
      formaterValeur({
        raw: 'BET 1901 AND 1909',
        qualifier: 'entre',
        min: '1901-01-01',
        max: '1909-12-31',
        place: null,
        texte: 'entre 1901 et 1909',
      } as never),
    ).toBe('entre 1901 et 1909')
  })

  it('retombe sur `raw`, qui est encore une chaîne du serveur', () => {
    expect(
      formaterValeur({
        raw: 'BET 1901 AND 1909',
        qualifier: 'entre',
        min: '1901-01-01',
        max: '1909-12-31',
        place: null,
      } as never),
    ).toBe('BET 1901 AND 1909')
  })

  it('ne recompose jamais une date depuis `min` et `max`', () => {
    // « entre 1901 et 1909 » ne se fabrique pas depuis deux bornes sans
    // réimplémenter le formateur du serveur, et en divergeant de lui au premier
    // cas limite. On préfère le dire que l'inventer.
    const rendu = formaterValeur({
      qualifier: 'entre',
      min: '1901-01-01',
      max: '1909-12-31',
      place: null,
    } as never)

    expect(rendu).not.toContain('1901')
    expect(rendu).not.toContain('1909')
  })

  it('le formateur ne lit littéralement pas ces deux clés', () => {
    const source = readFileSync(fileURLToPath(new URL('./diff.ts', import.meta.url)), 'utf8')
      .replace(/\/\*[\s\S]*?\*\//g, ' ')
      .replace(/(^|[^:])\/\/.*$/gm, '$1')

    expect(source).not.toMatch(/\['min'\]|\.min\b/)
    expect(source).not.toMatch(/\['max'\]|\.max\b/)
  })
})

describe('une note et une citation ne sont pas des dates', () => {
  /*
   * ════════════════════════════════════════════════════════════════════════
   * LE DÉFAUT, CONSTATÉ EN PRODUCTION SUR LA DEMANDE 592.
   *
   * Le rendu d'une valeur-objet avait été écrit quand la seule valeur-objet
   * était un bloc date : `texte`, sinon `raw`, sinon « (date non formatée par
   * le serveur) ». Puis `ajout_note` et `ajout_citation` sont arrivés avec deux
   * autres formes d'objet, et personne n'est revenu ici.
   *
   * Conséquence : une note de recherche de quarante lignes, enregistrée sans le
   * moindre problème, s'affichait à l'administrateur comme un défaut de
   * formatage de date. Le message était faux sur trois points — ce n'était pas
   * une date, il n'y avait pas de défaut, et le serveur n'y était pour rien —
   * et il portait sur le seul contenu que l'administrateur devait lire pour
   * décider.
   *
   * Ce que ces cas tiennent : chaque forme à valeur-objet s'affiche, et le
   * repli ne nomme plus jamais « date ».
   * ════════════════════════════════════════════════════════════════════════
   */

  it('affiche le corps d’une note, et non un message d’erreur', () => {
    const rendu = formaterValeur({ body: 'Le troisième volume n’est pas Montignac.', scope: 'generale' } as never)

    expect(rendu).toBe('Le troisième volume n’est pas Montignac.')
  })

  it('affiche le corps entier : une note de recherche ne se tronque pas', () => {
    /* C'est ce que l'administrateur lit pour décider. Le tronquer reviendrait à
       lui demander de valider ce qu'il ne voit pas. */
    const corps = 'A'.repeat(4000)

    expect(formaterValeur({ body: corps, scope: 'generale' } as never)).toHaveLength(4000)
  })

  it('dit la portée d’une note quand ce n’est pas la portée générale', () => {
    expect(formaterValeur({ body: 'Acte retrouvé.', scope: 'naissance' } as never)).toBe(
      'Acte retrouvé. (sur naissance)',
    )
  })

  it('tait la portée générale, qui est le défaut et n’apprend rien', () => {
    expect(formaterValeur({ body: 'Acte retrouvé.', scope: 'generale' } as never)).not.toContain('sur')
  })

  it('affiche une citation par son titre de source, sa page et son extrait', () => {
    const rendu = formaterValeur({
      sourceId: 12,
      sourceTitle: 'Collection communale 1743-1790',
      page: 'vue 140',
      quay: 3,
      dataText: 'sépulture de Pierre Voulgre',
      scope: 'deces',
    } as never)

    expect(rendu).toBe(
      'Collection communale 1743-1790, vue 140 — « sépulture de Pierre Voulgre » (sur décès)',
    )
  })

  it('ne remonte jamais un identifiant de source seul : un numéro ne se relit pas', () => {
    const rendu = formaterValeur({ sourceId: 12, sourceTitle: 'Registre paroissial' } as never)

    expect(rendu).toBe('Registre paroissial')
    expect(rendu).not.toContain('12')
  })

  it('ne dit plus jamais « date » quand il ne sait pas lire une valeur', () => {
    /*
     * LA GARDE. Le repli d'origine nommait une forme — « date » — qu'il n'avait
     * aucun moyen de connaître. Un message de repli ne doit pas affirmer ce
     * qu'il ignore : c'est ce qui a envoyé chercher un défaut de formatage de
     * date là où il n'y avait qu'un type d'op non prévu par cet écran.
     */
    const rendu = formaterValeur({ uneFormeInconnue: true } as never)

    expect(rendu).not.toContain('date')
    expect(rendu).toContain('affich')
  })

  it('laisse un bloc date passer avant tout le reste', () => {
    /* L'ordre compte : un bloc date porte `texte`, et c'est lui qui gagne même
       si une clé d'une autre forme venait à coexister. */
    const rendu = formaterValeur({ texte: 'entre 1901 et 1909', body: 'à ne pas lire' } as never)

    expect(rendu).toBe('entre 1901 et 1909')
  })
})

describe('le nom du champ, pour quelqu’un qui n’est pas technicien', () => {
  it('laisse la main au libellé du serveur quand il existe', () => {
    // Il porte le contexte et les valeurs déjà formatées : le front ne réécrit
    // pas ce que le serveur a rédigé.
    expect(ligneDeDiff(OP_ADMINISTRATION).libelle).toBe('Nom : Sureau → SUREAU')
  })

  it('traduit le nom de champ quand le serveur n’en fournit pas', () => {
    // C'est le cas de la charge de l'auteur (§ 6.5) : sans cette table, quelqu'un
    // qui relit sa propre proposition lirait « surname ».
    expect(ligneDeDiff(OP_AUTEUR).libelle).toBe('Nom de famille')
    expect(ligneDeDiff({ ...OP_AUTEUR, field: 'birth' }).libelle).toBe('Naissance')
    expect(ligneDeDiff({ ...OP_AUTEUR, field: 'givenNames' }).libelle).toBe('Prénoms')
  })

  it('affiche le nom technique plutôt que rien sur un champ inconnu', () => {
    // Mieux vaut un nom technique qu'une ligne manquante : le contrat peut
    // ajouter un champ, et le taire ferait disparaître une modification du diff.
    expect(libelleDeChamp('unChampQueLeContratAjouterait')).toBe('unChampQueLeContratAjouterait')
  })

  it('ne prétend pas traduire les colonnes dérivées', () => {
    // `branch`, `generation` et `displayName` ne sont pas modifiables (§ 6.2) :
    // aucune demande ne peut les viser, et leur inventer un libellé laisserait
    // croire le contraire.
    for (const derivee of ['branch', 'generation', 'displayName', 'version']) {
      expect(libelleDeChamp(derivee)).toBe(derivee)
    }
  })
})

describe('les valeurs ordinaires', () => {
  it('rendent les scalaires tels quels', () => {
    expect(formaterValeur('Sureau')).toBe('Sureau')
    expect(formaterValeur(17)).toBe('17')
  })

  it('traduisent les booléens pour un lecteur non technicien', () => {
    expect(formaterValeur(true)).toBe('oui')
    expect(formaterValeur(false)).toBe('non')
  })

  it('disent « vide » plutôt que rien', () => {
    expect(formaterValeur(null)).toBe('(vide)')
    expect(formaterValeur('')).toBe('(vide)')
    expect(formaterValeur(undefined)).toBe('(vide)')
  })

  it('n’écrit pas « non » pour une valeur absente', () => {
    // `false` et « absent » ont deux sens ; les confondre ferait lire « décédé :
    // non » là où le serveur n'a rien dit.
    expect(formaterValeur(null)).not.toBe('non')
  })
})

/**
 * **Un champ, un seul nom à l'écran.**
 *
 * `married_name` s'affichait sous trois noms : « Nom d'usage » au formulaire de
 * dépôt et sur la fiche, « Nom d'épouse » dans le diff d'administration et sur la
 * fiche proposée, et le nom de colonne lui-même dans les messages qui n'avaient
 * pas de libellé. Un membre qui remplit « Nom d'usage » puis relit sa demande
 * sous « Nom d'épouse » ne sait pas s'il s'agit du même champ.
 *
 * Le terme retenu est **« Nom d'usage »** : c'est celui du droit français, il ne
 * présume ni du sexe du porteur ni d'un mariage, et c'est déjà celui des deux
 * surfaces qu'un membre touche le plus. La colonne, elle, reste `married_name` —
 * une migration pour un mot ne se paie pas.
 *
 * Ces tests balaient **les quatre** sources qui portent le libellé, et le compte
 * est assertionné : une cinquième surface qui réintroduirait « nom d'épouse » ne
 * doit pas passer inaperçue.
 */
describe('le nom d’usage porte un seul nom à l’écran', () => {
  const SURFACES = [
    './diff.ts',
    './FicheProposee.vue',
    '../personne/champs.ts',
    '../proposition/champs.ts',
  ] as const

  /**
   * Le texte **sans les commentaires** — même précaution qu'au balayage de
   * `min`/`max` plus haut. L'assertion porte sur ce qui s'affiche ; un
   * commentaire qui explique pourquoi « nom d'épouse » a été écarté doit
   * pouvoir citer le terme écarté sans faire rougir le test.
   */
  const lire = (chemin: string): string =>
    readFileSync(fileURLToPath(new URL(chemin, import.meta.url)), 'utf8')
      .replace(/\/\*[\s\S]*?\*\//g, ' ')
      .replace(/<!--[\s\S]*?-->/g, ' ')
      .replace(/(^|[^:])\/\/.*$/gm, '$1')

  it('balaie les quatre surfaces qui nomment le champ, et pas moins', () => {
    expect(SURFACES).toHaveLength(4)
    // Chacune doit réellement parler de ce champ, sans quoi le balayage serait
    // vert pour la mauvaise raison — un chemin faux ne prouve rien.
    for (const chemin of SURFACES) {
      expect(lire(chemin), chemin).toContain('marriedName')
    }
  })

  it('aucune ne dit plus « nom d’épouse »', () => {
    for (const chemin of SURFACES) {
      expect(lire(chemin), chemin).not.toMatch(/[Nn]om d[’']épouse/)
    }
  })

  it('les quatre disent « Nom d’usage »', () => {
    for (const chemin of SURFACES) {
      expect(lire(chemin), chemin).toMatch(/Nom d[’']usage/)
    }
  })

  it('la table des libellés rend le terme retenu', () => {
    expect(libelleDeChamp('marriedName')).toBe('Nom d’usage')
  })
})
