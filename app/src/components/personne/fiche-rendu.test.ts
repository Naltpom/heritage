import { createSSRApp, defineComponent, h } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { describe, expect, it } from 'vitest'
import type { BlocDate, FichePersonneComplete, FichePersonneRestreinte } from '@/lib/api'
import FicheRestreinte from './FicheRestreinte.vue'
import IdentiteFiche from './IdentiteFiche.vue'

/**
 * Deux règles de cet écran ne se prouvent pas sur une fonction : elles se
 * prouvent sur ce qui est **écrit à l'écran**. `champs.test.ts` vérifie ce que
 * la logique décide ; ce fichier vérifie que le gabarit l'imprime.
 *
 *  1. **Le calque est à côté de la valeur validée, jamais à la place** (§ 13.2).
 *     Une ligne en attente doit porter **les deux** valeurs et la pastille. Un
 *     gabarit qui remplacerait l'une par l'autre passerait tous les tests de
 *     `champs.ts` sans en manquer un seul — la fonction rendrait toujours les
 *     deux, et personne ne verrait que l'écran n'en montre qu'une.
 *  2. **Un champ interdit n'a pas de ligne** (§ 1.3). Ce qui se vérifie ici,
 *     c'est l'absence de l'**étiquette** : « Naissance » ne doit pas apparaître
 *     sur la fiche d'une personne qu'on n'a pas le droit de lire, pas même
 *     suivie d'un tiret.
 *
 * Rendu en SSR — même méthode que `ResultatCandidats.test.ts` : pas de DOM, pas
 * de dépendance de test supplémentaire, et la chaîne produite se lit.
 */

/* `RouterLink` n'est pas résolu hors d'une application routée : on le remplace
   par une ancre, ce qui suffit — aucune assertion ne porte sur la navigation. */
const LienFactice = defineComponent({
  name: 'RouterLink',
  props: { to: { type: [String, Object], default: '' } },
  setup: (_, { slots }) => () => h('a', { href: '#' }, slots['default']?.()),
})

async function rendre(composant: unknown, props: Record<string, unknown>): Promise<string> {
  const app = createSSRApp({
    render: () => h(composant as never, props),
  })
  app.component('RouterLink', LienFactice)
  return renderToString(app)
}

const NAISSANCE: BlocDate = {
  raw: 'BET 1901 AND 1909',
  qualifier: 'entre',
  min: '1901-01-01',
  max: '1909-12-31',
  place: 'Saule, Ombrage, Sylvanie',
  texte: 'entre 1901 et 1909',
}

function ficheComplete(surcharge: Partial<FichePersonneComplete> = {}): FichePersonneComplete {
  return {
    visibilite: 'complete',
    id: 17,
    gedcomXref: '@I500017@',
    displayName: 'Marne Dolomite Sureau',
    givenNames: 'Marne Dolomite',
    surname: 'Bourrache',
    marriedName: 'Sarriette',
    nickname: null,
    sex: 'feminin',
    deceased: true,
    vivant: false,
    birth: NAISSANCE,
    death: null,
    ligneDates: '1901-1909 – 1987',
    branch: 'pm',
    generation: -3,
    bestQuay: 2,
    citationCount: 6,
    forcePrivacy: false,
    privacyNote: null,
    version: 8,
    parente: 'arrière-grand-mère',
    portrait: null,
    parents: [],
    fratrie: [],
    unions: [],
    faits: [],
    citations: [],
    notes: [],
    medias: [],
    enAttente: {},
    demandesEnAttente: [],
    ...surcharge,
  }
}

const RESTREINTE: FichePersonneRestreinte = {
  id: 202,
  displayName: 'Pyroxène Tilleul',
  sex: 'masculin',
  generation: -2,
  branch: 'mm',
  vivant: true,
  visibilite: 'restreinte',
}

/* ------------------------------------------------------------------ */

describe('§ 13.2 — l’écran montre la valeur proposée ET la valeur validée', () => {
  it('imprime les deux, plus la pastille « en attente de validation »', async () => {
    const html = await rendre(
      IdentiteFiche,
      {
        fiche: ficheComplete({
          surname: 'Bourrache',
          enAttente: {
            surname: {
              propose: 'BOURRACHE',
              actuel: 'Bourrache',
              demandeId: 14,
              auteur: 'Opale Romarin',
              depuis: '2026-09-14T11:02:00+02:00',
              autresDemandes: [],
            },
          },
        }),
      },
    )

    expect(html).toContain('BOURRACHE')
    // La valeur validée **reste dans le corps** : un lecteur qui ignore le
    // calque doit voir l'arbre validé, pas une proposition présentée comme
    // acquise.
    expect(html).toContain('Bourrache')
    expect(html).toContain('En attente de validation')
    // Le calque est **à côté**, jamais à la place : la valeur validée vient
    // d'abord, à sa place, puis le bloc du calque, qui porte la valeur proposée.
    const validee = html.indexOf('data-valeur="validee"')
    const calque = html.indexOf('data-calque')
    expect(validee).toBeGreaterThan(-1)
    expect(calque).toBeGreaterThan(validee)
    expect(html.slice(validee, calque)).toContain('Bourrache')
    expect(html.slice(validee, calque)).not.toContain('BOURRACHE')
    expect(html.slice(calque)).toContain('BOURRACHE')
    // « Proposé par {auteur} le {date} · Demande n° {id} ».
    expect(html).toContain('Proposé par Opale Romarin')
    expect(html).toContain('Demande n° 14')
    // `actuel` est accessible sans condition, dans le repli.
    expect(html).toContain('Valeur au dépôt de la demande')
  })

  it('annonce la caducité quand la fiche a bougé depuis le dépôt', async () => {
    const html = await rendre(
      IdentiteFiche,
      {
        fiche: ficheComplete({
          surname: 'Bourrache-Sureau',
          enAttente: {
            surname: {
              propose: 'BOURRACHE',
              actuel: 'Bourrache',
              demandeId: 14,
              auteur: 'Opale Romarin',
              depuis: '2026-09-14T11:02:00+02:00',
              autresDemandes: [],
            },
          },
        }),
      },
    )

    expect(html).toContain('La fiche a changé depuis le dépôt')
    expect(html).toContain('Bourrache-Sureau')
  })

  it('ne pose aucune pastille quand le calque est vide', async () => {
    const html = await rendre(IdentiteFiche, { fiche: ficheComplete({ enAttente: {} }) })
    expect(html).not.toContain('En attente de validation')
  })
})

describe('§ 14 — la fourchette arrive telle quelle jusqu’au HTML', () => {
  it('écrit « entre 1901 et 1909 », et pas « 1901 » seul', async () => {
    const html = await rendre(IdentiteFiche, { fiche: ficheComplete() })
    expect(html).toContain('entre 1901 et 1909')
    expect(html).toContain('Saule, Ombrage, Sylvanie')
  })
})

describe('§ 1.3 — une valeur nulle garde sa ligne, un champ interdit n’en a pas', () => {
  it('écrit un mot pour un champ à null, jamais un tiret', async () => {
    const html = await rendre(IdentiteFiche, { fiche: ficheComplete({ nickname: null }) })
    expect(html).toContain('Surnom')
    expect(html).toContain('aucun')
  })

  it('n’écrit aucune étiquette de champ interdit sur une fiche réduite', async () => {
    const html = await rendre(FicheRestreinte, { fiche: RESTREINTE })

    // Les trois lignes que les sept clés permettent d'écrire.
    expect(html).toContain('Sexe')
    expect(html).toContain('Homme')
    expect(html).toContain('Génération')

    // Et pas une quatrième. C'est **l'étiquette** qu'on cherche : une ligne
    // « Naissance — » dirait « on ne sait pas » là où la vérité est « vous
    // n'avez pas le droit de savoir ».
    for (const interdit of ['Naissance', 'Décès', 'Nom', 'Prénoms', 'Parenté', 'Référence GEDCOM']) {
      expect(html).not.toContain(interdit)
    }
  })

  it('dit pourquoi, et propose une sortie — ce n’est pas un écran d’erreur', async () => {
    const html = await rendre(FicheRestreinte, { fiche: RESTREINTE })

    // Depuis le 2026-10-01, un membre ne reçoit plus de fiche réduite que confidentielle.
    expect(html).toContain('Fiche confidentielle')
    // La phrase qui distingue « masqué à l'écran » de « pas envoyé » : c'est
    // toute la différence entre une règle appliquée et une règle affichée.
    expect(html).toContain('le serveur ne les a pas envoyées')
    expect(html).toContain('Revenir à l’arbre')
  })

  it('n’écrit aucun nom : ni le libellé reçu, ni un vrai nom qu’un serveur ancien enverrait', async () => {
    // Révision du 2026-10-01 (ADR 0003) : une personne vivante hors cercle n'a plus de nom.
    const html = await rendre(FicheRestreinte, { fiche: RESTREINTE })
    // Le prénom seul : « Tilleul » est aussi le nom d'une branche (`champs.ts`).
    expect(html).not.toContain('Pyroxène')
  })
})
