import { createSSRApp, h } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { describe, expect, it } from 'vitest'
import type { CarenceDePartage, PartageNaissance as Partage } from '@/lib/api'
import PartageNaissance from './PartageNaissance.vue'

/**
 * Le bloc « J'accepte de partager ma date de naissance » — rendu côté serveur.
 *
 * Trois cases indépendantes, chacune avec sa valeur ; la règle de réciprocité
 * et la phrase sur la photo dites avant qu'on coche ; une case en carence
 * éteinte, avec sa date.
 */

async function rendre(
  partage: Partage,
  options: { carence?: CarenceDePartage; sansExplication?: boolean } = {},
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

  it('éteint une case retirée récemment et dit à partir de quand', async () => {
    const html = await rendre(
      { annee: false, mois: true, jour: true },
      { carence: { annee: '2026-11-02T12:00:00+01:00', mois: '2026-11-02T12:00:00+01:00' } },
    )

    expect(caseDe(html, 'annee')).toContain('disabled')
    // Cochée : retirer reste toujours possible.
    expect(caseDe(html, 'mois')).not.toContain('disabled')
    expect(html).toContain('data-carence="annee"')
    expect(html).not.toContain('data-carence="mois"')
    expect(html).toContain('vous pourrez le partager à nouveau à partir du 2 novembre 2026')
  })

  it('se tait sur la règle quand l’administrateur coche pour un tiers', async () => {
    const html = await rendre({ annee: true, mois: false, jour: false }, { sansExplication: true })
    expect(html).not.toContain('Ce que vous ne partagez pas')
    expect(html).not.toContain('Sans photo de vous')
  })
})
