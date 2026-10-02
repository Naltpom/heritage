<script lang="ts">
/**
 * Les quatre onglets d'Explorer, dans leur ordre (Stats ajouté le 02/10/2026).
 *
 * Dans un bloc `<script>` ordinaire, et non dans le `<script setup>`, pour la
 * raison qu'explique `AnniversairesView` : ce qui est dans une fermeture de
 * `<script setup>` ne s'importe pas, et la suite `explorer-rendu.test.ts`
 * vérifie l'ordre et les libellés sans avoir à les recopier.
 *
 * **L'ordre fait le sens du glissement.** `ContenuGlissant` le lit pour savoir
 * si l'on va « vers la droite » (onglet suivant) ou « vers la gauche » : le
 * réordonner ici change la direction de l'animation, sans rien d'autre à
 * toucher.
 *
 * **Les clés sont des noms de route**, et les noms de route sont un contrat :
 * les notifications et les liens partagés y renvoient. « Calendrier » garde donc
 * la route `anniversaires` — c'est le libellé qui a changé, pas le nom.
 */
export const ONGLETS_EXPLORER = [
  { cle: 'chronologie', libelle: 'Chronologie', vers: { name: 'chronologie' } },
  { cle: 'carte', libelle: 'Carte', vers: { name: 'carte' } },
  { cle: 'anniversaires', libelle: 'Calendrier', vers: { name: 'anniversaires' } },
  { cle: 'stats', libelle: 'Stats', vers: { name: 'stats' } },
] as const

export type OngletExplorer = (typeof ONGLETS_EXPLORER)[number]['cle']

const CLES: readonly string[] = ONGLETS_EXPLORER.map((o) => o.cle)

/** L'onglet d'un nom de route. Hors des quatre (la route parente, le temps de sa
 *  redirection), c'est le premier : la rangée n'est jamais sans onglet actif. */
export function ongletDe(nom: unknown): OngletExplorer {
  return typeof nom === 'string' && CLES.includes(nom) ? (nom as OngletExplorer) : 'chronologie'
}
</script>

<script setup lang="ts">
/**
 * Explorer — une page, quatre onglets qui glissent (refonte du 30/09/2026).
 *
 * La chronologie, la carte et le calendrier étaient trois entrées de l'en-tête ;
 * ils deviennent trois onglets d'une seule entrée, et trois **routes enfants**
 * (`/explorer/chronologie`, `/explorer/carte`, `/explorer/calendrier`) : l'onglet
 * vit dans l'URL, si bien qu'il se partage, se recharge, et que « précédent »
 * revient à l'onglet d'avant.
 *
 * Cette vue ne fait que deux choses : poser la rangée d'onglets
 * (`OngletsGlissants`), et faire coulisser l'écran de l'onglet dessous
 * (`ContenuGlissant`). Chaque écran garde tout le reste — sa charge, ses
 * réglages, sa barre d'écran, la mesure de sa hauteur.
 *
 * ────────────────────────────────────────────────────────────────────
 * **LE GLISSEMENT DU DOIGT NE CHANGE PAS D'ONGLET.** C'est voulu, et il ne faut
 * pas « l'ajouter ». La carte se déplace au doigt, la chronologie aussi : un
 * balayage horizontal qui changerait d'onglet volerait le geste principal de
 * deux écrans sur trois, et le lecteur changerait d'écran en voulant voir plus
 * loin. Seul le **tap sur l'onglet** change d'onglet ; l'animation dit d'où
 * l'on vient, elle n'invite pas à balayer.
 *
 * **Pas de titre d'écran sous la rangée.** L'onglet actif *est* le titre : un
 * « Chronologie » de plus dans la barre d'écran ferait doublon. Les barres
 * d'écran n'emploient donc pas leur emplacement `#titre`.
 *
 * **Pas de `KeepAlive`.** Changer d'onglet remonte l'écran, qui recharge sa
 * donnée. Garder les trois en vie demanderait à chacun de retirer ses écoutes
 * clavier et de rendre son résumé d'en-tête à la désactivation — trois écrans à
 * reprendre pour épargner un appel que le serveur rend vite. Et `ContenuGlissant`
 * enveloppe chaque onglet dans un bloc à clé : un `KeepAlive` posé dedans serait
 * recréé à chaque changement, donc sans mémoire.
 * ────────────────────────────────────────────────────────────────────
 *
 * **La hauteur.** Les quatre écrans mesurent le haut de leur cadre
 * (`hauteurDuCadre()`, `views/cadre.ts`) : la rangée d'onglets est donc déduite
 * d'elle-même, sans que cette vue ait à la déclarer. Pendant l'animation,
 * l'écran sortant quitte le flux (voir `ContenuGlissant`) : l'entrant se monte
 * déjà à sa place définitive, et sa mesure de montage est juste. On lui demande
 * tout de même de **remesurer une fois en place** (`apres`) — la translation ne
 * change pas une boîte, mais une carte Leaflet montée pendant le mouvement
 * gagne à recalculer sa taille, et cela ne coûte rien.
 */
import { computed, type ComponentPublicInstance } from 'vue'
import { useRoute } from 'vue-router'
import ContenuGlissant from '@/components/mise-en-page/ContenuGlissant.vue'
import OngletsGlissants from '@/components/mise-en-page/OngletsGlissants.vue'

const route = useRoute()
const actif = computed(() => ongletDe(route.name))

/** Ce que chaque écran d'Explorer expose : de quoi se remesurer une fois posé. */
interface EcranExplorer {
  remesurer?: () => void
}

/**
 * L'écran de l'onglet affiché, retenu par une **fonction** de `ref` et jamais
 * effacé. Une `ref` nommée ne convient pas ici : pendant le glissement, deux
 * écrans sont montés, et le sortant, démonté à la fin de son animation —
 * c'est-à-dire au moment même où `apres` part —, remettrait la `ref` à `null`
 * par-dessus l'entrant. Le dernier écran monté est toujours celui de l'onglet
 * actif : c'est lui qu'on garde.
 */
let ecran: EcranExplorer | null = null

function retenirEcran(instance: Element | ComponentPublicInstance | null): void {
  // Un `<component :is>` rend toujours une instance, jamais un élément nu.
  if (instance !== null) ecran = instance as unknown as EcranExplorer
}

function surEcranEnPlace(): void {
  ecran?.remesurer?.()
}
</script>

<template>
  <div class="flex flex-col">
    <div class="flex-none border-b border-ligne bg-fond md:px-5">
      <OngletsGlissants
        :onglets="ONGLETS_EXPLORER"
        :actif="actif"
        etiquette="Explorer"
        egaux
        class="md:max-w-[520px]"
      />
    </div>

    <RouterView v-slot="{ Component, route: affichee }">
      <ContenuGlissant :ordre="CLES" :actif="ongletDe(affichee.name)" @apres="surEcranEnPlace">
        <component :is="Component" v-if="Component" :ref="retenirEcran" />
      </ContenuGlissant>
    </RouterView>
  </div>
</template>
