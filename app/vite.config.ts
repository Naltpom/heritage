import { fileURLToPath, URL } from 'node:url'
import { defineConfig, loadEnv } from 'vite'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'

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
    plugins: [vue(), tailwindcss()],
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
    },
  }
})
