import { describe, expect, it } from 'vitest'
import type { ChargeDeSession } from '@/lib/api'
import { genealogieSynthetique } from './banc/genealogie'
import { disposerLignees } from './lignees'
import {
  DISPOSITIONS_GARDEES,
  cleDeDisposition,
  coffreEnMemoire,
  creerMemoire,
  identiteDuLecteur,
  type Coffre,
  type EntreesDeLaDisposition,
} from './memoire'

/**
 * Ce que ces tests protègent : la mémoire de l'arbre d'un F5 à l'autre. Ses fautes ne
 * se voient pas à l'écran — un arbre s'affiche toujours —, mais l'une montrerait à un
 * lecteur le graphe d'un autre, une autre une disposition calculée sur un autre graphe
 * ou par un autre code, une troisième ferait tomber l'arbre en navigation privée.
 *
 * Chaque test relit par une SECONDE mémoire sur le même coffre : c'est ce que fait un
 * F5 (plus rien en main, tout vient du disque).
 */

const A = 'compte:1|voirComme:|par:'
const B = 'compte:2|voirComme:|par:'
const graphe = genealogieSynthetique({ personnes: 60, graine: 3 })
const disposition = disposerLignees(graphe)
const ENTREES: EntreesDeLaDisposition = {
  avecAlliances: false,
  avecConjoint: true,
  avecSansLien: false,
  masquees: [],
  format: 'compact',
  alliancesDansLArbre: false,
  fratriesParNaissance: false,
}
const CLE = cleDeDisposition(ENTREES, 'v1')

const nouvelle = (coffre: Coffre | null, dispositions = true) => creerMemoire({ coffre, dispositions, delai: 50 })
const dispositionsDuCoffre = (c: ReturnType<typeof coffreEnMemoire>): string[] =>
  [...c.contenu.keys()].filter((k) => k.startsWith('disposition:'))

describe('la mémoire de l’arbre', () => {
  it('rend, après un F5, l’ETag et le graphe gardés — pour ce lecteur seulement', async () => {
    const coffre = coffreEnMemoire()
    await nouvelle(coffre).garderLeGraphe(A, '"e1"', graphe)

    const apres = nouvelle(coffre)
    expect(await apres.etagGarde(A)).toBe('"e1"')
    expect(await apres.grapheGarde(A, '"e1"')).toEqual(graphe)
    // Un autre lecteur ne trouve rien, ni par l'ETag ni en le devinant.
    expect(await apres.etagGarde(B)).toBeNull()
    expect(await apres.grapheGarde(B, '"e1"')).toBeNull()
    // Le graphe n'est rendu que sous SON ETag : c'est lui qu'un 304 déclare à jour.
    expect(await apres.grapheGarde(A, '"autre"')).toBeNull()
  })

  it('garde les dispositions sous leur clé, Map comprises, et les relit après un F5', async () => {
    const coffre = coffreEnMemoire()
    const m = nouvelle(coffre)
    await m.garderLeGraphe(A, '"e1"', graphe)
    await m.garderLaDisposition(A, '"e1"', CLE, disposition)

    const apres = nouvelle(coffre)
    expect(apres.dispositionGardee(A, '"e1"', CLE)).toBeNull()
    await apres.prechargerLaDisposition(A, '"e1"', CLE)
    const relue = apres.dispositionGardee(A, '"e1"', CLE)!
    expect(relue).toEqual(disposition)
    expect(relue.parId).toBeInstanceOf(Map)
    // Les fiches de l'index sont les fiches de la liste, comme dans la disposition calculée.
    expect(relue.parId.get(relue.fiches[0]!.id)).toBe(relue.fiches[0])
    // Ni un autre ETag, ni une autre clé, ni un autre lecteur.
    await apres.prechargerLaDisposition(A, '"e2"', CLE)
    expect(apres.dispositionGardee(A, '"e2"', CLE)).toBeNull()
    const autreCle = cleDeDisposition({ ...ENTREES, fratriesParNaissance: true }, 'v1')
    await apres.prechargerLaDisposition(A, '"e1"', autreCle)
    expect(apres.dispositionGardee(A, '"e1"', autreCle)).toBeNull()
    await apres.prechargerLaDisposition(B, '"e1"', CLE)
    expect(apres.dispositionGardee(B, '"e1"', CLE)).toBeNull()
  })

  it(`n'en garde que ${DISPOSITIONS_GARDEES}, les plus récentes`, async () => {
    const coffre = coffreEnMemoire()
    const m = nouvelle(coffre)
    await m.garderLeGraphe(A, '"e1"', graphe)
    const cles = (['compact', 'portrait', 'x', 'y'] as const).map((format) => cleDeDisposition({ ...ENTREES, format }, 'v1'))
    for (const cle of cles) await m.garderLaDisposition(A, '"e1"', cle, disposition)

    expect(dispositionsDuCoffre(coffre)).toHaveLength(DISPOSITIONS_GARDEES)
    const apres = nouvelle(coffre)
    await apres.prechargerLaDisposition(A, '"e1"', cles[0]!)
    expect(apres.dispositionGardee(A, '"e1"', cles[0]!)).toBeNull()
    await apres.prechargerLaDisposition(A, '"e1"', cles[3]!)
    expect(apres.dispositionGardee(A, '"e1"', cles[3]!)).not.toBeNull()
  })

  it('un graphe nouveau (autre ETag) efface les dispositions de l’ancien', async () => {
    const coffre = coffreEnMemoire()
    const m = nouvelle(coffre)
    await m.garderLeGraphe(A, '"e1"', graphe)
    await m.garderLaDisposition(A, '"e1"', CLE, disposition)
    await m.garderLeGraphe(A, '"e2"', graphe)

    expect(dispositionsDuCoffre(coffre)).toEqual([])
    expect(await nouvelle(coffre).etagGarde(A)).toBe('"e2"')
  })

  it('le graphe d’un autre lecteur efface tout ce que gardait le précédent', async () => {
    const coffre = coffreEnMemoire()
    const m = nouvelle(coffre)
    await m.garderLeGraphe(A, '"e1"', graphe)
    await m.garderLaDisposition(A, '"e1"', CLE, disposition)
    await m.garderLeGraphe(B, '"f1"', graphe)

    expect(dispositionsDuCoffre(coffre)).toEqual([])
    const apres = nouvelle(coffre)
    expect(await apres.etagGarde(A)).toBeNull()
    expect(await apres.etagGarde(B)).toBe('"f1"')
  })

  it('oublier() vide tout, en main comme sur le disque, et une écriture en file ne revient pas', async () => {
    const coffre = coffreEnMemoire()
    const m = nouvelle(coffre)
    await m.garderLeGraphe(A, '"e1"', graphe)
    const enFile = m.garderLaDisposition(A, '"e1"', CLE, disposition)
    await m.oublier()
    await enFile

    expect(coffre.contenu.size).toBe(0)
    expect(await m.etagGarde(A)).toBeNull()
    expect(m.dispositionGardee(A, '"e1"', CLE)).toBeNull()
  })

  it('sans dispositions (développement), seul le graphe se garde', async () => {
    const coffre = coffreEnMemoire()
    const m = nouvelle(coffre, false)
    await m.garderLeGraphe(A, '"e1"', graphe)
    await m.garderLaDisposition(A, '"e1"', CLE, disposition)

    expect(m.dispositionGardee(A, '"e1"', CLE)).toBeNull()
    expect(dispositionsDuCoffre(coffre)).toEqual([])
  })

  it('ne fait jamais tomber l’arbre : coffre absent, en panne, lent, ou entrée corrompue', async () => {
    const sans = nouvelle(null)
    expect(await sans.etagGarde(A)).toBeNull()
    await sans.garderLeGraphe(A, '"e1"', graphe)

    const enPanne: Coffre = {
      lire: () => Promise.reject(new Error('QuotaExceededError')),
      ecrire: () => Promise.reject(new Error('QuotaExceededError')),
      vider: () => Promise.reject(new Error('QuotaExceededError')),
    }
    const p = nouvelle(enPanne)
    await expect(p.garderLeGraphe(A, '"e1"', graphe)).resolves.toBeUndefined()
    expect(await nouvelle(enPanne).etagGarde(A)).toBeNull()
    await expect(p.oublier()).resolves.toBeUndefined()

    const lent: Coffre = { ...coffreEnMemoire(), lire: () => new Promise(() => {}) }
    expect(await nouvelle(lent).etagGarde(A)).toBeNull()

    const corrompu = coffreEnMemoire()
    corrompu.contenu.set('entete', { format: 1, identite: A, etag: '"e1"', dispositions: [] })
    corrompu.contenu.set('graphe', { format: 1, identite: A, etag: '"e1"', graphe: { noeuds: 'pas une liste' } })
    corrompu.contenu.set(`disposition:${CLE}`, { format: 1, identite: A, etag: '"e1"', cle: CLE, disposition: { fiches: [] } })
    const c = nouvelle(corrompu)
    expect(await c.grapheGarde(A, '"e1"')).toBeNull()
    await c.prechargerLaDisposition(A, '"e1"', CLE)
    expect(c.dispositionGardee(A, '"e1"', CLE)).toBeNull()
    // Une entrée d'une autre forme (un ancien déploiement) est ignorée.
    corrompu.contenu.set('entete', { format: 0, identite: A, etag: '"e1"', dispositions: [] })
    expect(await nouvelle(corrompu).etagGarde(A)).toBeNull()
  })
})

describe('la clé d’une disposition', () => {
  it('nomme chaque entrée de `disposer()`, et le code qui la calcule', () => {
    const variantes: Partial<EntreesDeLaDisposition>[] = [
      { avecAlliances: true },
      { avecConjoint: false },
      { avecSansLien: true },
      { masquees: ['pm'] },
      { format: 'portrait' },
      { alliancesDansLArbre: true },
      { fratriesParNaissance: true },
      { avecEnAttente: false },
    ]
    const cles = new Set([CLE, ...variantes.map((v) => cleDeDisposition({ ...ENTREES, ...v }, 'v1'))])
    expect(cles.size).toBe(variantes.length + 1)
    expect(cleDeDisposition(ENTREES, 'v2')).not.toBe(CLE)
  })

  it('ne dépend pas de l’ordre des quarts masqués (ils forment un ensemble)', () => {
    expect(cleDeDisposition({ ...ENTREES, masquees: ['pm', 'cpp'] }, 'v1')).toBe(
      cleDeDisposition({ ...ENTREES, masquees: ['cpp', 'pm'] }, 'v1'),
    )
  })
})

describe('l’identité du lecteur', () => {
  const session = (id: number, voirComme: number | null, par: number | null): ChargeDeSession =>
    ({
      utilisateur: { id },
      voirComme: voirComme === null ? null : { memberId: voirComme, memberLabel: '', depuis: '' },
      incarnation: par === null ? null : { par: { id: par, displayName: '' } },
    }) as unknown as ChargeDeSession

  it('distingue le compte, la bascule « voir comme » et l’incarnation ; rien sans session', () => {
    const ids = new Set([
      identiteDuLecteur(session(1, null, null)),
      identiteDuLecteur(session(2, null, null)),
      identiteDuLecteur(session(1, 7, null)),
      identiteDuLecteur(session(1, null, 9)),
    ])
    expect(ids.size).toBe(4)
    expect(identiteDuLecteur(null)).toBeNull()
  })

  it('distingue le point de vue choisi (`/arbre?depuis=`), sans changer l’identité de toujours', () => {
    // Sans `depuis`, l'identité d'avant : les graphes déjà gardés restent valables.
    expect(identiteDuLecteur(session(1, null, null), null)).toBe(identiteDuLecteur(session(1, null, null)))
    const ids = new Set([
      identiteDuLecteur(session(1, null, null)),
      identiteDuLecteur(session(1, null, null), 42),
      identiteDuLecteur(session(1, null, null), 43),
    ])
    expect(ids.size).toBe(3)
    expect(identiteDuLecteur(null, 42)).toBeNull()
  })
})

describe('la clé d’une disposition', () => {
  it('distingue le moteur : `/arbre` (groupes) ne relit jamais une disposition de `/arbre-2` (lignées)', () => {
    const lignees = cleDeDisposition(ENTREES, 'v1')
    expect(cleDeDisposition({ ...ENTREES, moteur: 'lignees' }, 'v1')).toBe(lignees)
    expect(cleDeDisposition({ ...ENTREES, moteur: 'groupes' }, 'v1')).not.toBe(lignees)
  })
})
