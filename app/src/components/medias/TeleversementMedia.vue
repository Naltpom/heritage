<script setup lang="ts">
/**
 * Le dépôt d'un portrait ou d'une preuve — **une erreur par fichier**.
 *
 * ────────────────────────────────────────────────────────────────────
 * **Le téléversement échoue souvent, et pas pour une seule raison.** Taille,
 * type réel, réseau, plafond de débit (20 par heure, § 1.9), régime du compte :
 * cinq causes, cinq messages, et le lecteur en dépose rarement un seul fichier à
 * la fois. Une alerte globale dirait « ça n'a pas marché » sans dire **lequel** —
 * et ferait recommencer les quatre qui étaient passés.
 *
 * D'où la file : chaque fichier porte son état et son message, les envois sont
 * **séquentiels**, et un échec n'interrompt pas les suivants. Ce qui est passé
 * reste passé.
 *
 * **Et jamais d'`alert()` ni de `confirm()`** : ils bloquent le fil du
 * navigateur, ne se stylent pas, et ne se lisent pas au lecteur d'écran comme le
 * reste de la page.
 * ────────────────────────────────────────────────────────────────────
 *
 * **Deux refus, deux natures, deux traitements.**
 *
 * | | Refus local | Refus du serveur |
 * |---|---|---|
 * | Quoi | la taille, avant l'envoi | tout le reste |
 * | Pourquoi ici | éviter de pousser vingt mégaoctets sur une liaison familiale pour recevoir un `413` | le serveur est seul à renifler les octets d'en-tête |
 * | Message | écrit ici, en clair | celui du serveur, via `analyserErreur()` |
 *
 * Le **type** de fichier n'est jamais contrôlé ici : `File.type` est le
 * `Content-Type` annoncé, c'est-à-dire précisément ce dont le § 7.1 dit qu'il ne
 * fait pas foi. L'attribut `accept` reste un confort de sélection.
 *
 * **Déposer n'est pas publier.** Le média naît `en_attente` et attend la
 * validation d'un administrateur. L'écran le dit avant l'envoi, pas seulement
 * après — un contributeur qui l'apprend après coup a déjà cru son acte publié.
 */
import { computed, onBeforeUnmount, ref, useId } from 'vue'
import { analyserErreur, proposerLeMedia, televerserMedia, type Media, type NatureMedia } from '@/lib/api'
import BoutonBase from '@/components/base/BoutonBase.vue'
import ChampTexte from '@/components/base/ChampTexte.vue'
import BoutonsPhotos from './BoutonsPhotos.vue'
import { TAILLE_MAX_PAR_DEFAUT, tailleLisible, verifierAvantEnvoi } from './medias'
import { estPivotable, pivoterFichier } from './pivotement'

const { personId, nom } = defineProps<{ personId: number; nom: string }>()

const emit = defineEmits<{
  /** Un fichier est passé. La galerie se recharge : c'est elle qui sait dans
   *  quel ordre ranger ce qui attend une décision. */
  televerse: [media: Media]
}>()

interface EnvoiEnFile {
  cle: number
  fichier: File
  /** URL d'objet pour l'aperçu d'une image, `null` pour un PDF. */
  apercu: string | null
  legende: string
  etat: 'attente' | 'en_cours' | 'reussi' | 'echec'
  /** Refus local (la taille), écrit ici. */
  refus: string | null
  /** Refus du serveur, brut. `analyserErreur()` en tire le message. */
  erreur: unknown
}

let compteur = 0

const file = ref<EnvoiEnFile[]>([])
const nature = ref<NatureMedia>('preuve')
const envoiEnCours = ref(false)
const identifiantNature = useId()

const aEnvoyer = computed(() => file.value.filter((envoi) => envoi.etat === 'attente'))
const deposes = computed(() => file.value.filter((envoi) => envoi.etat === 'reussi').length)

function ajouter(fichiers: File[]): void {
  for (const fichier of fichiers) {
    const verdict = verifierAvantEnvoi(fichier)
    file.value.push({
      cle: ++compteur,
      fichier,
      apercu: estPivotable(fichier) ? URL.createObjectURL(fichier) : null,
      legende: '',
      // Le refus de taille est connu tout de suite : inutile de faire croire
      // que le fichier part.
      etat: verdict.accepte ? 'attente' : 'echec',
      refus: verdict.accepte ? null : verdict.message,
      erreur: null,
    })
  }
  // `BoutonsPhotos` vide ses champs : un même fichier peut être repris après un échec.
}

function retirer(cle: number): void {
  const envoi = file.value.find((e) => e.cle === cle)
  if (envoi?.apercu) URL.revokeObjectURL(envoi.apercu)
  file.value = file.value.filter((e) => e.cle !== cle)
}

/** Un quart de tour horaire — une photo de téléphone arrive parfois couchée. */
async function pivoter(envoi: EnvoiEnFile): Promise<void> {
  const tourne = await pivoterFichier(envoi.fichier, 90)
  if (tourne === envoi.fichier) return
  if (envoi.apercu) URL.revokeObjectURL(envoi.apercu)
  envoi.fichier = tourne
  envoi.apercu = URL.createObjectURL(tourne)
}

onBeforeUnmount(() => {
  for (const envoi of file.value) if (envoi.apercu) URL.revokeObjectURL(envoi.apercu)
})

/**
 * Les envois sont **séquentiels**, et c'est délibéré.
 *
 * Six requêtes parallèles sur un limiteur à vingt par heure produiraient six
 * `429` au lieu d'un, et le lecteur ne saurait pas lesquels sont réellement
 * passés. En série, le compteur du serveur et ce que l'écran affiche restent
 * d'accord.
 */
async function envoyer(): Promise<void> {
  if (envoiEnCours.value) return
  envoiEnCours.value = true
  try {
    for (const envoi of file.value) {
      if (envoi.etat !== 'attente') continue
      envoi.etat = 'en_cours'
      envoi.erreur = null
      try {
        const media = await televerserMedia({
          fichier: envoi.fichier,
          kind: nature.value,
          subjectKind: 'personne',
          subjectId: personId,
          caption: envoi.legende,
        })
        // Déposé par un membre, il attend : la demande « Ajout d'un document » le porte
        // jusqu'à la file de l'administration. Sans elle, il restait orphelin, en
        // attente pour toujours et absent des demandes (signalé le 03/10/2026).
        await proposerLeMedia(media, personId)
        envoi.etat = 'reussi'
        emit('televerse', media)
      } catch (cause) {
        // Un échec n'interrompt pas la file : ce qui suit peut très bien passer.
        envoi.etat = 'echec'
        envoi.erreur = cause
      }
    }
  } finally {
    envoiEnCours.value = false
  }
}

function messageDEchec(envoi: EnvoiEnFile): string {
  if (envoi.refus !== null) return envoi.refus
  // Le message du serveur, tel quel : « français, une phrase, affichable telle
  // quelle » (§ 15.1). Aucun écran ne réinterprète un code d'erreur.
  return analyserErreur(envoi.erreur).message
}

function reprendre(envoi: EnvoiEnFile): void {
  if (envoi.refus !== null) return
  envoi.etat = 'attente'
  envoi.erreur = null
}
</script>

<template>
  <div class="flex flex-col gap-3">
    <p class="m-0 text-sm text-attenue">
      Ajoutez un portrait ou la photographie d’un acte concernant {{ nom }}.
      <strong class="font-semibold text-texte">Un document déposé n’est pas publié :</strong>
      il attend la validation d’un administrateur, et n’est visible d’ici là que de vous et
      d’eux.
    </p>

    <div class="flex flex-col gap-3">
      <div class="flex flex-col gap-1">
        <label :for="identifiantNature" class="text-sm font-medium">Nature</label>
        <select
          :id="identifiantNature"
          v-model="nature"
          class="rounded-champ border border-ligne bg-fond px-2.5 py-1.5 text-sm text-texte"
        >
          <!-- Valeurs du modèle, jamais traduites : c'est la chaîne qui part en
               base. Le libellé français est le contenu de l'option. -->
          <option value="preuve">Preuve — acte, document, photo d’archive</option>
          <option value="portrait">Portrait de la personne</option>
        </select>
      </div>

      <BoutonsPhotos
        importer-aide="Galerie, fichiers ou PDF"
        accept="image/*,application/pdf"
        @fichiers="ajouter"
      />
    </div>

    <p class="m-0 text-xs text-attenue">
      Images et PDF, {{ tailleLisible(TAILLE_MAX_PAR_DEFAUT) }} au maximum par fichier. Les
      métadonnées des photographies — dont les coordonnées GPS — sont purgées par le serveur.
    </p>

    <!-- La file. Chaque ligne porte son propre état et son propre message. -->
    <ul v-if="file.length > 0" class="m-0 flex list-none flex-col gap-2 p-0">
      <li
        v-for="envoi in file"
        :key="envoi.cle"
        class="rounded-panneau border border-ligne px-3 py-2"
        :class="envoi.etat === 'echec' ? 'border-alerte-bord bg-alerte-doux' : ''"
      >
        <div class="flex flex-wrap items-center gap-2">
          <img
            v-if="envoi.apercu !== null"
            :src="envoi.apercu"
            alt=""
            class="size-14 shrink-0 rounded-champ border border-ligne object-contain bg-fond"
          />
          <span class="min-w-0 flex-1 text-sm break-all">{{ envoi.fichier.name }}</span>
          <span class="text-xs text-attenue">{{ tailleLisible(envoi.fichier.size) }}</span>
          <BoutonBase
            v-if="envoi.apercu !== null && envoi.etat === 'attente'"
            variante="discret"
            :aria-label="`Pivoter ${envoi.fichier.name} d’un quart de tour`"
            @click="pivoter(envoi)"
          >
            ↻ Pivoter
          </BoutonBase>
        </div>

        <ChampTexte
          v-if="envoi.etat === 'attente'"
          v-model="envoi.legende"
          etiquette="Description"
          aide="Ce que montre ce document : qui, où, quand. 255 caractères."
          class="mt-2"
        />

        <p v-if="envoi.etat === 'en_cours'" class="m-0 mt-1 text-xs text-attenue" role="status">
          Envoi en cours…
        </p>

        <p v-else-if="envoi.etat === 'reussi'" class="m-0 mt-1 text-xs text-ok" role="status">
          Déposé. En attente de validation.
        </p>

        <template v-else-if="envoi.etat === 'echec'">
          <p class="m-0 mt-1 text-xs text-alerte" role="alert">{{ messageDEchec(envoi) }}</p>
          <div class="mt-1.5 flex flex-wrap gap-2">
            <!-- Un refus de taille ne se rejoue pas : le fichier ne rétrécira
                 pas. Un refus du serveur, si — le réseau peut avoir flanché. -->
            <BoutonBase v-if="envoi.refus === null" variante="secondaire" @click="reprendre(envoi)">
              Réessayer ce fichier
            </BoutonBase>
            <BoutonBase variante="discret" @click="retirer(envoi.cle)">Retirer</BoutonBase>
          </div>
        </template>

        <div v-else class="mt-1.5">
          <BoutonBase variante="discret" @click="retirer(envoi.cle)">Retirer</BoutonBase>
        </div>
      </li>
    </ul>

    <div v-if="file.length > 0" class="flex flex-wrap items-center gap-3">
      <BoutonBase
        :chargement="envoiEnCours"
        :desactive="aEnvoyer.length === 0"
        @click="envoyer"
      >
        Déposer {{ aEnvoyer.length }} fichier<template v-if="aEnvoyer.length > 1">s</template>
      </BoutonBase>
      <span v-if="deposes > 0" class="text-xs text-attenue">
        {{ deposes }} déposé<template v-if="deposes > 1">s</template> sur {{ file.length }}.
      </span>
    </div>
  </div>
</template>
