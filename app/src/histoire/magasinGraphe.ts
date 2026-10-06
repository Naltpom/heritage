/**
 * Le graphe de l'arbre et sa disposition, **gardés d'une visite à l'autre**.
 *
 * Le contrat (§ 5.2, étape 3) : un `304` veut dire « le client garde son dessin en
 * place ; il ne le reconstruit pas, il ne le vide pas ». Encore faut-il avoir
 * gardé quelque chose : la vue est démontée à chaque départ vers une fiche, et un
 * `304` reçu au retour ne rendrait rien à afficher. Le graphe **et** sa disposition
 * (`disposerLignees`, le calcul le plus lourd de l'écran) vivent donc ici, à la
 * portée du module ; un `304` les reprend tels quels, sans rien recalculer.
 *
 * **Oublié à tout changement de session.** La charge dépend du lecteur — son cercle,
 * ses demandes en attente, le membre emprunté sous « voir comme », le compte
 * incarné (ADR 0010). Le module de session oublie alors tous les `ETag`
 * (`oublierEtag()`), et ce magasin s'y abonne (`surOubliEtag`) : un lecteur ne voit
 * jamais l'arbre du précédent, pas même le temps d'une revalidation. Une réponse
 * partie avant le changement et arrivée après est jetée (`generation`).
 *
 * Une écriture (`invaliderGraphe()`) ne vide rien : l'arbre reste affiché, et la
 * prochaine revalidation rend un `200` qui le remplace.
 */
import { chargerGraphe, invaliderGraphe, surOubliEtag, type Graphe } from '@/lib/api'
import { sansFamillesParAlliance } from './alliances'
import { auFormat, type FormatDeCarte } from './cartes'
import { disposerLignees, sansFamilleDuConjoint, type DispositionHistoire } from './lignees'

/** Les réglages du lecteur qui changent la disposition (les autres filtres estompent). */
export interface ReglagesDeDisposition {
  avecConjoint: boolean
  avecAlliances: boolean
  alliancesDansLArbre: boolean
  fratriesParNaissance: boolean
  format: FormatDeCarte
}

export type ResultatDuMagasin =
  /** `304` : le graphe en mémoire est à jour. */
  | { statut: 'inchange'; graphe: Graphe }
  /** `200` : un graphe neuf, à redisposer. */
  | { statut: 'nouveau'; graphe: Graphe }
  /** La session a changé pendant l'appel : la réponse a été jetée. */
  | { statut: 'perime' }

interface DispositionGardee {
  graphe: Graphe
  cle: string
  valeur: DispositionHistoire
}

let graphe: Graphe | null = null
let disposition: DispositionGardee | null = null
/** Avancée à chaque oubli : une réponse d'une génération passée ne s'écrit pas. */
let generation = 0
let enVol: { generation: number; promesse: Promise<ResultatDuMagasin> } | null = null

/** Le graphe gardé, ou `null` (premier passage, ou session changée depuis). */
export function grapheEnMemoire(): Graphe | null {
  return graphe
}

/** Oublie tout. Appelé par l'abonnement ci-dessous ; exporté pour les tests. */
export function viderMagasinGraphe(): void {
  graphe = null
  disposition = null
  enVol = null
  generation++
}

surOubliEtag((cle) => {
  // Tous les `ETag` oubliés d'un coup : c'est un changement de session.
  if (cle === undefined) viderMagasinGraphe()
})

/**
 * Revalide le graphe (`If-None-Match`). Deux appels simultanés — le montage et le
 * retour d'onglet — partagent la même requête.
 */
export function revaliderGraphe(): Promise<ResultatDuMagasin> {
  if (enVol !== null && enVol.generation === generation) return enVol.promesse
  const depart = generation
  const promesse = (async (): Promise<ResultatDuMagasin> => {
    // Sans graphe en mémoire, un `304` ne rendrait rien à dessiner : on demande un `200`.
    if (graphe === null) invaliderGraphe()
    try {
      const reponse = await chargerGraphe()
      if (depart !== generation) {
        // L'`ETag` retenu est celui du lecteur précédent : on ne revalidera pas contre lui.
        invaliderGraphe()
        return { statut: 'perime' }
      }
      if (reponse.statut === 'inchange') {
        return graphe === null ? { statut: 'perime' } : { statut: 'inchange', graphe }
      }
      graphe = reponse.donnees
      disposition = null
      return { statut: 'nouveau', graphe: reponse.donnees }
    } finally {
      // Une seule requête en vol par génération : c'est forcément celle-ci.
      if (enVol?.generation === depart) enVol = null
    }
  })()
  enVol = { generation: depart, promesse }
  return promesse
}

/** La clé d'un jeu de réglages : deux réglages égaux donnent la même disposition. */
export function cleDesReglages(r: ReglagesDeDisposition): string {
  return [r.avecConjoint, r.avecAlliances, r.alliancesDansLArbre, r.fratriesParNaissance, r.format].join('|')
}

/**
 * La disposition d'un graphe pour des réglages — **recalculée seulement si l'un
 * des deux a changé**. Deux bascules RETIRENT des fiches avant la disposition
 * (« Famille du conjoint », « Familles par alliance »), pour que l'arbre se
 * resserre ; le format change la taille des boîtes.
 */
export function disposer(g: Graphe, r: ReglagesDeDisposition): DispositionHistoire {
  const cle = cleDesReglages(r)
  if (disposition !== null && disposition.graphe === g && disposition.cle === cle) return disposition.valeur
  // Les alliances d'abord, classées sur le graphe ENTIER : c'est lui que le serveur a classé.
  const sansAlliances = r.avecAlliances ? g : sansFamillesParAlliance(g)
  const filtre = r.avecConjoint ? sansAlliances : sansFamilleDuConjoint(sansAlliances)
  // Les boîtes en dernier : le tri des familles ne lit que les liens, jamais `w` ni `h`.
  const valeur = disposerLignees(auFormat(filtre, r.format), {
    alliances: r.alliancesDansLArbre ? 'dans-l-arbre' : 'a-part',
    fratriesParNaissance: r.fratriesParNaissance,
  })
  // On ne garde que la disposition du graphe en mémoire : un graphe d'ailleurs (un test,
  // une réponse jetée) ne doit pas l'évincer.
  if (g === graphe) disposition = { graphe: g, cle, valeur }
  return valeur
}
