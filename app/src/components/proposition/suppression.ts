/**
 * **Supprimer une fiche** — § 6.4 quater du contrat d'API.
 *
 * ────────────────────────────────────────────────────────────────────
 * ## La seule capacité du produit qui détruit
 *
 * Les huit autres natures de demande ajoutent ou corrigent : une erreur s'y
 * répare par une seconde demande. Celle-ci efface, et il n'y a pas de seconde
 * demande possible — la fiche n'est plus là pour en être la cible.
 *
 * Elle existe parce qu'elle manquait : un doublon dans un arbre généalogique se
 * réparait à la main en base, par un `DELETE` écrit dans un client SQL, sans
 * demande, sans motif, sans révision et sans relecture. C'était le seul chemin
 * d'écriture qui contournait le mécanisme, et l'un des deux doublons ainsi
 * traités avait détaché six générations.
 *
 * ## Ce que ce module fait, et ce qu'il se garde de faire
 *
 * Il **met en français** ce que le serveur a mesuré, et il valide le motif
 * localement pour que l'auteur n'apprenne pas en `422` qu'il a écrit trop court.
 *
 * Il ne décide **jamais** si une suppression est possible. `bloquee` et
 * `raisons` arrivent du serveur et s'affichent tels quels : la règle connaît les
 * six clés étrangères de `tree_person` et la contrainte qui décide du sort d'une
 * union, et une copie ici finirait par diverger — dans le sens permissif, c'est
 * à dire en proposant un bouton qui efface une branche.
 * ────────────────────────────────────────────────────────────────────
 */

import type {
  ApercuDeSuppression,
  CorpsSuppressionPersonne,
  DependanceDeFiche,
  InventaireDeSuppression,
} from '@/lib/api'

/**
 * Le plancher du motif, **le même nombre que côté serveur**.
 *
 * Il est recopié ici en connaissance de cause : c'est une validation de confort,
 * pas la règle. Le serveur reste la source, et si les deux divergent un jour,
 * c'est son message qui s'affiche — `ChampTexte` montre l'erreur du serveur
 * par-dessus la locale, précisément pour cela.
 */
export const MOTIF_MINIMUM = 10

/**
 * Le motif est-il recevable ? Rend le message d'erreur, ou `null`.
 *
 * Les deux refus disent des choses différentes, et c'est voulu : « il manque »
 * n'aide pas quelqu'un qui a écrit « doublon » et croit avoir répondu.
 */
export function erreurDeMotif(motif: string): string | null {
  const propre = motif.trim()

  if (propre.length === 0) {
    return 'Dites pourquoi supprimer cette fiche : un administrateur va trancher.'
  }

  if (propre.length < MOTIF_MINIMUM) {
    return `Trop court (${propre.length} caractères sur ${MOTIF_MINIMUM}) : si c’est un doublon, de quelle autre fiche ?`
  }

  return null
}

/** Une ligne de l'énumération montrée avant le clic. */
export interface LigneEmportee {
  libelle: string
  /** `true` quand la ligne est ce qui **interdit** la suppression. */
  bloquante: boolean
}

/**
 * Ce que la suppression emporte, ligne à ligne et en français.
 *
 * ── Pourquoi une liste et non la phrase du serveur ──────────────────────────
 *
 * `apercu.resume` en est le résumé — « emporte 3 notes, 2 citations » — et il
 * sert le diff de l'administration, où une ligne suffit. Ici, l'auteur est sur
 * le point de détruire : il a besoin de voir **chaque catégorie séparément**,
 * avec les deux blocages détachés du reste, parce qu'une énumération qui se lit
 * d'un coup d'œil est ce qui lui fait remarquer qu'il vise la mauvaise fiche.
 *
 * Une catégorie vide n'apparaît pas. Afficher « 0 note » ferait chercher un
 * risque là où il n'y en a pas, et noierait les lignes qui comptent.
 */
export function lignesEmportees(inventaire: InventaireDeSuppression): LigneEmportee[] {
  const lignes: LigneEmportee[] = []

  const compte = (nombre: number, singulier: string, pluriel: string): void => {
    if (nombre > 0) lignes.push({ libelle: `${nombre} ${nombre === 1 ? singulier : pluriel}`, bloquante: false })
  }

  compte(inventaire.notes, 'note de recherche', 'notes de recherche')
  compte(inventaire.citations, 'source citée', 'sources citées')
  compte(inventaire.faits, 'fait (profession, résidence…)', 'faits (professions, résidences…)')
  compte(inventaire.filiations, 'rattachement à ses parents', 'rattachements à ses parents')

  for (const union of inventaire.unions) {
    lignes.push({
      libelle: union.supprimee
        ? `l’union « ${union.libelle} », qui disparaît faute d’autre époux`
        : `son rôle d’époux dans l’union « ${union.libelle} », qui reste`,
      bloquante: false,
    })
  }

  // Ce que les autres modules accrochent et qui part avec elle — les questions
  // posées à son sujet. Celles qui bloquent viennent plus bas, avec les autres.
  const dependances = inventaire.dependances ?? []
  for (const dependance of dependances.filter((d) => !d.bloquante)) {
    lignes.push({ libelle: libelleDeDependance(dependance), bloquante: false })
  }

  // Les deux blocages en dernier, et marqués : ce sont les seules lignes qui
  // demandent une action avant de pouvoir continuer.
  if (inventaire.medias > 0) {
    lignes.push({
      libelle: `${inventaire.medias} preuve${inventaire.medias > 1 ? 's' : ''} rattachée${inventaire.medias > 1 ? 's' : ''} à cette fiche`,
      bloquante: true,
    })
  }

  // **Le total d'abord, en une ligne à lui.** Les noms qui suivent disent laquelle
  // des deux fiches d'un doublon on tient ; le total dit ce qu'on perdrait. Sans
  // lui, « Cobalt Sureau, dont cette fiche est le parent » se lit comme une
  // formalité — un enfant, on l'efface sans y penser.
  if (inventaire.descendanceTotale > 0) {
    const n = inventaire.descendanceTotale
    lignes.push({
      libelle: `${n} fiche${n > 1 ? 's' : ''} qui descend${n > 1 ? 'ent' : ''} de celle-ci, toutes générations confondues`,
      bloquante: true,
    })
  }

  for (const descendant of inventaire.descendants) {
    lignes.push({ libelle: `${descendant.displayName}, dont cette fiche est le parent`, bloquante: true })
  }

  // Le rattachement d'un compte, et l'invitation qui en créerait un plus tard. Ce
  // sont les deux seules lignes de cette liste qui ne parlent pas de l'arbre — et
  // les deux dont la perte ne se verrait nulle part : un compte sans racine n'a ni
  // cercle ni parenté, et son titulaire le découvre en se connectant.
  if (inventaire.membreId !== null) {
    lignes.push({
      libelle: `le compte membre n° ${inventaire.membreId}, relié à cette fiche`,
      bloquante: true,
    })
  }

  if (inventaire.invitationsActives > 0) {
    lignes.push({
      libelle: 'une invitation en attente, qui resterait sans fiche à son acceptation',
      bloquante: true,
    })
  }

  // Les autres modules : une démarche, un événement, un tirage, une autorisation.
  // Le serveur dit lesquelles bloquent ; `raisons` dit quoi faire pour chacune.
  for (const dependance of dependances.filter((d) => d.bloquante)) {
    lignes.push({ libelle: libelleDeDependance(dependance), bloquante: true })
  }

  return lignes
}

/**
 * Une dépendance d'un autre module, en français.
 *
 * Le libellé est ici et non dans la charge : l'inventaire est comparé en entier
 * à la validation, et un libellé reformulé entre le dépôt et la validation y
 * rendrait caduque une demande que rien ne contredit. Une nature que ce front ne
 * connaît pas encore s'affiche quand même, avec son nom brut — une ligne
 * maladroite vaut mieux qu'une ligne absente sur l'écran qui détruit.
 */
export function libelleDeDependance(dependance: DependanceDeFiche): string {
  const n = dependance.nombre
  const s = n > 1 ? 's' : ''

  switch (dependance.nature) {
    case 'demarche':
      return `${n} démarche${s} d’acte qui la concerne${n > 1 ? 'nt' : ''}`
    case 'evenement_a_venir':
      return `${n} invitation${s} à un événement à venir`
    case 'tirage':
      return `${n} participation${s} à un tirage au sort`
    case 'autorisation_naissance':
      return 'une autorisation signée de partager sa date de naissance'
    case 'question':
      return `${n} question${s} posée${s} à son sujet`
    default:
      return `${n} élément${s} lié${s} à cette fiche (${dependance.nature})`
  }
}

/**
 * Le corps de la demande.
 *
 * Aucun `ops` : la demande dit ce qu'elle vise, le serveur mesure ce qu'elle
 * emporte. Un inventaire fabriqué ici serait une seconde source de vérité pour
 * l'unique opération du produit qui détruit.
 */
export function corpsDeSuppression(
  personneId: number,
  motif: string,
  soumettre = true,
): CorpsSuppressionPersonne {
  return {
    kind: 'suppression_personne',
    targetKind: 'personne',
    targetId: personneId,
    message: motif.trim(),
    soumettre,
  }
}

/**
 * Le bouton d'envoi est-il actionnable ?
 *
 * Il ne l'est pas tant que l'aperçu n'est pas arrivé : proposer d'effacer avant
 * de savoir ce qu'on efface est exactement ce que cet écran existe pour éviter.
 */
export function envoiPossible(apercu: ApercuDeSuppression | null, motif: string): boolean {
  return apercu !== null && !apercu.bloquee && erreurDeMotif(motif) === null
}
