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
 * mesure de lisibilité : on ne propose pas un écran qui refusera.
 *
 * Le créneau `actions` est laissé aux écrans qui ajoutent un contrôle global
 * (compteur de notifications, recherche) sans modifier ce fichier.
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
 */
import { useRouter } from 'vue-router'
import { deconnecter, useSession } from '@/composables/useSession'
import BoutonBase from '@/components/base/BoutonBase.vue'
import PointCreation from '@/components/proposition/PointCreation.vue'

const routeur = useRouter()
const { regime, connecte, rattache, administrateur, nomAffiche } = useSession()

async function quitter(): Promise<void> {
  await deconnecter()
  await routeur.push({ name: 'connexion' })
}
</script>

<template>
  <header
    class="flex flex-wrap items-center gap-x-4 gap-y-2 border-b border-ligne bg-panneau px-4 py-2.5"
  >
    <RouterLink
      :to="{ name: 'arbre' }"
      class="text-sm font-semibold tracking-tight whitespace-nowrap"
    >
      Heritage
    </RouterLink>

    <span class="h-5 w-px bg-ligne" aria-hidden="true" />

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
      <span class="max-w-[16rem] truncate text-xs text-attenue" :title="nomAffiche">
        {{ nomAffiche }}
      </span>
      <span v-if="regime === 'en_attente'" class="text-xs text-attenue">· en attente</span>
      <BoutonBase variante="discret" @click="quitter">Se déconnecter</BoutonBase>
    </template>
  </header>
</template>
