import { describe, expect, it } from 'vitest'
import {
  analyserReprise,
  lireBlocDate,
  phraseOrigine,
  type RepriseCreation,
  type RepriseModification,
} from './reprise'
import type { StatutDemande } from '@/lib/api'
import { corpsCreationPersonne, estFicheProposee, type ChoixDePersonne } from './creation'
import { relectureFidele, valeurAEnvoyer } from './dates'

/**
 * Ce que ces tests protègent : **une caducité ne doit pas être une contribution
 * perdue**.
 *
 * « Un membre dont la première contribution se perd n'en dépose pas de
 * seconde » (§ 9.6). Le bouton « refaire » n'a de valeur que si le formulaire
 * qu'il rouvre contient bien ce que l'auteur avait écrit — et rien de ce qu'il
 * n'avait pas écrit. Deux fautes symétriques sont possibles, et les deux sont
 * silencieuses : perdre un lien, ou figer un résultat de validation passée.
 */

const REPRISE_MODIFICATION = {
  kind: 'modification_personne',
  message: 'l’acte de Saule donne la fourchette',
  soumettre: false,
  targetKind: 'personne',
  targetId: 17,
  ops: [
    {
      seq: 1,
      opKind: 'champ',
      subjectKind: 'personne',
      subjectId: 17,
      field: 'birth',
      valueKind: 'date_incertaine',
      oldValue: { raw: 'ABT 1904', qualifier: 'environ', min: '1904-01-01', max: '1904-12-31', place: null },
      newValue: {
        raw: 'BET 1901 AND 1909',
        qualifier: 'entre',
        min: '1901-01-01',
        max: '1909-12-31',
        place: 'Saule, Ombrage, Sylvanie',
      },
      baseVersion: 9,
    },
    {
      seq: 2,
      opKind: 'champ',
      subjectKind: 'personne',
      subjectId: 17,
      // Une colonne dérivée n'est pas éditable : le serveur n'en produit pas,
      // et si l'on en recevait une, elle n'aurait nulle part où s'afficher.
      field: 'displayName',
      valueKind: 'texte',
      oldValue: 'Marne Sureau',
      newValue: 'Marne SUREAU',
      baseVersion: 9,
    },
  ],
  origine: { demandeId: 14, statut: 'caduque' },
}

const REPRISE_CREATION = {
  kind: 'creation_personne',
  message: 'épouse de Jargon, j’ai le livret de famille',
  soumettre: false,
  brouillons: [
    {
      localRef: '#p1',
      givenNames: 'Tourmaline',
      surname: 'Muguet',
      marriedName: 'Sarriette',
      nickname: null,
      sex: 'feminin',
      deceased: true,
      birth: { raw: 'ABT 1889', qualifier: 'environ', min: '1889-01-01', max: '1889-12-31', place: 'Saule' },
      death: { raw: '1972', qualifier: 'annee', min: '1972-01-01', max: '1972-12-31', place: null },
      note: 'Livret de famille en ma possession, photographié.',
    },
  ],
  liens: [
    {
      seq: 1,
      kind: 'conjoint_de',
      subjectDraftRef: '#p1',
      otherPersonId: 55,
      marriage: { raw: '23 SEP 1927', qualifier: 'exacte', min: '1927-09-23', max: '1927-09-23', place: 'Saule' },
      divorced: false,
      divorce: null,
      note: 'date du livret',
    },
    { seq: 2, kind: 'enfant_de', subjectDraftRef: '#p1', otherPersonId: 61, other2PersonId: 62 },
    { seq: 3, kind: 'parent_de', subjectDraftRef: '#p1', otherPersonId: 63, siblingOrder: 0 },
  ],
  origine: { demandeId: 12, statut: 'caduque' },
  avertissements: [
    {
      code: 'reference_disparue',
      message: 'Jargon Sarriette n’existe plus dans l’arbre ; le lien « conjoint de » a été retiré.',
      lien: 1,
    },
  ],
}

function modification(): RepriseModification {
  const reprise = analyserReprise(REPRISE_MODIFICATION)
  if (reprise === null || reprise.kind !== 'modification_personne') throw new Error('nature inattendue')
  return reprise
}

function creation(): RepriseCreation {
  const reprise = analyserReprise(REPRISE_CREATION)
  if (reprise === null || reprise.kind !== 'creation_personne') throw new Error('nature inattendue')
  return reprise
}

/**
 * L'identifiant de personne d'un choix, ou `null` si c'est une fiche proposée.
 *
 * Une extrémité a **trois** formes depuis qu'une demande peut désigner la fiche
 * proposée par une autre : `personId` n'existe donc plus sur toutes. Le test
 * rétrécit ici plutôt que de caster — un `as PersonneChoisie` ferait passer au
 * vert un jour où la relecture rendrait la mauvaise forme.
 */
function idDe(choix: ChoixDePersonne | undefined): number | null {
  if (choix === undefined || estFicheProposee(choix)) return null
  return choix.personId
}

describe('reprendre une modification', () => {
  it('ne reprend que la valeur proposée, jamais l’ancienne ni la version', () => {
    // `oldValue` et `baseVersion` sont reconstruits depuis la fiche que
    // l'auteur vient de lire : le corps rendu a pu être calculé sur une version
    // qu'il n'a jamais eue sous les yeux.
    const reprise = modification()

    expect(reprise.proposees['birth']).toEqual({
      raw: 'BET 1901 AND 1909',
      qualifier: 'entre',
      min: '1901-01-01',
      max: '1909-12-31',
      place: 'Saule, Ombrage, Sylvanie',
    })
    expect(JSON.stringify(reprise.proposees)).not.toMatch(/baseVersion|oldValue/)
  })

  it('écarte un champ hors de la surface éditable', () => {
    expect(modification().proposees).not.toHaveProperty('displayName')
  })

  it('rapporte l’origine, sans quoi les médias resteraient orphelins', () => {
    // C'est la seule fonction d'`origine` : faire suivre vers la demande neuve
    // les médias déjà téléversés, qui pointent encore vers la demande morte.
    expect(modification().origine).toEqual({ demandeId: 14, statut: 'caduque' })
    expect(modification().message).toBe('l’acte de Saule donne la fourchette')
  })
})

describe('reprendre une création', () => {
  it('conserve la référence provisoire telle quelle', () => {
    // La changer casserait le rattachement des médias, qui la portent.
    expect(creation().saisie.localRef).toBe('#p1')
  })

  it('remet la fiche dans les champs, fourchette comprise', () => {
    const { brouillon } = creation().saisie

    expect(brouillon.surname).toBe('Muguet')
    expect(brouillon.deceased).toBe(true)
    expect(brouillon.naissance.forme).toBe('environ')
    expect(brouillon.naissance.debut).toBe('1889')
    expect(brouillon.naissance.lieu).toBe('Saule')
    expect(brouillon.deces.forme).toBe('connue')
    expect(brouillon.deces.debut).toBe('1972')
  })

  it('range les deux parents d’un même lien enfant_de côte à côte', () => {
    // Deux parents ne font **pas** deux liens : `other2PersonId` est le second
    // parent du même lien. Les relire comme deux liens en ferait quatre à la
    // reprise suivante.
    const parents = creation().saisie.filiation.parents

    expect(parents.map(idDe)).toEqual([61, 62])
    expect(creation().saisie.filiation.famille).toBeNull()
  })

  it('remet l’union avec sa date et sa note', () => {
    const [union] = creation().saisie.unions

    expect(idDe(union?.personne)).toBe(55)
    expect(union?.mariage.forme).toBe('connue')
    expect(union?.mariage.debut).toBe('23/09/1927')
    expect(union?.rompue).toBe(false)
    expect(union?.note).toBe('date du livret')
  })

  it('remet l’enfant avec son rang', () => {
    expect(idDe(creation().saisie.enfants[0]?.personne)).toBe(63)
    expect(creation().saisie.enfants[0]?.rangFratrie).toBe(0)
  })

  it('liste les personnes à nommer, parce que le corps ne porte que des numéros', () => {
    // Afficher « personne n° 55 » mettrait un identifiant sous les yeux du
    // lecteur, ce que le dépôt s'interdit partout ailleurs.
    expect(creation().aResoudre.sort()).toEqual([55, 61, 62, 63])
  })

  it('rapporte les avertissements du serveur', () => {
    // Sans eux, on redéposerait une demande amputée d'un lien sans le voir.
    expect(creation().avertissements).toHaveLength(1)
    expect(creation().avertissements[0]?.code).toBe('reference_disparue')
  })

  it('reprend une famille désignée — l’intention — et pas un résultat résolu', () => {
    const reprise = analyserReprise({
      ...REPRISE_CREATION,
      liens: [
        {
          seq: 1,
          kind: 'enfant_de',
          subjectDraftRef: '#p1',
          familyId: 47,
          // `resolved_family_id` est le **résultat** d'une déduction passée.
          // « Une reprise redécrit une intention, pas un résultat. »
          resolvedFamilyId: 104,
        },
      ],
    })
    if (reprise === null || reprise.kind !== 'creation_personne') throw new Error('nature inattendue')

    expect(reprise.saisie.filiation.famille?.familyId).toBe(47)
    expect(reprise.saisie.filiation.parents).toEqual([])
    expect(JSON.stringify(reprise.saisie)).not.toMatch(/104/)
  })
})

describe('la lecture défensive', () => {
  it('refuse un bloc date sans qualificatif reconnaissable', () => {
    expect(lireBlocDate(null)).toBeNull()
    expect(lireBlocDate({ min: '1901-01-01' })).toBeNull()
    expect(lireBlocDate({ qualifier: 'peut_etre' })).toBeNull()
  })

  it('accepte les dix qualificatifs du § 14.1', () => {
    // `mois` et `depuis` ont manqué à cette liste : une date `JAN 1920`
    // disparaissait alors du formulaire de reprise, sans erreur nulle part.
    for (const qualifier of ['exacte', 'annee', 'mois', 'environ', 'avant', 'apres', 'entre', 'depuis', 'jusqu_a', 'inconnue']) {
      expect(lireBlocDate({ qualifier, raw: null, min: null, max: null, place: null })).not.toBeNull()
    }
  })

  it('ne rend jamais de sixième clé', () => {
    const bloc = lireBlocDate({ qualifier: 'annee', min: '1930-01-01', max: '1930-12-31', texte: '1930' })

    expect(bloc).not.toBeNull()
    expect(Object.keys(bloc!).sort()).toEqual(['max', 'min', 'place', 'qualifier', 'raw'])
  })

  it('ignore une nature de demande qu’il ne sait pas remettre en formulaire', () => {
    expect(analyserReprise({ kind: 'ajout_media' })).toBeNull()
    expect(analyserReprise(null)).toBeNull()
    expect(analyserReprise('texte')).toBeNull()
  })
})

describe('le corps de reprise réellement rendu par l’API', () => {
  /**
   * **Capturé sur l'API qui tourne**, au retour du `409 demande_caduque`
   * ci-contre. Deux écarts avec l'exemple du § 6.10, tous deux à supporter :
   *
   * 1. **`avertissements` est absent**, pas vide. Une modification n'a aucune
   *    référence à re-résoudre — seules les créations en ont. Une lecture qui
   *    supposerait la clé présente tomberait sur le cas le plus courant.
   * 2. **`brouillons` et `liens` sont là, vides.** On ne les lit pas pour une
   *    modification, mais leur présence confirme qu'un seul corps sert les six
   *    natures de demande : un seul mécanisme, comme le veut l'ADR 0004.
   */
  const CAPTURE = {
    kind: 'modification_personne',
    message: "l'acte donne une fourchette",
    soumettre: false,
    targetKind: 'personne',
    targetId: 46,
    ops: [
      {
        seq: 1,
        opKind: 'champ',
        subjectKind: 'personne',
        subjectId: 46,
        field: 'birth',
        valueKind: 'date_incertaine',
        // Rafraîchi par le serveur sur la valeur courante…
        oldValue: { raw: 'ABT 1812', qualifier: 'environ', min: '1812-01-01', max: '1812-12-31', place: null },
        newValue: { max: '1815-12-31', min: '1810-01-01', raw: 'BET 1810 AND 1815', place: null, qualifier: 'entre' },
        // …et la version au moment du `GET`, pas au moment où l'auteur lira.
        baseVersion: 3,
      },
    ],
    brouillons: [],
    liens: [],
    origine: { demandeId: 1, statut: 'caduque' },
  }

  it('reprend la proposition et l’origine, et rien d’autre', () => {
    const reprise = analyserReprise(CAPTURE)
    if (reprise === null || reprise.kind !== 'modification_personne') throw new Error('nature inattendue')

    expect(reprise.proposees['birth']).toEqual(CAPTURE.ops[0]!.newValue)
    expect(reprise.origine).toEqual({ demandeId: 1, statut: 'caduque' })
    expect(reprise.message).toBe("l'acte donne une fourchette")
  })

  it('laisse tomber le baseVersion du serveur — c’est la lecture de l’auteur qui fait foi', () => {
    // Le serveur a calculé `baseVersion: 3` au moment du `GET`. Le formulaire
    // relit la fiche à son ouverture et repart de **cette** version-là : c'est
    // celle que l'auteur a sous les yeux, et c'est ce que le § 6.2 demande.
    // Reprendre celle du corps ferait voyager une version que personne n'a vue.
    const reprise = analyserReprise(CAPTURE)
    if (reprise === null || reprise.kind !== 'modification_personne') throw new Error('nature inattendue')

    expect(JSON.stringify(reprise)).not.toMatch(/baseVersion|oldValue/)
  })

  it('supporte l’absence de la clé avertissements', () => {
    const reprise = analyserReprise(CAPTURE)

    expect('avertissements' in CAPTURE).toBe(false)
    expect(reprise?.avertissements).toEqual([])
  })
})

describe('les trois formes d’avertissements que l’API rend réellement', () => {
  /**
   * Capturé sur l'API : une reprise de **modification** ne porte pas la clé du
   * tout, une reprise de **création** la porte à `null` quand il n'y a rien à
   * signaler, et le § 6.10 la montre en tableau quand une référence a disparu.
   *
   * Trois formes pour une seule information, et c'est précisément le genre de
   * détail qu'une lecture écrite d'après le contrat seul manque : `?? []` couvre
   * l'absence et le `null`, mais un `.map()` posé directement sur la valeur
   * tombe sur les deux.
   */
  const BASE = { kind: 'creation_personne', message: '', soumettre: false, brouillons: [], liens: [] }

  it.each([
    ['absente', {}],
    ['nulle', { avertissements: null }],
    ['vide', { avertissements: [] }],
  ])('« %s » se lit comme aucun avertissement', (_nom, fragment) => {
    expect(analyserReprise({ ...BASE, ...fragment })?.avertissements).toEqual([])
  })

  it('remonte une référence disparue quand il y en a une', () => {
    const reprise = analyserReprise({
      ...BASE,
      avertissements: [
        { code: 'reference_disparue', message: 'Jargon Sarriette n’existe plus dans l’arbre.', lien: 1 },
        // Sans message, l'avertissement n'a rien à dire : on ne l'affiche pas.
        { code: 'vide' },
      ],
    })

    expect(reprise?.avertissements).toHaveLength(1)
    expect(reprise?.avertissements[0]?.code).toBe('reference_disparue')
  })
})

/**
 * **Reprendre un ajout refusé qui portait un conjoint.**
 *
 * Le corps ci-dessous est celui que rend `GET /api/demandes/{id}/refaire` sur un
 * lien `conjoint_de` qui n'a **jamais été rompu** : `divorced: false`, et
 * pourtant un bloc `divorce` **non nul** — `qualifier: "inconnue"`, les quatre
 * autres clés à `null`.
 *
 * Ce bloc ne désigne aucune date, et le prendre pour une date rendait la reprise
 * impossible. La mécanique, en trois temps dont aucun n'est visible : la saisie
 * ressortait **vide mais d'origine non nulle** ; le champ se **verrouillait**,
 * parce que la relecture ne sait pas recomposer « rien » en cet objet-là ; et la
 * valeur d'origine repartait telle quelle, si bien que le dépôt concluait
 * qu'« une date de rupture est indiquée » et réclamait de l'effacer.
 *
 * L'auteur lisait donc « cochez « union rompue », ou effacez la date » devant un
 * champ vide et verrouillé. Le seul contournement — retirer le conjoint puis le
 * rajouter — ne se devine pas, et ce qu'on perd ici est exactement ce que le
 * § 6.10 dit de ne pas perdre : la contribution de quelqu'un dont la première
 * proposition a été refusée avec un motif, et qui essaie de la corriger.
 *
 * Les trois assertions couvrent les trois temps, parce que réparer un seul des
 * trois laisserait l'écran cassé d'une autre façon : sans le deuxième, le champ
 * reste verrouillé et personne ne peut plus **ajouter** une date de rupture.
 */
describe('reprendre un ajout refusé qui portait un conjoint', () => {
  const CONJOINT_JAMAIS_ROMPU = {
    kind: 'creation_personne',
    message: 'épouse de Jargon, le livret de famille est joint',
    soumettre: false,
    brouillons: [
      {
        localRef: '#p1',
        givenNames: 'Opale',
        surname: 'Bourrache',
        marriedName: null,
        nickname: null,
        sex: 'feminin',
        deceased: true,
        birth: null,
        death: null,
        note: null,
      },
    ],
    liens: [
      {
        seq: 1,
        kind: 'conjoint_de',
        subjectDraftRef: '#p1',
        otherPersonId: 55,
        marriage: { raw: '23 SEP 1927', qualifier: 'exacte', min: '1927-09-23', max: '1927-09-23', place: 'Saule' },
        divorced: false,
        // Le bloc qui ne dit rien, tel que l'API le rend.
        divorce: { raw: null, qualifier: 'inconnue', min: null, max: null, place: null },
      },
    ],
    origine: { demandeId: 8, statut: 'refusee' },
  }

  function union() {
    const reprise = analyserReprise(CONJOINT_JAMAIS_ROMPU) as RepriseCreation
    return reprise.saisie.unions[0]!
  }

  it('n’installe aucune date de rupture — il n’y en a aucune à effacer', () => {
    // `origine` est ce que `valeurAEnvoyer()` fait repartir tel quel. Non nulle,
    // elle transforme « pas de date » en « une date est indiquée ».
    expect(union().rompue).toBe(false)
    expect(union().rupture.origine).toBeNull()

    const repart = valeurAEnvoyer(union().rupture)
    expect(repart.valide).toBe(true)
    expect(repart.valide ? repart.valeur : undefined).toBeNull()
  })

  it('laisse le champ de rupture modifiable, pour qu’une date puisse y entrer', () => {
    // C'est `relectureFidele(origine)` qui verrouille le champ dans
    // `ChampDateIncertaine`. Verrouillé sur un bloc qui ne désigne rien, il
    // interdisait les deux gestes à la fois : saisir une date, et en effacer une.
    expect(relectureFidele(union().rupture.origine)).toBe(true)
  })

  it('se redépose sans réclamer d’effacer une date qui n’existe pas', () => {
    const reprise = analyserReprise(CONJOINT_JAMAIS_ROMPU) as RepriseCreation
    const resultat = corpsCreationPersonne(reprise.saisie, { message: reprise.message, soumettre: true })

    expect(resultat.valide).toBe(true)
    if (!resultat.valide) return

    const conjoint = resultat.corps.liens.find((lien) => lien.kind === 'conjoint_de')
    expect(conjoint?.divorced).toBe(false)
    // Le serveur compte les clés **non nulles** : ne pas écrire `divorce` n'est
    // pas la même chose que l'écrire à `null`.
    expect('divorce' in conjoint!).toBe(false)
  })

  it('ne jette pas pour autant la date de mariage, qui, elle, désigne quelque chose', () => {
    // La réparation porte sur les blocs qui n'affirment **rien**. Un bloc daté —
    // et même un bloc « inconnue » qui porte un lieu — reste une donnée que
    // quelqu'un a pris le temps d'écrire.
    const mariage = valeurAEnvoyer(union().mariage)

    expect(mariage.valide ? mariage.valeur?.raw : undefined).toBe('23 SEP 1927')
    expect(mariage.valide ? mariage.valeur?.place : undefined).toBe('Saule')
  })
})

/**
 * `phraseOrigine` — **l'écran doit dire l'état réel de la demande reprise**.
 *
 * L'écran de modification écrivait « devenue caduque » en dur. Or `RefaireDemande`
 * ouvre la reprise sur **deux** fins de course — `caduque` et `refusee` — et
 * l'auteur d'une demande refusée lisait donc qu'elle était caduque. C'est la
 * distinction même que l'ADR 0004 protège : « personne n'a refusé une demande
 * caduque, le monde a bougé ». Les deux cas appellent des relectures différentes,
 * et un seul des deux met un humain en cause.
 */
describe('la phrase qui annonce la demande reprise', () => {
  it('dit « refusée » quand la demande reprise a été refusée', () => {
    const phrase = phraseOrigine({ demandeId: 14, statut: 'refusee' })

    expect(phrase).toBe('Cette demande reprend la demande n° 14, refusée.')
    // La faute d'origine, nommément : elle annonçait une caducité à l'auteur
    // d'une demande que quelqu'un avait tranchée.
    expect(phrase).not.toContain('caduque')
  })

  it('dit « devenue caduque » quand la fiche a bougé sous la demande', () => {
    const phrase = phraseOrigine({ demandeId: 8, statut: 'caduque' })

    expect(phrase).toBe('Cette demande reprend la demande n° 8, devenue caduque.')
    expect(phrase).not.toContain('refusée')
  })

  it('ne qualifie rien sur un statut qu’il ne connaît pas — `lireOrigine` ne le contrôle pas', () => {
    // `statut` arrive d'un `as StatutDemande` non vérifié : il peut être vide si
    // le serveur change. Se taire est honnête ; deviner ne l'est pas.
    const vide = phraseOrigine({ demandeId: 3, statut: '' as StatutDemande })

    expect(vide).toBe('Cette demande reprend la demande n° 3.')
    expect(vide).not.toContain('caduque')
    expect(vide).not.toContain('refusée')
  })

  it('porte le numéro de la demande reprise, qui est la seule donnée de la phrase', () => {
    expect(phraseOrigine({ demandeId: 271, statut: 'caduque' })).toContain('n° 271')
  })
})
