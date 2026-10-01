import { createSSRApp, defineComponent, h } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { describe, expect, it } from 'vitest'
import { CODE_RESEAU, ErreurApi } from '@/lib/api'
import type { ResumeDemande } from '@/lib/api'
import ListeMesDemandes from './ListeMesDemandes.vue'
import type { ComptesParFiltre, MotifDecision } from './mes-demandes'

/**
 * `mes-demandes.test.ts` vérifie ce que la logique décide ; **ce fichier
 * vérifie que l'écran l'imprime**. Trois choses ne se prouvent que sur la chaîne
 * rendue, et ce sont les trois qui font l'écran :
 *
 * 1. **le motif de refus est à l'écran, en clair.** C'est la demande du
 *    commanditaire, et elle n'était satisfaite nulle part. Une fonction qui rend
 *    le bon motif ne prouve rien si le gabarit ne l'imprime pas — c'est
 *    exactement ce qui s'était passé : la clé était servie par l'API, lue par
 *    personne ;
 * 2. **le vide parle.** Un membre qui n'a rien proposé ne doit pas trouver une
 *    page blanche, qui se lit comme une panne ;
 * 3. **l'échec parle.** « Un écran qui ne dit rien quand la requête échoue » est
 *    le défaut dont ce lot est né. Le message du serveur, son code, et la
 *    conduite à tenir doivent être là.
 *
 * Rendu en SSR — même méthode que `fiche-rendu.test.ts` : pas de DOM, pas de
 * dépendance de test supplémentaire, et la chaîne produite se lit.
 */

const LienFactice = defineComponent({
  name: 'RouterLink',
  props: { to: { type: [String, Object], default: '' } },
  setup: (_, { slots }) => () => h('a', { href: '#' }, slots['default']?.()),
})

function resume(fragment: Partial<ResumeDemande> & { id: number }): ResumeDemande {
  return {
    kind: 'creation_personne',
    status: 'en_attente',
    authorUserId: 17,
    authorLabel: 'Opale Bourrache',
    targetKind: null,
    targetId: null,
    targetLabel: null,
    message: null,
    autoValidated: false,
    submittedAt: '2026-09-16T10:56:18+00:00',
    createdAt: '2026-09-16T10:56:18+00:00',
    ...fragment,
  }
}

async function rendre(props: {
  demandes: ResumeDemande[]
  motifs?: Map<number, MotifDecision | null>
  filtre?: ResumeDemande['status'] | ''
  total?: number
  chargement?: boolean
  erreur?: unknown
  comptes?: ComptesParFiltre
}): Promise<string> {
  const app = createSSRApp({
    render: () =>
      h(ListeMesDemandes as never, {
        motifs: new Map<number, MotifDecision | null>(),
        filtre: '',
        total: props.demandes.length,
        chargement: false,
        erreur: null,
        ...props,
      }),
  })
  app.component('RouterLink', LienFactice)
  return renderToString(app)
}

describe('le motif de refus est à l’écran, pas derrière un clic', () => {
  const REFUSEE = resume({ id: 8, status: 'refusee', kind: 'creation_personne' })
  const MOTIF: MotifDecision = {
    message: 'Le surnom Sarriette n’est appuyé par aucun acte. Merci de joindre la pièce avant de reproposer.',
    parQui: 'Agate Fenouil',
    quand: '2026-09-16T10:58:33+00:00',
  }

  it('imprime le mot de l’administrateur, mot pour mot', async () => {
    const html = await rendre({ demandes: [REFUSEE], motifs: new Map([[8, MOTIF]]) })

    expect(html).toContain('Motif du refus')
    expect(html).toContain('Le surnom Sarriette n’est appuyé par aucun acte.')
    expect(html).toContain('Agate Fenouil')
  })

  it('dit que le motif arrive, tant qu’il n’est pas là', async () => {
    // La clé absente veut dire « pas encore lu ». Ne rien afficher ferait croire
    // qu'on a été refusé sans motif — c'est-à-dire sans explication, ce que le
    // produit s'interdit précisément.
    const html = await rendre({ demandes: [REFUSEE], motifs: new Map() })

    expect(html).toContain('Chargement du motif')
  })

  it('renvoie au détail quand le motif n’a pas pu être lu', async () => {
    // Lu, mais rien à montrer. La ligne ne reste pas muette là où il y avait un
    // motif : le détail le porte de toute façon.
    const html = await rendre({ demandes: [REFUSEE], motifs: new Map([[8, null]]) })

    expect(html).toContain('n’a pas pu être chargé')
    expect(html).toContain('Ouvrir la demande')
  })

  it('n’affiche aucun panneau de motif sur une demande qui n’a pas été refusée', async () => {
    const html = await rendre({ demandes: [resume({ id: 9, status: 'validee' })] })

    expect(html).not.toContain('Motif du refus')
    expect(html).not.toContain('Chargement du motif')
  })

  it('propose de refaire une demande refusée, et rien sur une demande validée', async () => {
    const refusee = await rendre({ demandes: [REFUSEE], motifs: new Map([[8, MOTIF]]) })
    const validee = await rendre({ demandes: [resume({ id: 9, status: 'validee' })] })

    expect(refusee).toContain('Refaire cette demande')
    expect(validee).not.toContain('Refaire cette demande')
  })
})

describe('le vide parle', () => {
  it('invite celui qui n’a encore rien proposé, au lieu d’une page blanche', async () => {
    const html = await rendre({ demandes: [], total: 0 })

    expect(html).toContain('Vous n’avez encore rien proposé')
    // Et il lui donne le geste suivant, plutôt que de le laisser chercher.
    expect(html).toContain('Proposer une personne')
  })

  it('dit à celui qui filtre que ses autres demandes sont toujours là', async () => {
    const html = await rendre({ demandes: [], total: 0, filtre: 'refusee' })

    expect(html).toContain('Choisissez « Toutes » pour voir les autres')
    // Pas d'invitation à proposer ici : ce n'est pas qu'il n'a rien proposé.
    expect(html).not.toContain('Vous n’avez encore rien proposé')
  })
})

describe('l’échec parle — le défaut dont ce lot est né', () => {
  it('affiche le message du serveur, son code, et de quoi réessayer', async () => {
    const html = await rendre({
      demandes: [],
      erreur: new ErreurApi(503, 'service_indisponible', 'Le service est momentanément indisponible.'),
    })

    expect(html).toContain('Vos demandes n’ont pas pu être chargées.')
    expect(html).toContain('Le service est momentanément indisponible.')
    // Le code est stable : c'est lui qu'on cite dans un rapport de bogue.
    expect(html).toContain('service_indisponible')
  })

  it('ne fait pas passer un échec pour une liste vide', async () => {
    // La confusion à ne pas commettre : « vous n'avez rien proposé » affiché à
    // quelqu'un dont la requête vient d'échouer effacerait ses contributions à
    // ses propres yeux.
    const html = await rendre({
      demandes: [],
      erreur: new ErreurApi(0, CODE_RESEAU, 'Le service n’a pas répondu.'),
    })

    expect(html).not.toContain('Vous n’avez encore rien proposé')
  })
})

describe('la liste elle-même', () => {
  it('nomme la nature, l’état et la cible de chaque demande', async () => {
    const html = await rendre({
      demandes: [
        resume({
          id: 7,
          kind: 'modification_personne',
          status: 'validee',
          targetKind: 'personne',
          targetLabel: 'Sarriette Muguet',
          targetId: 483,
          message: 'seconde sonde : même surnom',
        }),
      ],
    })

    expect(html).toContain('Modification d’une fiche')
    expect(html).toContain('Validée')
    expect(html).toContain('Sarriette Muguet')
    expect(html).toContain('seconde sonde : même surnom')
  })

  it('dit quand la liste est plus longue que ce qu’elle affiche', async () => {
    // Le serveur plafonne `parPage` à 100 sans le dire. Laisser croire que le
    // compte affiché est la liste entière serait mentir par omission.
    const html = await rendre({ demandes: [resume({ id: 1 })], total: 140 })

    expect(html).toContain('plus récentes de vos 140 demandes')
  })
})

describe('la barre d’écran de Mes demandes', () => {
  it('porte le titre, l’état en segmenté avec compteurs, et le nombre de demandes', async () => {
    const html = await rendre({
      demandes: [resume({ id: 1 }), resume({ id: 2 })],
      total: 2,
      comptes: { '': 2, en_attente: 2, validee: 0 },
    })
    const texte = html.replace(/\s+/g, ' ')

    expect(texte).toContain('Mes demandes')
    // L'état actif est dit par `aria-pressed`, jamais par la seule couleur.
    expect(html).toMatch(/aria-pressed="true"[^>]*>\s*Toutes/)
    expect(texte).toContain('2 demandes')
    expect(html).not.toContain('<select')
  })

  it('n’a ni bouton Filtres ni feuillet : il n’y a rien au-delà de la vue', async () => {
    const html = await rendre({ demandes: [resume({ id: 1 })] })

    expect(html).not.toContain('tiroir-outils')
    expect(html).not.toMatch(/>\s*Filtres\s*</)
  })
})
