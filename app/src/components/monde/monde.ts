/**
 * Le **Monde** — la maquette 3D du suivi : ce qui se calcule sans WebGL.
 *
 * ────────────────────────────────────────────────────────────────────
 * Une île flottante, un objet par partie du système. Chaque objet ne bouge
 * que si une donnée réelle le fait bouger : ce fichier est la traduction de
 * l'une en l'autre, et `monde.test.ts` la tient. La scène (`scene.ts`) ne lit
 * que l'`EtatDuMonde` rendu ici — jamais l'API, jamais une ligne de journal.
 *
 * Les sept zones et leurs données :
 *
 * | Zone       | Objet                      | Donnée                                     |
 * |------------|----------------------------|--------------------------------------------|
 * | `maison`   | la maison des archives     | sonde `/api/sante` (état, latence)         |
 * | `file`     | le tapis « À traiter »     | demandes, inscriptions, RGPD en attente    |
 * | `comptes`  | la tour des comptes        | comptes actifs, connexions du jour         |
 * | `portail`  | le portail des invitations | liens actifs, invitations utilisées du jour|
 * | `nuage`    | le nuage du modèle         | appels au modèle de langage                |
 * | `arbre`    | le grand arbre             | chantier : fiches à compléter              |
 * | `phare`    | le phare                   | connexions échouées sur 24 h               |
 * ────────────────────────────────────────────────────────────────────
 */

import type { Chantier, CompteursUtilisateurs, LigneDuJournal, MesureDeSante } from '@/lib/api'
import { LATENCE_LENTE_MS, SEUIL_CONNEXIONS_ECHOUEES } from '@/components/suivi/suivi'

export type IdZone = 'maison' | 'file' | 'comptes' | 'portail' | 'nuage' | 'arbre' | 'phare'

export interface DefinitionDeZone {
  id: IdZone
  nom: string
  /** Une teinte d'aquarelle, en CSS : la puce, le dock, l'icône du panneau. */
  couleur: string
  /** La phrase du panneau : ce que l'objet représente, en clair. */
  phrase: string
}

/** Dans l'ordre du dock et de la visite automatique. */
export const ZONES: readonly DefinitionDeZone[] = [
  {
    id: 'maison',
    nom: 'Maison des archives',
    couleur: '#8FA3AD',
    phrase:
      'L’API et sa base. La cheminée fume à chaque réponse, d’autant plus haut que la réponse a été lente ; le gyrophare s’allume si la base ne répond plus.',
  },
  {
    id: 'file',
    nom: 'Tapis « À traiter »',
    couleur: '#C9955A',
    phrase:
      'Une carte par décision qui attend : demandes (ocre), inscriptions à rattacher (bleu-gris), demandes RGPD (rouille). Plus elle attend, plus elle approche du bureau.',
  },
  {
    id: 'comptes',
    nom: 'Tour des comptes',
    couleur: '#A9B98F',
    phrase: 'Un étage pour cinq comptes actifs, une fenêtre allumée par connexion aujourd’hui.',
  },
  {
    id: 'portail',
    nom: 'Portail des invitations',
    couleur: '#D4A28A',
    phrase:
      'On n’entre sur l’arbre que par un lien d’invitation. Le portail scintille tant qu’un lien est actif ; chaque invitation utilisée aujourd’hui y laisse une lanterne.',
  },
  {
    id: 'nuage',
    nom: 'Nuage du modèle',
    couleur: '#A9BCC4',
    phrase:
      'Le modèle de langage, hors de l’île : il pleut quand un appel a eu lieu dans les dix dernières minutes, et chaque nouvel appel fait un éclair. Le journal ne garde que des comptes, jamais le contenu.',
  },
  {
    id: 'arbre',
    nom: 'Le grand arbre',
    couleur: '#7E9468',
    phrase: 'Le chantier de l’arbre : un fruit éteint pour dix fiches à compléter, une feuille claire pour chaque demande validée ce mois-ci.',
  },
  {
    id: 'phare',
    nom: 'Le phare',
    couleur: '#B5653A',
    phrase: `Il veille sur les connexions. Son feu passe à l’orange à partir de ${SEUIL_CONNEXIONS_ECHOUEES} connexions échouées en 24 heures.`,
  },
]

export function zone(id: IdZone): DefinitionDeZone {
  return ZONES.find((z) => z.id === id)!
}

/* ───────────────── L'état du monde ───────────────── */

export type NatureDeCarte = 'demande' | 'inscription' | 'rgpd'

export interface CarteDuTapis {
  cle: string
  nature: NatureDeCarte
  /** 0 = vient d'arriver (début du tapis), 1 = au bout, devant le bureau. */
  avancee: number
}

export interface EtatDuMonde {
  maison: { connue: boolean; ok: boolean; /** 0 → rapide, 1 → au seuil de lenteur ou au-delà. */ lenteur: number }
  file: { cartes: CarteDuTapis[]; total: number | null }
  comptes: { etages: number; fenetres: number; allumees: number }
  portail: { actives: number; lanternes: number }
  nuage: { pluie: boolean; /** L'identifiant du dernier appel : un nouvel identifiant fait un éclair. */ dernierAppel: number | null; parJour: number }
  arbre: { connu: boolean; fruits: number; feuilles: number }
  phare: { alerte: boolean; echecs: number }
}

export interface DonneesDuMonde {
  sante: MesureDeSante | null
  /** Ce qui attend, avec depuis quand (ISO) — déjà assemblé par l'écran. */
  attente: { cle: string; nature: NatureDeCarte; depuis: string | null }[]
  aTraiter: number | null
  comptes: CompteursUtilisateurs | null
  journal: readonly LigneDuJournal[]
  appels: readonly LigneDuJournal[]
  echecs24h: number
  invitationsActives: number | null
  chantier: Chantier | null
}

/** Au-delà de ce nombre, des cartes se cacheraient l'une l'autre : le chiffre dit le reste. */
export const CARTES_MAX = 12
/** Une carte qui attend depuis ce temps est au bout du tapis. */
export const ATTENTE_PLEINE_H = 72
/** Des fenêtres au-delà, la tour serait un mur de lumière. */
const FENETRES_PAR_ETAGE = 6
const ETAGES_MAX = 6
/** La pluie dure ce temps après un appel. */
export const PLUIE_MS = 10 * 60_000

function memeJourLocal(iso: string, maintenant: Date): boolean {
  const d = new Date(iso)
  return d.getFullYear() === maintenant.getFullYear() && d.getMonth() === maintenant.getMonth() && d.getDate() === maintenant.getDate()
}

/** La clé `AAAA-MM` du mois courant, en heure locale — celle du chantier. */
function moisCourant(maintenant: Date): string {
  return `${maintenant.getFullYear()}-${String(maintenant.getMonth() + 1).padStart(2, '0')}`
}

export function etatDuMonde(d: DonneesDuMonde, maintenant: Date): EtatDuMonde {
  const t = maintenant.getTime()

  const lenteur = d.sante?.ok ? Math.min(1, d.sante.latenceMs / LATENCE_LENTE_MS) : 0

  const cartes = d.attente.slice(0, CARTES_MAX).map((a) => {
    const heures = a.depuis === null ? 0 : Math.max(0, (t - new Date(a.depuis).getTime()) / 3_600_000)
    return { cle: a.cle, nature: a.nature, avancee: Math.min(1, heures / ATTENTE_PLEINE_H) }
  })

  const actifs = d.comptes?.actif ?? 0
  const etages = Math.min(ETAGES_MAX, Math.max(1, Math.ceil(actifs / 5)))
  const fenetres = etages * FENETRES_PAR_ETAGE
  const connexions = d.journal.filter((l) => l.action === 'connexion' && memeJourLocal(l.occurredAt, maintenant)).length

  const utilisees = d.journal.filter((l) => l.action === 'invitation_utilisee' && memeJourLocal(l.occurredAt, maintenant)).length

  const dernier = d.appels[0]
  const pluie = dernier !== undefined && t - new Date(dernier.occurredAt).getTime() < PLUIE_MS
  const parJour = d.appels.filter((l) => t - new Date(l.occurredAt).getTime() < 86_400_000).length

  const c = d.chantier
  const aCompleter = c === null ? 0 : c.fiches.sansDateDeNaissance.n + c.fiches.sansParents.n + c.fiches.sansSource.n
  const validees = c?.activite.demandesParMois.find((m) => m.mois === moisCourant(maintenant))?.validees ?? 0

  return {
    maison: { connue: d.sante !== null, ok: d.sante?.ok ?? false, lenteur },
    file: { cartes, total: d.aTraiter },
    comptes: { etages, fenetres, allumees: Math.min(fenetres, connexions) },
    portail: { actives: d.invitationsActives ?? 0, lanternes: Math.min(8, utilisees) },
    nuage: { pluie, dernierAppel: dernier?.id ?? null, parJour },
    arbre: { connu: c !== null, fruits: Math.min(24, Math.ceil(aCompleter / 10)), feuilles: Math.min(30, validees) },
    phare: { alerte: d.echecs24h >= SEUIL_CONNEXIONS_ECHOUEES, echecs: d.echecs24h },
  }
}

/** Le chiffre court de la puce flottante d'une zone. `–` tant que la donnée manque. */
export function valeurDeZone(id: IdZone, e: EtatDuMonde, d: DonneesDuMonde): string {
  const n = (x: number | null | undefined): string => (x === null || x === undefined ? '–' : x.toLocaleString('fr-FR'))
  switch (id) {
    case 'maison':
      if (!e.maison.connue) return '–'
      return d.sante?.ok ? `${d.sante.latenceMs} ms` : 'ne répond pas'
    case 'file':
      return e.file.total === null ? '–' : e.file.total === 0 ? 'rien n’attend' : `${n(e.file.total)} en attente`
    case 'comptes':
      return d.comptes === null ? '–' : `${n(d.comptes.actif)} actifs · ${e.comptes.allumees} connexion${e.comptes.allumees > 1 ? 's' : ''}`
    case 'portail':
      return d.invitationsActives === null ? '–' : `${n(d.invitationsActives)} lien${d.invitationsActives > 1 ? 's' : ''} actif${d.invitationsActives > 1 ? 's' : ''}`
    case 'nuage':
      return `${e.nuage.parJour} appel${e.nuage.parJour > 1 ? 's' : ''} en 24 h`
    case 'arbre':
      return d.chantier === null ? '–' : `${n(d.chantier.fiches.sansSource.n)} sans source`
    case 'phare':
      return `${e.phare.echecs} échec${e.phare.echecs > 1 ? 's' : ''} en 24 h`
  }
}

/* ───────────────── Les paramètres d'adresse ───────────────── */

export interface ParametresDuMonde {
  theme: 'clair' | 'nuit' | null
  zone: IdZone | null
  /** `false` : pas de visite automatique (captures, tests). */
  visite: boolean
}

/** `#theme=dark|light&zone=<id>&tour=0` — pour les captures et les liens directs. */
export function lireParametres(hash: string): ParametresDuMonde {
  const p = new URLSearchParams(hash.replace(/^#/, ''))
  const t = p.get('theme')
  const z = p.get('zone')
  return {
    theme: t === 'dark' ? 'nuit' : t === 'light' ? 'clair' : null,
    zone: ZONES.some((x) => x.id === z) ? (z as IdZone) : null,
    visite: p.get('tour') !== '0',
  }
}

/** Sans geste pendant ce temps, la visite automatique commence. */
export const VISITE_APRES_MS = 20_000
/** Le temps passé sur chaque zone pendant la visite. */
export const VISITE_ETAPE_MS = 9_000
/** La cadence de lecture du Monde : la sonde et les compteurs suffisent toutes les 5 s. */
export const CADENCE_MONDE_MS = 5_000
