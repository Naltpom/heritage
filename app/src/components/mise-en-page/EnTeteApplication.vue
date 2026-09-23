<script setup lang="ts">
/**
 * L'en-tête applicatif : identité, navigation, compte.
 *
 * La navigation suit le **régime** et non le rôle : un compte en attente de
 * validation accède à l'arbre et à la chronologie (§ 12.1), mais pas à la
 * contribution. Les entrées d'administration n'apparaissent que pour
 * `ROLE_ADMIN`.
 *
 * Masquer une entrée de menu n'est **pas** une mesure de sécurité — les gardes
 * de route la doublent, et le serveur les double toutes les deux. C'est une
 * mesure de lisibilité : on ne propose pas un écran qui refusera. **Le tiroir
 * mobile ne change rien à cela** : il déplace les entrées, il n'en retire
 * aucune, et rien n'y devient une autorisation.
 *
 * Le créneau `actions` est laissé aux écrans qui ajoutent un contrôle global
 * (compteur de notifications, recherche) sans modifier ce fichier. Sur mobile il
 * atterrit dans le tiroir, avec le reste.
 *
 * **« Proposer une personne » est ici, et pas dans la barre d'outils de
 * l'arbre.** La personne à ajouter est justement celle qui n'a pas de fiche : le
 * geste ne part d'aucun contexte, et une barre d'outils est contextuelle. Dans
 * l'en-tête, il reste atteignable depuis n'importe quel écran, y compris la
 * chronologie. `PointCreation` porte ses deux conditions d'affichage lui-même —
 * rattachement validé, et hors bascule « voir comme un membre » — parce qu'elles
 * appartiennent au formulaire qu'il ouvre, pas au cadre qui l'affiche.
 *
 * Il est monté en `discret` : l'en-tête porte déjà « Se déconnecter », et deux
 * boutons pleins se disputeraient l'attention.
 *
 * **« Mes demandes » est ici pour la même raison.** La décision d'une demande
 * doit revenir à son auteur, et elle ne lui revenait pas : le serveur refuse
 * avec un motif obligatoire, le stocke et le sert, mais aucun écran ne
 * l'affichait et aucun chemin n'y menait — on n'atteignait le motif de son
 * propre refus qu'en tapant `/demandes/6` dans la barre d'adresse. Une capacité
 * entière tenait donc, là aussi, à deux lignes d'en-tête.
 *
 * Le lien suit le **régime**, comme le reste de cette barre : un compte en
 * attente de validation n'a aucune demande — l'API lui refuse le dépôt — et un
 * écran toujours vide est une promesse qu'on ne tient pas.
 *
 * (Ce paragraphe est dans le script et non en commentaire de template : un
 * commentaire de template est **rendu dans le HTML** — le rendu SSR d'un test
 * l'a montré en faisant échouer une assertion sur un texte qui n'existait que
 * dans ce commentaire. C'est arrivé une seconde fois en ajoutant « Mes
 * demandes » : trois tests d'absence sont tombés d'un coup, sur un texte qui
 * n'existait que dans le commentaire qu'on vient de déplacer ici.)
 *
 * ────────────────────────────────────────────────────────────────────
 * **LE MOBILE, ET CE QUI LE REND SÛR.**
 *
 * En deçà de `md`, cette barre s'enroulait sur quatre rangs et prenait 192 px
 * d'un écran de 400 — mesuré. Les entrées passent donc dans un tiroir, et les
 * deux boutons qui les commandent se tiennent aux deux bouts de la barre : la
 * navigation à gauche, les filtres de l'écran à droite.
 *
 * **Rien de ce qui suit ne peut atteindre un écran large.** Toute déclaration
 * mobile s'écrit `max-md:`, qui compile en `@media (width < 48rem)`, ou vit sur
 * un élément portant `md:hidden`. Aucune classe existante n'a été modifiée.
 *
 * **`md:contents` est la pièce maîtresse.** Au-delà du seuil, le conteneur du
 * tiroir ne génère aucune boîte : ses enfants redeviennent enfants flex directs
 * de l'en-tête, dans le même ordre, avec les mêmes gouttières. L'ordre aplati y
 * est donc, jeton pour jeton, celui d'avant ce commentaire.
 *
 * **Le burger est conditionné à `connecte`, et ce n'est pas une coquetterie.**
 * `entete-creation.test.ts` vérifie qu'un visiteur anonyme ne reçoit **aucun**
 * `<button>` — l'assertion porte sur l'élément et non sur le texte, précisément
 * parce qu'une assertion sur le texte avait déjà menti. Un burger inconditionnel
 * la ferait tomber. Et elle a raison : un en-tête anonyme n'a rien à ouvrir.
 *
 * **Aucun `useRoute()` ici.** Le même fichier de test simule `vue-router` en
 * n'exportant que `useRouter` ; un `useRoute()` ferait lever ses dix cas. La
 * fermeture des tiroirs au changement de page appartient de toute façon au
 * routeur, et elle est dans `router/index.ts`.
 *
 * **Pas de piège à focus, délibérément.** Ce sont des *disclosures*
 * (`aria-expanded` + `aria-controls`), pas des dialogues : on n'ajoute pas
 * `aria-modal`, qui seul rendrait le piège obligatoire. Fermé, le panneau est
 * `max-md:hidden` — donc retiré de la mise en page, du clavier **et** de l'arbre
 * d'accessibilité, et ce de façon bornée au point de rupture. `inert`, lui,
 * n'aurait pas de variante de largeur et fuirait sur grand écran.
 * ────────────────────────────────────────────────────────────────────
 */
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { deconnecter, useSession } from '@/composables/useSession'
import { useTiroirs } from '@/composables/useTiroirs'
import BoutonBase from '@/components/base/BoutonBase.vue'
import PointCreation from '@/components/proposition/PointCreation.vue'

const routeur = useRouter()
const { regime, connecte, rattache, administrateur, nomAffiche } = useSession()
const { ouvert, outilsDisponibles, basculerTiroir, fermerTiroirs } = useTiroirs()

const boutonMenu = ref<HTMLButtonElement | null>(null)
const boutonOutils = ref<HTMLButtonElement | null>(null)

/** Le focus revient au bouton qui a ouvert le tiroir. Sans ce retour, refermer
 *  au clavier renvoie le focus au début du document, et il faut retraverser la
 *  page pour revenir là où l'on était. */
function fermerEtRendreLeFocus(): void {
  const declencheur = ouvert.value === 'menu' ? boutonMenu.value : boutonOutils.value
  fermerTiroirs()
  declencheur?.focus()
}

async function quitter(): Promise<void> {
  fermerTiroirs()
  await deconnecter()
  await routeur.push({ name: 'connexion' })
}
</script>

<template>
  <header
    class="flex flex-wrap items-center gap-x-4 gap-y-2 border-b border-ligne bg-panneau px-4 py-2.5 max-md:relative max-md:flex-nowrap"
    @keydown.escape="fermerEtRendreLeFocus"
  >
    <button
      v-if="connecte"
      ref="boutonMenu"
      type="button"
      class="rounded-champ px-2 py-1.5 text-attenue hover:text-texte md:hidden max-md:relative max-md:z-40"
      :aria-label="ouvert === 'menu' ? 'Fermer le menu' : 'Ouvrir le menu'"
      :aria-expanded="ouvert === 'menu'"
      aria-controls="menu-principal"
      @click="basculerTiroir('menu')"
    >
      <svg width="20" height="20" viewBox="0 0 20 20" aria-hidden="true">
        <path
          d="M3 5h14M3 10h14M3 15h14"
          fill="none"
          stroke="currentColor"
          stroke-width="1.6"
          stroke-linecap="round"
        />
      </svg>
    </button>

    <RouterLink
      :to="{ name: 'arbre' }"
      class="text-sm font-semibold tracking-tight whitespace-nowrap max-md:relative max-md:z-40"
    >
      Heritage
    </RouterLink>

    <span class="h-5 w-px bg-ligne max-md:hidden" aria-hidden="true" />

    <div
      v-if="ouvert === 'menu'"
      class="fixed inset-0 z-30 bg-black/40 md:hidden"
      aria-hidden="true"
      @click="fermerTiroirs"
    />

    <div
      id="menu-principal"
      class="md:contents max-md:absolute max-md:inset-x-0 max-md:top-full max-md:z-40 max-md:flex max-md:flex-col max-md:items-stretch max-md:gap-1 max-md:border-b max-md:border-ligne max-md:bg-panneau max-md:p-3 max-md:shadow-panneau"
      :class="ouvert === 'menu' ? '' : 'max-md:hidden'"
    >
      <nav aria-label="Navigation principale" class="flex flex-wrap items-center gap-1">
        <RouterLink
          v-if="connecte"
          :to="{ name: 'arbre' }"
          class="rounded-champ px-2.5 py-1 text-sm text-attenue hover:text-texte"
          active-class="bg-accent-doux font-semibold text-texte"
        >
          Arbre
        </RouterLink>
        <RouterLink
          v-if="connecte"
          :to="{ name: 'chronologie' }"
          class="rounded-champ px-2.5 py-1 text-sm text-attenue hover:text-texte"
          active-class="bg-accent-doux font-semibold text-texte"
        >
          Chronologie
        </RouterLink>
        <RouterLink
          v-if="connecte"
          :to="{ name: 'carte' }"
          class="rounded-champ px-2.5 py-1 text-sm text-attenue hover:text-texte"
          active-class="bg-accent-doux font-semibold text-texte"
        >
          Carte
        </RouterLink>
        <RouterLink
          v-if="connecte"
          :to="{ name: 'anniversaires' }"
          class="rounded-champ px-2.5 py-1 text-sm text-attenue hover:text-texte"
          active-class="bg-accent-doux font-semibold text-texte"
        >
          Anniversaires
        </RouterLink>
        <!--
          `rattache` et non `connecte` : un compte dont le rattachement n'est pas
          validé n'a **pas de cercle** (ADR 0003), donc aucune question sur une
          personne vivante — et l'API lui refuse de toute façon le dépôt d'une
          réponse. Lui ouvrir l'entrée serait lui promettre une contribution
          impossible. La route porte la même exigence, `meta.acces: 'membre'` :
          le lien caché n'est pas la protection, il évite une impasse.
        -->
        <RouterLink
          v-if="rattache"
          :to="{ name: 'questions' }"
          class="rounded-champ px-2.5 py-1 text-sm text-attenue hover:text-texte"
          active-class="bg-accent-doux font-semibold text-texte"
        >
          Questions
        </RouterLink>
        <RouterLink
          v-if="rattache"
          :to="{ name: 'demandes.miennes' }"
          class="rounded-champ px-2.5 py-1 text-sm text-attenue hover:text-texte"
          active-class="bg-accent-doux font-semibold text-texte"
        >
          Mes demandes
        </RouterLink>
        <RouterLink
          v-if="administrateur"
          :to="{ name: 'administration' }"
          class="rounded-champ px-2.5 py-1 text-sm text-attenue hover:text-texte"
          active-class="bg-accent-doux font-semibold text-texte"
        >
          Administration
        </RouterLink>
      </nav>

      <span class="flex-1" />

      <PointCreation discret />

      <slot name="actions" />

      <template v-if="connecte">
        <div
          class="md:contents max-md:mt-1 max-md:flex max-md:flex-wrap max-md:items-center max-md:gap-2 max-md:border-t max-md:border-ligne max-md:pt-2"
        >
          <span class="max-w-[16rem] truncate text-xs text-attenue" :title="nomAffiche">
            {{ nomAffiche }}
          </span>
          <span v-if="regime === 'en_attente'" class="text-xs text-attenue">· en attente</span>
          <RouterLink
            :to="{ name: 'compte' }"
            class="rounded-champ px-2.5 py-1 text-xs text-attenue hover:text-texte"
            active-class="bg-accent-doux font-semibold text-texte"
          >
            Mon compte
          </RouterLink>
          <BoutonBase variante="discret" @click="quitter">Se déconnecter</BoutonBase>
        </div>
      </template>
    </div>

    <span class="flex-1 md:hidden" aria-hidden="true" />

    <button
      v-if="connecte && outilsDisponibles"
      ref="boutonOutils"
      type="button"
      class="rounded-champ px-2 py-1.5 text-attenue hover:text-texte md:hidden max-md:relative max-md:z-40"
      :aria-label="ouvert === 'outils' ? 'Fermer les filtres' : 'Ouvrir les filtres'"
      :aria-expanded="ouvert === 'outils'"
      aria-controls="tiroir-outils"
      @click="basculerTiroir('outils')"
    >
      <svg width="20" height="20" viewBox="0 0 20 20" aria-hidden="true">
        <path
          d="M3 5h14M6 10h8M9 15h2"
          fill="none"
          stroke="currentColor"
          stroke-width="1.6"
          stroke-linecap="round"
        />
      </svg>
    </button>
  </header>
</template>
