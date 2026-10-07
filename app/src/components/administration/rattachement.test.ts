import { describe, expect, it } from 'vitest'
import { createSSRApp, defineComponent, h, type PropType } from 'vue'
import { renderToString } from 'vue/server-renderer'
import type { MembreAdministration, StatutCompte } from '@/lib/api'
import { mentionDeRattachement } from './rattachement'
import MentionDeRattachement from './MentionDeRattachement.vue'

/**
 * ═══════════════════════════════════════════════════════════════════════════
 *  LE CAS LE PLUS GRAVE ÉTAIT LE SEUL SILENCIEUX.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Un compte sans ligne `mbr_member` regarde l'arbre **depuis la racine** :
 * `AnnuaireDesMembres::personneDuCompte()` rend `null`, et
 * `Tree\Serializer\PersonneDuLecteur` l'écrit mot pour mot — « `null` veut dire
 * « on retombe sur la racine de l'arbre », pas « interdit » ». Le visiteur se
 * connecte normalement, voit un arbre parfaitement plausible, centré sur le
 * propriétaire, et n'a aucun moyen de s'en apercevoir.
 *
 * L'écran d'administration qui devrait le diagnostiquer affichait la mention de
 * rattachement sous `v-if="membre !== null"` : il ne montrait donc **rien**
 * pour ce cas-là précisément. L'information était dans la charge — `membre:
 * null` distingue le compte sans rattachement sans ambiguïté — et c'est le
 * gabarit qui la jetait.
 *
 * Ce fichier tient les deux moitiés, et elles se cassent séparément :
 *
 *  - ce que la logique **décide** (`mentionDeRattachement`) ;
 *  - ce que le gabarit **imprime** (`MentionDeRattachement.vue`), rendu en SSR
 *    comme le reste du projet — pas de `jsdom`, pas de `@vue/test-utils`.
 *
 * Le second n'est pas un doublon du premier : le défaut réparé ici vivait
 * entièrement dans le gabarit, au-dessus d'une charge qui disait déjà la
 * vérité. C'est exactement la jonction que personne ne regardait.
 *
 * Les noms de ce jeu d'essai sont inventés. La base porte l'arbre réel du
 * commanditaire, et une suite de tests n'est pas un endroit où en recopier des
 * lignes.
 */

function membre(
  status: StatutCompte,
  sur: { personId?: number | null; personLabel?: string | null } = {},
): MembreAdministration {
  return {
    id: 7,
    status,
    personId: sur.personId === undefined ? 42 : sur.personId,
    personLabel: sur.personLabel === undefined ? 'Cobalt Romarin' : sur.personLabel,
    matchKind: 'pre_lie',
    invitationId: null,
    linkedAt: '2026-09-01T10:00:00+02:00',
    activatedAt: status === 'actif' ? '2026-09-02T10:00:00+02:00' : null,
  }
}

function rendre(sujet: MembreAdministration | null): Promise<string> {
  const app = createSSRApp({ render: () => h(MentionDeRattachement, { membre: sujet }) })
  app.component(
    'RouterLink',
    defineComponent({
      props: { to: { type: Object as PropType<{ name: string; params: { id: number } }>, required: true } },
      setup(props, { slots }) {
        return () => h('a', { 'data-vers': props.to.name, 'data-id': props.to.params.id }, slots.default?.())
      },
    }),
  )
  return renderToString(app)
}

/* ==================================================================== */
/* Ce que la logique décide                                             */
/* ==================================================================== */

describe('mentionDeRattachement', () => {
  it('ne signale rien pour un rattachement actif, et nomme la fiche', () => {
    const mention = mentionDeRattachement(membre('actif'))

    expect(mention.etat).toBe('actif')
    expect(mention.etiquette).toBe('Rattaché à Cobalt Romarin')
    // Le cas normal ne porte **aucune** conséquence : s'il en portait une, les
    // deux autres ne se distingueraient plus d'un coup d'œil.
    expect(mention.consequence).toBeNull()
  })

  /**
   * **Le cas qui n'existait pas à l'écran.** `membre === null`, c'est-à-dire
   * aucune ligne `mbr_member` — et non un rattachement en attente.
   */
  it('nomme l’absence de rattachement ET sa conséquence', () => {
    const mention = mentionDeRattachement(null)

    expect(mention.etat).toBe('absent')
    expect(mention.etiquette).toBe('Aucune fiche rattachée')
    expect(mention.consequence).not.toBeNull()
    expect(mention.consequence).toContain('voit l’arbre centré sur le propriétaire')
  })

  it('distingue le rattachement en attente du rattachement actif', () => {
    const enAttente = mentionDeRattachement(membre('en_attente'))

    expect(enAttente.etat).toBe('inactif')
    expect(enAttente.etiquette).toContain('pas encore validé')
    expect(enAttente.etiquette).toContain('Cobalt Romarin')
    // Un rattachement en attente retombe sur la racine lui aussi :
    // `personneDuCompte()` ne rend une personne que pour un membre `actif`.
    expect(enAttente.consequence).toContain('voit l’arbre centré sur le propriétaire')
    expect(enAttente.consequence).toContain('Tant qu’il n’est pas validé')
  })

  /**
   * `suspendu` est le troisième `MemberStatus`, et il retombe sur la racine
   * exactement comme `en_attente`. Il n'a pas d'état à lui parce que la
   * conséquence, elle, est la même.
   */
  it('traite un rattachement suspendu comme inactif, sans inventer un quatrième état', () => {
    const mention = mentionDeRattachement(membre('suspendu'))

    expect(mention.etat).toBe('inactif')
    expect(mention.etiquette).toContain('suspendu')
    expect(mention.consequence).toContain('voit l’arbre centré sur le propriétaire')
  })

  /**
   * Un rattachement **actif mais cassé** : la ligne existe, elle est active, et
   * elle ne désigne aucune fiche. Il retombe sur la racine comme les autres, et
   * le dire « actif » sans plus serait le rendre invisible une seconde fois.
   */
  it('ne présente pas comme actif un rattachement actif qui ne désigne rien', () => {
    const mention = mentionDeRattachement(
      membre('actif', { personId: null, personLabel: null }),
    )

    expect(mention.etat).toBe('inactif')
    expect(mention.consequence).toContain('voit l’arbre centré sur le propriétaire')
  })

  /** `person_label` est `NOT NULL` en base ; l'identifiant reste diagnosticable. */
  it('désigne la fiche par son identifiant quand le libellé manque', () => {
    const mention = mentionDeRattachement(membre('actif', { personLabel: '' }))

    expect(mention.etat).toBe('actif')
    expect(mention.etiquette).toBe('Rattaché à la fiche n° 42')
  })

  /**
   * ADR 0013 — inscrit sans s'être trouvé. La charge **omet** `personId` et
   * `personLabel` (pas `null` : absents) ; la mention ne doit ni écrire « la
   * fiche n° undefined », ni promettre « Valider », mais renvoyer à l'onglet.
   */
  it('nomme le compte sans fiche et renvoie à « À traiter »', () => {
    const sansFiche: MembreAdministration = {
      id: 14,
      status: 'en_attente',
      matchKind: 'a_rattacher',
      invitationId: 3,
      linkedAt: null,
      activatedAt: null,
    }
    const mention = mentionDeRattachement(sansFiche)

    expect(mention.etat).toBe('inactif')
    expect(mention.etiquette).toContain('Fiche à identifier')
    expect(mention.consequence).toContain('À traiter')
    expect(`${mention.etiquette} ${mention.consequence}`).not.toContain('undefined')
  })

  it('désigne la fiche quand les clés sont absentes plutôt que nulles', () => {
    const { personId: _id, personLabel: _libelle, ...sansCles } = membre('suspendu')
    expect(mentionDeRattachement(sansCles).etiquette).toBe('Rattachement suspendu')
  })
})

/* ==================================================================== */
/* Ce que le gabarit imprime                                            */
/* ==================================================================== */

describe('MentionDeRattachement.vue', () => {
  /**
   * **Le test qui échoue sans le correctif**, et dont l'échec *est* le défaut :
   * mesuré sur l'ancien gabarit, il rend `expected '<!---->' to contain …`.
   * `<!---->` est le rendu d'un `v-if` faux — strictement rien.
   *
   * Les deux premières assertions portent sur le **texte** et non sur
   * `data-rattachement` : un jour où quelqu'un renommera la prise, ce test doit
   * échouer en disant que la phrase a disparu, pas que l'attribut a changé.
   */
  it('imprime l’avertissement et sa conséquence quand il n’y a aucun rattachement', async () => {
    const html = await rendre(null)

    expect(html).toContain('Aucune fiche rattachée')
    expect(html).toContain('voit l’arbre centré sur le propriétaire')
    expect(html).toContain('data-rattachement="absent"')
  })

  it('imprime le rattachement actif en ligne simple, sans avertissement', async () => {
    const html = await rendre(membre('actif'))

    expect(html).toContain('data-rattachement="actif"')
    expect(html.replace(/<[^>]+>|<!--.*?-->/g, '').replace(/\s+/g, ' ')).toContain('Rattaché à Cobalt Romarin')
    expect(html).not.toContain('depuis la racine')
  })

  it('fait du nom de la fiche un lien vers elle', async () => {
    const html = await rendre(membre('actif'))

    expect(html).toMatch(/<a data-vers="personne\.detail" data-id="42"[^>]*>Cobalt Romarin<\/a>/)
  })

  /**
   * Avant le correctif, `personLabel` étant `NOT NULL` côté serveur, une ligne
   * `en_attente` imprimait « Rattaché à Cobalt Romarin » — **le même texte
   * qu'une ligne active**. Les deux états étaient indiscernables à l'écran.
   */
  it('ne rend pas un rattachement en attente identique à un rattachement actif', async () => {
    const attente = await rendre(membre('en_attente'))
    const actif = await rendre(membre('actif'))

    expect(attente).not.toBe(actif)
    expect(attente).toContain('pas encore validé')
    expect(attente).toContain('voit l’arbre centré sur le propriétaire')
    expect(attente).toContain('data-rattachement="inactif"')
  })

  /**
   * **Aucune alerte rouge sur le compte d'amorçage.** `membre === null` ne
   * désigne en pratique que lui — un compte né d'une invitation a toujours une
   * ligne `mbr_member` —, et il retombe sur une racine qui est justement sa
   * fiche. Un voyant rouge permanent sur la ligne du propriétaire apprendrait à
   * l'administrateur que le rouge de cet écran ne veut rien dire.
   *
   * Les deux états restent **visibles** et **distincts** : c'est l'objet du
   * correctif, et ce test le tient en même temps que la retenue du ton.
   */
  it('n’allume aucune alerte rouge sur le cas sans rattachement', async () => {
    const absent = await rendre(null)
    const inactif = await rendre(membre('en_attente'))

    expect(absent).not.toContain('alerte')
    expect(inactif).not.toContain('alerte')
    // Visibles tous les deux, et distincts l'un de l'autre.
    expect(absent).toContain('data-rattachement="absent"')
    expect(inactif).toContain('data-rattachement="inactif"')
    expect(absent).not.toBe(inactif)
  })

  /**
   * La phrase qui empêche la mention `absent` d'envoyer l'administrateur
   * chasser un défaut qui n'en est pas un sur cette ligne-là.
   */
  it('nomme le compte d’amorçage dans la mention sans rattachement', async () => {
    const html = await rendre(null)

    expect(html).toContain('compte du propriétaire')
    expect(html).toContain('sur tout autre compte')
  })
})
