<script setup lang="ts">
/**
 * **Demander la suppression d'une fiche** — § 6.4 quater.
 *
 * C'est la demande du commanditaire, dans ses mots : *« crée la route en vrai
 * pour le faire et ajoute un CTA sur les fiches, et c'est comme une
 * proposition — nécessite un commentaire évidemment, pour que l'admin du site
 * comprenne pourquoi on demande la suppression »*.
 *
 * ────────────────────────────────────────────────────────────────────
 * ## Trois choses que cet écran fait, et qu'aucun autre formulaire ne fait
 *
 * 1. **Il dit ce qu'il efface avant qu'on écrive.** L'aperçu est chargé à
 *    l'ouverture, et le motif est demandé après — pas l'inverse. Découvrir
 *    qu'on visait la mauvaise fiche *après* avoir rédigé sa justification est
 *    exactement ce que cet ordre évite.
 * 2. **Il éteint son bouton tant que le serveur n'a pas dit oui.** `bloquee`
 *    vient de l'API et ne se recalcule pas ici : la règle connaît les six clés
 *    étrangères de `tree_person`, une copie en TypeScript divergerait — dans le
 *    sens permissif.
 * 3. **Il exige un motif substantiel.** C'est la seule nature de demande où
 *    `message` est obligatoire, parce que `POST /api/demandes/{id}/valider` ne
 *    lit aucun corps : une validation ne laisse aucune trace écrite de sa
 *    raison. Le motif vit sur la demande, déposé à la création, ou nulle part.
 *
 * ## Ce que le bandeau rouge dit, et ce qu'il ne dit pas
 *
 * Il dit « cette fiche ne peut pas être supprimée », jamais « vous n'avez pas le
 * droit ». La différence compte : les deux blocages — une descendance rattachée,
 * une preuve jointe — ne sont pas des refus d'autorisation mais des constats sur
 * la fiche, et chacun a une suite possible. Pour un doublon, c'est presque
 * toujours l'**autre** fiche qu'il faut viser, et le message le dit.
 *
 * ## Une fiche réduite n'arrive jamais ici
 *
 * Le composant qui mène à cet écran n'est monté que sur une fiche complète, et
 * l'aperçu rendrait `404` de toute façon — le filtrage de visibilité est celui
 * de la cible, appliqué en amont par `Tree`.
 * ────────────────────────────────────────────────────────────────────
 */
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  analyserErreur,
  creerDemande,
  invaliderGraphe,
  lireApercuDeSuppression,
  type ApercuDeSuppression,
} from '@/lib/api'
import { useSession } from '@/composables/useSession'
import BoutonBase from '@/components/base/BoutonBase.vue'
import MessageErreur from '@/components/base/MessageErreur.vue'
import PanneauBase from '@/components/base/PanneauBase.vue'
import SqueletteChargement from '@/components/base/SqueletteChargement.vue'
import { ecrituresSuspendues, motifSuspension } from '@/components/administration/ecriture'
import { conflitDeDemande, type ConflitDeDemande } from '@/components/proposition/conflit'
import PanneauConflit from '@/components/proposition/PanneauConflit.vue'
import {
  corpsDeSuppression,
  envoiPossible,
  erreurDeMotif,
  lignesEmportees,
} from '@/components/proposition/suppression'

const route = useRoute()
const routeur = useRouter()
const { vueEmpruntee } = useSession()

const apercu = ref<ApercuDeSuppression | null>(null)
const motif = ref('')
/** L'erreur de motif n'apparaît qu'après une première tentative : la signaler
 *  sur un champ jamais touché accuse quelqu'un qui n'a encore rien fait. */
const motifTouche = ref(false)

const chargement = ref(false)
const envoi = ref(false)
const erreur = ref<unknown>(null)
const conflit = ref<ConflitDeDemande | null>(null)
/** L'erreur du serveur sur `message`, qui l'emporte sur la validation locale. */
const erreurServeur = ref('')

const identifiant = computed(() => Number(route.params['id']))

const suspendu = computed(() => ecrituresSuspendues(vueEmpruntee.value))
const motifSuspendu = computed(() => motifSuspension(vueEmpruntee.value))

const lignes = computed(() => (apercu.value === null ? [] : lignesEmportees(apercu.value.inventaire)))
const bloquantes = computed(() => lignes.value.filter((ligne) => ligne.bloquante))
const emportees = computed(() => lignes.value.filter((ligne) => !ligne.bloquante))

const erreurMotifAffichee = computed(() => {
  if (erreurServeur.value.length > 0) return erreurServeur.value
  return motifTouche.value ? (erreurDeMotif(motif.value) ?? '') : ''
})

const peutEnvoyer = computed(() => envoiPossible(apercu.value, motif.value) && !suspendu.value)

async function charger(): Promise<void> {
  chargement.value = true
  erreur.value = null
  conflit.value = null

  try {
    apercu.value = await lireApercuDeSuppression(identifiant.value)
  } catch (cause) {
    erreur.value = cause
  } finally {
    chargement.value = false
  }
}

watch(identifiant, charger, { immediate: true })

async function deposer(): Promise<void> {
  motifTouche.value = true
  erreurServeur.value = ''

  if (!peutEnvoyer.value || envoi.value) return

  envoi.value = true
  erreur.value = null
  conflit.value = null

  try {
    const demande = await creerDemande(corpsDeSuppression(identifiant.value, motif.value))

    // Le graphe incorpore les nœuds en attente du lecteur : son `ETag` est périmé
    // dès qu'une demande naît (§ 5.2, § 13.4).
    invaliderGraphe()

    await routeur.push({ name: 'demande.detail', params: { id: demande.id } })
  } catch (cause) {
    const reconnu = conflitDeDemande(cause)

    if (reconnu !== null) {
      conflit.value = reconnu
      return
    }

    // Le message du serveur l'emporte sur la validation locale : c'est la règle
    // réellement appliquée (§ 15.1), et les deux peuvent diverger.
    const champs = analyserErreur(cause).champs

    erreurServeur.value = champs['message'] ?? ''

    // Une `422` sur `targetId` veut dire que la fiche a changé sous nos pieds :
    // une preuve ou une descendance est apparue depuis le chargement de l'aperçu.
    // On **relit** plutôt que de poser un message sur un champ que l'auteur ne
    // peut pas corriger — il n'a pas fait de faute de saisie, la fiche a bougé.
    if (champs['targetId'] !== undefined) {
      void charger()
    } else if (erreurServeur.value === '') {
      erreur.value = cause
    }
  } finally {
    envoi.value = false
  }
}
</script>

<template>
  <div class="mx-auto w-full max-w-3xl px-4 py-8">
    <div v-if="chargement && apercu === null">
      <PanneauBase>
        <p class="mb-3 text-sm text-attenue" role="status">Lecture de ce que cette fiche porte…</p>
        <SqueletteChargement :lignes="4" />
      </PanneauBase>
    </div>

    <MessageErreur v-else-if="erreur !== null" :erreur="erreur" @action="charger" />

    <template v-else-if="apercu !== null">
      <header class="mb-5">
        <h1 class="text-xl font-semibold tracking-tight">
          Demander la suppression — {{ apercu.inventaire.displayName }}
        </h1>
        <p class="mt-1.5 text-sm text-attenue">
          Un administrateur lira votre motif avant de décider.
        </p>
      </header>

      <p
        v-if="motifSuspendu !== null"
        class="mb-4 rounded-panneau border border-alerte-bord bg-alerte-doux px-3.5 py-3 text-sm"
        role="status"
      >
        {{ motifSuspendu }}
      </p>

      <PanneauConflit v-if="conflit !== null" :conflit="conflit" class="mb-4" @reessayer="deposer" />

      <!-- ------------------------------------------------------- -->
      <!-- Le blocage : un constat sur la fiche, pas un refus de    -->
      <!-- droit — et il dit quoi faire à la place.                 -->
      <!-- ------------------------------------------------------- -->
      <PanneauBase v-if="apercu.bloquee" titre="Cette fiche ne peut pas être supprimée" class="mb-4">
        <ul class="m-0 list-disc space-y-2 pl-5 text-sm">
          <li v-for="(raison, index) in apercu.raisons" :key="index">{{ raison }}</li>
        </ul>
      </PanneauBase>

      <!-- ------------------------------------------------------- -->
      <!-- Ce que la suppression emporte — la règle « rien ne part  -->
      <!-- en silence », côté auteur.                               -->
      <!-- ------------------------------------------------------- -->
      <PanneauBase titre="Ce que la suppression emporterait" class="mb-4">
        <ul v-if="emportees.length > 0" class="m-0 list-disc space-y-1 pl-5 text-sm">
          <li v-for="(ligne, index) in emportees" :key="index">{{ ligne.libelle }}</li>
        </ul>

        <p v-else class="m-0 text-sm">
          Rien d’autre que la fiche&nbsp;: ni note, ni source, ni union.
        </p>

        <ul v-if="bloquantes.length > 0" class="mt-3 m-0 list-disc space-y-1 pl-5 text-sm text-alerte">
          <li v-for="(ligne, index) in bloquantes" :key="index">{{ ligne.libelle }}</li>
        </ul>

        <p class="mt-3 mb-0 text-xs text-attenue">
          L’historique de la fiche est conservé.
        </p>
      </PanneauBase>

      <!-- ------------------------------------------------------- -->
      <!-- Le motif — obligatoire, et la seule nature où il l'est.  -->
      <!-- ------------------------------------------------------- -->
      <PanneauBase titre="Pourquoi cette fiche doit-elle disparaître&nbsp;?" class="mb-4">
        <label class="flex flex-col gap-1">
          <span class="text-sm font-medium">
            Motif
            <span class="text-alerte" aria-hidden="true">*</span>
            <span class="lecteur-ecran">(obligatoire)</span>
          </span>
          <textarea
            v-model="motif"
            rows="3"
            :aria-invalid="erreurMotifAffichee.length > 0"
            placeholder="« Doublon de la fiche 42 : même homme, même acte de naissance, celle-ci est vide. »"
            class="rounded-champ border border-ligne bg-fond px-2.5 py-1.5 text-sm text-texte"
            @blur="motifTouche = true"
          />
          <span v-if="erreurMotifAffichee.length > 0" class="text-xs text-alerte" role="alert">
            {{ erreurMotifAffichee }}
          </span>
          <span v-else class="text-xs text-attenue">
            C’est la seule trace écrite de la raison.
          </span>
        </label>
      </PanneauBase>

      <div class="flex flex-wrap items-center gap-2">
        <BoutonBase
          variante="danger"
          :desactive="!peutEnvoyer"
          :chargement="envoi"
          @click="deposer"
        >
          Demander la suppression
        </BoutonBase>

        <BoutonBase variante="secondaire" @click="routeur.back()">Annuler</BoutonBase>

        <RouterLink
          :to="{ name: 'personne.detail', params: { id: identifiant } }"
          class="text-sm text-accent underline"
        >
          Revenir à la fiche
        </RouterLink>
      </div>
    </template>
  </div>
</template>
