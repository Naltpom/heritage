/**
 * « Décrivez votre famille », avec sa consigne parlée et son message vocal —
 * rendus côté serveur, et lus dans le source quand c'est l'ordre qui compte.
 *
 * Ce que ces cas tiennent :
 *
 * 1. **La consigne** : un bouton « Écouter les explications », « Lire le
 *    texte », et la transcription exacte, présente pour les lecteurs d'écran
 *    même repliée. Au rendu serveur, aucun son n'est demandé.
 * 2. **Le micro** est proposé à côté du champ — qui reste là, et reste
 *    suffisant. Au rendu serveur, rien n'est décidé sur le micro : le bouton est
 *    éteint jusqu'au montage.
 * 3. **Avec un message vocal**, le texte n'est plus obligatoire : plus de
 *    `required`, un compteur qui ne réclame rien, et le lecteur de réécoute
 *    avec « Recommencer » et « Supprimer ».
 * 4. **L'écran de recherche** amorce la consigne **avant** d'attendre la
 *    réponse (Safari), et ouvre la description d'emblée sur un résultat vide —
 *    une décision qui ne lit que le nombre de candidats.
 */
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { createSSRApp, h } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { describe, expect, it } from 'vitest'
import ConsigneParlee from './ConsigneParlee.vue'
import DeclarationFamille from './DeclarationFamille.vue'
import EnregistreurVocal from './EnregistreurVocal.vue'
import { TEXTE_CONSIGNE } from './consigneVocale'

async function rendre(composant: object, props: Record<string, unknown> = {}): Promise<string> {
  const app = createSSRApp({ render: () => h(composant, props) })
  return (await renderToString(app)).replace(/<!--[\s\S]*?-->/g, '')
}

/** Le texte tel que Vue l'échappe dans le HTML. */
function echappe(texte: string): string {
  return texte.replace(/&/g, '&amp;').replace(/'/g, '&#39;')
}

const IDENTITE = { surname: 'Dupont', givenNames: 'Léa Marie', birthDate: '1998-04-12' }
const VOCAL = { fichier: new Blob(['x'.repeat(2048)], { type: 'audio/webm' }), type: 'audio/webm', dureeSecondes: 42 }

describe('la consigne parlée', () => {
  it('propose la transcription exacte, repliée mais lisible des lecteurs d’écran', async () => {
    const html = await rendre(ConsigneParlee)

    expect(html).toContain('Lire le texte')
    expect(html).toContain('aria-expanded="false"')
    expect(html).toContain(echappe(TEXTE_CONSIGNE))
    expect(html).toMatch(/data-transcription[^>]*|class="lecteur-ecran"/)
    expect(html).toContain('lecteur-ecran')
  })

  it('ne pose aucun élément audio dans le gabarit : l’élément est partagé, créé au montage', async () => {
    const html = await rendre(ConsigneParlee, { lectureAuto: true })
    expect(html).not.toContain('<audio')
  })

  it('n’anime la barre que hors `prefers-reduced-motion`', () => {
    const source = readFileSync(fileURLToPath(new URL('./ConsigneParlee.vue', import.meta.url)), 'utf8')
    expect(source).toContain('motion-reduce:transition-none')
  })
})

describe('le micro, à côté du champ', () => {
  it('est proposé, et le champ reste — obligatoire tant qu’il n’y a pas de vocal', async () => {
    const html = await rendre(DeclarationFamille, { ...IDENTITE, description: '' })

    expect(html).toContain('Enregistrer un message vocal')
    expect(html).toContain('Ou dites-le à voix haute')
    expect(html).toMatch(/<textarea[^>]*required/)
    expect(html).toContain('0 / 60 caractères au minimum')
    expect(html).toContain('Écouter les explications')
    // Le vocal a sa propre mention : il ne part pas à l'analyse automatique.
    expect(html).toContain('Écouté seulement par les administrateurs')
  })

  it('n’est pas décidé au rendu serveur : le bouton attend le montage', async () => {
    const html = await rendre(EnregistreurVocal)
    expect(html).toMatch(/<button[^>]*disabled[^>]*data-micro|<button[^>]*data-micro[^>]*disabled/)
    expect(html).toContain('Cinq minutes au plus')
  })
})

describe('avec un message vocal', () => {
  it('le texte devient facultatif', async () => {
    const html = await rendre(DeclarationFamille, { ...IDENTITE, description: '', messageVocal: VOCAL })

    expect(html).not.toMatch(/<textarea[^>]*required/)
    expect(html).toContain('facultatif avec le message vocal')
    expect(html).not.toContain('caractères au minimum')
  })

  it('se réécoute, se recommence, se supprime', async () => {
    const html = await rendre(EnregistreurVocal, { modelValue: VOCAL })

    expect(html).toContain('Message vocal enregistré')
    expect(html).toContain('0:42')
    expect(html).toContain('Recommencer')
    expect(html).toContain('Supprimer')
    expect(html).not.toContain('Enregistrer un message vocal')
  })
})

describe('l’écran de recherche', () => {
  const SOURCE = readFileSync(
    fileURLToPath(new URL('../../views/InscriptionRattachementView.vue', import.meta.url)),
    'utf8',
  )

  it('amorce la consigne pendant le geste, avant d’attendre la réponse', () => {
    const amorce = SOURCE.indexOf('deverrouillerLaConsigne()')
    const attente = SOURCE.indexOf('await rechercherRattachement(')

    expect(amorce).toBeGreaterThan(-1)
    expect(attente).toBeGreaterThan(-1)
    expect(amorce).toBeLessThan(attente)
  })

  it('ouvre la description sur un résultat vide, par le seul nombre de candidats', () => {
    expect(SOURCE).toContain('if (reponse.candidats.length === 0) declarer.value = true')
  })

  it('demande la lecture automatique et garde le vocal comme le brouillon', () => {
    expect(SOURCE).toContain('consigneAuto')
    expect(SOURCE).toContain('v-model:messageVocal="messageVocal"')
  })
})
