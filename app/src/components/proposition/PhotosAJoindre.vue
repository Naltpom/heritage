<script setup lang="ts">
/**
 * Les photos jointes à une personne proposée, **chacune avec sa description**.
 *
 * « Il faut aussi un bloc import de photo et prendre des photos (avec
 * description) » (05/10/2026). Rien ne part d'ici : le formulaire dépose d'abord
 * la demande, puis envoie chaque photo sur la fiche proposée (`demandeId` +
 * `p1`) — une photo n'a de sujet qu'une fois la demande créée. La validation la
 * rattache ensuite à la vraie fiche (passe 6 du § 9.5).
 *
 * Le refus de taille est dit tout de suite, sur la ligne du fichier : inutile de
 * faire croire qu'une photo de 40 Mo partira. Le type, lui, n'est jamais
 * contrôlé ici — le serveur seul renifle les octets (§ 7.1).
 *
 * Le parent détient la liste et révoque les aperçus quand il n'en a plus besoin.
 */
import BoutonBase from '@/components/base/BoutonBase.vue'
import BoutonsPhotos from '@/components/medias/BoutonsPhotos.vue'
import { TAILLE_MAX_PAR_DEFAUT, tailleLisible, verifierAvantEnvoi } from '@/components/medias/medias'
import { estPivotable, pivoterFichier } from '@/components/medias/pivotement'

export interface PhotoAJoindre {
  cle: number
  fichier: File
  /** URL d'objet de l'aperçu, `null` si le fichier ne s'affiche pas en image. */
  apercu: string | null
  description: string
  etat: 'attente' | 'en_cours' | 'reussie' | 'echec'
  /** Refus local (taille) ou message du serveur. */
  message: string | null
  /** `false` pour un refus de taille : le fichier ne rétrécira pas, inutile de le renvoyer. */
  rejouable: boolean
}

const { desactive = false } = defineProps<{ desactive?: boolean }>()

const photos = defineModel<PhotoAJoindre[]>({ required: true })

let compteur = 0

function ajouter(fichiers: File[]): void {
  for (const fichier of fichiers) {
    const verdict = verifierAvantEnvoi(fichier)
    photos.value.push({
      cle: ++compteur + Date.now(),
      fichier,
      apercu: estPivotable(fichier) ? URL.createObjectURL(fichier) : null,
      description: '',
      etat: verdict.accepte ? 'attente' : 'echec',
      message: verdict.accepte ? null : verdict.message,
      rejouable: verdict.accepte,
    })
  }
}

function retirer(cle: number): void {
  const photo = photos.value.find((p) => p.cle === cle)
  if (photo?.apercu) URL.revokeObjectURL(photo.apercu)
  photos.value = photos.value.filter((p) => p.cle !== cle)
}

/** Un quart de tour horaire — une photo de téléphone arrive parfois couchée. */
async function pivoter(photo: PhotoAJoindre): Promise<void> {
  const tourne = await pivoterFichier(photo.fichier, 90)
  if (tourne === photo.fichier) return
  if (photo.apercu) URL.revokeObjectURL(photo.apercu)
  photo.fichier = tourne
  photo.apercu = URL.createObjectURL(tourne)
}
</script>

<template>
  <div class="flex flex-col gap-3">
    <p class="m-0 text-sm text-attenue">
      Une photo de la personne, d’un acte, d’un livret de famille… Décrivez chacune : qui,
      où, quand. Elles partent avec la proposition et attendent la même validation.
    </p>

    <BoutonsPhotos :desactive="desactive" @fichiers="ajouter" />

    <p class="m-0 text-xs text-attenue">
      Images, {{ tailleLisible(TAILLE_MAX_PAR_DEFAUT) }} au maximum par photo. Les
      coordonnées GPS des photos sont retirées par le serveur.
    </p>

    <ul v-if="photos.length > 0" class="m-0 flex list-none flex-col gap-2 p-0" aria-label="Photos à joindre">
      <li
        v-for="(photo, index) in photos"
        :key="photo.cle"
        class="rounded-panneau border border-ligne p-2.5"
        :class="photo.etat === 'echec' ? 'border-alerte-bord bg-alerte-doux' : ''"
      >
        <div class="flex gap-3">
          <img
            v-if="photo.apercu !== null"
            :src="photo.apercu"
            :alt="`Photo ${index + 1}`"
            class="size-20 shrink-0 rounded-champ border border-ligne bg-fond object-cover"
          />
          <div
            v-else
            class="flex size-20 shrink-0 items-center justify-center rounded-champ border border-ligne bg-fond text-xs text-attenue"
          >
            Fichier
          </div>

          <label class="flex min-w-0 flex-1 flex-col gap-1">
            <span class="text-sm font-medium">Description</span>
            <textarea
              v-model="photo.description"
              rows="2"
              maxlength="255"
              placeholder="« Mariage de Jeanne et Paul, à Vannes, en 1952. »"
              :disabled="desactive || photo.etat === 'reussie' || photo.etat === 'en_cours'"
              class="rounded-champ border border-ligne bg-fond px-2.5 py-1.5 text-sm text-texte"
            />
          </label>
        </div>

        <div class="mt-2 flex flex-wrap items-center gap-2">
          <span class="min-w-0 flex-1 truncate text-xs text-attenue">
            {{ photo.fichier.name }} · {{ tailleLisible(photo.fichier.size) }}
          </span>
          <BoutonBase
            v-if="photo.apercu !== null && photo.etat === 'attente'"
            variante="discret"
            :desactive="desactive"
            :aria-label="`Pivoter la photo ${index + 1} d’un quart de tour`"
            @click="pivoter(photo)"
          >
            ↻ Pivoter
          </BoutonBase>
          <BoutonBase
            v-if="photo.etat !== 'reussie' && photo.etat !== 'en_cours'"
            variante="discret"
            :desactive="desactive"
            @click="retirer(photo.cle)"
          >
            Retirer
          </BoutonBase>
        </div>

        <p v-if="photo.etat === 'en_cours'" class="m-0 mt-1 text-xs text-attenue" role="status">Envoi en cours…</p>
        <p v-else-if="photo.etat === 'reussie'" class="m-0 mt-1 text-xs text-ok" role="status">Envoyée.</p>
        <p v-else-if="photo.message !== null" class="m-0 mt-1 text-xs text-alerte" role="alert">{{ photo.message }}</p>
      </li>
    </ul>
  </div>
</template>
