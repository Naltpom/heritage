/// <reference types="vitest/config" />
import { readFileSync } from 'node:fs'
import { createRequire } from 'node:module'
import { dirname, join } from 'node:path'
import { fileURLToPath, URL } from 'node:url'
import { defineConfig, loadEnv, type Plugin } from 'vite'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'

/**
 * Les décodeurs d'images de pdf.js — CCITT, JBIG2, JPEG 2000 —, servis sous un
 * chemin **fixe** de la même origine.
 *
 * Depuis pdf.js 6, ces décodeurs ne sont plus dans le worker : il les importe à
 * l'exécution depuis `wasmUrl` + un nom de fichier qu'il compose lui-même. Un
 * `import …?url` ne convient donc pas (Vite hacherait le nom). Sans eux, un scan
 * de mairie — texte en masque CCITT sur fond JPEG — s'affiche **sans son texte**,
 * sans erreur visible : c'est ce qui a été constaté sur les premiers actes.
 *
 * Seules les versions **JavaScript** sont servies : la CSP du site n'autorise pas
 * le WebAssembly (`'wasm-unsafe-eval'` absent), et `pdf.ts` passe `useWasm: false`.
 */
const CHEMIN_DECODEURS_PDF = 'pdfjs-decodeurs/'

function decodeursPdfJs(): Plugin {
  const dossier = join(dirname(createRequire(import.meta.url).resolve('pdfjs-dist/package.json')), 'wasm')
  const fichiers = ['jbig2_nowasm_fallback.js', 'openjpeg_nowasm_fallback.js']

  return {
    name: 'heritage:decodeurs-pdfjs',
    configureServer(serveur) {
      serveur.middlewares.use(`/${CHEMIN_DECODEURS_PDF}`, (requete, reponse, suite) => {
        const nom = (requete.url ?? '').replace(/^\//, '').split('?')[0] ?? ''
        if (!fichiers.includes(nom)) return suite()
        reponse.setHeader('Content-Type', 'text/javascript; charset=utf-8')
        reponse.end(readFileSync(join(dossier, nom)))
      })
    },
    generateBundle() {
      for (const nom of fichiers) {
        this.emitFile({
          type: 'asset',
          fileName: `${CHEMIN_DECODEURS_PDF}${nom}`,
          source: readFileSync(join(dossier, nom)),
        })
      }
    },
  }
}

export default defineConfig(({ mode }) => {
  // Préfixe vide : on veut aussi les variables passées par le shell ou par
  // Docker Compose, pas seulement celles préfixées VITE_ des fichiers .env.
  const env = loadEnv(mode, process.cwd(), '')

  // Hôte de l'API visé par le proxy de développement.
  //   - hors conteneur : http://127.0.0.1:8000
  //   - dans Docker    : http://api:8000 (nom du service compose)
  // Configurable pour que les deux cas marchent sans toucher au fichier.
  const cibleApi = env.VITE_API_PROXY_TARGET || 'http://127.0.0.1:8000'

  return {
    plugins: [vue(), tailwindcss(), decodeursPdfJs()],
    test: {
      // Les tests de rendu passent par le SSR (`renderToString`), qui ne sait ni
      // attendre une lecture ni cliquer. Les fichiers `*.client.test.ts` montent
      // l'écran dans un rendu en mémoire, sans DOM, et il leur faut les `.vue`
      // compilés **pour le client** — un composant compilé pour le serveur n'a
      // qu'un `ssrRender`, que rien ne peut monter.
      testTransformMode: { web: ['**/*.client.test.ts'] },
    },
    resolve: {
      alias: {
        '@': fileURLToPath(new URL('./src', import.meta.url)),
      },
    },
    server: {
      // Écoute sur toutes les interfaces dans un conteneur, sur la boucle locale
      // sinon : une appli familiale n'a pas à s'exposer au réseau du logement.
      host: env.VITE_DEV_HOST || '127.0.0.1',
      port: Number(env.VITE_DEV_PORT || 5173),
      proxy: {
        // Le front et l'API partagent la même origine, en développement comme en
        // production (§ 2 du dossier de mission). Aucun CORS n'est ouvert côté
        // Symfony : c'est ce proxy — et nginx en production — qui unifie
        // l'origine, ce qui permet au cookie de session d'être HttpOnly +
        // SameSite=Lax sans exception.
        '/api': {
          target: cibleApi,
          // Volontairement false : l'API doit voir le Host du navigateur pour
          // fabriquer des URLs absolues (liens d'invitation) cohérentes.
          changeOrigin: false,
        },
      },
    },
    build: {
      // Repérer une régression de poids sur le canevas de l'arbre (§ 7.2) avant
      // qu'elle n'arrive chez le commanditaire.
      chunkSizeWarningLimit: 700,
      rollupOptions: {
        output: {
          // Le worker de pdf.js arrive en `.mjs`, une extension que le
          // `mime.types` de nginx ne garantit pas : servi en
          // `application/octet-stream`, un worker de module est refusé par le
          // navigateur. Un worker se déclare module par son option `type`, pas
          // par son extension — `.js` suffit.
          assetFileNames: (actif) =>
            actif.names.some((nom) => nom.endsWith('.mjs'))
              ? 'assets/[name]-[hash].js'
              : 'assets/[name]-[hash][extname]',
        },
      },
    },
  }
})
