import { describe, expect, it } from 'vitest'
import type { FichePersonneComplete } from '@/lib/api'
import {
  construireOps,
  corpsModificationPersonne,
  instantaneDeFiche,
  motifDeBlocage,
  saisieDepuisFiche,
  texteOuNull,
  valeursDepuisSaisie,
  type ValeursPersonne,
} from './operations'

/**
 * Ce que ces tests protègent : **la demande envoie ce qui change, pas la fiche
 * remplacée**, et elle l'envoie avec la version que l'auteur avait sous les yeux.
 *
 * Le scénario qui justifie tout le fichier est celui du § 9.6 : *déposer lundi,
 * valider vendredi*. Entre les deux, quelqu'un d'autre a pu écrire. `oldValue`
 * et `baseVersion` sont les deux témoins qui permettent au serveur de choisir
 * entre rejouer la demande et la déclarer caduque ; les fabriquer au moment du
 * `POST` détruirait exactement la protection qu'ils apportent.
 */

const FICHE: FichePersonneComplete = {
  visibilite: 'complete',
  id: 17,
  gedcomXref: '@I17@',
  displayName: 'Marne Sureau',
  givenNames: 'Marne Dolomite',
  surname: 'Sureau',
  marriedName: null,
  nickname: null,
  sex: 'feminin',
  deceased: true,
  vivant: false,
  birth: {
    raw: '1987',
    qualifier: 'annee',
    min: '1987-01-01',
    max: '1987-12-31',
    place: null,
    texte: '1987',
  },
  death: null,
  ligneDates: '1987 – …',
  branch: 'pm',
  generation: -2,
  bestQuay: 2,
  citationCount: 3,
  forcePrivacy: false,
  privacyNote: null,
  version: 8,
  parente: 'grand-tante',
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
}

function editeDepuis(fiche: FichePersonneComplete): ValeursPersonne {
  return { ...instantaneDeFiche(fiche).valeurs }
}

describe('un op par champ réellement modifié', () => {
  it('ne produit aucun op quand rien n’a bougé', () => {
    // `ck_prp_op_changement` refuse en base un op sans changement, et le contrat
    // rend 422 avant. Filtrer ici, c'est la différence entre un formulaire qui
    // marche et un formulaire qui se plaint.
    const instantane = instantaneDeFiche(FICHE)

    expect(construireOps(instantane, editeDepuis(FICHE))).toEqual([])
  })

  it('numérote les seq sans trou, quels que soient les champs sautés', () => {
    const instantane = instantaneDeFiche(FICHE)
    const edite = editeDepuis(FICHE)
    edite.surname = 'SUREAU'
    edite.nickname = 'Mimi'

    const ops = construireOps(instantane, edite)

    expect(ops.map((op) => op.seq)).toEqual([1, 2])
    expect(ops.map((op) => op.field)).toEqual(['surname', 'nickname'])
  })

  it('porte le bon valueKind par champ, jamais déduit de la valeur', () => {
    const instantane = instantaneDeFiche(FICHE)
    const edite = editeDepuis(FICHE)
    edite.sex = 'masculin'
    edite.forcePrivacy = true

    const ops = construireOps(instantane, edite)

    // `sex` est un `enum` et `forcePrivacy` un `booleen` : deux formes
    // différentes, deux contrôles différents côté service.
    expect(ops.find((op) => op.field === 'sex')?.valueKind).toBe('enum')
    expect(ops.find((op) => op.field === 'forcePrivacy')?.valueKind).toBe('booleen')
  })
})

describe('le bloc date est un seul op', () => {
  it('nomme le bloc, jamais une de ses cinq colonnes', () => {
    const instantane = instantaneDeFiche(FICHE)
    const edite = editeDepuis(FICHE)
    edite.birth = {
      raw: 'BET 1901 AND 1909',
      qualifier: 'entre',
      min: '1901-01-01',
      max: '1909-12-31',
      place: 'Saule, Ombrage, Sylvanie',
    }

    const ops = construireOps(instantane, edite)

    // Cinq ops permettraient à l'administrateur d'en valider trois et de
    // fabriquer une date incohérente — l'aplatissement que le § 9.1 interdit.
    expect(ops).toHaveLength(1)
    expect(ops[0]!.field).toBe('birth')
    expect(ops[0]!.valueKind).toBe('date_incertaine')
  })

  it('envoie une ancienne valeur à cinq clés, sans texte', () => {
    const instantane = instantaneDeFiche(FICHE)
    const edite = editeDepuis(FICHE)
    edite.birth = { raw: '1977', qualifier: 'annee', min: '1977-01-01', max: '1977-12-31', place: null }

    const ancienne = construireOps(instantane, edite)[0]!.oldValue as unknown as Record<string, unknown>

    expect('texte' in ancienne).toBe(false)
    expect(Object.keys(ancienne).sort()).toEqual(['max', 'min', 'place', 'qualifier', 'raw'])
  })

  it('ne produit pas d’op quand seule la prose du serveur diffère', () => {
    const instantane = instantaneDeFiche(FICHE)
    const edite = editeDepuis(FICHE)

    // La même date, relue avec un `texte` différent : rien n'a changé.
    const relue = instantaneDeFiche({
      ...FICHE,
      birth: { ...FICHE.birth!, texte: 'année 1987' },
    })

    expect(construireOps(relue, edite)).toEqual([])
    expect(construireOps(instantane, editeDepuis({ ...FICHE, birth: { ...FICHE.birth!, texte: 'x' } }))).toEqual([])
  })
})

describe('le verrou optimiste inter-requêtes', () => {
  it('fige baseVersion sur la version lue à l’ouverture', () => {
    // Le cliché est pris une fois. S'il était relu à l'envoi, la demande dirait
    // « je pars de la version d'il y a trois secondes » alors que l'auteur a lu
    // la fiche une heure plus tôt, et le verrou ne protégerait plus rien.
    const instantane = instantaneDeFiche(FICHE)
    const edite = editeDepuis(FICHE)
    edite.surname = 'SUREAU'

    // La fiche bouge côté serveur : le cliché, lui, ne bouge pas.
    const ops = construireOps(instantane, edite)

    expect(ops[0]!.baseVersion).toBe(8)
    expect(instantaneDeFiche({ ...FICHE, version: 12 }).version).toBe(12)
    expect(ops[0]!.baseVersion).toBe(8)
  })

  it('prend oldValue sur la fiche validée, jamais sur le calque', () => {
    // Le calque porte ce qu'une demande **propose** ; `oldValue` doit porter ce
    // qui est **validé**. Les confondre déposerait une demande dont l'ancienne
    // valeur n'a jamais existé en base — donc caduque à coup sûr.
    const avecCalque: FichePersonneComplete = {
      ...FICHE,
      enAttente: {
        surname: {
          propose: 'BOURRACHE',
          actuel: 'Sureau',
          demandeId: 3,
          auteur: 'Cobalt Romarin',
          depuis: '2026-09-10T10:00:00+02:00',
          autresDemandes: [],
        },
      },
    }

    const instantane = instantaneDeFiche(avecCalque)
    const edite = editeDepuis(avecCalque)
    edite.surname = 'SUREAU'

    expect(construireOps(instantane, edite)[0]!.oldValue).toBe('Sureau')
  })
})

describe('le corps de la demande', () => {
  it('dénormalise la cible depuis le cliché, donc toujours cohérente', () => {
    const instantane = instantaneDeFiche(FICHE)
    const edite = editeDepuis(FICHE)
    edite.surname = 'SUREAU'

    const corps = corpsModificationPersonne(instantane, edite, { message: '  ', soumettre: true })

    expect(corps.kind).toBe('modification_personne')
    expect(corps.targetKind).toBe('personne')
    expect(corps.targetId).toBe(17)
    expect(corps.ops[0]!.subjectId).toBe(17)
    // Un message vide n'est pas un message : la clé est absente, pas vide.
    expect('message' in corps).toBe(false)
  })

  it('porte l’origine quand la demande en reprend une autre', () => {
    // C'est la seule fonction d'`origine` : faire suivre les médias déjà
    // téléversés vers la demande neuve (§ 6.10).
    const instantane = instantaneDeFiche(FICHE)
    const edite = editeDepuis(FICHE)
    edite.surname = 'SUREAU'

    const corps = corpsModificationPersonne(instantane, edite, {
      message: 'l’acte de Saule donne la fourchette',
      soumettre: false,
      origine: { demandeId: 14, statut: 'caduque' },
    })

    expect(corps.origine).toEqual({ demandeId: 14, statut: 'caduque' })
    expect(corps.soumettre).toBe(false)
  })

  it('bloque un dépôt qui ne propose rien', () => {
    expect(motifDeBlocage([])).toMatch(/Aucun champ/)
    expect(motifDeBlocage(construireOps(instantaneDeFiche(FICHE), { ...editeDepuis(FICHE), surname: 'X' }))).toBeNull()
  })
})

describe('la normalisation des champs texte', () => {
  it('rend null pour un champ vidé — « effacer » est une valeur légitime', () => {
    expect(texteOuNull('  ')).toBeNull()
    expect(texteOuNull(' Sureau ')).toBe('Sureau')
  })
})

describe('une date que l’éditeur ne sait pas rééditer', () => {
  it('ne produit aucun op — donc aucune suppression silencieuse', () => {
    // La faute que ce test ferme : un `FROM 15 JUN 1900` que le formulaire ne
    // sait pas exprimer ressortait dans un champ **vide**, et un champ vide se
    // compose en « aucune date ». Un contributeur venu corriger un prénom
    // effaçait une date sourcée, sans la voir passer et sans que personne ne
    // puisse le distinguer d'une demande légitime.
    const avecFrom: FichePersonneComplete = {
      ...FICHE,
      birth: {
        raw: 'FROM 15 JUN 1900',
        qualifier: 'depuis',
        min: '1900-06-15',
        max: null,
        place: null,
        texte: 'depuis le 15 juin 1900',
      },
    }

    const instantane = instantaneDeFiche(avecFrom)
    const saisie = saisieDepuisFiche(avecFrom)
    // Le contributeur corrige un tout autre champ, sans toucher à la date.
    saisie.surname = 'SUREAU'

    const converti = valeursDepuisSaisie(saisie)
    const ops = construireOps(instantane, converti.valeurs!)

    expect(ops.map((op) => op.field)).toEqual(['surname'])
    expect(converti.valeurs?.birth).toEqual({
      raw: 'FROM 15 JUN 1900',
      qualifier: 'depuis',
      min: '1900-06-15',
      max: null,
      place: null,
    })
  })

  it('laisse rééditer une date de mois, qui est une forme connue de la saisie', () => {
    const avecMois: FichePersonneComplete = {
      ...FICHE,
      birth: {
        raw: 'JAN 1920',
        qualifier: 'mois',
        min: '1920-01-01',
        max: '1920-01-31',
        place: null,
        texte: 'janvier 1920',
      },
    }

    const saisie = saisieDepuisFiche(avecMois)

    expect(saisie.naissance.debut).toBe('01/1920')
    // Rouvert puis refermé sans y toucher : aucun op.
    expect(construireOps(instantaneDeFiche(avecMois), valeursDepuisSaisie(saisie).valeurs!)).toEqual([])
  })
})

/**
 * ────────────────────────────────────────────────────────────────────
 * **UNE DATE DE DÉCÈS SUR QUELQU'UN QU'ON LAISSE VIVANT.**
 *
 * Le formulaire de modification ne disait rien : la règle n'existait qu'en base
 * — `ck_tree_person_deces_coherent` —, et elle s'appliquait au `flush`. Le
 * contributeur recevait une erreur de pilote de base de données, un message qui
 * ne nomme ni le champ ni le geste à faire. Constaté sur une fiche réelle.
 *
 * La raison qu'on s'était donnée de ne pas le contrôler ici était : « une fiche
 * importée porte 25 `DEAT Y` sans date, refuser bloquerait la correction d'un
 * prénom ». Elle visait **l'incohérence inverse**, celle qui est parfaitement
 * valide — décès coché sans date. Celle-ci ne peut pas préexister : la base
 * l'interdit. Toute occurrence vient donc de la saisie en cours.
 * ────────────────────────────────────────────────────────────────────
 */
describe('une date de décès implique le décès', () => {
  /** La fiche de référence, remise vivante et sans date de décès. */
  function vivante(): FichePersonneComplete {
    return { ...FICHE, deceased: false, vivant: true, death: null }
  }

  it('refuse la date quand la case reste décochée', () => {
    const saisie = saisieDepuisFiche(vivante())
    saisie.deces.forme = 'connue'
    saisie.deces.debut = '1991'

    const converti = valeursDepuisSaisie(saisie)

    expect(converti.valeurs).toBeNull()
    // La clé est `deceased` et non `death` : c'est la case qui manque, et c'est
    // sur elle que le formulaire doit poser le message.
    expect(converti.erreurs['deceased']).toMatch(/personne décédée/)
    expect(converti.erreurs['deceased']).toMatch(/effacez la date/)
  })

  it('accepte la date et la case dans la même saisie', () => {
    const saisie = saisieDepuisFiche(vivante())
    saisie.deces.forme = 'connue'
    saisie.deces.debut = '1991'
    saisie.deceased = true

    expect(valeursDepuisSaisie(saisie).valeurs).not.toBeNull()
  })

  it('laisse passer une fiche déjà décédée qui reçoit une date', () => {
    /* Le cas des 25 `DEAT Y` sans date : on retrouve la date des années plus
       tard, et rien d'autre n'a à être coché. */
    const saisie = saisieDepuisFiche(FICHE)
    saisie.deces.forme = 'connue'
    saisie.deces.debut = '1991'

    expect(valeursDepuisSaisie(saisie).valeurs).not.toBeNull()
  })

  it('ne bloque pas la correction d’un prénom sur une fiche vivante sans date', () => {
    /* La crainte d'origine, vérifiée : on ne juge que ce que la saisie produit. */
    const saisie = saisieDepuisFiche(vivante())
    saisie.surname = 'SUREAU'

    expect(valeursDepuisSaisie(saisie).valeurs).not.toBeNull()
  })

  it('n’appelle pas une date un lieu de décès seul', () => {
    /*
     * Le critère recopie celui de la contrainte : `raw`, `min` ou `max`. Le lieu
     * n'en fait pas partie — « inhumé à Trochu » sans date est une information
     * qu'on n'a pas à refuser. Élargir « par prudence » ferait refuser au front
     * des saisies que le serveur accepte.
     */
    const saisie = saisieDepuisFiche(vivante())
    saisie.deces.lieu = 'Trochu'

    expect(valeursDepuisSaisie(saisie).valeurs).not.toBeNull()
  })
})
