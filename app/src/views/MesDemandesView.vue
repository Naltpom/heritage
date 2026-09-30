<script setup lang="ts">
/**
 * **Mes demandes** — la décision d'une demande revient à son auteur.
 *
 * C'était la demande du commanditaire, mot pour mot : « notif de refus si
 * l'admin refuse avec le msg ». Le serveur la tenait entièrement — la
 * notification existe, le motif de l'administrateur est dedans, il est
 * obligatoire, le compteur répond. **Aucun écran ne l'affichait.** Un membre
 * proposait, on lui refusait avec un motif, et il n'atteignait ce motif qu'en
 * tapant `/demandes/6` à la main.
 *
 * Cet écran est le chemin qui manquait.
 *
 * ────────────────────────────────────────────────────────────────────
 * **Deux lectures, et la seconde est bornée.**
 *
 * 1. `GET /api/demandes` rend la liste. Le serveur filtre sur l'auteur : il n'y
 *    a pas de paramètre pour demander celles d'un autre, et sous la bascule
 *    « voir comme un membre » c'est l'identité empruntée qui filtre.
 * 2. `GET /api/demandes/{id}` rend le motif — **le résumé de la liste ne porte
 *    pas `decisionMessage`** (mesuré : douze clés, pas celle-là). On le lit donc
 *    demande par demande, **sur les seules refusées**, jamais sur les autres :
 *    `motifsARecuperer()` le décide, et c'est testé.
 *
 * Ces lectures-là ne doivent pas faire tomber l'écran : une liste affichée avec
 * un motif manquant vaut infiniment mieux qu'une page d'erreur à la place de la
 * liste. Chaque motif échoue donc **pour lui seul**, et la ligne le dit.
 * ────────────────────────────────────────────────────────────────────
 *
 * Le jour où le serveur portera `decisionMessage` dans le résumé, la seconde
 * lecture disparaît d'ici sans que rien d'autre bouge. C'est signalé au lot
 * serveur ; le front n'attend pas après pour afficher le motif.
 *
 * **La barre d'écran et les compteurs.** Le titre, le segmenté d'état et le
 * compteur sont dans la barre de `ListeMesDemandes`. Les compteurs du segmenté
 * se déduisent des réponses **déjà reçues** (`comptesDepuisListe()`) — aucune
 * lecture de plus — et se cumulent d'un filtre à l'autre. Le résumé d'une ligne
 * de l'en-tête mobile (« 7 demandes ») est publié ici.
 */
import { onMounted, ref, watch } from 'vue'
import { lireDemande, listerDemandes, type ResumeDemande, type StatutDemande } from '@/lib/api'
import ListeMesDemandes from '@/components/proposition/ListeMesDemandes.vue'
import {
  comptesDepuisListe,
  motifDepuisDemande,
  motifsARecuperer,
  type ComptesParFiltre,
  type MotifDecision,
} from '@/components/proposition/mes-demandes'
import { definirResume } from '@/composables/entete'

const demandes = ref<ResumeDemande[]>([])
const motifs = ref(new Map<number, MotifDecision | null>())
const total = ref(0)
const filtre = ref<StatutDemande | ''>('')
const chargement = ref(false)
const erreur = ref<unknown>(null)
/** Ce qu'on sait du compte de chaque filtre — voir `comptesDepuisListe()`. */
const comptes = ref<ComptesParFiltre>({})

/**
 * `parPage: 100` — le plafond du serveur, atteint volontairement.
 *
 * Sa propre liste de demandes n'est pas une file d'administration : elle tient
 * sur un écran. Paginer coûterait un état de plus pour un cas qui n'arrive pas,
 * et le composant dit honnêtement « les 100 plus récentes » si le total dépasse.
 */
async function charger(): Promise<void> {
  chargement.value = true
  erreur.value = null
  try {
    const liste = await listerDemandes({
      status: filtre.value === '' ? undefined : filtre.value,
      parPage: 100,
    })
    demandes.value = liste.elements
    total.value = liste.total
    comptes.value = { ...comptes.value, ...comptesDepuisListe(filtre.value, liste.elements, liste.total) }
    void chargerMotifs(liste.elements)
  } catch (cause) {
    erreur.value = cause
  } finally {
    chargement.value = false
  }
}

/**
 * Va chercher le motif des refus affichés.
 *
 * **Aucun échec ici ne remonte à `erreur`** : la liste est déjà à l'écran, et la
 * remplacer par un message d'erreur parce qu'un motif manque serait perdre la
 * liste pour une ligne. On inscrit `null` — « lu, rien à montrer » — et la ligne
 * renvoie au détail, qui porte le motif de toute façon.
 *
 * Les motifs déjà connus ne sont pas relus : changer de filtre relit la liste,
 * pas les motifs.
 */
async function chargerMotifs(elements: readonly ResumeDemande[]): Promise<void> {
  const aLire = motifsARecuperer(elements, new Set(motifs.value.keys()))
  if (aLire.length === 0) return

  await Promise.all(
    aLire.map(async (id) => {
      let motif: MotifDecision | null = null
      try {
        motif = motifDepuisDemande(await lireDemande(id))
      } catch {
        motif = null
      }
      // Une `Map` mutée en place ne réveille pas un `ref` : on en repose une.
      motifs.value = new Map(motifs.value).set(id, motif)
    }),
  )
}

function filtrer(valeur: StatutDemande | ''): void {
  filtre.value = valeur
  void charger()
}

onMounted(charger)

// Le résumé sous le titre, dans l'en-tête du téléphone : le compteur de la
// barre n'y est pas visible. Rien tant que la liste n'est pas là.
watch(
  [total, chargement, erreur, comptes],
  () => {
    const n = total.value
    const connu = comptes.value[filtre.value] !== undefined
    definirResume(!connu || chargement.value || erreur.value !== null ? '' : `${n} demande${n > 1 ? 's' : ''}`)
  },
  { immediate: true },
)
</script>

<template>
  <ListeMesDemandes
    :demandes="demandes"
    :motifs="motifs"
    :filtre="filtre"
    :total="total"
    :chargement="chargement"
    :erreur="erreur"
    :comptes="comptes"
    @filtrer="filtrer"
    @recharger="charger"
  />
</template>
