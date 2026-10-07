import { createSSRApp, h } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { describe, expect, it } from 'vitest'
import type { PartageNaissance as Partage, VerrouDePartage } from '@/lib/api'
import PartageNaissance from './PartageNaissance.vue'

/**
 * Le bloc « Qui voit ma date de naissance ? » — rendu côté serveur.
 *
 * Trois lignes indépendantes, chacune à trois niveaux (tous, mes proches,
 * personne) ; la règle de réciprocité, la définition des proches et la phrase
 * sur la photo dites avant qu'on choisisse, avec l'avertissement des trente
 * jours ; une ligne verrouillée à « tous », avec sa date et le chemin de la
 * demande RGPD.
 */

async function rendre(
  partage: Partage,
  options: { verrou?: VerrouDePartage; sansExplication?: boolean } = {},
): Promise<string> {
  return renderToString(
    createSSRApp({ render: () => h(PartageNaissance, { modelValue: partage, ...options }) }),
  )
}

/** La balise `<input>` d'un niveau d'une composante. */
function choixDe(html: string, cle: string, niveau: string): string {
  return (
    new RegExp(`<input[^>]*data-composante="${cle}"[^>]*data-niveau="${niveau}"[^>]*>`).exec(html)?.[0] ?? ''
  )
}

describe('PartageNaissance', () => {
  it('porte trois lignes à trois niveaux, la règle de réciprocité et la définition des proches', async () => {
    const html = await rendre({ annee: 'personne', mois: 'tous', jour: 'proches' })

    expect(html).toContain('Qui voit ma date de naissance ?')
    expect(html.match(/type="radio"/g)).toHaveLength(9)
    expect(choixDe(html, 'annee', 'personne')).toContain('checked')
    expect(choixDe(html, 'annee', 'tous')).not.toContain('checked')
    expect(choixDe(html, 'mois', 'tous')).toContain('checked')
    expect(choixDe(html, 'jour', 'proches')).toContain('checked')
    expect(html).toContain('Tous les vivants de l’arbre')
    expect(html).toContain('Mes proches')
    expect(html).toContain('C’est réciproque')
    expect(html).toContain('Les personnes décédées ne sont pas concernées.')
    expect(html).toContain('data-definition-proches')
    expect(html).toContain('Sans photo de vous (même en attente de validation)')
  })

  it('avertit des trente jours avant qu’on choisisse « tous »', async () => {
    const html = await rendre({ annee: 'proches', mois: 'proches', jour: 'proches' })
    expect(html).toContain('data-avertissement-partage')
    expect(html).toContain('Une information partagée avec tous le reste au moins 30 jours.')
  })

  it('verrouille une ligne à « tous » et dit jusqu’à quand, jamais une ligne à « proches »', async () => {
    const date = '2026-11-02T12:00:00+01:00'
    const html = await rendre({ annee: 'proches', mois: 'tous', jour: 'tous' }, { verrou: { annee: date, mois: date } })

    expect(choixDe(html, 'mois', 'proches')).toContain('disabled')
    expect(choixDe(html, 'mois', 'personne')).toContain('disabled')
    expect(choixDe(html, 'mois', 'tous')).not.toContain('disabled')
    // À « proches » : on va et vient librement, verrou ou pas.
    expect(choixDe(html, 'annee', 'personne')).not.toContain('disabled')
    expect(choixDe(html, 'jour', 'proches')).not.toContain('disabled')
    expect(html).toContain('data-verrou="mois"')
    expect(html).not.toContain('data-verrou="annee"')
    expect(html).toMatch(/Partagé avec tous jusqu’au\s+2 novembre 2026 au minimum\. Pour le retirer avant,/)
    expect(html).toContain('data-demande-rgpd="mois"')
    expect(html).toContain('faites une demande RGPD')
    expect(html).toContain('data-avertissement-partage')
  })

  it('se tait sur l’avertissement quand tout est à « tous » et verrouillé', async () => {
    const date = '2026-11-02T12:00:00+01:00'
    const html = await rendre({ annee: 'tous', mois: 'tous', jour: 'tous' }, { verrou: { annee: date, mois: date, jour: date } })
    expect(html.match(/disabled/g)).toHaveLength(6)
    expect(html).not.toContain('data-avertissement-partage')
  })

  it('se tait sur la règle quand l’administrateur choisit pour un tiers', async () => {
    const html = await rendre({ annee: 'tous', mois: 'proches', jour: 'personne' }, { sansExplication: true })
    expect(html).not.toContain('C’est réciproque')
    expect(html).not.toContain('Sans photo de vous')
    expect(html).not.toContain('data-avertissement-partage')
  })
})
