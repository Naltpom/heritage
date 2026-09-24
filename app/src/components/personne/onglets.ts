/**
 * Les onglets de la fiche — **et pourquoi ils voyagent dans la requête.**
 *
 * La fiche est une seule page, `/personnes/:id` (route `personne.detail`). Son
 * nom de route est un contrat avec les notifications, qui y renvoient : on n'en
 * ajoute donc **aucun**. L'onglet est un état d'affichage, et il voyage dans la
 * requête — `?onglet=famille | sources | documents | demandes` —, ce qui lui
 * donne une URL qu'on peut partager ou recharger, et un « précédent » du
 * navigateur qui revient à l'onglet d'avant. Identité est le défaut : il n'a
 * **pas** de clé, pour que l'URL nue de la fiche reste l'URL canonique.
 *
 * Module pur : la décision « quel onglet ouvrir pour cette requête et cette
 * fiche » se prouve sans monter la vue (`fiche-page.test.ts`).
 *
 * **L'onglet Demandes n'existe que si la fiche en porte.** `?onglet=demandes`
 * sur une fiche sans demande en attente — un lien de notification ancien, une
 * demande décidée entre-temps — retombe sur Identité plutôt que d'ouvrir un
 * onglet vide qu'on ne voit pas dans la rangée.
 *
 * Les compteurs viennent de la charge utile, et de rien d'autre : ce que la
 * fiche porte, pas ce qu'on suppose exister ailleurs.
 */
import type { FichePersonneComplete } from '@/lib/api'

export type OngletFiche = 'identite' | 'famille' | 'sources' | 'documents' | 'demandes'

export interface DescripteurOnglet {
  cle: OngletFiche
  /** Le libellé desktop : « Sources et notes ». */
  libelle: string
  /** Le libellé de la rangée mobile, plus étroite : « Sources ». */
  libelleCourt: string
  /** `null` : l'onglet n'affiche pas de compteur (Identité, Famille). */
  compte: number | null
}

const VALEURS: readonly OngletFiche[] = ['identite', 'famille', 'sources', 'documents', 'demandes']

/** Les onglets de **cette** fiche, dans l'ordre de la rangée. */
export function ongletsDeFiche(fiche: FichePersonneComplete): DescripteurOnglet[] {
  const onglets: DescripteurOnglet[] = [
    { cle: 'identite', libelle: 'Identité', libelleCourt: 'Identité', compte: null },
    { cle: 'famille', libelle: 'Famille', libelleCourt: 'Famille', compte: null },
    {
      cle: 'sources',
      libelle: 'Sources et notes',
      libelleCourt: 'Sources',
      compte: fiche.citations.length + fiche.notes.length,
    },
    {
      cle: 'documents',
      libelle: 'Preuves et documents',
      libelleCourt: 'Documents',
      compte: fiche.medias.length,
    },
  ]
  if (fiche.demandesEnAttente.length > 0) {
    onglets.push({
      cle: 'demandes',
      libelle: 'Demandes',
      libelleCourt: 'Demandes',
      compte: fiche.demandesEnAttente.length,
    })
  }
  return onglets
}

/**
 * L'onglet à ouvrir, depuis la valeur brute de `route.query.onglet`.
 *
 * Tolérante : une valeur inconnue, répétée (`?onglet=a&onglet=b`) ou absente
 * ouvre Identité. Une requête ne fait jamais tomber l'écran.
 */
export function ongletDepuisRequete(valeur: unknown, fiche: FichePersonneComplete): OngletFiche {
  const brute = Array.isArray(valeur) ? valeur[0] : valeur
  if (typeof brute !== 'string' || !(VALEURS as readonly string[]).includes(brute)) return 'identite'
  const onglet = brute as OngletFiche
  if (onglet === 'demandes' && fiche.demandesEnAttente.length === 0) return 'identite'
  return onglet
}

/**
 * Le glissement du doigt sur la fiche (téléphone) : `1` vers l'onglet suivant
 * (doigt vers la gauche), `-1` vers le précédent, `0` si ce n'est pas un
 * glissement d'onglet. Il faut un geste franc — au moins 60 px, et nettement
 * plus horizontal que vertical — pour ne jamais voler un défilement de la page.
 */
export function sensDuGlissement(dx: number, dy: number): -1 | 0 | 1 {
  if (Math.abs(dx) < 60 || Math.abs(dx) < 1.5 * Math.abs(dy)) return 0
  return dx < 0 ? 1 : -1
}

/** L'onglet voisin dans la rangée, ou `null` au bout (on ne boucle pas). */
export function ongletVoisin(
  onglets: readonly DescripteurOnglet[],
  actif: OngletFiche,
  sens: -1 | 1,
): OngletFiche | null {
  const i = onglets.findIndex((o) => o.cle === actif)
  return onglets[i + sens]?.cle ?? null
}

/** La requête qui ouvre cet onglet. Identité, le défaut, n'a pas de clé. */
export function requeteDOnglet(onglet: OngletFiche): Record<string, string> {
  return onglet === 'identite' ? {} : { onglet }
}
