import { createSSRApp, h } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { describe, expect, it } from 'vitest'
import type { PartageNaissance as Partage, VerrouDePartage } from '@/lib/api'
import PartageNaissance from './PartageNaissance.vue'

/**
 * Le bloc « J'accepte de partager ma date de naissance » — rendu côté serveur.
 *
 * Trois cases indépendantes, chacune avec sa valeur ; la règle de réciprocité
 * et la phrase sur la photo dites avant qu'on coche, avec l'avertissement des
 * trente jours ; une case cochée verrouillée éteinte, avec sa date et le
 * chemin de la demande RGPD.
 */

async function rendre(
  partage: Partage,
  options: { verrou?: VerrouDePartage; sansExplication?: boolean } = {},
): Promise<string> {
  return renderToString(
    createSSRApp({ render: () => h(PartageNaissance, { modelValue: partage, ...options }) }),
  )
}

/** La balise `<input>` d'une composante. */
function caseDe(html: string, cle: string): string {
  return new RegExp(`<input[^>]*data-composante="${cle}"[^>]*>`).exec(html)?.[0] ?? ''
}

describe('PartageNaissance', () => {
  it('porte trois cases indépendantes et la règle de réciprocité', async () => {
    const html = await rendre({ annee: false, mois: true, jour: true })

    expect(html).toContain('J’accepte de partager ma date de naissance')
    expect(html.match(/type="checkbox"/g)).toHaveLength(3)
    expect(html).toMatch(/data-composante="annee"/)
    expect(caseDe(html, 'annee')).not.toContain('checked')
    expect(caseDe(html, 'mois')).toContain('checked')
    expect(caseDe(html, 'jour')).toContain('checked')
    expect(html).toContain('Ce que vous ne partagez pas, vous ne le verrez pas non plus chez les autres personnes vivantes.')
    expect(html).toContain('Les personnes décédées ne sont pas concernées.')
    expect(html).toContain('Sans photo de vous (même en attente de validation)')
  })

  it('avertit des trente jours avant qu’on coche', async () => {
    const html = await rendre({ annee: false, mois: false, jour: false })
    expect(html).toContain('data-avertissement-partage')
    expect(html).toContain('Une information partagée reste visible des autres membres au moins 30 jours.')
    expect(html).not.toContain('retiré récemment')
  })

  it('éteint une case cochée verrouillée et dit jusqu’à quand, jamais une case non cochée', async () => {
    const html = await rendre(
      { annee: false, mois: true, jour: true },
      { verrou: { annee: '2026-11-02T12:00:00+01:00', mois: '2026-11-02T12:00:00+01:00' } },
    )

    expect(caseDe(html, 'mois')).toContain('disabled')
    // Non cochée : partager reste toujours possible.
    expect(caseDe(html, 'annee')).not.toContain('disabled')
    expect(caseDe(html, 'jour')).not.toContain('disabled')
    expect(html).toContain('data-verrou="mois"')
    expect(html).not.toContain('data-verrou="annee"')
    expect(html).toMatch(/Partagé jusqu’au\s+2 novembre 2026 au minimum\. Pour le retirer avant,/)
    expect(html).toContain('data-demande-rgpd="mois"')
    expect(html).toContain('faites une demande RGPD')
    // Il reste des cases à cocher : l'avertissement tient.
    expect(html).toContain('data-avertissement-partage')
  })

  it('se tait sur l’avertissement quand tout est partagé et verrouillé', async () => {
    const date = '2026-11-02T12:00:00+01:00'
    const html = await rendre({ annee: true, mois: true, jour: true }, { verrou: { annee: date, mois: date, jour: date } })
    expect(html.match(/disabled/g)).toHaveLength(3)
    expect(html).not.toContain('data-avertissement-partage')
  })

  it('se tait sur la règle quand l’administrateur coche pour un tiers', async () => {
    const html = await rendre({ annee: true, mois: false, jour: false }, { sansExplication: true })
    expect(html).not.toContain('Ce que vous ne partagez pas')
    expect(html).not.toContain('Sans photo de vous')
    expect(html).not.toContain('data-avertissement-partage')
  })
})
