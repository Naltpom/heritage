import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { createSSRApp, h } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { describe, expect, it } from 'vitest'
import type { ResultatRattachement } from '@/lib/api'
import ResultatCandidats from './ResultatCandidats.vue'

/**
 * Ce que ces tests protègent : **le serveur ment délibérément, et l'écran doit
 * le respecter.**
 *
 * Quand un candidat dépasse cinq tentatives par heure sur un même jeton, le
 * serveur ne rend pas une erreur de débit. Il rend `200 { "candidats": [] }` —
 * exactement la réponse d'une recherche infructueuse, sans `Retry-After`, sans
 * en-tête de quota, `tentativesRestantes` à `null` pour tout le monde (§ 4.2,
 * exception nommée au § 15.3). C'est la seule dérogation à la convention
 * d'erreur du projet.
 *
 * La raison tient en une phrase : annoncer « trop de tentatives » révélerait à
 * qui cherche que la limite existe, donc qu'il y a quelque chose à trouver. Le
 * lien d'invitation deviendrait un moyen d'énumérer les personnes vivantes de
 * l'arbre — « le principal risque du produit » (§ 6.1 du dossier).
 *
 * Ce fichier interdit donc trois choses, dans cet ordre de gravité :
 *
 *  1. afficher deux écrans différents selon la cause — l'écran ne la connaît
 *     pas, et prétendre la deviner rouvrirait la fuite que le serveur ferme ;
 *  2. employer un vocabulaire de blocage sur l'écran vide ;
 *  3. compter les tentatives côté client, ou mesurer le temps de réponse, pour
 *     reconstituer l'information par la bande.
 */

/* ------------------------------------------------------------------ */
/* Les trois causes, telles que le serveur les rend                    */
/* ------------------------------------------------------------------ */

/**
 * Recherche honnête, aucun résultat. `result = aucun_candidat` au journal.
 */
const RECHERCHE_INFRUCTUEUSE: ResultatRattachement = { candidats: [] }

/**
 * Sixième tentative de l'heure sur le même jeton. Le serveur a **quand même**
 * fait la recherche — pour que le temps de réponse ne trahisse pas — puis a
 * jeté le résultat. `result = limite_atteinte` au journal, `200` sur le fil.
 */
const PLAFOND_ATTEINT: ResultatRattachement = { candidats: [] }

/**
 * Mode confirmation, date de naissance qui ne correspond pas à la personne
 * pré-liée. `result = echec_date` au journal — et surtout pas un « la date
 * attendue est en 1934 » sur le fil.
 */
const DATE_NON_CONFIRMEE: ResultatRattachement = { candidats: [] }

const TROUVE: ResultatRattachement = {
  candidats: [
    { ref: 'c1', libelle: 'P. Romarin, 1980' },
    { ref: 'c2', libelle: 'P. Romarin, 1982' },
  ],
}

function rendre(resultat: ResultatRattachement | null): Promise<string> {
  return renderToString(
    createSSRApp({ render: () => h(ResultatCandidats, { resultat, refChoisi: null }) }),
  )
}

/* ------------------------------------------------------------------ */

describe('la réponse vide, quelle qu’en soit la cause', () => {
  it('produit exactement le même écran dans les trois cas', async () => {
    const [infructueuse, plafond, date] = await Promise.all([
      rendre(RECHERCHE_INFRUCTUEUSE),
      rendre(PLAFOND_ATTEINT),
      rendre(DATE_NON_CONFIRMEE),
    ])

    // Non pas « se ressemblent » : sont la même chaîne. C'est la seule assertion
    // qui garantit qu'aucune nuance d'affichage ne s'est glissée entre les cas.
    expect(plafond).toBe(infructueuse)
    expect(date).toBe(infructueuse)
  })

  it('dit « aucune correspondance » et donne la suite à donner', async () => {
    const html = await rendre(RECHERCHE_INFRUCTUEUSE)

    expect(html).toContain('Aucune correspondance')
    // « Ce qu'il faut faire ensuite » : l'orthographe, la date, et la personne
    // qui a envoyé l'invitation. Un écran vide sans recours pousse à l'abandon.
    expect(html).toMatch(/orthographe/i)
    expect(html).toMatch(/date/i)
    expect(html).toMatch(/invitation/i)
  })

  it('n’emploie aucun mot qui suggérerait un blocage', async () => {
    const html = await rendre(PLAFOND_ATTEINT)

    // Chacun de ces mots dirait au chercheur qu'il a touché quelque chose.
    for (const interdit of [
      /tentative/i,
      /trop de/i,
      /bloqu/i,
      /limite/i,
      /plafond/i,
      /patient/i,
      /réessayez dans/i,
      /quota/i,
      /verrouill/i,
    ]) {
      expect(html).not.toMatch(interdit)
    }
  })

  it('n’affiche aucun compteur, ni restant ni consommé', async () => {
    const html = await rendre(PLAFOND_ATTEINT)

    // Pas de « 3 essais restants », pas de « 5 / 5 », pas de « il vous reste ».
    expect(html).not.toMatch(/\d\s*\/\s*\d/)
    expect(html).not.toMatch(/restant/i)
    expect(html).not.toMatch(/il vous reste/i)
  })

  it('ne propose pas de bouton qui relancerait la même recherche', async () => {
    const html = await rendre(RECHERCHE_INFRUCTUEUSE)

    // Le formulaire reste au-dessus, modifiable : c'est lui le recours. Un
    // bouton « Réessayer » ici inviterait à rejouer la requête à l'identique,
    // donc à consommer le plafond sans rien changer à la saisie.
    expect(html).not.toMatch(/réessayer/i)
  })
})

describe('la réponse peuplée', () => {
  it('affiche les candidats dans la forme minimale du § 4.2', async () => {
    const html = await rendre(TROUVE)

    expect(html).toContain('P. Romarin, 1980')
    expect(html).toContain('P. Romarin, 1982')
    // Initiale, nom, année — et rien d'autre. Le libellé vient du serveur tel
    // quel : l'écran ne le recompose pas et ne lui ajoute rien.
    expect(html).not.toMatch(/Pyrite/)
  })

  it('ne met jamais le `ref` opaque sous les yeux du lecteur', async () => {
    const html = await rendre(TROUVE)

    // Le `ref` sert de valeur de formulaire, il ne s'affiche pas : ce n'est ni
    // un identifiant de personne ni une information pour le candidat.
    expect(html).not.toMatch(/>\s*c1\s*</)
  })

  it('ne rend rien tant qu’aucune recherche n’a été faite', async () => {
    const html = await rendre(null)

    expect(html).not.toContain('Aucune correspondance')
    expect(html).not.toContain('P. Romarin')
  })
})

describe('les entrées du composant', () => {
  it('se limitent à la réponse du serveur et au choix en cours', () => {
    // L'assertion qui donne son sens à « le même écran dans les trois cas » :
    // le composant n'a aucune entrée par où une cause pourrait arriver. Ajouter
    // un `plafondAtteint`, un `cause` ou un `tentativesRestantes` casserait ce
    // test — et c'est le moment où quelqu'un doit relire le § 4.2.
    const declarees = (ResultatCandidats as unknown as { props?: Record<string, unknown> }).props

    // `refChoisiModifiers` est la pièce que `defineModel` ajoute d'office pour
    // porter les modificateurs de `v-model` : de la mécanique, pas une entrée.
    const entrees = Object.keys(declarees ?? {})
      .filter((nom) => !nom.endsWith('Modifiers'))
      .sort()

    expect(entrees).toEqual(['refChoisi', 'resultat'])
  })
})

/* ------------------------------------------------------------------ */
/* La fuite ne peut pas non plus revenir par le code source            */
/* ------------------------------------------------------------------ */

describe('aucune reconstitution du plafond côté client', () => {
  const SOUS_SURVEILLANCE = [
    '../../views/InscriptionRattachementView.vue',
    './ResultatCandidats.vue',
    './fluxInscription.ts',
  ] as const

  /**
   * Le **code**, commentaires retirés.
   *
   * Ce qui est interdit, c'est d'écrire la logique, pas d'en parler : les
   * fichiers surveillés expliquent longuement pourquoi on ne compte pas les
   * tentatives, et cette explication est ce qui empêchera le prochain lecteur de
   * les compter. Scanner les commentaires interdirait de documenter la règle.
   */
  function source(chemin: string): string {
    return readFileSync(fileURLToPath(new URL(chemin, import.meta.url)), 'utf8')
      .replace(/\/\*[\s\S]*?\*\//g, ' ')
      .replace(/<!--[\s\S]*?-->/g, ' ')
      .replace(/(^|[^:])\/\/.*$/gm, '$1')
  }

  it('ne lit aucun indicateur de quota sur la réponse', () => {
    for (const chemin of SOUS_SURVEILLANCE) {
      const code = source(chemin)
      // `tentativesRestantes` n'existe dans le contrat « que pour être toujours
      // null ». Le type du socle ne le déclare pas ; personne ne le rajoute.
      expect(code).not.toMatch(/tentativesRestantes/)
      expect(code).not.toMatch(/[Rr]etry-?After/)
    }
  })

  it('ne compte pas les essais et ne chronomètre pas les réponses', () => {
    for (const chemin of SOUS_SURVEILLANCE) {
      const code = source(chemin)
      // Un compteur d'essais côté client redirait au chercheur, à une tentative
      // près, ce que le serveur refuse de lui dire.
      expect(code).not.toMatch(/\bessais\b|\bnbTentatives\b|compteurTentatives/)
      // Mesurer le temps de réponse est l'autre façon de distinguer les deux
      // cas — le serveur s'en prémunit en cherchant avant de jeter le résultat,
      // le front n'a donc aucune raison de sortir un chronomètre.
      expect(code).not.toMatch(/performance\.now|Date\.now|process\.hrtime/)
    }
  })

  it('ne journalise rien de ce parcours', () => {
    for (const chemin of SOUS_SURVEILLANCE) {
      // Le jeton, les `ref` et les libellés de candidats n'ont rien à faire dans
      // une console — la page peut rester ouverte sur une machine partagée.
      expect(source(chemin)).not.toMatch(/console\.(log|debug|info|warn|error)/)
    }
  })
})
