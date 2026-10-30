import { describe, expect, it } from 'vitest'
import type { AreteGraphe, FinDeRecherche, MotifDeFinDeRecherche, NoeudGraphe, NoeudPersonne, ValeurFinDeRecherche } from '@/lib/api'
import {
  AUCUN_PARENT,
  BOITE_DU_MUR,
  BRIQUE,
  CHAMP_DU_COTE,
  cheminDuMur,
  corpsDeFinDeRecherche,
  cotesConnusDansLeGraphe,
  cotesConnusDe,
  cotesDesParentsDeFiche,
  cotesDuChoix,
  cotesDUneFamille,
  descriptionDuMotif,
  epaisseurDuMur,
  erreursDeFinDeRecherche,
  estEnFinDeRecherche,
  estMotif,
  estValeurDeFinDeRecherche,
  etatDuSommet,
  etatsDuSommet,
  infobulleDuSommet,
  LEGENDE_DU_MUR,
  libelleDuCote,
  libelleDuMotif,
  LONGUEUR_MAX_DU_TEXTE,
  memesValeurs,
  MOTIFS_DE_FIN_DE_RECHERCHE,
  motifsDuChoix,
  normaliserValeur,
  opsDeFinDeRecherche,
  phraseDeFinDeRecherche,
  TRACE_DU_MUR,
  valeurDeFinDeRecherche,
} from './finDeRecherche'

const REGISTRES: ValeurFinDeRecherche = { reason: 'registres_inexistants', beforeYear: 1668, body: 'Saint-Martin commence en 1668.' }
const DETRUITS: ValeurFinDeRecherche = { reason: 'registres_detruits', beforeYear: null, body: null }

function finServie(valeur: ValeurFinDeRecherche): FinDeRecherche {
  return { ...valeur, authorLabel: 'Nathan Provost', closedAt: '2026-10-30T10:12:00+00:00' }
}

describe('les motifs — la liste du commanditaire, et les libellés du serveur', () => {
  it('compte exactement les huit motifs, dans l’ordre du formulaire', () => {
    // La liste du type et celle-ci doivent coïncider : un motif ajouté au serveur
    // (`Core\Enum\MotifDeFinDeRecherche`) s'ajoute aux deux, et ce test le rappelle.
    const attendus: MotifDeFinDeRecherche[] = [
      'registres_inexistants',
      'registres_detruits',
      'lacune',
      'pere_inconnu',
      'enfant_trouve',
      'origine_inconnue',
      'homonymie',
      'autre',
    ]
    expect(MOTIFS_DE_FIN_DE_RECHERCHE.map((m) => m.code)).toEqual(attendus)
  })

  it('ne donne une année qu’aux registres inexistants, et n’exige un texte que pour « autre »', () => {
    expect(MOTIFS_DE_FIN_DE_RECHERCHE.filter((m) => m.avecAnnee).map((m) => m.code)).toEqual(['registres_inexistants'])
    expect(MOTIFS_DE_FIN_DE_RECHERCHE.filter((m) => m.texteObligatoire).map((m) => m.code)).toEqual(['autre'])
  })

  it('réserve « père inconnu » au côté du père, et le retire du choix « les deux »', () => {
    expect(descriptionDuMotif('pere_inconnu')?.cotes).toEqual(['pere'])
    expect(motifsDuChoix('pere').map((m) => m.code)).toContain('pere_inconnu')
    expect(motifsDuChoix('mere').map((m) => m.code)).not.toContain('pere_inconnu')
    expect(motifsDuChoix('les-deux').map((m) => m.code)).not.toContain('pere_inconnu')
    expect(motifsDuChoix('les-deux')).toHaveLength(7)
  })

  it('dit le motif avec son année, et rend un code inconnu tel quel', () => {
    expect(libelleDuMotif('registres_inexistants', 1668)).toBe('Registres inexistants avant 1668')
    expect(libelleDuMotif('registres_inexistants')).toBe('Registres inexistants')
    expect(libelleDuMotif('origine_inconnue')).toBe('Lieu d’origine inconnu')
    expect(libelleDuMotif('enfant_trouve')).toBe('Enfant trouvé ou abandonné')
    expect(libelleDuMotif('motif_de_demain')).toBe('motif_de_demain')
  })

  it('reconnaît un code de motif', () => {
    expect(estMotif('lacune')).toBe(true)
    expect(estMotif('Lacune')).toBe(false)
    expect(estMotif(3)).toBe(false)
  })

  it('compose la phrase du diff : le motif, puis le texte', () => {
    expect(phraseDeFinDeRecherche(REGISTRES)).toBe('Registres inexistants avant 1668 — Saint-Martin commence en 1668.')
    expect(phraseDeFinDeRecherche(DETRUITS)).toBe('Registres détruits')
    expect(phraseDeFinDeRecherche({ reason: 'lacune', beforeYear: null })).toBe('Lacune dans les registres')
    expect(phraseDeFinDeRecherche({ reason: 'autre', beforeYear: null, body: '   ' })).toBe('Autre motif')
  })

  it('nomme les côtés et la ligne de légende de l’affiche', () => {
    expect(libelleDuCote('pere')).toBe('côté père')
    expect(libelleDuCote('mere')).toBe('côté mère')
    expect(LEGENDE_DU_MUR).toBe('recherche close faute d’archives')
    expect(CHAMP_DU_COTE).toEqual({ pere: 'finDeRecherchePere', mere: 'finDeRechercheMere' })
    expect(cotesDuChoix('les-deux')).toEqual(['pere', 'mere'])
    expect(cotesDuChoix('mere')).toEqual(['mere'])
  })
})

describe('la valeur d’un op — trois clés, toujours les trois', () => {
  it('retire l’auteur et la date d’une fin de recherche lue sur la fiche', () => {
    expect(valeurDeFinDeRecherche(finServie(REGISTRES))).toEqual(REGISTRES)
    expect(Object.keys(valeurDeFinDeRecherche(finServie(REGISTRES)) ?? {})).toEqual(['reason', 'beforeYear', 'body'])
    expect(valeurDeFinDeRecherche(null)).toBeNull()
    expect(valeurDeFinDeRecherche(undefined)).toBeNull()
  })

  it('rogne le texte, ramène le vide à null, et ne garde l’année que pour les registres inexistants', () => {
    expect(normaliserValeur({ reason: 'lacune', beforeYear: 1700, body: '  ' })).toEqual({ reason: 'lacune', beforeYear: null, body: null })
    expect(normaliserValeur({ reason: 'registres_inexistants', beforeYear: 1700, body: ' x ' })).toEqual({
      reason: 'registres_inexistants',
      beforeYear: 1700,
      body: 'x',
    })
  })

  it('compare deux valeurs clé par clé, et deux absences comme égales', () => {
    expect(memesValeurs(REGISTRES, { ...REGISTRES })).toBe(true)
    expect(memesValeurs(REGISTRES, { ...REGISTRES, beforeYear: 1669 })).toBe(false)
    expect(memesValeurs(null, null)).toBe(true)
    expect(memesValeurs(null, DETRUITS)).toBe(false)
  })

  it('reconnaît une valeur de fin de recherche parmi les valeurs d’op', () => {
    expect(estValeurDeFinDeRecherche(REGISTRES)).toBe(true)
    expect(estValeurDeFinDeRecherche({ body: 'une note', scope: 'generale' })).toBe(false)
    expect(estValeurDeFinDeRecherche({ reason: 'lacune' })).toBe(false)
    expect(estValeurDeFinDeRecherche(null)).toBe(false)
    expect(estValeurDeFinDeRecherche(['lacune'])).toBe(false)
  })
})

describe('les contrôles de la saisie — ceux du dépôt, devant celui qui saisit', () => {
  it('laisse partir une saisie juste', () => {
    expect(erreursDeFinDeRecherche(REGISTRES, 'mere', 2026)).toEqual({})
    expect(erreursDeFinDeRecherche({ reason: 'pere_inconnu', beforeYear: null, body: null }, 'pere', 2026)).toEqual({})
  })

  it('exige l’année des registres inexistants, entre 1000 et l’année en cours', () => {
    expect(erreursDeFinDeRecherche({ ...REGISTRES, beforeYear: null }, 'pere', 2026).beforeYear).toMatch(/Indiquez/)
    expect(erreursDeFinDeRecherche({ ...REGISTRES, beforeYear: 999 }, 'pere', 2026).beforeYear).toMatch(/entre 1000 et 2026/)
    expect(erreursDeFinDeRecherche({ ...REGISTRES, beforeYear: 2027 }, 'pere', 2026).beforeYear).toMatch(/entre 1000 et 2026/)
    expect(erreursDeFinDeRecherche({ ...REGISTRES, beforeYear: 1668.5 }, 'pere', 2026).beforeYear).toBeDefined()
    expect(erreursDeFinDeRecherche({ ...REGISTRES, beforeYear: 2026 }, 'pere', 2026)).toEqual({})
  })

  it('refuse « père inconnu » du côté de la mère', () => {
    expect(erreursDeFinDeRecherche({ reason: 'pere_inconnu', beforeYear: null, body: null }, 'mere', 2026).reason).toBe(
      '« Père inconnu » ne vaut que du côté du père.',
    )
  })

  it('exige un texte pour « autre », et borne sa longueur', () => {
    expect(erreursDeFinDeRecherche({ reason: 'autre', beforeYear: null, body: ' ' }, 'pere', 2026).body).toMatch(/pourquoi/)
    const long = 'é'.repeat(4001)
    expect(erreursDeFinDeRecherche({ reason: 'lacune', beforeYear: null, body: long }, 'pere', 2026).body).toBe('Le texte dépasse 4 000 caractères.')
    expect(LONGUEUR_MAX_DU_TEXTE).toBe(4000)
    expect(erreursDeFinDeRecherche({ reason: 'lacune', beforeYear: null, body: 'é'.repeat(4000) }, 'pere', 2026)).toEqual({})
  })

  it('refuse un motif inconnu sans aller plus loin', () => {
    const saisie = { reason: 'inconnu', beforeYear: null, body: null } as unknown as ValeurFinDeRecherche
    expect(erreursDeFinDeRecherche(saisie, 'pere', 2026)).toEqual({ reason: 'Choisissez un motif.' })
  })
})

describe('le sommet d’une branche — parents connus, recherche close, pas encore cherché', () => {
  it('donne les trois états d’un côté', () => {
    expect(etatDuSommet(true, null)).toBe('connus')
    expect(etatDuSommet(false, DETRUITS)).toBe('close')
    expect(etatDuSommet(false, null)).toBe('a-chercher')
    expect(etatDuSommet(false, undefined)).toBe('a-chercher')
  })

  it('fait l’emporter un parent connu sur une fin de recherche restée enregistrée', () => {
    expect(etatDuSommet(true, DETRUITS)).toBe('connus')
  })

  it('juge les deux côtés séparément, et pose le mur dès qu’un côté est clos', () => {
    const etats = etatsDuSommet({ pere: true, mere: false }, { finDeRecherchePere: DETRUITS, finDeRechercheMere: REGISTRES })
    expect(etats).toEqual({ pere: 'connus', mere: 'close' })
    expect(estEnFinDeRecherche(etats)).toBe(true)
    expect(estEnFinDeRecherche(etatsDuSommet(AUCUN_PARENT, {}))).toBe(false)
  })

  it('lit indifféremment une fiche, un nœud de graphe ou une personne d’affiche', () => {
    const noeud: Pick<NoeudPersonne, 'finDeRecherchePere'> = { finDeRecherchePere: { reason: 'lacune', beforeYear: null } }
    expect(etatsDuSommet(AUCUN_PARENT, noeud)).toEqual({ pere: 'close', mere: 'a-chercher' })
    const fiche = { finDeRecherchePere: null, finDeRechercheMere: finServie(REGISTRES) }
    expect(etatsDuSommet(AUCUN_PARENT, fiche)).toEqual({ pere: 'a-chercher', mere: 'close' })
  })

  it('dit au survol chaque côté clos, et rien quand aucun ne l’est', () => {
    expect(infobulleDuSommet(AUCUN_PARENT, { finDeRecherchePere: { reason: 'pere_inconnu', beforeYear: null }, finDeRechercheMere: REGISTRES })).toBe(
      'Recherche close côté père : Père inconnu\nRecherche close côté mère : Registres inexistants avant 1668',
    )
    expect(infobulleDuSommet({ pere: true, mere: true }, { finDeRecherchePere: DETRUITS })).toBeNull()
    expect(infobulleDuSommet(AUCUN_PARENT, {})).toBeNull()
  })
})

describe('père ou mère — la règle de Tree, recopiée', () => {
  it('range un homme à la place du père et une femme à celle de la mère', () => {
    expect(cotesDUneFamille(['masculin'])).toEqual({ pere: true, mere: false })
    expect(cotesDUneFamille(['feminin'])).toEqual({ pere: false, mere: true })
    expect(cotesDUneFamille(['feminin', 'masculin'])).toEqual({ pere: true, mere: true })
  })

  it('met un parent de sexe inconnu, ou un second du même sexe, à la place libre — celle du père d’abord', () => {
    expect(cotesDUneFamille(['inconnu'])).toEqual({ pere: true, mere: false })
    expect(cotesDUneFamille(['feminin', 'inconnu'])).toEqual({ pere: true, mere: true })
    expect(cotesDUneFamille(['masculin', 'masculin'])).toEqual({ pere: true, mere: true })
    expect(cotesDUneFamille([])).toEqual(AUCUN_PARENT)
  })

  it('lit la place des parents d’une fiche dans `cote`, sans rien deviner', () => {
    expect(cotesDesParentsDeFiche([{ cote: 'mere' }])).toEqual({ pere: false, mere: true })
    expect(cotesDesParentsDeFiche([{ cote: 'pere' }, { cote: 'mere' }])).toEqual({ pere: true, mere: true })
    expect(cotesDesParentsDeFiche([{}])).toEqual(AUCUN_PARENT)
  })
})

describe('les côtés connus de chaque personne du graphe', () => {
  function personne(id: string, sex: NoeudPersonne['sex']): NoeudGraphe {
    return {
      id,
      type: 'personne',
      personId: Number(id.slice(1)),
      x: 0,
      y: 0,
      w: 100,
      h: 40,
      displayName: id,
      etiquetteNom: id,
      sex,
      generation: 0,
      branch: 'pp',
      vivant: false,
      visibilite: 'complete',
    }
  }
  function famille(id: string): NoeudGraphe {
    return { id, type: 'famille', familyId: Number(id.slice(1)), x: 0, y: 0, w: 4, h: 4, divorced: false }
  }
  const union = (de: string, vers: string): AreteGraphe => ({ de, vers, genre: 'union' })
  const filiation = (de: string, vers: string): AreteGraphe => ({ de, vers, genre: 'filiation' })

  it('lit le père et la mère d’un enfant sur les arêtes, et rien pour qui n’a pas de parents', () => {
    // p1 × p2 → f10 → p3 ; p4 (mère seule) → f11 → p2.
    const noeuds = [personne('p1', 'masculin'), personne('p2', 'feminin'), personne('p3', 'masculin'), personne('p4', 'feminin'), famille('f10'), famille('f11')]
    const aretes = [union('p1', 'f10'), union('p2', 'f10'), filiation('f10', 'p3'), union('p4', 'f11'), filiation('f11', 'p2')]
    const carte = cotesConnusDansLeGraphe(noeuds, aretes)
    expect(cotesConnusDe(carte, 'p3')).toEqual({ pere: true, mere: true })
    expect(cotesConnusDe(carte, 'p2')).toEqual({ pere: false, mere: true })
    expect(cotesConnusDe(carte, 'p1')).toEqual(AUCUN_PARENT)
    expect(carte.has('p1')).toBe(false)
  })

  it('cumule les familles de parents d’un enfant (naissance et adoption)', () => {
    const noeuds = [personne('p1', 'masculin'), personne('p2', 'feminin'), personne('p3', 'inconnu'), famille('f10'), famille('f11')]
    const aretes = [union('p1', 'f10'), filiation('f10', 'p3'), union('p2', 'f11'), filiation('f11', 'p3')]
    expect(cotesConnusDe(cotesConnusDansLeGraphe(noeuds, aretes), 'p3')).toEqual({ pere: true, mere: true })
  })

  it('range un parent seul de sexe inconnu à la place du père, comme Tree', () => {
    const noeuds = [personne('p1', 'inconnu'), personne('p3', 'feminin'), famille('f10')]
    const aretes = [union('p1', 'f10'), filiation('f10', 'p3')]
    expect(cotesConnusDe(cotesConnusDansLeGraphe(noeuds, aretes), 'p3')).toEqual({ pere: true, mere: false })
  })

  it('compte une famille sans époux dans le graphe comme sans parent', () => {
    const noeuds = [personne('p3', 'feminin'), famille('f10')]
    expect(cotesConnusDe(cotesConnusDansLeGraphe(noeuds, [filiation('f10', 'p3')]), 'p3')).toEqual(AUCUN_PARENT)
  })
})

describe('les ops d’une demande — poser, modifier, lever', () => {
  const vierge = { id: 17, version: 8, finDeRecherchePere: null, finDeRechercheMere: null }

  it('pose une fin de recherche d’un côté : null → valeur, avec la version de la fiche', () => {
    expect(opsDeFinDeRecherche(vierge, ['mere'], REGISTRES)).toEqual([
      {
        seq: 1,
        opKind: 'champ',
        subjectKind: 'personne',
        subjectId: 17,
        field: 'finDeRechercheMere',
        valueKind: 'fin_de_recherche',
        oldValue: null,
        newValue: REGISTRES,
        baseVersion: 8,
      },
    ])
  })

  it('pose « les deux » en deux ops de la même demande, le père d’abord', () => {
    const ops = opsDeFinDeRecherche(vierge, cotesDuChoix('les-deux'), DETRUITS, 3)
    expect(ops.map((o) => [o.seq, o.field])).toEqual([
      [3, 'finDeRecherchePere'],
      [4, 'finDeRechercheMere'],
    ])
  })

  it('modifie : l’ancienne valeur est celle de la fiche, sans son auteur ni sa date', () => {
    const fiche = { ...vierge, finDeRecherchePere: finServie(DETRUITS) }
    const [op] = opsDeFinDeRecherche(fiche, ['pere'], { ...DETRUITS, body: 'Incendie de 1871.' })
    expect(op?.oldValue).toEqual(DETRUITS)
    expect(op?.newValue).toEqual({ ...DETRUITS, body: 'Incendie de 1871.' })
  })

  it('lève : valeur → null, pour rouvrir la recherche quand un parent est retrouvé', () => {
    const fiche = { ...vierge, finDeRecherchePere: finServie(DETRUITS) }
    const ops = opsDeFinDeRecherche(fiche, ['pere', 'mere'], null)
    expect(ops).toHaveLength(1)
    expect(ops[0]).toMatchObject({ field: 'finDeRecherchePere', oldValue: DETRUITS, newValue: null })
  })

  it('omet un côté qui ne changerait pas — le serveur refuse une modification qui ne modifie rien', () => {
    const fiche = { ...vierge, finDeRecherchePere: finServie(DETRUITS) }
    expect(opsDeFinDeRecherche(fiche, ['pere'], { ...DETRUITS, body: '  ' })).toEqual([])
    expect(opsDeFinDeRecherche(vierge, ['pere'], null)).toEqual([])
    expect(opsDeFinDeRecherche(fiche, cotesDuChoix('les-deux'), DETRUITS).map((o) => o.field)).toEqual(['finDeRechercheMere'])
  })

  it('normalise la saisie avant de l’envoyer', () => {
    const [op] = opsDeFinDeRecherche(vierge, ['pere'], { reason: 'lacune', beforeYear: 1700, body: '  ' })
    expect(op?.newValue).toEqual({ reason: 'lacune', beforeYear: null, body: null })
  })

  it('tient le corps d’une modification de personne, soumis par défaut, le message seulement s’il dit quelque chose', () => {
    expect(corpsDeFinDeRecherche(vierge, ['pere'], DETRUITS)).toEqual({
      kind: 'modification_personne',
      targetKind: 'personne',
      targetId: 17,
      soumettre: true,
      ops: opsDeFinDeRecherche(vierge, ['pere'], DETRUITS),
    })
    expect(corpsDeFinDeRecherche(vierge, ['pere'], DETRUITS, { message: ' lu aux AD ', soumettre: false })).toMatchObject({
      message: 'lu aux AD',
      soumettre: false,
    })
    expect(corpsDeFinDeRecherche(vierge, ['pere'], DETRUITS, { message: '  ' })).not.toHaveProperty('message')
    expect(corpsDeFinDeRecherche(vierge, ['pere'], null)).toBeNull()
  })

  it('lit une fiche d’un serveur antérieur, sans les deux clés, comme sans fin de recherche', () => {
    const [op] = opsDeFinDeRecherche({ id: 5, version: 2 }, ['pere'], DETRUITS)
    expect(op?.oldValue).toBeNull()
  })
})

describe('le mur — un tracé, deux supports', () => {
  it('donne le tracé de l’icône dans sa boîte de 24', () => {
    expect(TRACE_DU_MUR).toBe('M3 4.5H21V19.5H3ZM3 9.5L21 9.5M3 14.5L21 14.5M12 4.5L12 9.5M7.5 9.5L7.5 14.5M16.5 9.5L16.5 14.5M12 14.5L12 19.5')
    expect(BOITE_DU_MUR).toBe(24)
  })

  it('pose le même mur dans un carré quelconque, en commandes absolues', () => {
    // 4 mm à (100, 50) : k = 1/6.
    const d = cheminDuMur(100, 50, 4)
    expect(d.startsWith('M100.5 50.75H103.5V53.25H100.5Z')).toBe(true)
    const nombres = (d.match(/-?\d+(\.\d+)?/g) ?? []).map(Number)
    expect(Math.min(...nombres)).toBeGreaterThanOrEqual(50)
    expect(Math.max(...nombres)).toBeLessThanOrEqual(104)
    expect(d).not.toMatch(/\d\.\d{4,}/)
  })

  it('met à l’échelle l’épaisseur du trait', () => {
    expect(epaisseurDuMur(24)).toBeCloseTo(1.8)
    expect(epaisseurDuMur(4)).toBeCloseTo(0.3)
  })

  it('peint en brique, une couleur distincte de l’alerte', () => {
    expect(BRIQUE).toMatch(/^#[0-9a-f]{6}$/)
    expect(BRIQUE).not.toBe('#a8501e')
  })
})
