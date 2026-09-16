import { createSSRApp, h } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { describe, expect, it } from 'vitest'
import { ErreurApi } from '@/lib/api'
import { estLienNonValide, jetonDeLaRoute } from './lienInvitation'
import LienNonValide from './LienNonValide.vue'

/**
 * Ce que ces tests protègent : **un lien refusé ne dit pas pourquoi, et ne
 * propose rien à refaire.**
 *
 * Les trois routes publiques du § 4 rendent `404` — jamais `403` — sur un jeton
 * inconnu, mal formé, expiré, révoqué ou déjà consommé, avec le même corps dans
 * les cinq cas. Un `403` confirmerait que la route existe et que le jeton a une
 * forme valide ; un message « votre invitation a expiré » distinguerait un jeton
 * ayant existé d'un jeton inventé. Les deux reviennent au même : on ne révèle
 * pas l'existence du point d'entrée (§ 6.1 du dossier).
 *
 * L'écran doit donc traiter ce `404` comme « ce lien n'est pas valide », **sans
 * laisser entendre qu'il aurait pu l'être**.
 */

const CORPS_404 = {
  erreur: {
    code: 'introuvable',
    message: 'Ressource introuvable.',
    champs: null,
    details: null,
  },
}

/** Les cinq causes, indistinguables sur le fil : même statut, même corps. */
const JETON_INCONNU = new ErreurApi(404, 'introuvable', CORPS_404.erreur.message)
const JETON_MAL_FORME = new ErreurApi(404, 'introuvable', CORPS_404.erreur.message)
const JETON_EXPIRE = new ErreurApi(404, 'introuvable', CORPS_404.erreur.message)
const JETON_REVOQUE = new ErreurApi(404, 'introuvable', CORPS_404.erreur.message)
const JETON_CONSOMME = new ErreurApi(404, 'introuvable', CORPS_404.erreur.message)

describe('estLienNonValide', () => {
  it('reconnaît les cinq causes, sans les distinguer', () => {
    for (const erreur of [
      JETON_INCONNU,
      JETON_MAL_FORME,
      JETON_EXPIRE,
      JETON_REVOQUE,
      JETON_CONSOMME,
    ]) {
      expect(estLienNonValide(erreur)).toBe(true)
    }
  })

  it('accepte une 404 qui n’aurait pas l’enveloppe du § 15.1', () => {
    // Une 404 du routeur mal câblé veut dire la même chose pour le candidat qui
    // a collé un lien tronqué : le test porte sur le statut, pas sur le code.
    expect(estLienNonValide(new ErreurApi(404, 'client_reponse_illisible', 'Réponse inattendue.'))).toBe(
      true,
    )
  })

  it('ne confond pas une panne avec un lien refusé', () => {
    // Le serveur éteint, c'est « réessayez » ; le lien refusé, c'est « écrivez à
    // la personne qui vous l'a envoyé ». Les confondre enverrait l'un des deux
    // dans la mauvaise direction.
    expect(estLienNonValide(new ErreurApi(0, 'client_reseau_indisponible', 'Pas de réponse.'))).toBe(false)
    expect(estLienNonValide(new ErreurApi(500, 'erreur_interne', 'Erreur interne.'))).toBe(false)
    expect(estLienNonValide(new ErreurApi(429, 'trop_de_tentatives', 'Trop de tentatives.'))).toBe(false)
    expect(estLienNonValide(null)).toBe(false)
    expect(estLienNonValide(new Error('bogue du front'))).toBe(false)
  })
})

describe('l’écran « lien non valide »', () => {
  async function rendre(): Promise<string> {
    return renderToString(createSSRApp({ render: () => h(LienNonValide) }))
  }

  it('dit que le lien n’est pas valide', async () => {
    const html = await rendre()
    expect(html).toMatch(/ce lien n’est pas valide|ce lien n'est pas valide/i)
  })

  it('ne propose pas de réessayer', async () => {
    const html = await rendre()

    // Le même jeton donnera le même 404 : un bouton ferait tourner en rond
    // quelqu'un dont le seul recours est d'écrire à un humain.
    expect(html).not.toMatch(/réessayer/i)
    expect(html).not.toMatch(/recharger/i)
    expect(html).not.toMatch(/<button/i)
  })

  it('ne laisse pas entendre que le lien aurait pu être valide', async () => {
    const html = await rendre()

    // « Expiré », « déjà utilisé », « révoqué » diraient qu'il a existé.
    // « Demandez-en un nouveau » suppose qu'il y avait quelque chose à demander.
    for (const interdit of [
      /expir/i,
      /déjà utilisé/i,
      /déjà consommé/i,
      /révoqu/i,
      /nouveau lien/i,
      /demandez-en/i,
      /plus valable/i,
    ]) {
      expect(html).not.toMatch(interdit)
    }
  })

  it('renvoie vers la personne qui a envoyé l’invitation', async () => {
    const html = await rendre()
    expect(html).toMatch(/invitation/i)
  })
})

describe('jetonDeLaRoute', () => {
  // Le contrat bâtit le lien avec le jeton **dans le chemin** (§ 3.2) ; le
  // routeur du socle le décrit en chaîne de requête. Les deux se rencontreront.
  it('lit le jeton posé dans le chemin', () => {
    expect(jetonDeLaRoute({ params: { jeton: 'kJ8s-2Qb1nR7' }, query: {} })).toBe('kJ8s-2Qb1nR7')
  })

  it('lit le jeton posé en chaîne de requête', () => {
    expect(jetonDeLaRoute({ params: {}, query: { jeton: 'kJ8s-2Qb1nR7' } })).toBe('kJ8s-2Qb1nR7')
  })

  it('rend null quand il n’y a pas de jeton du tout', () => {
    expect(jetonDeLaRoute({ params: {}, query: {} })).toBeNull()
    expect(jetonDeLaRoute({ params: {}, query: { jeton: '   ' } })).toBeNull()
    // `?jeton=a&jeton=b` donne un tableau : ce n'est pas un jeton.
    expect(jetonDeLaRoute({ params: {}, query: { jeton: ['a', 'b'] } })).toBeNull()
  })

  it('ne juge pas de la forme du jeton', () => {
    // Trier le mal formé côté client rendrait « mal formé » distinguable de
    // « inconnu » — précisément ce que le 404 uniforme du serveur empêche.
    expect(jetonDeLaRoute({ params: { jeton: 'trop-court' }, query: {} })).toBe('trop-court')
  })
})
