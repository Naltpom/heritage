<script setup lang="ts">
/**
 * Créer un événement (`/evenements/nouveau`) ou modifier son annonce
 * (`/evenements/:id/modifier`).
 *
 * **D'abord, le type** (02/10/2026 — « le tirage au sort c'est un événement en
 * soi ») : sans `?type=`, la page montre deux grandes cartes, « Rassemblement »
 * et « Tirage au sort » (`ChoixDuType`). `?type=tirage` montre le formulaire
 * du tirage (`FormulaireNouveauTirage`, celui de l'ancienne page « Tirages »),
 * qui mène à la page du tirage ; `?type=rassemblement`, celui qui suit.
 *
 * **À la création**, l'annonce puis les invités, sur la même page : le titre,
 * les deux dates (une seule suffit pour un événement d'un jour — la fin prend
 * le début), le lieu, la description, puis les vivants de l'arbre à cocher. Le
 * compte de l'organisateur est coché d'office : il vient presque toujours, et
 * se décoche d'un geste. « Créer et prévenir » envoie tout, et chaque invité
 * qui a un compte reçoit sa notification.
 *
 * **À la modification**, l'annonce seule : les invités se gèrent sur la page de
 * l'événement, un par un, chacun avec sa notification — renvoyer la liste
 * entière ici ferait croire qu'on peut l'éditer d'un bloc, sans dire qui serait
 * prévenu. Changer le début ou la fin **prévient tous les invités qui ont un
 * compte**, et ceux qui avaient répondu sont priés de confirmer : l'écran le
 * dit sous les dates, avant qu'on enregistre.
 *
 * Les erreurs se lisent sous chaque champ : celles que l'écran voit avant
 * d'envoyer (`verifierAnnonce`), puis celles que le serveur nomme — les mêmes
 * champs, le serveur reste juge.
 */
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  analyserErreur,
  creerEvenement,
  listerEvenements,
  lireEvenement,
  modifierEvenement,
  type AnnonceDEvenement,
} from '@/lib/api'
import BoutonBase from '@/components/base/BoutonBase.vue'
import ChampTexte from '@/components/base/ChampTexte.vue'
import MessageErreur from '@/components/base/MessageErreur.vue'
import PanneauBase from '@/components/base/PanneauBase.vue'
import SqueletteChargement from '@/components/base/SqueletteChargement.vue'
import { ecrituresSuspendues, motifSuspension } from '@/components/administration/ecriture'
import SelecteurDInvites from '@/components/evenements-familiaux/SelecteurDInvites.vue'
import ChoixDuType from '@/components/evenements-familiaux/ChoixDuType.vue'
import FormulaireNouveauTirage from '@/components/tirages/FormulaireNouveauTirage.vue'
import { lireTypeDEvenement } from '@/components/evenements-familiaux/liste-commune'
import {
  annonceAEnvoyer,
  annonceDe,
  annonceVide,
  compteDInvites,
  verifierAnnonce,
} from '@/components/evenements-familiaux/evenements'
import { chargerLesInvitables, type Invitable } from '@/components/evenements-familiaux/invites'
import { useSession } from '@/composables/useSession'

const route = useRoute()
const routeur = useRouter()
const { vueEmpruntee } = useSession()
const suspendu = computed(() => ecrituresSuspendues(vueEmpruntee.value))
const motifSuspendu = computed(() => motifSuspension(vueEmpruntee.value))

/** `null` : création. */
const id = computed<number | null>(() => (route.params['id'] === undefined ? null : Number(route.params['id'])))
const creation = computed(() => id.value === null)

/**
 * Ce qu'on crée : lu dans l'adresse. `null` à la création sans `?type=` — la
 * page demande alors. Une modification est toujours celle d'un rassemblement :
 * un tirage se règle sur sa propre page.
 */
const type = computed(() => (creation.value ? lireTypeDEvenement(route.query['type']) : 'rassemblement'))

/** Les dates d'origine, pour dire — avant d'enregistrer — que les invités seront prévenus. */
const datesDOrigine = ref<{ debut: string; fin: string } | null>(null)

const annonce = ref<AnnonceDEvenement>(annonceVide())
const invites = ref<number[]>([])
const invitables = ref<Invitable[] | null>(null)
const erreurDeChargement = ref<unknown>(null)
const pret = ref(false)
let avorteur: AbortController | null = null

async function charger(): Promise<void> {
  avorteur?.abort()
  avorteur = new AbortController()
  const { signal } = avorteur
  erreurDeChargement.value = null
  pret.value = false

  // Le choix du type, ou le formulaire du tirage : rien à charger ici.
  if (type.value !== 'rassemblement') return

  try {
    if (id.value === null) {
      annonce.value = annonceVide()
      // Les deux en parallèle : la personne du lecteur (cochée d'office) et les vivants.
      const [liste, vivants] = await Promise.all([listerEvenements(signal), chargerLesInvitables(signal)])
      invitables.value = vivants
      invites.value = liste.moi !== null && vivants.some((v) => v.personId === liste.moi?.personId) ? [liste.moi.personId] : []
    } else {
      annonce.value = annonceDe(await lireEvenement(id.value, signal))
      datesDOrigine.value = { debut: annonce.value.debut, fin: annonce.value.fin }
    }
    pret.value = true
  } catch (e) {
    if (!signal.aborted) erreurDeChargement.value = e
  }
}

watch([id, type], charger, { immediate: true })

/** Les dates touchées à la modification : un enregistrement préviendra les invités. */
const datesTouchees = computed(() => {
  const origine = datesDOrigine.value
  if (creation.value || origine === null) return false
  const fin = annonce.value.fin === '' ? annonce.value.debut : annonce.value.fin
  return origine.debut !== annonce.value.debut || origine.fin !== fin
})

const titreDeLaPage = computed(() => {
  if (!creation.value) return 'Modifier l’annonce'
  if (type.value === 'tirage') return 'Nouveau tirage au sort'
  if (type.value === 'rassemblement') return 'Nouveau rassemblement'
  return 'Créer un événement'
})

const sousTitre = computed(() => {
  if (!creation.value) return 'Les invités voient l’annonce telle que vous la laissez.'
  if (type.value === 'tirage') return 'Chacun tirera au sort à qui offrir un cadeau, et ne verra que le sien.'
  if (type.value === 'rassemblement') return 'Une cousinade, un anniversaire, des retrouvailles : choisissez qui inviter, chacun sera prévenu.'
  return 'Que voulez-vous organiser ?'
})
onBeforeUnmount(() => avorteur?.abort())

/* ───────────────── Les champs texte ─────────────────
 * `ChampTexte` lit et écrit une chaîne ; le lieu et la description sont
 * `null` quand ils sont vides. */

const lieu = computed({
  get: () => annonce.value.lieu ?? '',
  set: (v: string) => (annonce.value = { ...annonce.value, lieu: v }),
})
const description = computed({
  get: () => annonce.value.description ?? '',
  set: (v: string) => (annonce.value = { ...annonce.value, description: v }),
})

/** Un seul jour : on règle le début, la fin le suit tant qu'elle ne le précède pas. */
watch(
  () => annonce.value.debut,
  (debut) => {
    if (debut !== '' && (annonce.value.fin === '' || annonce.value.fin < debut)) annonce.value = { ...annonce.value, fin: debut }
  },
)

/* ───────────────── Envoyer ───────────────── */

const tente = ref(false)
const enCours = ref(false)
const erreurDEnvoi = ref<unknown>(null)

const erreursLocales = computed(() => (tente.value ? verifierAnnonce(annonce.value, new Date()) : {}))
const erreursServeur = computed<Record<string, string>>(() => (erreurDEnvoi.value === null ? {} : analyserErreur(erreurDEnvoi.value).champs))
const erreurs = computed<Record<string, string>>(() => ({ ...erreursServeur.value, ...erreursLocales.value }))

async function envoyer(): Promise<void> {
  if (enCours.value || suspendu.value) return
  tente.value = true
  if (Object.keys(verifierAnnonce(annonce.value, new Date())).length > 0) return

  enCours.value = true
  erreurDEnvoi.value = null

  try {
    const corps = annonceAEnvoyer(annonce.value)
    const enregistre = id.value === null ? await creerEvenement(corps, invites.value) : await modifierEvenement(id.value, corps)
    await routeur.replace({ name: 'evenement.detail', params: { id: enregistre.id } })
  } catch (e) {
    erreurDEnvoi.value = e
  } finally {
    enCours.value = false
  }
}

function abandonner(): void {
  if (id.value === null) void routeur.push({ name: 'evenements' })
  else void routeur.push({ name: 'evenement.detail', params: { id: id.value } })
}

const classeZone =
  'min-h-28 rounded-champ border bg-fond px-2.5 py-2 text-[15px] leading-relaxed text-texte transition-colors focus:border-accent focus:outline-none'
</script>

<template>
  <div class="mx-auto w-full max-w-2xl px-4 py-6">
    <header class="mb-5">
      <RouterLink
        v-if="creation && type !== null"
        :to="{ name: 'evenement.nouveau' }"
        class="mb-2 inline-block text-xs text-accent underline"
      >
        Changer de type d’événement
      </RouterLink>
      <h1 class="text-xl font-semibold text-texte">{{ titreDeLaPage }}</h1>
      <p class="mt-1 text-sm text-attenue">{{ sousTitre }}</p>
    </header>

    <p v-if="motifSuspendu !== null" class="mb-4 rounded-panneau border border-ligne bg-panneau p-3 text-xs text-attenue">
      {{ motifSuspendu }}
    </p>

    <!-- ───────── D'abord, le type ───────── -->
    <ChoixDuType v-if="creation && type === null" />

    <!-- ───────── Un tirage au sort : son propre formulaire ───────── -->
    <FormulaireNouveauTirage v-else-if="type === 'tirage'" :suspendu="suspendu" @abandon="abandonner" />

    <MessageErreur
      v-else-if="erreurDeChargement !== null"
      :erreur="erreurDeChargement"
      titre="Le formulaire n’a pas pu être préparé"
      @action="charger"
    />

    <SqueletteChargement v-else-if="!pret" :lignes="6" />

    <form v-else class="flex flex-col gap-5" novalidate @submit.prevent="envoyer">
      <PanneauBase titre="L’annonce">
        <div class="flex flex-col gap-4">
          <ChampTexte
            v-model="annonce.titre"
            etiquette="Titre"
            placeholder="Cousinade été 2027"
            requis
            :erreur="erreurs['titre'] ?? ''"
          />

          <div class="grid grid-cols-2 gap-3">
            <ChampTexte v-model="annonce.debut" etiquette="Du" type="date" requis :erreur="erreurs['debut'] ?? ''" />
            <ChampTexte v-model="annonce.fin" etiquette="Au" type="date" :erreur="erreurs['fin'] ?? ''" />
          </div>
          <p class="-mt-2 text-xs text-attenue">Un seul jour ? Laissez la même date.</p>
          <p v-if="datesTouchees" class="-mt-2 rounded-panneau border border-ligne bg-fond p-2 text-xs text-texte" role="status">
            Les dates changent : tous les invités qui ont un compte seront prévenus, et ceux qui avaient répondu devront confirmer.
          </p>

          <ChampTexte v-model="lieu" etiquette="Lieu (facultatif)" placeholder="Chez Tante Ardoise, à Fleurance" :erreur="erreurs['lieu'] ?? ''" />

          <div class="flex flex-col gap-1">
            <label for="description-evenement" class="text-sm font-medium">Description (facultatif)</label>
            <textarea
              id="description-evenement"
              v-model="description"
              rows="5"
              :class="[classeZone, erreurs['description'] ? 'border-alerte' : 'border-ligne']"
              placeholder="Le programme, ce qu’on apporte, où dormir…"
              :aria-invalid="erreurs['description'] ? 'true' : undefined"
            />
            <p v-if="erreurs['description']" class="text-xs text-alerte">{{ erreurs['description'] }}</p>
          </div>
        </div>
      </PanneauBase>

      <PanneauBase v-if="creation && invitables !== null" titre="Les invités">
        <SelecteurDInvites v-model="invites" :invitables="invitables" />
        <p v-if="erreurs['invites']" class="mt-2 text-xs text-alerte">{{ erreurs['invites'] }}</p>
        <p class="mt-3 text-xs text-attenue">
          Ceux qui ont un compte reçoivent une notification. Pour les autres, vous enverrez un lien personnel depuis la page de l’événement.
        </p>
      </PanneauBase>

      <MessageErreur
        v-if="erreurDEnvoi !== null && Object.keys(erreursServeur).length === 0"
        :erreur="erreurDEnvoi"
        :titre="creation ? 'L’événement n’a pas pu être créé' : 'L’annonce n’a pas pu être enregistrée'"
      />

      <!-- Collée en bas sur téléphone : le bouton reste sous le pouce, même au fond de la liste. -->
      <div class="sticky bottom-[calc(var(--hauteur-barre-bas,0px)+0.5rem)] flex gap-2 rounded-panneau border border-ligne bg-panneau p-2 shadow-panneau md:static md:border-0 md:bg-transparent md:p-0 md:shadow-none">
        <BoutonBase type="submit" class="min-h-11 flex-1 md:flex-none" :chargement="enCours" :desactive="suspendu">
          {{ creation ? (invites.length > 0 ? `Créer et prévenir · ${compteDInvites(invites.length)}` : 'Créer l’événement') : 'Enregistrer' }}
        </BoutonBase>
        <BoutonBase variante="discret" class="min-h-11" @click="abandonner">Annuler</BoutonBase>
      </div>
    </form>
  </div>
</template>
