<script setup lang="ts">
/**
 * **« C'est elle : compléter sa fiche »** — déplié dans la carte d'une personne
 * de l'arbre qui ressemble à la saisie (`PanneauRessemblances`).
 *
 * Au lieu de déposer un doublon, on reporte sur la fiche existante ce que la
 * saisie lui apporte : les écarts champ par champ, les liens saisis, les photos.
 * On coche ce qu'on garde ; partent alors, **dans cet ordre et sans quitter la
 * page ni l'arbre**, une `modification_personne`, une `ajout_lien`, et une
 * `ajout_media` par photo. Ce qui se compare et se coche d'office est décidé dans
 * `completion.ts` ; ce composant tient la lecture, les cases et le dépôt.
 *
 * ────────────────────────────────────────────────────────────────────
 * **La fiche est lue au clic, et figée.** Son cliché (`instantaneDeFiche`) porte
 * les `oldValue` et la `baseVersion` : ce que le lecteur avait sous les yeux. Si
 * elle a bougé avant l'envoi, le serveur rend `409` — l'écran le dit, et c'est le
 * lecteur qui la relit (« Relire la fiche »). Jamais une relecture silencieuse :
 * elle effacerait précisément le signal que le verrou optimiste existe pour
 * donner (§ 9.6).
 *
 * **Un second clic ne redépose rien.** Chaque demande partie est retenue, comme
 * dans le mode édition de la fiche (`useEditionFiche`) ; chaque photo téléversée
 * aussi. Après un échec, « Réessayer » ne renvoie que ce qui manque.
 * ────────────────────────────────────────────────────────────────────
 *
 * Le message envoyé est la note du formulaire (la source) suivie du message à
 * l'administrateur. Une fois tout parti, le composant annonce `deposee`
 * (`nature: 'fiche'`) et ne dit rien de plus : la page affiche « Demandes n° X et
 * Y déposées » et repart d'une saisie vierge ; le volet de `/arbre` le confirme et
 * montre la personne dans l'arbre.
 */
import { computed, onBeforeUnmount, onMounted, ref, shallowRef, useTemplateRef, watch } from 'vue'
import {
  CODE_VUE_COMME_ACTIVE,
  creerDemande,
  estErreurApi,
  invaliderGraphe,
  lireFichePersonne,
  proposerLeMedia,
  televerserMedia,
  type FichePersonneComplete,
  type Media,
} from '@/lib/api'
import BoutonBase from '@/components/base/BoutonBase.vue'
import MessageErreur from '@/components/base/MessageErreur.vue'
import { cleDeDepot } from '@/components/personne/edition'
import type { PhotoAJoindre } from './PhotosAJoindre.vue'
import type { ErreurSaisie, SaisieCreation } from './creation'
import { annonceDesDemandes, ficheCompletee, type PersonneDeposee } from './depot'
import {
  basculerEcart,
  cleDEcart,
  demandesDeCompletion,
  ecartsAvecFiche,
  envoyerLesPhotosSurLaFiche,
  liensPourFiche,
  messageDeCompletion,
  unionParDefaut,
  unionsPourEnfant,
  type Ecart,
  type LienPourFiche,
  type NatureEcart,
} from './completion'
import { instantaneDeFiche, type InstantaneFiche } from './operations'
import { photosAEnvoyer } from './photos'

const { personId, libelle, saisie, message, photos } = defineProps<{
  personId: number
  /** Le nom que la ressemblance a rendu, le temps que la fiche se lise. */
  libelle: string
  saisie: SaisieCreation
  /** Le message à l'administrateur, tel qu'écrit dans le formulaire. */
  message: string
  /** Les photos du formulaire : elles partent sur la fiche, si on les garde. */
  photos: PhotoAJoindre[]
}>()

const emit = defineEmits<{
  /** Tout est parti, photos comprises. */
  deposee: [depot: PersonneDeposee]
  /** « Annuler » : on referme sans rien envoyer. */
  fermer: []
}>()

/* --- La lecture ----------------------------------------------------- */

const fiche = shallowRef<FichePersonneComplete | null>(null)
/** Le cliché pris à la lecture : jamais rafraîchi tant qu'on ne relit pas. */
const instantane = shallowRef<InstantaneFiche | null>(null)
const lecture = ref(false)
const erreurLecture = ref<unknown>(null)
/** Une fiche que le lecteur ne voit pas en entier ne se complète pas d'ici. */
const restreinte = ref(false)
let lectureEnCours: AbortController | null = null

async function lire(): Promise<void> {
  lectureEnCours?.abort()
  const controleur = new AbortController()
  lectureEnCours = controleur
  lecture.value = true
  erreurLecture.value = null
  try {
    const lue = await lireFichePersonne(personId, controleur.signal)
    if (controleur.signal.aborted) return
    if (lue.visibilite !== 'complete') {
      restreinte.value = true
      return
    }
    // Une relecture repart des cases par défaut : les écarts ne sont plus les mêmes.
    vusEcarts.clear()
    vusLiens.clear()
    retenus.value = []
    liensRetenus.value = []
    fiche.value = lue
    instantane.value = instantaneDeFiche(lue)
  } catch (cause) {
    if (!controleur.signal.aborted) erreurLecture.value = cause
  } finally {
    if (lectureEnCours === controleur) {
      lecture.value = false
      lectureEnCours = null
    }
  }
}

/**
 * Le bouton « C'est elle » disparaît quand la complétion s'ouvre : le focus irait
 * nulle part. Il vient sur son titre — le lecteur a demandé à ouvrir, il y est.
 */
const titre = useTemplateRef<HTMLElement>('titre')

// Au montage, et non dans `setup` : le rendu serveur des tests ne lit rien.
onMounted(() => {
  titre.value?.focus?.()
  void lire()
})
onBeforeUnmount(() => lectureEnCours?.abort())

const nom = computed(() => fiche.value?.displayName ?? libelle)

/* --- Les écarts ----------------------------------------------------- */

const ecarts = computed<Ecart[]>(() => (fiche.value === null ? [] : ecartsAvecFiche(fiche.value, saisie.brouillon)))
/** Les écarts cochés ({@link cleDEcart}). */
const retenus = ref<string[]>([])
/** Les écarts déjà rencontrés : un nouvel écart prend sa case par défaut, un
 *  écart déjà vu garde celle que le lecteur a choisie. */
const vusEcarts = new Set<string>()

watch(
  ecarts,
  (liste) => {
    const ajoutes: string[] = []
    for (const e of liste) {
      const cle = cleDEcart(e)
      if (vusEcarts.has(cle)) continue
      vusEcarts.add(cle)
      if (e.cocheParDefaut) ajoutes.push(cle)
    }
    if (ajoutes.length > 0) retenus.value = [...retenus.value, ...ajoutes]
  },
  { immediate: true },
)

const retenusActuels = computed(() => {
  const presents = new Set(ecarts.value.map(cleDEcart))
  return retenus.value.filter((cle) => presents.has(cle))
})

function basculer(e: Ecart): void {
  retenus.value = basculerEcart(ecarts.value, retenusActuels.value, cleDEcart(e))
}

const NATURES: Record<NatureEcart, string> = {
  complement: 'à compléter',
  precision: 'plus précis',
  divergence: 'différent',
  aveugle: 'à l’aveugle',
}

/* --- Les liens ------------------------------------------------------ */

const liensRetenus = ref<string[]>([])
const vusLiens = new Set<string>()
const liens = computed<LienPourFiche[]>(() =>
  fiche.value === null ? [] : liensPourFiche(fiche.value, saisie, liensRetenus.value),
)

watch(
  liens,
  (liste) => {
    // Un lien nouveau dans la saisie se coche s'il est proposable — un parent ne
    // l'est que s'il reste une place.
    for (const lien of liste) {
      if (vusLiens.has(lien.cle)) continue
      vusLiens.add(lien.cle)
      if (lien.statut === 'proposable') liensRetenus.value = [...liensRetenus.value, lien.cle]
    }
  },
  { immediate: true },
)

function basculerLien(lien: LienPourFiche): void {
  if (lien.statut !== 'proposable') return
  liensRetenus.value = liensRetenus.value.includes(lien.cle)
    ? liensRetenus.value.filter((cle) => cle !== lien.cle)
    : [...liensRetenus.value, lien.cle]
}

const liensCoches = (lien: LienPourFiche) => lien.statut === 'proposable' && liensRetenus.value.includes(lien.cle)

/** L'union choisie pour chaque enfant, quand le lecteur en a choisi une. */
const unionsDesEnfants = ref<Record<string, number | null>>({})
const unions = computed(() => (fiche.value === null ? [] : unionsPourEnfant(fiche.value)))

function unionDeLEnfant(cle: string): string {
  const choisie = Object.hasOwn(unionsDesEnfants.value, cle)
    ? unionsDesEnfants.value[cle]!
    : fiche.value === null
      ? null
      : unionParDefaut(fiche.value)
  return choisie === null ? '' : String(choisie)
}

function choisirUnion(cle: string, brut: string): void {
  unionsDesEnfants.value = { ...unionsDesEnfants.value, [cle]: brut === '' ? null : Number(brut) }
}

/* --- Les photos ----------------------------------------------------- */

const avecPhotos = ref(true)
const photosAJoindre = computed(() => photosAEnvoyer(photos).length)

/* --- Le dépôt ------------------------------------------------------- */

const envoi = ref(false)
const erreur = ref<unknown>(null)
const erreursSaisie = ref<ErreurSaisie[]>([])
/** Le serveur a rendu `409` : la fiche a bougé depuis la lecture. */
const conflit = ref(false)
/** Rien de coché : le dire, et ne rien envoyer. */
const rien = ref(false)
/** Les demandes déjà parties — jamais redéposées. */
const deposees = ref<{ cle: string; id: number }[]>([])
const demandesDesPhotos = ref<number[]>([])
/** Les médias déjà téléversés, photo par photo : un second clic ne les renvoie pas. */
const televersees = new Map<number, Media>()
const photosEnEchec = ref(0)
const fait = ref(false)

const idsDeposes = computed(() => [...deposees.value.map((d) => d.id), ...demandesDesPhotos.value])

async function deposer(): Promise<void> {
  const lue = fiche.value
  const cliche = instantane.value
  if (envoi.value || fait.value || lue === null || cliche === null) return
  rien.value = false
  erreur.value = null
  erreursSaisie.value = []

  const demandes = demandesDeCompletion(lue, cliche, saisie, {
    ecarts: ecarts.value,
    retenus: retenusActuels.value,
    liensRetenus: liensRetenus.value,
    unionsDesEnfants: unionsDesEnfants.value,
    message: messageDeCompletion(saisie.brouillon.note, message),
  })
  if (!demandes.valide) {
    erreursSaisie.value = demandes.erreurs
    return
  }
  const aDeposer = demandes.corps.filter((corps) => !deposees.value.some((d) => d.cle === cleDeDepot(corps)))
  const photosAPartir = avecPhotos.value ? photosAJoindre.value : 0
  if (aDeposer.length === 0 && photosAPartir === 0 && idsDeposes.value.length === 0) {
    rien.value = true
    return
  }

  envoi.value = true
  conflit.value = false
  try {
    for (const corps of aDeposer) {
      const demande = await creerDemande(corps)
      deposees.value = [...deposees.value, { cle: cleDeDepot(corps), id: demande.id }]
    }
    // Le graphe porte le calque des demandes en attente du lecteur : son `ETag`
    // est périmé dès qu'une demande naît (§ 5.2, § 13.4).
    if (aDeposer.length > 0) invaliderGraphe()

    if (photosAPartir > 0) {
      const envoye = await envoyerLesPhotosSurLaFiche(photos, personId, televersees, televerserMedia, proposerLeMedia)
      demandesDesPhotos.value = [...demandesDesPhotos.value, ...envoye.demandeIds]
      photosEnEchec.value = envoye.echecs
      if (envoye.echecs > 0) return
    }

    fait.value = true
    emit('deposee', ficheCompletee(personId, nom.value, idsDeposes.value))
  } catch (cause) {
    // Une `409` au dépôt, c'est l'`oldValue` qui ne correspond plus : la fiche a
    // changé depuis qu'on l'a lue. La bascule « voir comme » est l'autre `409`,
    // et elle n'a rien à relire.
    if (estErreurApi(cause) && cause.statut === 409 && cause.code !== CODE_VUE_COMME_ACTIVE) conflit.value = true
    else erreur.value = cause
  } finally {
    envoi.value = false
  }
}

async function relire(): Promise<void> {
  conflit.value = false
  await lire()
}

/** Une partie est déjà partie : le bouton ne renvoie plus que ce qui manque. */
const libelleDuBouton = computed(() =>
  idsDeposes.value.length > 0 || photosEnEchec.value > 0 ? 'Réessayer l’envoi' : 'Proposer ces compléments',
)
</script>

<template>
  <section
    class="flex flex-col gap-3 rounded-panneau border border-accent bg-panneau px-3 py-3"
    data-completion
    :aria-label="`Compléter la fiche de ${nom}`"
  >
    <h4 ref="titre" tabindex="-1" class="m-0 text-sm font-semibold outline-none">Compléter la fiche de {{ nom }}</h4>

    <p v-if="lecture" class="m-0 text-sm text-attenue" role="status">Lecture de la fiche…</p>
    <MessageErreur v-else-if="erreurLecture !== null" :erreur="erreurLecture" />
    <p v-else-if="restreinte" class="m-0 text-sm text-attenue">
      Vous ne voyez pas cette fiche en entier : elle ne se complète pas d’ici.
    </p>

    <template v-else-if="fiche !== null">
      <p class="m-0 text-xs text-attenue">
        Cochez ce que vous voulez proposer. Rien ne change sur sa fiche avant la validation d’un
        administrateur.
      </p>

      <!-- Les champs -->
      <!-- `min-w-0` : un `fieldset` prend par défaut la largeur de son contenu le
           plus large, et une longue option de liste le ferait déborder du volet. -->
      <fieldset class="m-0 flex min-w-0 flex-col gap-1.5 border-0 p-0">
        <legend class="mb-1 text-xs font-semibold tracking-wide text-attenue uppercase">Sa fiche</legend>
        <p v-if="ecarts.length === 0" class="m-0 text-sm text-attenue" data-aucun-ecart>
          Votre saisie n’apporte rien de plus à ses champs.
        </p>
        <ul v-else class="m-0 flex list-none flex-col gap-1.5 p-0">
          <li v-for="e in ecarts" :key="cleDEcart(e)">
            <label
              class="flex cursor-pointer items-start gap-2.5 rounded-champ border px-2.5 py-2"
              :class="e.nature === 'divergence' ? 'border-alerte-bord bg-alerte-doux' : 'border-ligne bg-fond'"
              :data-ecart="e.champ"
              :data-nature="e.nature"
            >
              <input
                type="checkbox"
                class="mt-0.5 size-4 shrink-0 accent-accent"
                :checked="retenusActuels.includes(cleDEcart(e))"
                :disabled="envoi || fait"
                @change="basculer(e)"
              />
              <span class="flex min-w-0 flex-col gap-0.5 text-sm">
                <span class="flex flex-wrap items-baseline gap-x-2">
                  <span class="font-semibold">{{ e.etiquette }}</span>
                  <span class="text-[11.5px]" :class="e.nature === 'divergence' ? 'font-semibold text-alerte' : 'text-attenue'">
                    {{ NATURES[e.nature] }}
                  </span>
                </span>
                <span class="break-words">
                  <span class="text-attenue">{{ e.avant }}</span>
                  <span aria-hidden="true"> → </span>
                  <span class="sr-only">, proposé : </span>
                  <span>{{ e.apres }}</span>
                </span>
                <span v-if="e.motif !== null" class="text-xs" :class="e.nature === 'divergence' ? 'text-alerte' : 'text-attenue'">
                  {{ e.motif }}
                </span>
              </span>
            </label>
          </li>
        </ul>
      </fieldset>

      <!-- Les liens saisis -->
      <fieldset v-if="liens.length > 0" class="m-0 flex min-w-0 flex-col gap-1.5 border-0 p-0">
        <legend class="mb-1 text-xs font-semibold tracking-wide text-attenue uppercase">Ses liens</legend>
        <ul class="m-0 flex list-none flex-col gap-1.5 p-0">
          <li v-for="lien in liens" :key="lien.cle" :data-lien="lien.cle" :data-statut="lien.statut">
            <label
              class="flex items-start gap-2.5 rounded-champ border border-ligne px-2.5 py-2"
              :class="lien.statut === 'proposable' ? 'cursor-pointer bg-fond' : 'bg-panneau text-attenue'"
            >
              <input
                type="checkbox"
                class="mt-0.5 size-4 shrink-0 accent-accent"
                :checked="liensCoches(lien)"
                :disabled="lien.statut !== 'proposable' || envoi || fait"
                @change="basculerLien(lien)"
              />
              <span class="flex min-w-0 flex-col gap-0.5 text-sm">
                <span :class="lien.statut === 'proposable' ? 'font-semibold' : ''">{{ lien.libelle }}</span>
                <span v-if="lien.raison !== null" class="text-xs">{{ lien.raison }}</span>
              </span>
            </label>
            <label
              v-if="lien.nature === 'enfant' && lien.statut === 'proposable' && liensCoches(lien) && unions.length > 0"
              class="mt-1 ml-9 flex min-w-0 flex-col gap-1 text-xs"
            >
              <span class="text-attenue">Enfant de quelle union ?</span>
              <select
                class="w-full min-w-0 rounded-champ border border-ligne bg-fond px-2 py-1 text-sm text-texte"
                :value="unionDeLEnfant(lien.cle)"
                :disabled="envoi || fait"
                @change="choisirUnion(lien.cle, ($event.target as HTMLSelectElement).value)"
              >
                <option v-for="u in unions" :key="u.familleId" :value="String(u.familleId)">{{ u.libelle }}</option>
                <option value="">Aucune : l’autre parent est inconnu</option>
              </select>
            </label>
          </li>
        </ul>
      </fieldset>

      <!-- Les photos du formulaire -->
      <label v-if="photosAJoindre > 0 || demandesDesPhotos.length > 0" class="flex cursor-pointer items-start gap-2.5 text-sm">
        <input
          type="checkbox"
          class="mt-0.5 size-4 shrink-0 accent-accent"
          :checked="avecPhotos"
          :disabled="envoi || fait"
          data-avec-photos
          @change="avecPhotos = !avecPhotos"
        />
        <span>
          Joindre à sa fiche
          {{ photosAJoindre > 1 ? `les ${photosAJoindre} photos ou fichiers` : 'la photo ou le fichier' }}
          du formulaire. Chacun part en demande, avec sa description.
        </span>
      </label>

      <ul v-if="erreursSaisie.length > 0" class="m-0 list-disc pl-5 text-sm text-alerte" role="alert">
        <li v-for="(e, index) in erreursSaisie" :key="index">{{ e.message }}</li>
      </ul>
      <p v-if="rien" class="m-0 text-sm text-attenue" role="status" data-rien-a-proposer>
        Rien n’est coché : il n’y a rien à proposer pour sa fiche.
      </p>
      <div
        v-if="conflit"
        class="flex flex-wrap items-center gap-2 rounded-panneau border border-alerte-bord bg-alerte-doux px-3.5 py-3 text-sm"
        role="alert"
        data-conflit
      >
        <span class="mr-auto">La fiche a changé depuis que vous l’avez ouverte : relisez-la avant d’envoyer.</span>
        <BoutonBase variante="secondaire" @click="relire">Relire la fiche</BoutonBase>
      </div>
      <MessageErreur v-if="erreur !== null" :erreur="erreur" />
      <p
        v-if="photosEnEchec > 0 && !fait"
        class="m-0 rounded-panneau border border-alerte-bord bg-alerte-doux px-3.5 py-3 text-sm"
        role="alert"
      >
        {{ idsDeposes.length > 0 ? `${annonceDesDemandes(idsDeposes)}, mais` : '' }}
        {{ photosEnEchec > 1 ? `${photosEnEchec} fichiers n’ont pas pu partir` : 'un fichier n’a pas pu partir' }} :
        « Réessayer l’envoi » ne renvoie que ceux-là.
      </p>

      <!-- Une fois tout parti, c'est l'hôte qui l'annonce : la page dans son
           bandeau, le volet dans sa confirmation — le formulaire, lui, a servi. -->
      <div v-if="!fait" class="flex flex-wrap items-center gap-2">
        <BoutonBase :chargement="envoi" @click="deposer">{{ libelleDuBouton }}</BoutonBase>
        <BoutonBase variante="discret" :desactive="envoi" @click="emit('fermer')">Annuler</BoutonBase>
      </div>
    </template>
  </section>
</template>
