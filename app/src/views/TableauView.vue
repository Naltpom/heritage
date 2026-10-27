<script setup lang="ts">
/**
 * Explorer › Tableau — les personnes de l'arbre en un grand tableau, façon phpMyAdmin
 * (demande du 27/10/2026) : une ligne par fiche, un filtre par colonne, le tri au clic sur
 * l'en-tête, le choix des colonnes et l'export CSV de ce qui est filtré.
 *
 * ────────────────────────────────────────────────────────────────────
 * CE QUI SE DÉCIDE ICI, ET NULLE PART AILLEURS :
 *
 * 1. **Le serveur a déjà décidé de ce qui se voit** (`GET /api/arbre/tableau`, § 5.5 ter).
 *    Un vivant hors cercle n'a pas de ligne ; une naissance non partagée arrive réduite.
 *    Cet écran filtre, trie et exporte cette charge-là, et rien d'autre : le CSV porte
 *    exactement les cellules affichées (`components/tableau/tableau.ts`).
 *
 * 2. **L'export prend tout ce qui est filtré**, toutes pages confondues, dans l'ordre du
 *    tri, avec les colonnes affichées. Ce qu'on voit est ce qu'on télécharge.
 *
 * 3. **Les colonnes choisies et la taille de page sont retenues par ce navigateur** —
 *    une commodité : sans stockage, on repart des colonnes par défaut.
 *
 * 4. **La portée** est celle d'Explorer (`usePorteeExplorer`) : « Mon arbre » pour tout le
 *    monde, « Toute la base » en plus pour l'administration.
 * ────────────────────────────────────────────────────────────────────
 *
 * La hauteur vient de `hauteurDuCadre()`, comme les autres onglets : la page ne défile
 * pas, le tableau défile dans son cadre, en-têtes et filtres collés en haut.
 */
import { computed, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue'
import { hauteurDuCadre } from '@/views/cadre'
import { definirResume } from '@/composables/entete'
import { usePorteeExplorer } from '@/composables/usePorteeExplorer'
import BoutonBase from '@/components/base/BoutonBase.vue'
import MessageErreur from '@/components/base/MessageErreur.vue'
import SqueletteChargement from '@/components/base/SqueletteChargement.vue'
import { compte, nombre } from '@/components/statistiques/format'
import {
  CHOIX_VIDE,
  COLONNES,
  COLONNES_PAR_DEFAUT,
  enCsv,
  filtrer,
  nomDuFichier,
  trier,
  triSuivant,
  type Colonne,
  type Tri,
} from '@/components/tableau/tableau'
import { chargerTableau, type LigneDuTableau, type TableauDesPersonnes } from '@/lib/api'

const NOM_MASQUE = 'Personne vivante'
const TAILLES = [50, 100, 250, 500, 1000] as const
const CLE_COLONNES = 'heritage.tableau.colonnes'
const CLE_TAILLE = 'heritage.tableau.parPage'

/* ------------------------------------------------------------------ */
/* Préférences de ce navigateur                                        */
/* ------------------------------------------------------------------ */

function lireColonnes(): string[] {
  try {
    const brut = globalThis.localStorage?.getItem(CLE_COLONNES)
    const lues: unknown = brut ? JSON.parse(brut) : null
    if (Array.isArray(lues)) {
      const connues = lues.filter((c): c is string => typeof c === 'string' && COLONNES.some((col) => col.cle === c))
      if (connues.length > 0) return connues
    }
  } catch {
    // Stockage indisponible ou illisible : les colonnes par défaut.
  }
  return [...COLONNES_PAR_DEFAUT]
}

function lireTaille(): number {
  try {
    const n = Number(globalThis.localStorage?.getItem(CLE_TAILLE))
    if ((TAILLES as readonly number[]).includes(n)) return n
  } catch {
    // idem
  }
  return 100
}

function retenir(cle: string, valeur: string): void {
  try {
    globalThis.localStorage?.setItem(cle, valeur)
  } catch {
    // Navigation privée : la préférence vaut pour la visite.
  }
}

/* ------------------------------------------------------------------ */
/* Charge                                                              */
/* ------------------------------------------------------------------ */

const charge = shallowRef<TableauDesPersonnes | null>(null)
const erreur = shallowRef<unknown>(null)
const chargement = ref(false)
const { portee } = usePorteeExplorer()

let abandon: AbortController | null = null

async function charger(): Promise<void> {
  abandon?.abort()
  const controleur = new AbortController()
  abandon = controleur
  chargement.value = true
  erreur.value = null

  try {
    const reponse = await chargerTableau(portee.value, controleur.signal)
    if (controleur.signal.aborted) return
    charge.value = reponse
  } catch (cause) {
    if (!controleur.signal.aborted) erreur.value = cause
  } finally {
    if (!controleur.signal.aborted) chargement.value = false
  }
}

watch(portee, () => void charger())

/* ------------------------------------------------------------------ */
/* Colonnes, filtres, tri, pages                                       */
/* ------------------------------------------------------------------ */

const choisies = ref<string[]>(lireColonnes())
const affichees = computed<Colonne[]>(() => COLONNES.filter((c) => choisies.value.includes(c.cle)))

function basculerColonne(cle: string): void {
  const actuelles = choisies.value
  // Jamais zéro colonne : un tableau vide de colonnes ne se rattrape qu'en cherchant le menu.
  if (actuelles.includes(cle) && actuelles.length === 1) return
  choisies.value = actuelles.includes(cle) ? actuelles.filter((c) => c !== cle) : [...actuelles, cle]
  retenir(CLE_COLONNES, JSON.stringify(choisies.value))
  // Une colonne masquée ne filtre plus (`filtrer`) : on efface aussi sa saisie, pour
  // qu'elle ne revienne pas active en réaffichant la colonne.
  if (!choisies.value.includes(cle) && filtresDeColonne.value[cle] !== undefined) {
    const reste = { ...filtresDeColonne.value }
    delete reste[cle]
    filtresDeColonne.value = reste
  }
}

function colonnesParDefaut(): void {
  choisies.value = [...COLONNES_PAR_DEFAUT]
  retenir(CLE_COLONNES, JSON.stringify(choisies.value))
  filtresDeColonne.value = Object.fromEntries(Object.entries(filtresDeColonne.value).filter(([cle]) => choisies.value.includes(cle)))
}

const recherche = ref('')
/** La saisie qui filtre vraiment : la frappe attend un instant avant de refiltrer six mille lignes. */
const rechercheAppliquee = ref('')
let minuterie: ReturnType<typeof setTimeout> | null = null
watch(recherche, (texte) => {
  if (minuterie !== null) clearTimeout(minuterie)
  minuterie = setTimeout(() => (rechercheAppliquee.value = texte), 150)
})

const filtresDeColonne = ref<Record<string, string>>({})

function poserFiltre(cle: string, valeur: string): void {
  filtresDeColonne.value = { ...filtresDeColonne.value, [cle]: valeur }
}

const nombreDeFiltres = computed(
  () => Object.values(filtresDeColonne.value).filter((v) => v.trim() !== '').length + (recherche.value.trim() === '' ? 0 : 1),
)

function effacerLesFiltres(): void {
  filtresDeColonne.value = {}
  recherche.value = ''
  rechercheAppliquee.value = ''
}

const tri = ref<Tri | null>(null)

function trierPar(cle: string): void {
  tri.value = triSuivant(tri.value, cle)
}

function sensDuTri(cle: string): 'ascending' | 'descending' | 'none' {
  if (tri.value?.cle !== cle) return 'none'
  return tri.value.sens === 'asc' ? 'ascending' : 'descending'
}

const lignes = computed<LigneDuTableau[]>(() => charge.value?.elements ?? [])
const filtrees = computed(() =>
  filtrer(lignes.value, { recherche: rechercheAppliquee.value, colonnes: filtresDeColonne.value }, affichees.value),
)
const triees = computed(() => trier(filtrees.value, tri.value))

const parPage = ref(lireTaille())
const page = ref(1)
const pages = computed(() => Math.max(1, Math.ceil(triees.value.length / parPage.value)))
const visibles = computed(() => triees.value.slice((page.value - 1) * parPage.value, page.value * parPage.value))

watch(parPage, (n) => retenir(CLE_TAILLE, String(n)))
// Un filtre ou un tri qui change ramène en tête : rester page 12 d'une liste de trois pages
// montrerait un tableau vide.
watch([filtrees, tri, parPage], () => (page.value = 1))

const premiere = computed(() => (triees.value.length === 0 ? 0 : (page.value - 1) * parPage.value + 1))
const derniere = computed(() => Math.min(page.value * parPage.value, triees.value.length))

/* ------------------------------------------------------------------ */
/* Export                                                              */
/* ------------------------------------------------------------------ */

function exporter(): void {
  const blob = new Blob([enCsv(triees.value, affichees.value)], { type: 'text/csv;charset=utf-8' })
  const url = URL.createObjectURL(blob)
  const lien = document.createElement('a')
  lien.href = url
  lien.download = nomDuFichier()
  document.body.appendChild(lien)
  lien.click()
  lien.remove()
  // Laisser au navigateur le temps de commencer le téléchargement avant de libérer l'URL.
  setTimeout(() => URL.revokeObjectURL(url), 60_000)
}

/* ------------------------------------------------------------------ */
/* En-tête et cadre                                                    */
/* ------------------------------------------------------------------ */

const resumeEntete = computed(() => {
  const c = charge.value
  if (c === null) return ''
  return triees.value.length === c.total
    ? compte(c.total, 'personne')
    : `${nombre(triees.value.length)} sur ${compte(c.total, 'personne')}`
})
watch(resumeEntete, (texte) => definirResume(texte), { immediate: true })

const racine = ref<HTMLElement | null>(null)
const hauteurCadre = ref('70vh')

function mesurerHauteur(): void {
  const element = racine.value
  if (element === null) return
  hauteurCadre.value = hauteurDuCadre(element.getBoundingClientRect().top)
}

defineExpose({ remesurer: mesurerHauteur })

onMounted(() => {
  mesurerHauteur()
  window.addEventListener('resize', mesurerHauteur)
  void charger()
})

onBeforeUnmount(() => {
  abandon?.abort()
  if (minuterie !== null) clearTimeout(minuterie)
  window.removeEventListener('resize', mesurerHauteur)
})

/** L'aide d'une case de filtre : la syntaxe, dite là où on la tape. */
function aideDuFiltre(colonne: Colonne): string {
  if (colonne.type === 'date') return 'Année : 1900, >1900, <1950, 1850-1900 ; = vide, ! renseigné'
  if (colonne.type === 'nombre') return 'Nombre : 3, >0, <=-2, 1-5 ; = vide, ! renseigné'
  return 'Contient ; =égal ; !ne contient pas ; = vide, ! renseigné'
}
</script>

<template>
  <div ref="racine" class="flex flex-col" :style="{ height: hauteurCadre }">
    <!-- ─── Barre d'outils ───────────────────────────────────────────── -->
    <div class="flex flex-none flex-wrap items-center gap-2 border-b border-ligne px-4 py-2">
      <label class="flex min-w-48 flex-1 items-center gap-2">
        <span class="lecteur-ecran">Rechercher dans le tableau</span>
        <input
          v-model="recherche"
          type="search"
          placeholder="Rechercher dans toutes les colonnes…"
          class="w-full rounded-champ border border-ligne bg-panneau px-3 py-1.5 text-sm text-texte placeholder:text-attenue focus:border-accent focus:outline-none"
        />
      </label>

      <details class="relative">
        <summary class="cursor-pointer list-none rounded-champ border border-ligne bg-panneau px-3 py-1.5 text-sm hover:border-accent">
          Colonnes <span class="text-attenue tnum">{{ affichees.length }}/{{ COLONNES.length }}</span>
        </summary>
        <div class="absolute right-0 z-20 mt-1 max-h-96 w-64 overflow-y-auto rounded-panneau border border-ligne bg-panneau p-2 shadow-panneau">
          <label v-for="colonne in COLONNES" :key="colonne.cle" class="flex cursor-pointer items-center gap-2 rounded px-2 py-1 text-sm hover:bg-accent-doux">
            <input type="checkbox" :checked="choisies.includes(colonne.cle)" @change="basculerColonne(colonne.cle)" />
            {{ colonne.libelle }}
          </label>
          <button type="button" class="mt-1 w-full rounded px-2 py-1 text-left text-sm text-accent hover:bg-accent-doux" @click="colonnesParDefaut">
            Colonnes par défaut
          </button>
        </div>
      </details>

      <BoutonBase v-if="nombreDeFiltres > 0" variante="discret" @click="effacerLesFiltres">
        Effacer les filtres ({{ nombreDeFiltres }})
      </BoutonBase>

      <BoutonBase variante="secondaire" :desactive="triees.length === 0" @click="exporter">
        Exporter en CSV
        <span class="text-attenue tnum">({{ nombre(triees.length) }})</span>
      </BoutonBase>
    </div>

    <div v-if="erreur && charge" class="border-b border-ligne px-4 py-2">
      <MessageErreur :erreur="erreur" titre="Le tableau affiché reste juste, mais la mise à jour a échoué" @action="charger" />
    </div>

    <!-- ─── Le tableau ───────────────────────────────────────────────── -->
    <div class="relative min-h-0 flex-1 overflow-auto">
      <div v-if="!charge && !erreur" class="p-6">
        <SqueletteChargement :lignes="12" />
      </div>

      <div v-else-if="!charge" class="p-6">
        <MessageErreur :erreur="erreur" titre="Le tableau n’a pas pu être chargé" @action="charger" />
      </div>

      <table v-else class="w-max min-w-full border-separate border-spacing-0 text-sm" :aria-busy="chargement">
        <caption class="lecteur-ecran">
          Les personnes de l’arbre — {{ resumeEntete }}
        </caption>
        <thead class="sticky top-0 z-10 bg-fond">
          <tr>
            <th
              v-for="colonne in affichees"
              :key="colonne.cle"
              scope="col"
              :aria-sort="sensDuTri(colonne.cle)"
              class="border-b border-ligne px-2 pt-2 pb-1 text-left font-semibold whitespace-nowrap"
              :class="{ 'text-right': colonne.aDroite }"
            >
              <button type="button" class="inline-flex items-center gap-1 hover:text-accent" @click="trierPar(colonne.cle)">
                {{ colonne.libelle }}
                <span aria-hidden="true" class="w-3 text-accent">{{
                  sensDuTri(colonne.cle) === 'ascending' ? '▲' : sensDuTri(colonne.cle) === 'descending' ? '▼' : ''
                }}</span>
              </button>
            </th>
          </tr>
          <tr>
            <th v-for="colonne in affichees" :key="colonne.cle" class="border-b border-ligne px-1 pb-2 font-normal">
              <select
                v-if="colonne.type === 'choix'"
                :value="filtresDeColonne[colonne.cle] ?? ''"
                :aria-label="`Filtrer : ${colonne.libelle}`"
                class="w-full min-w-24 rounded-champ border border-ligne bg-panneau px-1 py-1 text-xs text-texte focus:border-accent focus:outline-none"
                @change="poserFiltre(colonne.cle, ($event.target as HTMLSelectElement).value)"
              >
                <option value="">Tous</option>
                <option v-for="choix in colonne.choix" :key="choix.valeur" :value="choix.valeur">{{ choix.libelle }}</option>
                <option :value="CHOIX_VIDE">(vide)</option>
              </select>
              <input
                v-else
                :value="filtresDeColonne[colonne.cle] ?? ''"
                type="text"
                :aria-label="`Filtrer : ${colonne.libelle}`"
                :title="aideDuFiltre(colonne)"
                placeholder="Filtrer…"
                class="w-full rounded-champ border border-ligne bg-panneau px-1.5 py-1 text-xs text-texte placeholder:text-attenue focus:border-accent focus:outline-none"
                :class="colonne.type === 'texte' ? 'min-w-28' : 'min-w-16'"
                @input="poserFiltre(colonne.cle, ($event.target as HTMLInputElement).value)"
              />
            </th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="ligne in visibles" :key="ligne.personId" class="hover:bg-accent-doux">
            <td
              v-for="colonne in affichees"
              :key="colonne.cle"
              class="max-w-80 truncate border-b border-ligne px-2 py-1"
              :class="{ 'text-right tnum': colonne.aDroite, 'text-attenue': colonne.texte(ligne) === null }"
              :title="colonne.texte(ligne) ?? undefined"
            >
              <RouterLink
                v-if="colonne.cle === 'nom'"
                :to="{ name: 'personne.detail', params: { id: ligne.personId } }"
                class="font-medium text-accent hover:underline"
              >
                {{ ligne.displayName }}
              </RouterLink>
              <template v-else-if="colonne.cle === 'parents' || colonne.cle === 'conjoints'">
                <template v-for="(proche, rang) in colonne.cle === 'parents' ? ligne.parents : ligne.conjoints" :key="proche.personId">
                  <span v-if="rang > 0">, </span>
                  <span v-if="proche.displayName === NOM_MASQUE" class="text-attenue">{{ proche.displayName }}</span>
                  <RouterLink v-else :to="{ name: 'personne.detail', params: { id: proche.personId } }" class="hover:text-accent hover:underline">
                    {{ proche.displayName }}
                  </RouterLink>
                </template>
                <span v-if="(colonne.cle === 'parents' ? ligne.parents : ligne.conjoints).length === 0">—</span>
              </template>
              <template v-else>{{ colonne.texte(ligne) ?? '—' }}</template>
            </td>
          </tr>
          <tr v-if="visibles.length === 0">
            <td :colspan="affichees.length" class="px-4 py-8 text-center text-attenue">
              {{ lignes.length === 0 ? 'Aucune personne à afficher.' : 'Aucune personne ne correspond à ces filtres.' }}
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- ─── Pages ────────────────────────────────────────────────────── -->
    <div v-if="charge" class="flex flex-none flex-wrap items-center justify-between gap-2 border-t border-ligne px-4 py-2 text-sm">
      <span class="text-attenue tnum">
        {{ nombre(premiere) }}–{{ nombre(derniere) }} sur {{ nombre(triees.length) }}
        <template v-if="triees.length !== charge.total">(filtrées parmi {{ nombre(charge.total) }})</template>
      </span>
      <div class="flex items-center gap-2">
        <label class="flex items-center gap-1 text-attenue">
          Par page
          <select v-model.number="parPage" class="rounded-champ border border-ligne bg-panneau px-1 py-0.5 text-texte">
            <option v-for="taille in TAILLES" :key="taille" :value="taille">{{ taille }}</option>
          </select>
        </label>
        <BoutonBase variante="discret" :desactive="page <= 1" @click="page = page - 1">‹ Précédente</BoutonBase>
        <span class="tnum">{{ page }} / {{ pages }}</span>
        <BoutonBase variante="discret" :desactive="page >= pages" @click="page = page + 1">Suivante ›</BoutonBase>
      </div>
    </div>
  </div>
</template>
