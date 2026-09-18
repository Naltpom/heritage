<script setup lang="ts">
/**
 * Le clic sur le lien de changement de courriel — § 2.5.
 *
 * Une seule chose à faire, et elle est faite au montage : présenter le jeton.
 * Deux issues, et l'écran ne cherche pas à en distinguer davantage :
 *
 * - succès : le courriel est changé. La session qui vient de confirmer, s'il y en
 *   avait une sur cet appareil, **continue** sous la nouvelle adresse ; celles
 *   ouvertes ailleurs sous l'ancienne tombent (§ 2.3, mesuré). L'écran propose
 *   donc la connexion sans l'imposer : sans session, c'est l'étape suivante ; avec,
 *   c'est un bouton de plus qui ne fait rien de mal ;
 * - refus : `404`, quel qu'en soit le motif — inconnu, périmé, déjà consommé, mal
 *   formé. Un lien ouvert deux fois, ou trop tard, tombe ici, et le message ne
 *   dit pas lequel : il n'a pas le moyen de le savoir, et c'est le but.
 *
 * Le jeton arrive en **prop** (`props: true` sur la route), pas par `useRoute()` :
 * la vue reste rendable sans routeur, ce que les tests SSR exigent.
 */
import { onMounted, ref } from 'vue'
import { analyserErreur, confirmerChangementDeCourriel } from '@/lib/api'
import BoutonBase from '@/components/base/BoutonBase.vue'
import MessageErreur from '@/components/base/MessageErreur.vue'
import PanneauBase from '@/components/base/PanneauBase.vue'

const { jeton } = defineProps<{ jeton: string }>()

const etat = ref<'en_cours' | 'confirme' | 'refuse' | 'erreur'>('en_cours')
const erreur = ref<unknown>(null)

onMounted(async () => {
  try {
    await confirmerChangementDeCourriel(jeton)
    etat.value = 'confirme'
  } catch (e) {
    erreur.value = e
    etat.value = analyserErreur(e)?.statut === 404 ? 'refuse' : 'erreur'
  }
})
</script>

<template>
  <div class="mx-auto flex min-h-[60vh] w-full max-w-md flex-col justify-center gap-4 px-4 py-8">
    <PanneauBase>
      <div class="flex flex-col gap-3 p-5">
        <h1 class="text-lg font-semibold">Changement de courriel</h1>

        <p v-if="etat === 'en_cours'" class="text-sm text-attenue" role="status">
          Confirmation en cours…
        </p>

        <template v-else-if="etat === 'confirme'">
          <p class="text-sm" role="status">
            Votre nouvelle adresse est confirmée : c'est elle qui vous identifie désormais.
            Les sessions ouvertes sur d'autres appareils devront se reconnecter avec elle.
          </p>
          <RouterLink :to="{ name: 'connexion' }" class="self-start">
            <BoutonBase variante="principal">Se connecter</BoutonBase>
          </RouterLink>
        </template>

        <template v-else-if="etat === 'refuse'">
          <p class="text-sm">
            Ce lien n'est plus valable. Il a peut-être déjà servi, ou son délai est passé :
            depuis votre compte, demandez un nouveau lien.
          </p>
          <RouterLink :to="{ name: 'connexion' }" class="self-start">
            <BoutonBase variante="secondaire">Se connecter</BoutonBase>
          </RouterLink>
        </template>

        <MessageErreur v-else :erreur="erreur" />
      </div>
    </PanneauBase>
  </div>
</template>
