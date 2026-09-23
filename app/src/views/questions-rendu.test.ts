/**
 * L'écran « Questions » — ce qui se prouve sans navigateur.
 *
 * ────────────────────────────────────────────────────────────────────
 * **CE QUI PEUT ÊTRE PROUVÉ ICI, ET CE QUI NE LE PEUT PAS.**
 *
 * Le projet n'a ni `jsdom`, ni `happy-dom`, ni `@vue/test-utils`, et il n'en
 * prendra pas pour ce fichier — même arbitrage qu'`anniversaires-rendu.test.ts`.
 * Sous l'environnement `node` de Vitest, les `.vue` sont compilés pour le
 * **rendu serveur** : `createSSRApp` + `renderToString` est le seul rendu
 * disponible, et `onMounted` ne s'exécute jamais.
 *
 * Ce fichier prouve donc trois choses, et pas une de plus :
 *
 *  - les deux **décisions** que la vue exporte, appelées directement ;
 *  - que la vue se monte sans toucher la moindre interface de navigateur ;
 *  - que l'attribut `capture` est **bien dans le balisage rendu**, avec la
 *    bonne valeur. C'est le seul endroit où il peut être attrapé : une valeur
 *    fautive reste du HTML parfaitement valide et ne produit aucune erreur, ni
 *    à la compilation, ni à l'exécution, ni dans un navigateur — elle laisse
 *    juste le téléphone choisir sa caméra, et souvent la mauvaise.
 *
 * Il ne prouve **rien de la peinture**, ni du comportement des trois boutons :
 * cela demande un navigateur.
 *
 * Les noms de ce jeu d'essai sont inventés (ADR 0006).
 * ────────────────────────────────────────────────────────────────────
 */
import { describe, expect, it } from 'vitest'
import { createSSRApp } from 'vue'
import { renderToString } from 'vue/server-renderer'

import QuestionsView, { etatDeLEcran, mentionDuModele } from './QuestionsView.vue'

describe('etatDeLEcran', () => {
  it('montre un squelette tant que rien n’est arrivé', () => {
    expect(etatDeLEcran(null, null)).toEqual({ corps: 'squelette', bandeau: false })
  })

  it('remplace l’écran par l’erreur quand il n’y a rien à préserver', () => {
    expect(etatDeLEcran(null, new Error('réseau'))).toEqual({ corps: 'panne', bandeau: false })
  })

  /**
   * **Le cas qui compte.** Le membre a peut-être déjà tapé une réponse dans
   * l'un des champs : effacer la liste pour y mettre une alerte lui ferait
   * perdre son texte — pas un affichage, son travail.
   */
  it('garde les questions et pose un bandeau quand la mise à jour échoue', () => {
    expect(etatDeLEcran([question()], new Error('réseau'))).toEqual({
      corps: 'contenu',
      bandeau: true,
    })
  })

  /**
   * Zéro question est un état **normal et désirable** — « nous n'avons rien à
   * vous demander ». Le confondre avec un squelette qui tourne pour toujours
   * ferait conclure à un écran cassé.
   */
  it('distingue une liste vide d’un chargement et d’une panne', () => {
    expect(etatDeLEcran([], null)).toEqual({ corps: 'vide', bandeau: false })
    expect(etatDeLEcran([], new Error('réseau'))).toEqual({ corps: 'panne', bandeau: false })
  })
})

describe('mentionDuModele', () => {
  it('ne dit rien quand aucun modèle n’a écrit', () => {
    expect(mentionDuModele(12, 0)).toBeNull()
  })

  /** Elle dit **combien** : deux sur vingt et dix-neuf sur vingt ne sont pas la même information. */
  it('chiffre la part rédigée par un modèle', () => {
    expect(mentionDuModele(20, 2)).toBe(
      '2 de ces questions sur 20 ont été formulées par un modèle de langage.',
    )
  })

  it('se simplifie quand tout vient du modèle', () => {
    expect(mentionDuModele(20, 20)).toBe('Ces questions ont été formulées par un modèle de langage.')
  })
})

describe('QuestionsView', () => {
  it('se monte sans toucher la moindre interface de navigateur', async () => {
    const html = await renderToString(createSSRApp(QuestionsView))

    // `onMounted` ne s'exécute pas en rendu serveur : l'écran en est donc à son
    // squelette, ce qui est exactement ce qu'on veut lire — un `setup()` qui
    // toucherait `window` ou `document` aurait levé avant d'arriver ici.
    expect(html).toContain('Questions')
  })

  /**
   * ════════════════════════════════════════════════════════════════
   *  `capture="environment"` — LE SEUL ENDROIT OÙ UNE FAUTE S'ATTRAPE.
   * ════════════════════════════════════════════════════════════════
   *
   * L'attribut n'accepte que `user` (caméra avant) et `environment` (caméra
   * arrière). Une valeur inventée — `camera`, `true`, `photo` — **reste du HTML
   * valide** : aucun outil ne s'en plaint, et le défaut ne se voit qu'en
   * tenant un téléphone, en constatant que la mauvaise caméra s'ouvre.
   *
   * On lit donc le balisage rendu. `environment` est le bon défaut ici : on
   * photographie un document ou une photo posée sur la table, jamais soi-même.
   */
  it('demande la caméra arrière sur l’entrée de fichier', async () => {
    const html = await renderToString(createSSRApp(CarteDeQuestionSeule))

    expect(html).toContain('capture="environment"')
    expect(html).toContain('accept="image/*"')
    expect(html).not.toContain('capture="camera"')
    expect(html).not.toContain('capture="user"')
  })
})

/**
 * La vue rendue **avec une question en main**.
 *
 * Le rendu serveur n'exécute pas `onMounted`, donc la vue seule s'arrête à son
 * squelette et ne peint aucune carte. On ne peut pas non plus lui injecter la
 * charge par une propriété : elle n'en a pas, et lui en donner une pour le seul
 * besoin d'un test ajouterait à l'écran une entrée que rien n'utiliserait.
 *
 * On rend donc **le même balisage de carte**, extrait tel quel du gabarit.
 * C'est une copie, et une copie peut diverger — d'où la garde : si le gabarit
 * de la vue perdait son `capture`, ce fichier resterait vert. Le second cas
 * ci-dessous lit donc le **source** du composant, qui ne peut pas diverger de
 * lui-même.
 */
const CarteDeQuestionSeule = {
  template: `
    <label>
      <input type="file" accept="image/*" capture="environment" />
    </label>
  `,
}

describe('le gabarit de la vue lui-même', () => {
  /**
   * La garde de la copie ci-dessus : on lit le **source** du composant.
   *
   * Sans elle, quelqu'un retirerait `capture` de `QuestionsView.vue` et le cas
   * précédent resterait vert — il ne mesurerait plus que sa propre copie. Un
   * test qui ne peut annoncer qu'un seul résultat n'informe de rien.
   */
  it('porte capture="environment" SUR SON INPUT, et pas seulement dans un commentaire', async () => {
    const { readFile } = await import('node:fs/promises')
    const { fileURLToPath } = await import('node:url')

    const source = await readFile(
      fileURLToPath(new URL('./QuestionsView.vue', import.meta.url)),
      'utf8',
    )

    // ── LA PRÉCISION DE CETTE EXPRESSION EST LE TEST ─────────────────────
    //
    // Une première version se contentait de `toContain('capture="environment"')`.
    // **Elle ne mesurait rien** : le gabarit porte un commentaire qui explique
    // l'attribut et le cite mot pour mot. Retirer l'attribut de l'`<input>` en
    // laissant le commentaire — exactement ce que fait un remaniement pressé —
    // laissait le cas au vert. Constaté en cassant le code exprès.
    //
    // On exige donc l'attribut **à l'intérieur d'une balise `<input>`**, ce
    // qu'aucun commentaire ne peut satisfaire.
    expect(source).toMatch(/<input\b[^>]*\bcapture="environment"/s)
    expect(source).toMatch(/<input\b[^>]*\baccept="image\/\*"/s)
  })
})

function question(): import('@/lib/api').Question {
  return {
    id: 1,
    texte: 'Savez-vous où Prune Bréval est décédée ?',
    nature: 'lieu_deces_inconnu',
    natureLibelle: 'Lieu de décès inconnu',
    redacteur: 'regles',
    redacteurLibelle: 'Formulation automatique',
    attendPhoto: false,
    sujet: { kind: 'personne', id: 214, libelle: 'Prune Bréval' },
    etat: 'posee',
    etatLibelle: 'Posée',
    reports: 0,
    demandes: [],
  }
}
