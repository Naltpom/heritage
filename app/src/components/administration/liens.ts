/**
 * Les liens d'une demande, mis en mots.
 *
 * Une création de personne se juge sur ses liens autant que sur sa fiche : « au
 * moins un lien, sinon la personne flotterait hors de l'arbre » (§ 6.4 du
 * dossier). L'administrateur doit donc lire « conjoint de Jargon Sarriette,
 * mariés le 23 septembre 1927 » et non `{kind: "conjoint_de", otherPersonId: 55}`.
 *
 * Deux règles, toutes deux déjà payées ailleurs :
 *
 * 1. **Les dates viennent du serveur, formatées.** On affiche `texte`, on ne
 *    recompose jamais depuis `min` / `max` — une fourchette ne s'aplatit pas
 *    (§ 14). Même règle que `diff.ts`, pour la même raison.
 * 2. **`divorced` et `divorce` sont facultatifs, et distincts.** `divorced:
 *    true` dit qu'il y a eu rupture ; `divorce` porte la date, qui peut manquer.
 *    « Une union sans date reste une union » (§ 6.4 du dossier) — et une rupture
 *    sans date reste une rupture. Exiger la date pour afficher la rupture
 *    l'effacerait de l'écran.
 */

import type { FiliationPrevue, LienDemande, NatureLien } from '@/lib/api'

/**
 * Le libellé d'une nature de lien.
 *
 * > **Une nature se lit du sujet vers l'autre.** `enfant_de` : le sujet est
 * > l'enfant, l'autre est le parent. `parent_de` : le sujet est le parent,
 * > l'autre est l'enfant.
 *
 * ⚠ Ces deux-là étaient **inversés**, et c'est le défaut le plus coûteux qu'une
 * table de libellés puisse porter : l'écran d'administration affichait « Enfant
 * de Argile Muguet » là où la demande dit « parent d'Argile Muguet ». **L'arbre
 * à l'envers, sur l'écran même où l'on décide** — un administrateur validait une
 * filiation inversée en croyant valider ce qu'il lisait. Rien ne le signalait :
 * une table de libellés ne se contredit jamais toute seule.
 *
 * Le sens est fixé par trois sources concordantes : le § 6.4 du dossier
 * (« `enfant_de` → on désigne un couple existant, ou un ou deux parents ;
 * `parent_de` → on désigne un ou plusieurs enfants »), `modele-donnees.md`
 * (`other2_person_id` est « le **second parent**, quand `kind = 'enfant_de'` »),
 * et l'énumération `LinkKind` de l'API.
 *
 * `Record<NatureLien, string>` et non `Record<string, string>` : c'est le type
 * qui rend la table vérifiable. Une clé manquante ne compile pas, une clé en
 * trop non plus — et il y en avait une, `frere_soeur_de`, qui n'existe **nulle
 * part ailleurs** dans le projet. `LinkKind` en compte trois « ni plus ni
 * moins » ; une fratrie s'exprime par deux `enfant_de` sur les mêmes parents,
 * avec `siblingOrder`, pas par une nature à elle.
 */
const NATURES: Record<NatureLien, string> = {
  enfant_de: 'Enfant de',
  parent_de: 'Parent de',
  conjoint_de: 'Conjoint·e de',
}

/** La date telle que le serveur l'a écrite, ou `null`. Jamais recomposée. */
function texteDeDate(bloc: LienDemande['marriage']): string | null {
  if (bloc === null || bloc === undefined) return null
  const texte = (bloc as { texte?: unknown }).texte
  if (typeof texte === 'string' && texte.length > 0) return texte
  const brut = (bloc as { raw?: unknown }).raw
  return typeof brut === 'string' && brut.length > 0 ? brut : null
}

export interface LienLisible {
  seq: number
  /**
   * **De qui parle ce lien.** Une demande qui ne propose qu'une personne n'en a
   * pas besoin — c'est elle, forcément. Une branche de vingt-deux personnes en a
   * une trentaine, et une liste de « Enfant de X » sans sujet ne dit pas qui est
   * l'enfant : c'est précisément ce qu'un commanditaire a eu sous les yeux.
   */
  sujet: string
  /** « Conjoint·e de », « Enfant de »… ou la valeur brute si elle est inconnue. */
  nature: string
  /** La personne à l'autre bout, telle que le serveur la nomme. */
  autre: string
  /**
   * Le **second parent** d'une filiation, quand le demandeur en a désigné deux
   * plutôt qu'un couple existant. `null` sinon — et non une chaîne vide : un
   * lien à un seul parent est le cas courant, pas une donnée manquante.
   */
  secondParent: string | null
  /** « mariés le 23 septembre 1927 », ou `null`. */
  union: string | null
  /** « séparés en 1943 », « séparés (date inconnue) », ou `null`. */
  rupture: string | null
  note: string | null
  /**
   * **Ce que la validation fera de cette filiation**, quand ça mérite d'être lu
   * avant le clic — `null` sinon. Voir {@link effetLisible}.
   */
  effet: EffetLisible | null
}

/**
 * L'annonce d'une filiation, prête à afficher.
 *
 * `null` quand il n'y a rien à dire : une filiation qui atterrit exactement là
 * où le lien se lit (« enfant de Jean-Marie et Hélène » → leur couple) n'a pas
 * besoin d'une ligne de plus. Une ligne affichée partout cesserait d'être lue —
 * et c'est celle qui dit « sera rattachée au couple Jean-Marie × Hélène » qu'on
 * veut voir lue.
 */
export interface EffetLisible {
  /** La phrase du serveur, telle quelle. */
  phrase: string
  /** « quitte « Jean-Marie Lebrun », supprimée (vide) » — une par famille quittée. */
  quittees: string[]
  /** Non nul : la validation sera refusée, et voici pourquoi. */
  refus: string | null
}

export function effetLisible(prevue: FiliationPrevue | undefined): EffetLisible | null {
  if (prevue === undefined || !prevue.aSignaler) return null

  return {
    phrase: prevue.phrase,
    quittees: prevue.quittees.map((quittee) =>
      quittee.supprimee
        ? `Quitte « ${quittee.libelle} », qui sera supprimée (vide).`
        : `Quitte « ${quittee.libelle} », conservée : elle porte encore des données.`,
    ),
    refus: prevue.incompatibilite,
  }
}

/** Un libellé non vide, ou `null`. Jamais une chaîne vide, qui s'affiche comme un trou. */
function nomOuRien(valeur: string | null | undefined): string | null {
  return typeof valeur === 'string' && valeur.length > 0 ? valeur : null
}

/**
 * Traduit un lien en phrase.
 *
 * `otherLabel` peut manquer — le serveur ne le renseigne pas toujours, et une
 * personne hors cercle n'est de toute façon pas nommable. On le dit plutôt que
 * d'afficher un identifiant : un numéro de ligne ne veut rien dire pour la
 * personne qui décide, et l'exposer irait contre la règle qui veut que le front
 * ne mette pas d'identifiant de personne sous les yeux du lecteur.
 *
 * **Une filiation désigne soit un couple existant, soit un ou deux parents.**
 * Jamais les deux — la base l'impose (`ck_prp_link_cible`). Le premier cas rend
 * `familyId` et `familyLabel`, le second `otherLabel` : on prend celui qui est
 * là, dans cet ordre, et on ne montre `familyId` sous aucun prétexte.
 */
export function lienLisible(lien: LienDemande): LienLisible {
  // Le `??` est un garde-fou d'exécution, pas une béance de typage : la table
  // est exhaustive au compilateur, mais rien n'empêche un serveur plus récent
  // d'envoyer une quatrième nature. Mieux vaut alors afficher la valeur brute
  // qu'un blanc — un libellé vide sur l'écran de décision serait pire.
  const nature = NATURES[lien.kind] ?? lien.kind
  const autre =
    nomOuRien(lien.otherLabel) ??
    nomOuRien(lien.familyLabel) ??
    'une personne que cette charge ne nomme pas'

  const dateUnion = texteDeDate(lien.marriage)
  const dateRupture = texteDeDate(lien.divorce)

  let rupture: string | null = null
  if (lien.divorced === true) {
    // La rupture s'affiche même sans date : c'est un fait, la date est un détail.
    rupture = dateRupture === null ? 'séparés, date inconnue' : `séparés ${dateRupture}`
  } else if (dateRupture !== null) {
    // Une date de rupture sans `divorced` : on montre ce qu'on a reçu plutôt que
    // de le taire, mais sans affirmer une rupture que la charge ne déclare pas.
    rupture = `date de séparation indiquée : ${dateRupture}`
  }

  return {
    seq: lien.seq,
    sujet: nomOuRien(lien.subjectLabel) ?? 'une personne que cette charge ne nomme pas',
    nature,
    autre,
    secondParent: nomOuRien(lien.other2Label),
    union: dateUnion === null ? null : `mariés ${dateUnion}`,
    rupture,
    note: nomOuRien(lien.note),
    effet: effetLisible(lien.filiationPrevue),
  }
}

export function liensLisibles(liens: LienDemande[]): LienLisible[] {
  return liens.map(lienLisible)
}
