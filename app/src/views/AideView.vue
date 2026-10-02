<script setup lang="ts">
/**
 * « Aide » (`/aide`) — les vidéos de présentation, en cartes.
 *
 * Deux sections, dans l'ordre où l'on en a besoin :
 *
 * 1. **Bien commencer** — « Bienvenue » et « Découvrir » : ce qu'on montre à
 *    qui arrive, et à qui veut le revoir ;
 * 2. **Chaque fonctionnalité** — un chapitre par écran ou par geste, avec un
 *    lien vers l'écran dont il parle.
 *
 * Une carte, c'est l'affiche, la durée (« 0:35 »), le titre et une phrase. Un
 * toucher ouvre le lecteur plein écran (`LecteurVideo`) ; à la fin, il se
 * referme de lui-même, comme à la croix.
 *
 * **La vidéo ouverte vit dans l'adresse** (`?video=<id>`), et c'est la seule
 * source de vérité : toucher une carte écrit le paramètre, fermer le lecteur le
 * retire, et la vue ne fait que le lire. D'où deux choses gratuites : un lien
 * `…/aide?video=filtres-de-l-arbre` partagé dans la famille ouvre directement
 * la bonne vidéo, et un rechargement ne la perd pas. `replace` et non `push` :
 * le bouton « précédent » quitte la page Aide, il ne rejoue pas les vidéos
 * déjà fermées. Un identifiant inconnu est ignoré (`videoDeLaRequete`) : un
 * lien vers une vidéo retirée ouvre la page, pas une erreur.
 *
 * **Une affiche qui ne charge pas** (fichier pas encore déposé, réseau) laisse
 * la carte propre : l'image est retirée, et il reste le fond neutre de la
 * charte avec le triangle de lecture — jamais l'icône d'image cassée du
 * navigateur.
 *
 * `membre` (voir la route) : les vidéos montrent l'arbre en action, et l'entrée
 * qui y mène est dans « Moi », avec les autres pages d'un membre.
 */
import { computed, reactive } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import LecteurVideo from '@/components/video/LecteurVideo.vue'
import {
  cheminsDe,
  dureeEnToutesLettres,
  formaterDuree,
  videoDeLaRequete,
  videosDuGroupe,
  type VideoAide,
} from '@/lib/videos'

const route = useRoute()
const routeur = useRouter()

const SECTIONS = [
  { cle: 'bien-commencer', titre: 'Bien commencer', videos: videosDuGroupe('bien-commencer') },
  { cle: 'fonctionnalite', titre: 'Chaque fonctionnalité', videos: videosDuGroupe('fonctionnalite') },
] as const

/** La vidéo ouverte : celle que nomme l'adresse, ou aucune. */
const courante = computed<VideoAide | null>(() => videoDeLaRequete(route.query['video']))

/** Les affiches qui n'ont pas chargé : leur carte garde le fond neutre. */
const affichesEnEchec = reactive(new Set<string>())

function ouvrir(video: VideoAide): void {
  void routeur.replace({ query: { ...route.query, video: video.id } })
}

function fermer(): void {
  const query = { ...route.query }
  delete query['video']
  void routeur.replace({ query })
}
</script>

<template>
  <div class="mx-auto w-full max-w-4xl px-4 py-6">
    <header class="mb-6">
      <h1 class="text-xl font-semibold text-texte">Aide</h1>
      <p class="mt-1 text-sm text-attenue">
        De courtes vidéos pour prendre Heritage en main. Elles démarrent sans le son : touchez « Activer le son ».
      </p>
    </header>

    <section v-for="s in SECTIONS" :key="s.cle" class="mb-8" :aria-labelledby="`aide-${s.cle}`">
      <h2 :id="`aide-${s.cle}`" class="mb-3 text-xs font-semibold tracking-[.08em] text-attenue uppercase">
        {{ s.titre }}
      </h2>

      <ul class="m-0 grid list-none grid-cols-2 gap-3 p-0 sm:grid-cols-3 md:grid-cols-4 md:gap-4">
        <li
          v-for="v in s.videos"
          :key="v.id"
          :data-carte-video="v.id"
          class="flex flex-col overflow-hidden rounded-panneau border border-ligne bg-panneau"
        >
          <button
            type="button"
            class="group flex flex-1 flex-col text-left"
            :aria-label="`${v.titre} — vidéo de ${dureeEnToutesLettres(v.dureeSecondes)}`"
            @click="ouvrir(v)"
          >
            <!-- L'affiche : 4:5, recadrée au centre — assez haute pour qu'on
                 reconnaisse l'écran, assez basse pour qu'une rangée tienne. -->
            <span class="relative block aspect-[4/5] w-full overflow-hidden bg-accent-doux">
              <img
                v-if="!affichesEnEchec.has(v.id)"
                :src="cheminsDe(v).affiche"
                alt=""
                loading="lazy"
                decoding="async"
                class="absolute inset-0 h-full w-full object-cover transition-transform duration-300 group-hover:scale-[1.03] motion-reduce:transition-none motion-reduce:group-hover:scale-100"
                @error="affichesEnEchec.add(v.id)"
              />
              <span class="absolute inset-0 flex items-center justify-center" aria-hidden="true">
                <span class="flex size-12 items-center justify-center rounded-full bg-black/45 text-white backdrop-blur-sm group-hover:bg-accent">
                  <svg viewBox="0 0 24 24" class="ml-0.5 size-6" fill="currentColor"><path d="M8 5.5v13l10.5-6.5z" /></svg>
                </span>
              </span>
              <span
                class="absolute right-2 bottom-2 rounded-pastille bg-black/65 px-1.5 py-0.5 font-mono text-[11px] font-semibold text-white tnum"
                aria-hidden="true"
              >{{ formaterDuree(v.dureeSecondes) }}</span>
            </span>
            <span class="flex flex-col gap-1 px-3 pt-2.5 pb-3">
              <span class="text-sm font-semibold leading-snug text-texte">{{ v.titre }}</span>
              <span class="text-xs leading-snug text-attenue">{{ v.description }}</span>
            </span>
          </button>
          <!-- Hors du bouton : un lien dans un bouton n'est ni l'un ni l'autre. -->
          <RouterLink
            v-if="v.ecran"
            :to="{ name: v.ecran.nom }"
            class="flex min-h-11 items-center border-t border-ligne px-3 text-xs font-semibold text-accent hover:bg-accent-doux"
          >
            {{ v.ecran.libelle }} <span class="ml-1" aria-hidden="true">›</span>
          </RouterLink>
        </li>
      </ul>
    </section>

    <LecteurVideo v-if="courante" :key="courante.id" :video="courante" @fin="fermer" @fermer="fermer" />
  </div>
</template>
