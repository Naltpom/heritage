import { createSSRApp, defineComponent, h, type Component } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { describe, expect, it } from 'vitest'
import type {
  CitationDeFiche,
  FichePersonneComplete,
  MediaDeFiche,
  NoteDeFiche,
  RevisionDeFiche,
} from '@/lib/api'
import { horodatageAvecHeure } from '@/lib/horodatage'
import FriseHistorique from './FriseHistorique.vue'
import SourcesFiche from './SourcesFiche.vue'
import { entreesDHistorique, extrait, lignesDeRevision } from './historique'

/**
 * L'onglet Historique de la fiche, et la date des sources dans l'onglet Sources.
 *
 * Ce qui s'éprouve ici :
 *
 *  - la frise mêle révisions appliquées, sources, notes et documents, **du plus
 *    récent au plus ancien** — l'ordre d'ajout, pas l'ordre de l'onglet Sources ;
 *  - chaque entrée porte sa date et son heure dans un `<time datetime>`, une
 *    icône par nature, un libellé court, et le lien vers sa demande quand elle
 *    en a une ;
 *  - un historique vide le dit ; un lecteur à qui la route du § 6.11 est fermée
 *    sait pourquoi il ne voit que les ajouts ;
 *  - une source de l'onglet Sources est datée comme une note, sans que l'ordre
 *    par appui change.
 *
 * Les charges sont celles que les normaliseurs produisent (`createdAt` ATOM sur
 * les citations, notes et médias ; `SerialiseurDeDemande::revision()` pour les
 * révisions), relues dans leur code.
 */

/* L'ancre factice écrit sa cible dans `data-vers`, pour vérifier où mène un lien. */
const LienFactice = defineComponent({
  name: 'RouterLink',
  props: { to: { type: [String, Object], default: '' } },
  setup: (props, { slots, attrs }) => () =>
    h('a', { ...attrs, href: '#', 'data-vers': JSON.stringify(props.to) }, slots['default']?.()),
})

function rendre(composant: Component, props: Record<string, unknown>): Promise<string> {
  const app = createSSRApp({ render: () => h(composant, props) })
  app.component('RouterLink', LienFactice)
  return renderToString(app)
}

function citation(surcharge: Partial<CitationDeFiche> = {}): CitationDeFiche {
  return {
    id: 901,
    scope: 'naissance',
    quay: 2,
    quayLabel: 'acte ou registre',
    appui: 'acte',
    appuiLabel: 'tenu par un acte',
    page: 'Saule, registre des naissances 1901-1909, vue 41',
    evenContext: null,
    url: null,
    dataText: null,
    source: {
      id: 4,
      title: 'État civil de Saule',
      author: null,
      sourceKind: 'acte_etat_civil',
      sourceKindLabel: 'Acte d’état civil',
    },
    medias: [],
    createdAt: '2026-09-12T18:04:00+02:00',
    ...surcharge,
  } as CitationDeFiche
}

const NOTE: NoteDeFiche = {
  id: 77,
  scope: 'naissance',
  body: 'MyHeritage avait recopié l’année de décès dans le champ naissance.',
  authorLabel: 'Natron Romarin',
  createdAt: '2026-09-13T21:13:00+02:00',
}

const MEDIA: MediaDeFiche = {
  mediaId: 120,
  kind: 'preuve',
  caption: 'Acte n° 3, Épinaille 1910',
  url: '/api/medias/120/fichier',
  vignetteUrl: '/api/medias/120/vignette',
  createdAt: '2026-09-14T10:22:00+02:00',
}

function revision(surcharge: Partial<RevisionDeFiche> = {}): RevisionDeFiche {
  return {
    id: 210,
    operation: 'modification',
    subjectKind: 'personne',
    subjectId: 17,
    subjectLabel: 'Marne Dolomite Sureau',
    changes: { birth: { avant: '1987', apres: 'entre 1901 et 1909' } },
    versionBefore: 8,
    versionAfter: 9,
    actorLabel: 'Natron Romarin',
    requestId: 14,
    appliedAt: '2026-09-15T09:31:00+02:00',
    ...surcharge,
  }
}

const FICHE = { citations: [citation()], notes: [NOTE], medias: [MEDIA] }

/* ------------------------------------------------------------------ */

describe('historique.ts — une seule frise, du plus récent au plus ancien', () => {
  it('mêle révisions, sources, notes et documents par date d’ajout décroissante', () => {
    const entrees = entreesDHistorique(FICHE, [
      revision(),
      revision({ id: 150, operation: 'creation', requestId: null, appliedAt: '2026-08-01T08:00:00+02:00' }),
    ])
    expect(entrees.map((e) => e.cle)).toEqual([
      'revision-210',
      'media-120',
      'note-77',
      'citation-901',
      'revision-150',
    ])
  })

  it('compare des instants, pas des chaînes : les fuseaux ne trompent pas le tri', () => {
    // 10:00Z est **après** 11:00+02:00 (09:00Z), alors que la chaîne dit l'inverse.
    const entrees = entreesDHistorique(
      {
        citations: [citation({ id: 1, createdAt: '2026-09-12T11:00:00+02:00' })],
        notes: [{ ...NOTE, id: 2, createdAt: '2026-09-12T10:00:00+00:00' }],
        medias: [],
      },
      [],
    )
    expect(entrees.map((e) => e.cle)).toEqual(['note-2', 'citation-1'])
  })

  it('une révision dit sa demande d’origine, ou l’import GEDCOM quand elle n’en a pas', () => {
    const [modifiee, importee] = entreesDHistorique({ citations: [], notes: [], medias: [] }, [
      revision(),
      revision({ id: 150, requestId: null, appliedAt: '2026-08-01T08:00:00+02:00' }),
    ])
    expect(modifiee).toMatchObject({ titre: 'Fiche modifiée', demandeId: 14, importGedcom: false })
    expect(modifiee?.lignes).toEqual(['Naissance : 1987 → entre 1901 et 1909'])
    expect(importee).toMatchObject({ demandeId: null, importGedcom: true })
  })

  it('traduit les noms de champ, garde les valeurs formatées du serveur, résume au-delà de quatre', () => {
    expect(
      lignesDeRevision({
        operation: 'creation',
        changes: {
          givenNames: { avant: null, apres: 'Marne' },
          surname: { avant: null, apres: 'Bourrache' },
          sex: { avant: null, apres: 'F' },
          deceased: { avant: null, apres: true },
          birth: { avant: null, apres: 'vers 1904' },
          death: { avant: null, apres: '1987' },
        },
      }),
    ).toEqual([
      'Prénoms : Marne',
      'Nom de famille : Bourrache',
      'Sexe : F',
      'Décédé·e : oui',
      '… et 2 autres champs',
    ])
    // Sur une fiche masquée, `avant` est retiré : on ne montre que la nouvelle valeur.
    expect(lignesDeRevision({ operation: 'modification', changes: { birth: { apres: '1904' } } })).toEqual([
      'Naissance : 1904',
    ])
  })

  it('un lien retiré se lit par sa phrase, telle que l’administrateur l’a lue', () => {
    expect(
      lignesDeRevision({
        operation: 'lien_retire',
        changes: { lien: { avant: 'Lien retiré : Pivoine Sureau n’est plus enfant de Cobalt Sureau.', apres: null } },
      }),
    ).toEqual(['Lien retiré : Pivoine Sureau n’est plus enfant de Cobalt Sureau.'])
  })

  it('une note se résume à son début, coupé à un mot', () => {
    const long = 'mot '.repeat(80)
    const court = extrait(long, 40)
    expect(court.endsWith('…')).toBe(true)
    expect(court.length).toBeLessThanOrEqual(41)
    expect(extrait('  court\n texte ')).toBe('court texte')
  })
})

describe('FriseHistorique — le rendu', () => {
  const entrees = entreesDHistorique(FICHE, [revision()])

  it('date chaque entrée, avec son heure, dans un `<time datetime>`', async () => {
    const html = await rendre(FriseHistorique, { entrees, ficheId: 17 })
    for (const date of [
      '2026-09-15T09:31:00+02:00',
      '2026-09-14T10:22:00+02:00',
      '2026-09-13T21:13:00+02:00',
      '2026-09-12T18:04:00+02:00',
    ]) {
      expect(html).toContain(`<time datetime="${date}">${horodatageAvecHeure(date)}</time>`)
    }
  })

  it('une icône et un libellé par nature, dans l’ordre de la frise', async () => {
    const html = await rendre(FriseHistorique, { entrees, ficheId: 17 })
    const natures = [...html.matchAll(/data-entree="([a-z]+)"/g)].map((m) => m[1])
    expect(natures).toEqual(['revision', 'media', 'note', 'citation'])
    expect(html.match(/<svg/g)?.length).toBe(4)
    for (const libelle of ['Fiche modifiée', 'Document ajouté', 'Note ajoutée', 'Source ajoutée']) {
      expect(html).toContain(libelle)
    }
    // La source : son titre, sa cote, son appui ; la note : son auteur ; le document : sa vignette.
    expect(html).toContain('État civil de Saule')
    expect(html).toContain('tenu par un acte')
    expect(html).toContain('Natron Romarin')
    expect(html).toContain('/api/medias/120/vignette')
  })

  it('renvoie à la demande d’origine, et aux Sources pour ce qui y vit', async () => {
    const html = await rendre(FriseHistorique, { entrees, ficheId: 17 })
    expect(html).toContain('Demande n° 14')
    expect(html).toContain(
      JSON.stringify({ name: 'demande.detail', params: { id: 14 } }).replaceAll('"', '&quot;'),
    )
    expect(html).toContain(
      JSON.stringify({ name: 'personne.detail', params: { id: 17 }, query: { onglet: 'sources' } }).replaceAll(
        '"',
        '&quot;',
      ),
    )
    // Une source, une note, un document : trois renvois vers Sources ; la révision n'en a pas.
    expect(html.match(/data-lien="sources"/g)?.length).toBe(3)
  })

  it('un historique vide le dit clairement', async () => {
    const html = await rendre(FriseHistorique, { entrees: [], ficheId: 17 })
    expect(html).toContain('data-historique-vide')
    expect(html).toContain('Rien n’a encore été ajouté ni modifié sur cette fiche.')
    expect(html).not.toContain('<ol')
  })

  it('ne dit pas « vide » tant que les révisions se chargent', async () => {
    const html = await rendre(FriseHistorique, { entrees: [], ficheId: 17, etatRevisions: 'chargement' })
    expect(html).not.toContain('data-historique-vide')
    expect(html).toContain('Chargement des modifications')
  })

  it('dit au lecteur sans accès aux révisions pourquoi il ne voit que les ajouts', async () => {
    const html = await rendre(FriseHistorique, { entrees, ficheId: 17, etatRevisions: 'reserve' })
    expect(html).toContain('data-etat-revisions="reserve"')
    expect(html).toContain('réservées aux membres')
  })
})

describe('SourcesFiche — les sources datées, comme les notes', () => {
  it('chaque source porte sa date et son heure d’ajout, sans changer l’ordre par appui', async () => {
    const fiche = {
      citations: [
        citation({ id: 1, appui: 'arbre', appuiLabel: 'tenu par un arbre en ligne', createdAt: '2026-09-20T08:00:00+02:00' }),
        citation({ id: 2, appui: 'acte', createdAt: '2026-01-02T09:15:00+01:00' }),
      ],
      notes: [],
      medias: [],
    } as unknown as FichePersonneComplete
    const html = await rendre(SourcesFiche, { fiche })

    expect(html).toContain(
      `<time datetime="2026-01-02T09:15:00+01:00">${horodatageAvecHeure('2026-01-02T09:15:00+01:00')}</time>`,
    )
    expect(html).toContain(
      `<time datetime="2026-09-20T08:00:00+02:00">${horodatageAvecHeure('2026-09-20T08:00:00+02:00')}</time>`,
    )
    // L'acte d'abord, même plus ancien : la date se lit, elle ne trie pas.
    expect(html.indexOf('tenu par un acte')).toBeLessThan(html.indexOf('tenu par un arbre en ligne'))
  })
})
