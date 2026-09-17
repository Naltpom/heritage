/**
 * Le diff d'une demande, champ par champ — et la règle qui le gouverne.
 *
 * > **Ce qui est absent de la charge utile ne s'affiche pas.**
 *
 * C'est le § 1.3 appliqué au diff, et c'est ici qu'il se joue. La charge de
 * l'auteur (§ 6.5) porte `oldValue` et `newValue` ; celle de l'administration
 * (§ 6.7) ajoute `valeurCourante` et `aBouge`, **calculés à la lecture**. La
 * colonne « valeur actuelle » se dessine donc quand l'op la porte, et **jamais
 * parce que le lecteur est administrateur**.
 *
 * La nuance n'est pas théorique : un `v-if="administrateur"` qui afficherait
 * cette colonne rendrait `undefined` — ou pire, un « — » qu'on prendrait pour
 * « la valeur actuelle est vide ». C'est la forme que prendrait côté front la
 * dérogation qu'il a fallu retirer du serveur : « le normaliseur n'a pas de mode
 * *sans filtre* » (§ 10.4, ADR 0003). Le front n'en a pas non plus.
 *
 * Seconde règle, héritée du § 14 : **une fourchette de dates ne s'aplatit
 * jamais.** « entre 1901 et 1909 » s'affiche tel quel, jamais « 1901 ». Le
 * serveur fournit `texte` ; on l'affiche, on ne le recompose pas. C'est pour
 * cela que `formaterValeur` **ne lit ni `min` ni `max`** : les lire serait la
 * première ligne d'un aplatissement, et il n'existe aucune façon correcte de
 * fabriquer « entre 1901 et 1909 » depuis deux bornes sans réimplémenter le
 * formateur du serveur — en divergeant de lui au premier cas limite.
 */

import type { OpDemande, ValeurOp } from '@/lib/api'

/**
 * Les champs modifiables d'une personne, en français.
 *
 * **Repli seulement.** Quand le serveur fournit `libelleDiff` — ce que fait la
 * charge d'administration (§ 6.7) —, c'est lui qui s'affiche : il porte le
 * contexte et les valeurs formatées, et le front ne réécrit pas ce que le
 * serveur a rédigé. Cette table sert à la charge de **l'auteur** (§ 6.5), qui ne
 * porte pas de libellé : sans elle, quelqu'un qui relit sa propre proposition
 * lirait « surname » et « birth ». Les destinataires sont des membres d'une
 * famille, pas des lecteurs de schéma Doctrine.
 *
 * La liste est exactement la surface éditable du § 6.2 — ni `branch`, ni
 * `generation`, ni `displayName`, qui sont des colonnes dérivées qu'aucune
 * demande ne peut viser. Un champ absent de la table s'affiche tel quel plutôt
 * que d'être masqué : mieux vaut un nom technique qu'une ligne manquante.
 */
const LIBELLES_DE_CHAMP: Record<string, string> = {
  givenNames: 'Prénoms',
  surname: 'Nom de famille',
  // « Nom d'usage » et non « nom d'épouse » : c'est le terme du droit français,
  // et il ne présume ni du sexe ni du mariage — un nom d'usage peut venir de
  // l'autre parent. La colonne reste `married_name` ; un mot ne vaut pas une
  // migration. Le terme est celui du formulaire et de la fiche (§ 6.2), et cette
  // table-ci sert la relecture de sa propre demande par l'auteur (§ 6.5) : c'est
  // le même champ, sous le même nom, d'un bout à l'autre de l'écran.
  marriedName: 'Nom d’usage',
  nickname: 'Surnom',
  sex: 'Sexe',
  deceased: 'Décédé·e',
  birth: 'Naissance',
  death: 'Décès',
  forcePrivacy: 'Fiche forcée en privé',
  privacyNote: 'Note de confidentialité',
  marriage: 'Mariage',
  divorce: 'Séparation',
  divorced: 'Séparés',
}

/** Le nom lisible d'un champ, ou le nom technique si on ne le connaît pas. */
export function libelleDeChamp(field: string): string {
  return LIBELLES_DE_CHAMP[field] ?? field
}

/** Ce qu'on affiche à la place d'une valeur vide. */
const VIDE = '(vide)'
/**
 * Ce qu'on affiche quand la valeur est un objet dont on ne sait rien dire.
 *
 * ────────────────────────────────────────────────────────────────────
 * **Ce message disait « date » et il a menti.** Il a été écrit quand le seul op
 * à valeur-objet était un bloc date, et il est resté quand `ajout_note` et
 * `ajout_citation` en ont apporté deux autres. Résultat, sur la demande 592 en
 * production : une note de quarante lignes, parfaitement enregistrée, s'affichait
 * « (date non formatée par le serveur) » — un message faux, sur un objet qui
 * n'était pas une date, pour un défaut qui n'existait pas côté serveur.
 *
 * Il ne nomme donc plus de forme. Et il ne doit plus jamais être atteint par une
 * valeur qu'on sait lire : la règle est d'ajouter une branche à
 * {@link formaterValeur} en même temps qu'un `ValueKind` à valeur-objet.
 * ────────────────────────────────────────────────────────────────────
 */
const INDISPONIBLE = '(valeur que cet écran ne sait pas encore afficher)'

/** Les portées de note et de citation, en français. `generale` ne s'affiche pas :
 *  c'est le défaut, et l'annoncer ajouterait du bruit à chaque ligne. */
const LIBELLES_DE_PORTEE: Record<string, string> = {
  naissance: 'naissance',
  deces: 'décès',
  mariage: 'mariage',
  profession: 'profession',
  residence: 'résidence',
  source: 'source',
}

function chaineNonVide(valeur: unknown): string | null {
  return typeof valeur === 'string' && valeur.trim().length > 0 ? valeur.trim() : null
}

/** « … (sur la naissance) », ou rien du tout. */
function mentionDePortee(scope: unknown): string {
  const brut = chaineNonVide(scope)
  if (brut === null || brut === 'generale') return ''
  return ` (sur ${LIBELLES_DE_PORTEE[brut] ?? brut})`
}

function estObjet(valeur: unknown): valeur is Record<string, unknown> {
  return typeof valeur === 'object' && valeur !== null && !Array.isArray(valeur)
}

/**
 * Rend une valeur d'op affichable.
 *
 * Les blocs date passent par `texte` — le libellé du serveur — puis par `raw`,
 * la chaîne source. Ni l'un ni l'autre n'est une recomposition : ce sont deux
 * chaînes que le serveur a écrites. À défaut des deux, on le dit, plutôt que de
 * fabriquer une date à partir de `min` et `max`.
 */
export function formaterValeur(valeur: ValeurOp | undefined): string {
  if (valeur === undefined || valeur === null) return VIDE
  if (typeof valeur === 'boolean') return valeur ? 'oui' : 'non'
  if (typeof valeur === 'number') return String(valeur)
  if (typeof valeur === 'string') return valeur.length > 0 ? valeur : VIDE

  if (Array.isArray(valeur)) {
    return valeur.length > 0 ? valeur.map((element) => formaterValeur(element as ValeurOp)).join(', ') : VIDE
  }

  if (estObjet(valeur)) {
    // ── Un bloc date ───────────────────────────────────────────────────────
    // `texte` d'abord — le libellé du serveur —, puis `raw`, la chaîne source.
    // On ne descend **pas** dans `min` / `max` : voir l'en-tête de ce fichier.
    const texte = chaineNonVide(valeur['texte'])
    if (texte !== null) return texte
    const brut = chaineNonVide(valeur['raw'])
    if (brut !== null) return brut

    // ── Une note (`ajout_note`) : `{body, scope}` ──────────────────────────
    // Le corps s'affiche **entier**. C'est ce que l'administrateur doit lire
    // pour décider, et une note de recherche fait volontiers quarante lignes —
    // la tronquer ici reviendrait à lui demander de valider ce qu'il ne voit
    // pas. Le gabarit s'occupe de l'enrouler.
    const corps = chaineNonVide(valeur['body'])
    if (corps !== null) return corps + mentionDePortee(valeur['scope'])

    // ── Une citation (`ajout_citation`) ────────────────────────────────────
    // Le titre vient de la **ligne** de source, pas du corps de la demande :
    // c'est le serveur qui l'a recopié, précisément pour que le diff dise ce
    // qui sera réellement écrit. On ne remonte donc jamais `sourceId` seul —
    // un numéro ne se relit pas.
    const titre = chaineNonVide(valeur['sourceTitle'])
    if (titre !== null) {
      const page = chaineNonVide(valeur['page'])
      const extrait = chaineNonVide(valeur['dataText'])
      return [
        page === null ? titre : `${titre}, ${page}`,
        extrait === null ? null : `« ${extrait} »`,
      ]
        .filter((part): part is string => part !== null)
        .join(' — ')
        .concat(mentionDePortee(valeur['scope']))
    }

    return INDISPONIBLE
  }

  return VIDE
}

/**
 * Une ligne de diff, prête à dessiner.
 *
 * `courante` et `aBouge` valent `null` quand la charge ne les porte pas — pas
 * `''`, pas `false`. L'appelant teste donc la présence, et ne peut pas
 * confondre « la charge ne le dit pas » avec « la valeur actuelle est vide » ou
 * « elle n'a pas bougé ».
 */
export interface LigneDiff {
  seq: number
  field: string
  /** Le libellé du serveur quand il existe (§ 6.7), sinon le nom du champ. */
  libelle: string
  ancienne: string
  proposee: string
  /** `null` ⇒ la charge ne porte pas la valeur actuelle : on ne l'affiche pas. */
  courante: string | null
  /** `null` ⇒ la charge ne dit rien du mouvement : on n'affiche pas l'indicateur. */
  aBouge: boolean | null
}

/**
 * Traduit un op en ligne de diff.
 *
 * **La signature n'a pas de paramètre pour le lecteur**, et c'est le fond du
 * sujet : rien dans cette fonction ne peut dépendre du rôle de celui qui
 * regarde. Ce qui s'affiche dépend de ce que le serveur a envoyé, point.
 */
export function ligneDeDiff(op: OpDemande): LigneDiff {
  // Le libellé du serveur d'abord — il porte le contexte et les valeurs déjà
  // formatées. La table française n'est qu'un repli pour la charge de l'auteur,
  // qui n'en fournit pas.
  const libelle =
    typeof op.libelleDiff === 'string' && op.libelleDiff.length > 0
      ? op.libelleDiff
      : libelleDeChamp(op.field)

  return {
    seq: op.seq,
    field: op.field,
    libelle,
    ancienne: formaterValeur(op.oldValue),
    proposee: formaterValeur(op.newValue),
    // `'valeurCourante' in op` et non `op.valeurCourante !== undefined` : la
    // distinction absent / null porte une règle, ici comme ailleurs (§ 1.3).
    courante: 'valeurCourante' in op ? formaterValeur(op.valeurCourante) : null,
    aBouge: typeof op.aBouge === 'boolean' ? op.aBouge : null,
  }
}

export function lignesDeDiff(ops: OpDemande[]): LigneDiff[] {
  return ops.map(ligneDeDiff)
}

/**
 * Le diff porte-t-il de quoi comparer à l'état actuel ?
 *
 * Sert à décider si l'on dessine la colonne « valeur actuelle » — une seule
 * fois, pour tout le tableau, plutôt que colonne par colonne.
 */
export function porteLEtatActuel(lignes: LigneDiff[]): boolean {
  return lignes.some((ligne) => ligne.courante !== null)
}

/** Les champs dont la valeur actuelle a bougé depuis le dépôt. */
export function champsQuiOntBouge(lignes: LigneDiff[]): string[] {
  return lignes.filter((ligne) => ligne.aBouge === true).map((ligne) => ligne.field)
}
