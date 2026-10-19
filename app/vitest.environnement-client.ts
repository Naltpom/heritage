import type { Environment } from 'vitest/runtime'

/**
 * L'environnement des tests `*.client.test.ts` : Node, sans DOM, mais avec les `.vue`
 * compilés **pour le client** (`viteEnvironment: 'client'`) — ce que faisait
 * `testTransformMode.web` jusqu'à Vitest 3, supprimé depuis. Voir `vite.config.ts`.
 */
export default <Environment>{
  name: 'node-client',
  viteEnvironment: 'client',
  setup() {
    return { teardown() {} }
  },
}
