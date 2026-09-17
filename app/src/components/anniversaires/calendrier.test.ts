/**
 * La grille du mois — le décalage du lundi, les 42 cases, et le 29 février.
 *
 * Les trois défauts que ces cas tiennent ont en commun de produire un calendrier
 * **plausible** : un mois décalé d'un jour, une grille qui change de hauteur, un
 * anniversaire déplacé d'un jour. Aucun des trois ne fait d'erreur à l'écran ;
 * tous les trois font mentir la page.
 *
 * Les dates de contrôle ont été prises sur un almanach, et non calculées avec la
 * fonction qu'elles vérifient.
 */
import { describe, expect, it } from 'vitest'
import {
  CASES_DE_GRILLE,
  JOURS_SEMAINE,
  cleJour,
  estBissextile,
  grilleDuMois,
  joursDuMois,
  type CaseJour,
} from './calendrier'

/** Un jour fixe, hors de tous les mois testés : aucun cas ci-dessous ne parle
 *  d'« aujourd'hui », et la suite ne doit pas changer de résultat selon l'heure
 *  à laquelle elle tourne. */
const JAMAIS = new Date(1970, 0, 1)

/** Les cases du mois lui-même, dans l'ordre. */
function duMois(annee: number, mois: number): CaseJour[] {
  return grilleDuMois(annee, mois, JAMAIS).filter((cellule) => cellule.dansLeMois)
}

/** La colonne (0 = lundi) où tombe un jour donné. */
function colonneDu(annee: number, mois: number, jour: number): number {
  const index = grilleDuMois(annee, mois, JAMAIS).findIndex(
    (cellule) => cellule.dansLeMois && cellule.jour === jour,
  )
  return index % 7
}

describe('la semaine commence le lundi', () => {
  it('place un 1er tombant un mardi en deuxième colonne', () => {
    /* 1er septembre 2026 : un mardi. Avec `getDay()` employé tel quel — 2 pour
       mardi — la case tomberait en troisième colonne, et tout le mois avec. */
    expect(colonneDu(2026, 9, 1)).toBe(1)
  })

  it('place un 1er tombant un dimanche en dernière colonne, et non en première', () => {
    /*
     * LE DÉFAUT LE PLUS RÉPANDU. `getDay()` rend **0** pour dimanche : sans le
     * `+ 6 % 7`, le mois ne serait pas décalé de six cases mais d'aucune, et il
     * commencerait sous « lundi ». C'est le seul jour de la semaine où l'erreur
     * se voit d'un coup d'œil, et c'est pour cela qu'on l'épingle ici.
     *
     * 1er février 2026 : un dimanche.
     */
    expect(colonneDu(2026, 2, 1)).toBe(6)
  })

  it('place un 1er tombant un lundi en première colonne, sans rang vide au-dessus', () => {
    /* 1er juin 2026 : un lundi. La grille commence donc au 1er, sans emprunt au
       mois précédent. */
    const grille = grilleDuMois(2026, 6, JAMAIS)
    expect(grille[0]?.dansLeMois).toBe(true)
    expect(grille[0]?.jour).toBe(1)
  })

  it('annonce les sept jours en commençant par lundi', () => {
    /* Les en-têtes et le décalage se contredisent en silence : une colonne
       « L » au-dessus d'un mois calé sur dimanche se lit comme un almanach. */
    expect(JOURS_SEMAINE.map((jour) => jour.long)).toEqual([
      'lundi',
      'mardi',
      'mercredi',
      'jeudi',
      'vendredi',
      'samedi',
      'dimanche',
    ])
  })
})

describe('la grille a toujours six rangs', () => {
  it('rend 42 cases quel que soit le mois — la hauteur ne bouge pas entre deux mois', () => {
    /*
     * LE DÉFAUT TENU. Un nombre de rangs calculé (« 5 rangs suffisent ce
     * mois-ci ») fait changer la hauteur de la grille d'un mois à l'autre :
     * tout ce qui est dessous saute, et les deux flèches se lisent comme
     * cassées. Les douze mois de deux années, dont une bissextile.
     */
    for (const annee of [2026, 2028]) {
      for (let mois = 1; mois <= 12; mois += 1) {
        expect(grilleDuMois(annee, mois, JAMAIS), `${annee}-${mois}`).toHaveLength(CASES_DE_GRILLE)
      }
    }
  })

  it('emprunte au mois précédent plutôt que de laisser des cases vides', () => {
    /* Mars 2026 commence un dimanche : six cases de tête, qui sont les 23 au 28
       février 2026 — et non six trous. */
    const grille = grilleDuMois(2026, 3, JAMAIS)
    expect(grille.slice(0, 6).map((cellule) => cellule.jour)).toEqual([23, 24, 25, 26, 27, 28])
    expect(grille.slice(0, 6).every((cellule) => cellule.mois === 2)).toBe(true)
    expect(grille.slice(0, 6).every((cellule) => !cellule.dansLeMois)).toBe(true)
  })

  it('emprunte au mois suivant, en passant l’année à la fin de décembre', () => {
    const grille = grilleDuMois(2026, 12, JAMAIS)
    const apres = grille.filter((cellule) => !cellule.dansLeMois && cellule.mois === 1)
    expect(apres.length).toBeGreaterThan(0)
    expect(apres.every((cellule) => cellule.annee === 2027)).toBe(true)
  })

  it('emprunte au mois précédent en passant l’année au début de janvier', () => {
    const grille = grilleDuMois(2026, 1, JAMAIS)
    const avant = grille.filter((cellule) => !cellule.dansLeMois && cellule.mois === 12)
    expect(avant.every((cellule) => cellule.annee === 2025)).toBe(true)
  })

  it('donne à chaque case la clé de son propre mois, et non du mois affiché', () => {
    /* Une case empruntée au 28 février porte `02-28`. Si elle portait la clé du
       mois affiché, elle afficherait les événements d'un autre jour — un défaut
       silencieux, puisque la case est peinte et plausible. */
    const grille = grilleDuMois(2026, 3, JAMAIS)
    expect(grille[0]?.cle).toBe('02-23')
    expect(grille[6]?.cle).toBe('03-01')
  })
})

describe('le 29 février', () => {
  it('a une case en 2028, qui est bissextile', () => {
    expect(estBissextile(2028)).toBe(true)
    expect(duMois(2028, 2)).toHaveLength(29)
    expect(duMois(2028, 2).at(-1)?.jour).toBe(29)
  })

  it('n’a aucune case le 29 février 2027 — et ne le déplace pas au 1er mars', () => {
    /*
     * LE DÉFAUT TENU. Reporter un anniversaire du 29 février au 28 ou au 1er
     * mars **invente une date**, ce que ce produit ne fait nulle part (§ 14). La
     * grille d'un février ordinaire s'arrête donc au 28 ; ce qui tombe le 29 est
     * listé sous la grille par `horsGrille()`.
     */
    const fevrier = duMois(2027, 2)
    expect(estBissextile(2027)).toBe(false)
    expect(fevrier).toHaveLength(28)
    expect(fevrier.some((cellule) => cellule.jour === 29)).toBe(false)

    /* Et le 1er mars n'a pas gagné de case en double au passage. */
    const mars = duMois(2027, 3)
    expect(mars.filter((cellule) => cellule.jour === 1)).toHaveLength(1)
  })

  it('applique la règle grégorienne entière : 1900 non, 2000 oui', () => {
    /* Un `annee % 4 === 0` seul se trompe trois fois par quatre siècles — assez
       rare pour traverser une relecture, et assez proche pour atteindre une
       donnée généalogique, qui porte des années du XIXe siècle. */
    expect(estBissextile(1900)).toBe(false)
    expect(estBissextile(2000)).toBe(true)
    expect(estBissextile(2024)).toBe(true)
    expect(estBissextile(2025)).toBe(false)
    expect(joursDuMois(1900, 2)).toBe(28)
    expect(joursDuMois(2000, 2)).toBe(29)
  })

  it('donne les longueurs des douze mois', () => {
    expect([...Array(12).keys()].map((index) => joursDuMois(2026, index + 1))).toEqual([
      31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31,
    ])
  })
})

describe('aujourd’hui', () => {
  it('marque la case du jour qu’on lui donne, et une seule', () => {
    const grille = grilleDuMois(2026, 3, new Date(2026, 2, 14))
    const marquees = grille.filter((cellule) => cellule.aujourdhui)
    expect(marquees).toHaveLength(1)
    expect(marquees[0]?.jour).toBe(14)
  })

  it('ne marque rien dans un mois qui n’est pas celui du jour', () => {
    expect(grilleDuMois(2026, 7, new Date(2026, 2, 14)).some((c) => c.aujourdhui)).toBe(false)
  })

  it('marque aussi une case empruntée au mois voisin : elle est bien aujourd’hui', () => {
    /* Le 28 février 2026 paraît en tête de la grille de mars. Ce jour-là, il est
       aujourd'hui, et la case le dit — atténuée, mais pas muette. */
    const grille = grilleDuMois(2026, 3, new Date(2026, 1, 28))
    const marquee = grille.find((cellule) => cellule.aujourdhui)
    expect(marquee?.mois).toBe(2)
    expect(marquee?.dansLeMois).toBe(false)
  })
})

describe('la clé d’un jour', () => {
  it('complète à deux chiffres, pour que la clé du client et celle du serveur soient la même', () => {
    expect(cleJour(2, 9)).toBe('02-09')
    expect(cleJour(12, 31)).toBe('12-31')
  })
})
