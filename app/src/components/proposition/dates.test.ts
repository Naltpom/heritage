import { describe, expect, it } from 'vitest'
import type { BlocDate } from '@/lib/api'
import {
  analyserBorne,
  composerBlocDate,
  designeUneDate,
  memeValeurDate,
  relectureFidele,
  saisieDepuisBloc,
  saisieVide,
  sansTexte,
  valeurAEnvoyer,
  type SaisieDate,
} from './dates'

/**
 * Ce que ces tests protègent : **une fourchette ne s'aplatit jamais**.
 *
 * C'est la règle de fond du § 4 du dossier de mission, et elle ne se défend pas
 * toute seule : la pente naturelle d'un formulaire est de réclamer un jour, un
 * mois et une année, puis de « ranger » ce qu'on a en date ferme. Ce corpus est
 * fait de `BET 1901 AND 1909`, de `ABT 1889` et de `BEF 1931` ; l'objet valeur
 * serveur n'a délibérément ni `getDate()` ni `getYear()`, et le front ne doit
 * pas introduire l'aplatissement par la porte de la saisie.
 */

function saisie(modifications: Partial<SaisieDate>): SaisieDate {
  return { ...saisieVide(), ...modifications }
}

/** Le cas nominal ne lève jamais : un échec ici veut dire que la saisie a été
 *  refusée, ce qui est une information différente d'une valeur fausse. */
function valeurDe(entree: SaisieDate) {
  const resultat = composerBlocDate(entree)
  if (!resultat.valide) throw new Error(`saisie refusée : ${resultat.message}`)
  return resultat.valeur
}

describe('la fourchette, de bout en bout', () => {
  it('rend les cinq clés du § 14.2 sur BET 1901 AND 1909', () => {
    const valeur = valeurDe(
      saisie({ forme: 'entre', debut: '1901', fin: '1909', lieu: 'Saule, Ombrage, Sylvanie' }),
    )

    expect(valeur).toEqual({
      raw: 'BET 1901 AND 1909',
      qualifier: 'entre',
      min: '1901-01-01',
      max: '1909-12-31',
      place: 'Saule, Ombrage, Sylvanie',
    })
  })

  it('refuse une fourchette dont les bornes ne s’ordonnent pas', () => {
    // `ck_…_intervalle` exige `min < max` **strictement**. Le refus arrive ici,
    // avec la phrase qui dit quoi faire, plutôt qu'en 422 après l'envoi.
    const resultat = composerBlocDate(saisie({ forme: 'entre', debut: '1909', fin: '1901' }))

    expect(resultat.valide).toBe(false)
    expect(resultat.valide ? '' : resultat.message).toMatch(/postérieure/i)
  })

  it('refuse une fourchette dont les deux bornes sont la même année', () => {
    // Deux bornes identiques ne font pas une fourchette : c'est une date, et le
    // message doit renvoyer vers la bonne forme plutôt que laisser bricoler.
    const resultat = composerBlocDate(saisie({ forme: 'entre', debut: '1901', fin: '1901' }))

    expect(resultat.valide).toBe(false)
    expect(resultat.valide ? '' : resultat.message).toMatch(/Date connue/)
  })
})

describe('ce n’est pas min/max qui porte l’incertitude, c’est qualifier', () => {
  it('donne à une année seule des bornes larges — et le qualificatif « annee »', () => {
    const valeur = valeurDe(saisie({ forme: 'connue', debut: '1930' }))

    // Le piège n° 1 du § 14.3 : `min ≠ max` ne veut **pas** dire « fourchette ».
    // Un client qui le croirait classerait les trois quarts de l'arbre en
    // incertain. Le test « est-ce une fourchette » est `qualifier === 'entre'`.
    expect(valeur?.min).toBe('1930-01-01')
    expect(valeur?.max).toBe('1930-12-31')
    expect(valeur?.qualifier).toBe('annee')
    expect(valeur?.qualifier).not.toBe('entre')
  })

  it('ne produit « exacte » que si le jour a réellement été saisi', () => {
    expect(valeurDe(saisie({ forme: 'connue', debut: '11/03/1930' }))).toEqual({
      raw: '11 MAR 1930',
      qualifier: 'exacte',
      min: '1930-03-11',
      max: '1930-03-11',
      place: null,
    })
  })

  it('n’élargit jamais ABT', () => {
    // « La source dit vers 1934, elle ne dit pas entre 1929 et 1939. » Une
    // tolérance serait le choix du lecteur, pas une donnée.
    const valeur = valeurDe(saisie({ forme: 'environ', debut: '1934' }))

    expect(valeur).toEqual({
      raw: 'ABT 1934',
      qualifier: 'environ',
      min: '1934-01-01',
      max: '1934-12-31',
      place: null,
    })
  })
})

describe('les bornes exclues', () => {
  it('place BEF 12 DEC 1920 à la veille, comme le § 14.3', () => {
    const valeur = valeurDe(saisie({ forme: 'avant', debut: '12/12/1920' }))

    expect(valeur?.min).toBeNull()
    expect(valeur?.max).toBe('1920-12-11')
    expect(valeur?.raw).toBe('BEF 12 DEC 1920')
  })

  it('place AFT 1900 au 1ᵉʳ janvier suivant', () => {
    const valeur = valeurDe(saisie({ forme: 'apres', debut: '1900' }))

    expect(valeur?.min).toBe('1901-01-01')
    expect(valeur?.max).toBeNull()
    expect(valeur?.raw).toBe('AFT 1900')
  })
})

describe('l’absence de date', () => {
  it('ne propose aucune date quand rien n’est saisi', () => {
    expect(valeurDe(saisieVide())).toBeNull()
  })

  it('garde le lieu quand seule la date manque', () => {
    // « Né à Saule, date inconnue » est le cas courant de ce corpus. Perdre le
    // lieu parce que la date manque, c'est perdre une donnée que quelqu'un a
    // pris le temps d'écrire.
    const valeur = valeurDe(saisie({ forme: 'inconnue', lieu: 'Saule' }))

    expect(valeur).toEqual({
      raw: null,
      qualifier: 'inconnue',
      min: null,
      max: null,
      place: 'Saule',
    })
  })
})

describe('l’analyse d’une borne', () => {
  it('accepte l’année, la date française et la date ISO', () => {
    expect(analyserBorne('1930').valide).toBe(true)
    expect(analyserBorne('11/03/1930').valide).toBe(true)
    expect(analyserBorne('11.03.1930').valide).toBe(true)
    expect(analyserBorne('1930-03-11').valide).toBe(true)
  })

  it('accepte le mois seul comme une précision à part entière', () => {
    // Ni un jour inventé au 1ᵉʳ, ni l'année entière : `JAN 1920` vaut
    // 1920-01-01 → 1920-01-31. Le refuser faisait disparaître du formulaire des
    // dates que l'import avait pourtant lues correctement.
    expect(analyserBorne('03/1930').valide).toBe(true)
    expect(analyserBorne('13/1930').valide).toBe(false)
  })

  it('refuse un jour qui n’existe pas', () => {
    expect(analyserBorne('31/02/1930').valide).toBe(false)
    // 1900 n'est pas bissextile ; 2000 l'est. Le calcul passe par le calendrier,
    // pas par une règle « divisible par 4 » écrite à la main.
    expect(analyserBorne('29/02/1900').valide).toBe(false)
    expect(analyserBorne('29/02/2000').valide).toBe(true)
  })
})

describe('texte, la sixième clé', () => {
  const recu: BlocDate = {
    raw: 'BET 1901 AND 1909',
    qualifier: 'entre',
    min: '1901-01-01',
    max: '1909-12-31',
    place: 'Saule',
    texte: 'entre 1901 et 1909',
  }

  it('ne repart jamais dans une requête', () => {
    // « Un back qui accepte `texte` en entrée accepte une sixième clé que la
    // comparaison JSONB de la détection de conflit fera diverger » (§ 14.1).
    const valeur = sansTexte(recu)

    expect('texte' in valeur).toBe(false)
    expect(Object.keys(valeur).sort()).toEqual(['max', 'min', 'place', 'qualifier', 'raw'])
  })

  it('est ignoré par la comparaison de deux blocs', () => {
    expect(memeValeurDate(sansTexte(recu), sansTexte({ ...recu, texte: 'autre chose' }))).toBe(true)
  })
})

describe('la provenance GEDCOM', () => {
  const recu: BlocDate = {
    raw: 'BET 1901 AND 1909',
    qualifier: 'entre',
    min: '1901-01-01',
    max: '1909-12-31',
    place: null,
    texte: 'entre 1901 et 1909',
  }

  it('survit intacte à un aller-retour sans modification', () => {
    // Sans cela, un champ que le contributeur n'a pas touché ressortirait
    // recomposé, et la comparaison du serveur y verrait une modification.
    const valeur = valeurDe(saisieDepuisBloc(recu))

    expect(valeur).toEqual(sansTexte(recu))
  })

  it('est recomposée dès que la date change', () => {
    // Garder l'ancienne chaîne ferait mentir la provenance : elle décrirait
    // autre chose que min/max.
    const relue = saisieDepuisBloc(recu)
    const valeur = valeurDe({ ...relue, fin: '1906' })

    expect(valeur?.raw).toBe('BET 1901 AND 1906')
    expect(valeur?.max).toBe('1906-12-31')
  })

  it('relit BEF sans décaler la borne affichée', () => {
    // `BEF 1920` porte `max = 1919-12-31`. Repartir de `max` afficherait
    // « avant 1919 », et le contributeur corrigerait une faute que personne
    // n'a commise.
    const relue = saisieDepuisBloc({
      raw: 'BEF 1920',
      qualifier: 'avant',
      min: null,
      max: '1919-12-31',
      place: null,
      // Sixième clé, obligatoire sur un bloc **reçu d'une fiche** (§ 14.1). Elle
      // ne repart jamais : `saisieDepuisBloc` passe par `sansTexte()`.
      texte: 'avant 1920',
    })

    expect(relue.forme).toBe('avant')
    expect(relue.debut).toBe('1920')
  })

  it('retombe sur les bornes quand il n’y a pas de provenance', () => {
    // Une date saisie ici même n'a pas de chaîne GEDCOM d'origine ; la relecture
    // doit quand même rendre une saisie fidèle.
    const relue = saisieDepuisBloc({
      raw: null,
      qualifier: 'environ',
      min: '1889-01-01',
      max: '1889-12-31',
      place: 'Saule',
      texte: 'vers 1889',
    })

    expect(relue.forme).toBe('environ')
    expect(relue.debut).toBe('1889')
    expect(relue.lieu).toBe('Saule')
  })
})


describe('le mois, troisième précision', () => {
  it('borne le mois entier et rend la syntaxe GEDCOM', () => {
    expect(valeurDe(saisie({ forme: 'connue', debut: '01/1920' }))).toEqual({
      raw: 'JAN 1920',
      qualifier: 'mois',
      min: '1920-01-01',
      max: '1920-01-31',
      place: null,
    })
  })

  it('compte les jours du mois, février bissextile compris', () => {
    expect(valeurDe(saisie({ forme: 'connue', debut: '02/1900' }))?.max).toBe('1900-02-28')
    expect(valeurDe(saisie({ forme: 'connue', debut: '02/2000' }))?.max).toBe('2000-02-29')
  })

  it('fait un aller-retour sans rien perdre', () => {
    // C'est **le** test qui ferme la faute : tant que `mois` manquait à la
    // saisie, ce champ ressortait vide, et un champ vide se compose en
    // « aucune date » — donc en proposition de suppression.
    const recu = {
      raw: 'JAN 1920',
      qualifier: 'mois',
      min: '1920-01-01',
      max: '1920-01-31',
      place: 'Saule',
      texte: 'janvier 1920',
    } as const

    const relue = saisieDepuisBloc(recu)

    expect(relue.debut).toBe('01/1920')
    expect(valeurDe(relue)).toEqual(sansTexte(recu))
  })
})

/**
 * **Le cas de perte de donnée, nommé et prouvé.**
 *
 * Le scénario, en une phrase : un membre ouvre la fiche de quelqu'un dont la
 * naissance est `JAN 1920`, corrige **un autre champ**, soumet — et la demande
 * propose d'effacer la date de naissance. Aucune erreur, aucun avertissement,
 * et l'administrateur valide une suppression que personne n'a demandée.
 *
 * Le mécanisme tient en trois pas : l'éditeur ne sait pas exprimer le
 * qualificatif → le champ ressort **vide** → un champ vide se compose en
 * « aucune date », c'est-à-dire en suppression.
 *
 * Deux remèdes étaient possibles. **Le refus** — interdire de modifier un champ
 * dont on ne sait rien faire — est visible, donc acceptable. **Apprendre la
 * forme** est meilleur, parce qu'il supprime la cause au lieu de la contenir :
 * `mois` est une précision légitime de ce corpus, et le contributeur qui la
 * connaît doit pouvoir l'écrire. C'est ce qui a été fait pour `mois`.
 *
 * Restent `depuis` et `jusqu_a`, que l'import initial a produits mais qu'un contributeur
 * n'écrit jamais : pour eux, c'est le refus — et il est verrouillé, pas vide.
 */
describe('le cas de perte de donnée : une date que l’éditeur ne sait pas rééditer', () => {
  const depuis = {
    raw: 'FROM 15 JUN 1900',
    qualifier: 'depuis',
    min: '1900-06-15',
    max: null,
    place: null,
  } as const

  it('reconnaît les formes que la saisie sait exprimer', () => {
    expect(relectureFidele(null)).toBe(true)
    expect(relectureFidele({ raw: '1987', qualifier: 'annee', min: '1987-01-01', max: '1987-12-31', place: null })).toBe(true)
    expect(relectureFidele({ raw: 'JAN 1920', qualifier: 'mois', min: '1920-01-01', max: '1920-01-31', place: null })).toBe(true)
    expect(relectureFidele({ raw: 'BEF 1920', qualifier: 'avant', min: null, max: '1919-12-31', place: null })).toBe(true)
  })

  it('reconnaît celles qu’elle ne sait pas', () => {
    // `FROM` et `TO` sont inclusifs ; les formes « après » et « avant » de la
    // saisie sont exclusives. Les relire par elles décalerait la borne d'un jour
    // et changerait le qualificatif — une modification que personne n'a demandée.
    expect(relectureFidele(depuis)).toBe(false)
  })

  it('renvoie la valeur d’origine intacte plutôt que de la vider', () => {
    // La conséquence utile : `construireOps` ne verra aucune différence, donc
    // aucun op, donc aucune suppression silencieuse.
    const resultat = valeurAEnvoyer(saisieDepuisBloc(depuis))

    expect(resultat.valide).toBe(true)
    expect(resultat.valide ? resultat.valeur : null).toEqual(depuis)
  })
})


describe('la réponse, qualificatif par qualificatif', () => {
  /** Ce que produirait un formulaire ouvert puis soumis **sans rien toucher**. */
  function allerRetour(bloc: Parameters<typeof saisieDepuisBloc>[0]) {
    return valeurAEnvoyer(saisieDepuisBloc(bloc))
  }

  const CAS = [
    ['exacte', { raw: '11 MAR 1930', qualifier: 'exacte', min: '1930-03-11', max: '1930-03-11', place: null }],
    ['annee', { raw: '1987', qualifier: 'annee', min: '1987-01-01', max: '1987-12-31', place: null }],
    ['mois', { raw: 'JAN 1920', qualifier: 'mois', min: '1920-01-01', max: '1920-01-31', place: null }],
    ['environ', { raw: 'ABT 1889', qualifier: 'environ', min: '1889-01-01', max: '1889-12-31', place: null }],
    ['entre', { raw: 'BET 1901 AND 1909', qualifier: 'entre', min: '1901-01-01', max: '1909-12-31', place: null }],
    ['avant', { raw: 'BEF 1920', qualifier: 'avant', min: null, max: '1919-12-31', place: null }],
    ['apres', { raw: 'AFT 1900', qualifier: 'apres', min: '1901-01-01', max: null, place: null }],
    ['depuis', { raw: 'FROM 15 JUN 1900', qualifier: 'depuis', min: '1900-06-15', max: null, place: null }],
    ['jusqu_a', { raw: 'TO 15 JUN 1900', qualifier: 'jusqu_a', min: null, max: '1900-06-15', place: null }],
    ['inconnue', { raw: null, qualifier: 'inconnue', min: null, max: null, place: null }],
  ] as const

  it.each(CAS)(
    '« %s » : ouvrir puis soumettre sans rien toucher ne change rien',
    (_nom, bloc) => {
      // **Le seul invariant qui compte, et il vaut pour les dix qualificatifs
      // du § 14.1** : rouvrir un formulaire sans rien modifier ne doit produire
      // aucune différence. Tant qu'il tient, aucun champ ne peut se transformer
      // seul en proposition de suppression ni en proposition de modification.
      const resultat = allerRetour(bloc)

      expect(resultat.valide).toBe(true)
      expect(resultat.valide ? resultat.valeur : undefined).toEqual(bloc)
    },
  )

  it.each(['depuis', 'jusqu_a'] as const)(
    '« %s » porte une borne nulle et ne se compose **pas** en « aucune date »',
    (qualificatif) => {
      // La question posée explicitement : une borne `null` se compose-t-elle en
      // « pas de date » par le même chemin que le mois ? **Non.** Le défaut
      // était d'une autre nature — la relecture les rendait en `apres` / `avant`,
      // qui sont **exclusifs** là où `FROM` / `TO` sont inclusifs : la borne
      // glissait d'un jour et le qualificatif changeait. Une modification
      // silencieuse, pas une suppression silencieuse. Le garde-fou couvre les
      // deux, et ce test fixe lequel est lequel.
      const bloc = CAS.find(([nom]) => nom === qualificatif)![1]
      const resultat = allerRetour(bloc)

      expect(resultat.valide ? resultat.valeur : undefined).not.toBeNull()
      expect(resultat.valide ? resultat.valeur?.qualifier : undefined).toBe(qualificatif)
      expect(relectureFidele(bloc)).toBe(false)
    },
  )

  it('« mois » est désormais éditable, et non plus seulement protégé', () => {
    // La différence entre contenir la faute et en supprimer la cause : un
    // contributeur qui connaît le mois peut l'écrire.
    const bloc = CAS.find(([nom]) => nom === 'mois')![1]

    expect(relectureFidele(bloc)).toBe(true)
    expect(saisieDepuisBloc(bloc).debut).toBe('01/1920')
  })

  it('aucun qualificatif ne rend un champ vide et modifiable à la fois', () => {
    // La conjonction exacte qui produisait la suppression : un champ **vide**
    // (donc composable en « aucune date ») et **modifiable** (donc non
    // verrouillé). Si un qualificatif futur réunit les deux, ce test le dit.
    for (const [nom, bloc] of CAS) {
      if (bloc.qualifier === 'inconnue') continue
      const relue = saisieDepuisBloc(bloc)
      const vide = relue.forme === 'inconnue' && relue.debut === ''
      expect(vide && relectureFidele(bloc), `${nom} : vide et modifiable`).toBe(false)
    }
  })
})

/**
 * **Un bloc qui n'affirme rien n'est pas une date.**
 *
 * `composerBlocDate()` écrit déjà la règle dans un sens : rien de saisi, ni date
 * ni lieu, rend `valeur: null` « et non un bloc `inconnue` ». Il manquait le
 * sens inverse, pour les blocs qui **arrivent** — et le manque n'était pas
 * théorique : `GET /api/demandes/{id}/refaire` rend un bloc `divorce` non nul,
 * `qualifier: "inconnue"` et tout le reste à `null`, sur un lien qui n'a jamais
 * été rompu. Pris pour une date, ce bloc verrouillait le champ et faisait
 * réclamer « effacez la date » devant un champ vide (voir `reprise.test.ts`).
 *
 * La frontière est celle de `composerBlocDate()`, pas une autre : **un lieu sans
 * date est une donnée**, et c'est le cas courant de ce corpus.
 */
describe('un bloc qui ne désigne rien', () => {
  const RIEN = { raw: null, qualifier: 'inconnue', min: null, max: null, place: null } as const

  it('ne désigne rien, et le dit', () => {
    expect(designeUneDate(RIEN)).toBe(false)
    expect(designeUneDate(null)).toBe(false)
  })

  it.each([
    ['un lieu seul', { ...RIEN, place: 'Saule, Ombrage, Sylvanie' }],
    ['une provenance seule', { ...RIEN, raw: 'ABT 1889' }],
    ['une borne basse', { ...RIEN, min: '1901-01-01' }],
    ['une borne haute', { ...RIEN, max: '1909-12-31' }],
  ])('%s suffit à désigner quelque chose', (_nom, bloc) => {
    // « Né à Saule, date inconnue » est une donnée que quelqu'un a pris le temps
    // d'écrire ; la perdre serait la faute symétrique de celle qu'on répare.
    expect(designeUneDate(bloc)).toBe(true)
  })

  it('ne se prononce jamais sur un bloc qui porte un autre qualificatif', () => {
    // Un qualificatif autre qu'`inconnue` **affirme** quelque chose, fût-ce mal :
    // ce prédicat n'est pas un contrôle de cohérence, et il ne doit pas le
    // devenir — ce serait une seconde règle de validité, concurrente du serveur.
    for (const qualifier of ['exacte', 'annee', 'mois', 'environ', 'avant', 'apres', 'entre', 'depuis', 'jusqu_a'] as const) {
      expect(designeUneDate({ ...RIEN, qualifier }), qualifier).toBe(true)
    }
  })

  it('accepte aussi un bloc de fiche, sa sixième clé comprise', () => {
    // `BlocDate` (six clés) et `ValeurDate` (cinq) passent tous deux : le
    // prédicat ne lit que les cinq du § 14.1, et `texte` ne désigne rien de plus.
    const deFiche: BlocDate = { ...RIEN, texte: '' }

    expect(designeUneDate(deFiche)).toBe(false)
  })
})

describe('un bloc « inconnue » vide, tel que la fiche le rend', () => {
  const vide = { raw: null, qualifier: 'inconnue', min: null, max: null, place: null } as const

  it('ne verrouille pas le champ : on peut y écrire la date trouvée', () => {
    expect(relectureFidele(vide)).toBe(true)
  })

  it('une date saisie part comme une vraie proposition', () => {
    const resultat = valeurAEnvoyer({ ...saisieDepuisBloc(vide), forme: 'connue', debut: '1887' })
    expect(resultat.valide ? resultat.valeur?.qualifier : undefined).toBe('annee')
  })

  it('laissé vide, il repart tel quel — aucun op fantôme', () => {
    const resultat = valeurAEnvoyer(saisieDepuisBloc(vide))
    expect(resultat.valide ? resultat.valeur : undefined).toEqual(vide)
  })
})
