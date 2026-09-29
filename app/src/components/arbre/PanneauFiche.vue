<script setup lang="ts">
/**
 * Le panneau latéral : la fiche de la personne sélectionnée.
 *
 * ────────────────────────────────────────────────────────────────────
 * **SUR TÉLÉPHONE, IL N'EST PAS LATÉRAL.** En deçà de `md`, un bandeau de
 * 340 px recouvrait 88 % de l'écran et laissait une bande d'arbre inutilisable
 * derrière lui. Il occupe donc toute la largeur, par quatre variantes `max-md:`
 * et rien d'autre : au-delà du seuil, la chaîne de classes est celle d'avant,
 * jeton pour jeton.
 *
 * **Il reste `absolute`, et ce choix compte.** Un `fixed` recouvrirait aussi
 * l'en-tête — donc le burger, donc le seul moyen de naviguer — et poserait en
 * prime la question de savoir si un ancêtre en `overflow-clip` le rogne, à
 * laquelle les navigateurs ne répondent pas tous pareil. En restant `absolute`,
 * il remplit le bloc rogné : tout ce qui est sous l'en-tête, de bord à bord. Et
 * fermé, son `translate-x-[102%]` reste rogné par ce même bloc, donc **le
 * débordement horizontal ne peut pas revenir** — ce que quatre tests de
 * `arbre-revalidation.test.ts` tiennent.
 *
 * Une conséquence à dire à voix haute : `max-md:max-w-none` neutralise
 * `max-w-[88vw]` sous le seuil. L'assertion qui cherche `max-w-[88vw]` ne veut
 * donc plus dire sur mobile ce que son commentaire raconte — mais la propriété
 * qu'elle protège réellement, « le panneau s'arrête au bord droit du bloc »,
 * reste vraie à 388 px comme à 1524.
 * ────────────────────────────────────────────────────────────────────
 * **Une fiche partielle n'est pas une fiche cassée.** Une personne vivante hors
 * du cercle du lecteur rend `200` avec **sept clés** (§ 11.1) : le serveur
 * n'envoie ni date, ni lieu, ni source, ni média, ni parenté. Le front ne
 * filtre rien et n'a rien à filtrer — il affiche ce qui est arrivé.
 *
 * D'où la forme du code : `FichePersonne` est une **union discriminée** sur
 * `visibilite`, et le compilateur refuse `fiche.birth` tant qu'on n'a pas
 * discriminé. On ne peut donc pas écrire par inadvertance `fiche.birth ?? '—'`,
 * qui afficherait un tiret — c'est-à-dire « on ne sait pas » — là où la vérité
 * est « vous n'avez pas le droit de savoir ». Ni « undefined », ni case vide,
 * ni ligne fantôme : une phrase qui dit ce qu'il en est, et rien d'autre.
 *
 * **Absent contre `null` (§ 1.3).** Dans la branche complète, `birth === null`
 * veut dire *inconnu* : la ligne existe et la valeur est vide. C'est la
 * distinction que le contrat demande de rendre visible.
 * ────────────────────────────────────────────────────────────────────
 *
 * **Une fourchette ne s'aplatit pas.** Les dates s'affichent par le `texte` que
 * le serveur fournit — « entre 1901 et 1909 », jamais « 1901 ». L'âge, lui,
 * n'est donné que si l'intervalle reste parlant.
 */
import { computed, ref, watch } from 'vue'
import MessageErreur from '@/components/base/MessageErreur.vue'
import PastilleEtat from '@/components/base/PastilleEtat.vue'
import SqueletteChargement from '@/components/base/SqueletteChargement.vue'
import type {
  BlocDate,
  CitationDeFiche,
  FicheBrouillon,
  FichePersonne,
  FichePersonneComplete,
} from '@/lib/api'

const { fiche, brouillon, chargement, erreur, ouvert } = defineProps<{
  fiche: FichePersonne | null
  /** Le détail d'un nœud « en attente » (§ 6.6), à la place d'une fiche. */
  brouillon: FicheBrouillon | null
  chargement: boolean
  erreur: unknown
  ouvert: boolean
}>()

const emit = defineEmits<{
  fermer: []
  /** Rebondir vers une autre personne, depuis un lien de parenté. */
  aller: [personId: number]
  /** Relancer le chargement après une erreur. */
  reessayer: []
}>()

/** La fiche complète, ou `null` — c'est ce `computed` qui fait la discrimination
 *  une fois pour tout le gabarit. */
const complete = computed<FichePersonneComplete | null>(() =>
  fiche !== null && fiche.visibilite === 'complete' ? fiche : null,
)

const restreinte = computed(() => fiche !== null && fiche.visibilite === 'restreinte')

/** Extrait pour que le gabarit n'ait pas à rétrécir un type au travers d'un
 *  `v-if` — le portrait est lu à deux endroits, dont un gestionnaire de clic. */
const portrait = computed(() => complete.value?.portrait ?? null)

const titre = computed(() => {
  if (brouillon !== null) {
    return [brouillon.givenNames, brouillon.surname].filter(Boolean).join(' ') || 'Sans nom'
  }
  return fiche?.displayName ?? ''
})

/* ------------------------------------------------------------------ */
/* Dates                                                               */
/* ------------------------------------------------------------------ */

/** Le rendu français fourni par le serveur. On l'affiche, on ne le recompose
 *  pas : lui seul sait dire « entre 1901 et 1909 » sans mentir. */
function texteDeDate(bloc: BlocDate | null): string {
  if (bloc === null) return 'inconnue'
  return bloc.texte ?? bloc.raw ?? 'inconnue'
}

function ligneDeDate(bloc: BlocDate | null): string {
  if (bloc === null) return 'inconnue'
  const texte = texteDeDate(bloc)
  return bloc.place === null ? texte : `${texte} — ${bloc.place}`
}

function annee(iso: string | null): number | null {
  if (iso === null) return null
  const n = Number.parseInt(iso.slice(0, 4), 10)
  return Number.isNaN(n) ? null : n
}

/**
 * L'âge, quand l'intervalle reste parlant.
 *
 * Une date approchée (`BET … AND …`, `ABT`) donne une **fourchette** d'âge :
 * l'afficher comme un nombre unique serait une affirmation que la source ne
 * porte pas. On montre alors l'intervalle, et rien du tout s'il dépasse trente
 * ans — à ce point, il n'apprend plus rien.
 */
function ageAffiche(naissance: BlocDate | null, deces: BlocDate | null): string | null {
  if (naissance === null || deces === null) return null
  const nMin = annee(naissance.min)
  const nMax = annee(naissance.max) ?? nMin
  const dMin = annee(deces.min)
  const dMax = annee(deces.max) ?? dMin
  if (nMin === null || dMin === null || nMax === null || dMax === null) return null

  const bas = dMin - nMax
  const haut = dMax - nMin
  if (bas === haut) return `${bas} ans`
  if (haut - bas <= 30) return `entre ${bas} et ${haut} ans`
  return null
}

const age = computed(() =>
  complete.value === null ? null : ageAffiche(complete.value.birth, complete.value.death),
)

const SEXES: Record<string, string> = {
  masculin: 'Homme',
  feminin: 'Femme',
  inconnu: 'Inconnu',
}

/* ------------------------------------------------------------------ */
/* Qualité documentaire                                                */
/* ------------------------------------------------------------------ */

/**
 * Le bandeau de confiance, repris du viewer d'origine.
 *
 * « Vérifié » veut dire *appuyé sur un acte ou un registre* (`QUAY >= 2`), et
 * non « a une source » : la moitié de l'arbre s'appuie sur des recopies
 * d'autres arbres, et les confondre viderait l'indication de son sens.
 */
const confiance = computed<{ ton: 'neutre' | 'mauvais' | 'bon'; texte: string } | null>(() => {
  const f = complete.value
  if (f === null) return null
  if (f.vivant) {
    return { ton: 'neutre', texte: 'Personne vivante — fiche à diffusion restreinte.' }
  }
  if (f.citationCount === 0) {
    return { ton: 'mauvais', texte: 'Aucune source. Rien n’appuie ces informations.' }
  }
  if ((f.bestQuay ?? -1) < 2) {
    return { ton: 'mauvais', texte: 'Non vérifié. Recopies d’arbres, pas d’acte.' }
  }
  return { ton: 'bon', texte: 'Appuyé sur un acte ou un registre.' }
})

/** Les citations les mieux appuyées d'abord. */
const citations = computed<CitationDeFiche[]>(() => {
  const f = complete.value
  if (f === null) return []
  return [...f.citations].sort((a, b) => (b.quay ?? -1) - (a.quay ?? -1))
})

/* ------------------------------------------------------------------ */
/* Loupe                                                               */
/* ------------------------------------------------------------------ */

/**
 * L'image d'archive pèse jusqu'à 3 000 px de large : on affiche la vignette et
 * on ne décode l'original qu'à la loupe, une seule à la fois.
 */
const loupe = ref<{ url: string; legende: string } | null>(null)

// Changer de fiche pendant qu'une loupe est ouverte laisserait à l'écran
// l'image de la fiche précédente.
watch(
  () => [fiche, brouillon],
  () => {
    loupe.value = null
  },
)
</script>

<template>
  <aside
    class="absolute inset-y-0 right-0 z-20 w-[340px] max-w-[88vw] overflow-y-auto border-l border-ligne bg-panneau px-4 pb-8 pt-4 transition-transform duration-200 max-md:left-0 max-md:z-30 max-md:w-full max-md:max-w-none max-md:border-l-0"
    :class="ouvert ? 'translate-x-0' : 'translate-x-[102%]'"
    :aria-hidden="!ouvert"
    :inert="!ouvert || undefined"
  >
    <button
      type="button"
      class="absolute right-3 top-3 cursor-pointer border-0 bg-transparent px-1.5 text-xl leading-none text-attenue hover:text-texte max-md:px-3 max-md:py-1.5 max-md:text-2xl"
      aria-label="Fermer le panneau"
      @click="emit('fermer')"
    >
      ×
    </button>

    <SqueletteChargement v-if="chargement" :lignes="6" />

    <MessageErreur v-else-if="erreur" :erreur="erreur" @action="emit('reessayer')" />

    <!-- ---------------------------------------------------------- -->
    <!-- Un nœud « en attente » : la personne n'existe pas encore     -->
    <!-- ---------------------------------------------------------- -->
    <template v-else-if="brouillon !== null">
      <h2 class="mb-0.5 pr-6 text-[17px] leading-tight tracking-tight">{{ titre }}</h2>
      <PastilleEtat etat="en_attente" />
      <p class="mt-2 text-xs text-attenue">
        Personne proposée par {{ brouillon.authorLabel }}. Elle n’est pas encore dans
        l’arbre : elle n’apparaît que pour vous et pour les administrateurs.
      </p>

      <dl class="mb-3.5 mt-4 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-[13px]">
        <dt class="text-attenue">Naissance</dt>
        <dd class="m-0">{{ ligneDeDate(brouillon.birth) }}</dd>
        <dt class="text-attenue">Décès</dt>
        <dd class="m-0">{{ ligneDeDate(brouillon.death) }}</dd>
        <dt class="text-attenue">Sexe</dt>
        <dd class="m-0">{{ SEXES[brouillon.sex] ?? 'Inconnu' }}</dd>
      </dl>

      <template v-if="brouillon.liens.length > 0">
        <h3 class="mb-1.5 mt-4 text-[11px] uppercase tracking-wider text-attenue">Liens proposés</h3>
        <ul class="m-0 list-none p-0 text-[13px]">
          <li v-for="lien in brouillon.liens" :key="lien.seq" class="py-0.5">
            <span class="text-attenue">{{ lien.kind.replace(/_/g, ' ') }}</span>
            <!--
              `otherLabel` et `otherPersonId` sont **absents** quand l'autre
              personne est vivante hors du cercle du lecteur (§ 11.3) : le lien
              existe, il n'est simplement pas cliquable.
            -->
            <button
              v-if="lien.otherPersonId !== undefined"
              type="button"
              class="cursor-pointer border-0 bg-transparent p-0 text-left text-accent hover:underline"
              @click="emit('aller', lien.otherPersonId)"
            >
              — {{ lien.otherLabel ?? 'cette personne' }}
            </button>
          </li>
        </ul>
      </template>

      <p v-if="brouillon.note" class="mt-3 border-l-[3px] border-ligne py-0.5 pl-2.5 text-xs">
        {{ brouillon.note }}
      </p>

      <RouterLink
        :to="{ name: 'demande.detail', params: { id: brouillon.demandeId } }"
        class="mt-4 inline-block text-[13px] text-accent hover:underline"
      >
        Voir la demande n° {{ brouillon.demandeId }}
      </RouterLink>
    </template>

    <!-- ---------------------------------------------------------- -->
    <!-- Une fiche réduite : sept clés, et c'est tout                 -->
    <!-- ---------------------------------------------------------- -->
    <template v-else-if="restreinte && fiche !== null">
      <h2 class="mb-0.5 pr-6 text-[17px] leading-tight tracking-tight">{{ fiche.displayName }}</h2>
      <p class="mt-3 rounded-[8px] border border-alerte-bord bg-alerte-doux px-2.5 py-2 text-xs leading-relaxed">
        <strong>Personne vivante.</strong> Elle ne fait pas partie de votre cercle de
        visibilité : ni dates, ni lieux, ni sources, ni photos ne vous sont communiqués.
        Sa place dans l’arbre reste visible.
      </p>
      <dl class="mb-3.5 mt-4 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-[13px]">
        <dt class="text-attenue">Sexe</dt>
        <dd class="m-0">{{ SEXES[fiche.sex] ?? 'Inconnu' }}</dd>
        <dt class="text-attenue">Génération</dt>
        <dd class="m-0 tnum">{{ fiche.generation }}</dd>
      </dl>
    </template>

    <!-- ---------------------------------------------------------- -->
    <!-- Une fiche complète                                          -->
    <!-- ---------------------------------------------------------- -->
    <template v-else-if="complete !== null">
      <h2 class="mb-0.5 pr-6 text-[17px] leading-tight tracking-tight">
        {{ complete.displayName }}
      </h2>
      <span
        v-if="complete.parente"
        class="mb-3.5 mt-1.5 inline-block rounded-pastille bg-accent-doux px-2.5 py-0.5 text-xs font-semibold text-accent"
      >
        {{ complete.parente }}
      </span>

      <img
        v-if="portrait !== null"
        :src="portrait.vignetteUrl"
        :alt="portrait.caption ?? `Portrait de ${complete.displayName}`"
        class="mb-3 w-full cursor-zoom-in rounded-[6px] border border-ligne"
        loading="lazy"
        decoding="async"
        @click="loupe = { url: portrait.url, legende: portrait.caption ?? complete.displayName }"
      />

      <dl class="mb-3.5 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-[13px]">
        <dt class="text-attenue">Naissance</dt>
        <dd class="m-0">{{ ligneDeDate(complete.birth) }}</dd>
        <template v-if="complete.deceased || complete.death !== null">
          <dt class="text-attenue">Décès</dt>
          <dd class="m-0">{{ ligneDeDate(complete.death) }}</dd>
        </template>
        <template v-if="age !== null">
          <dt class="text-attenue">Âge</dt>
          <dd class="m-0 tnum">{{ age }}</dd>
        </template>
        <dt class="text-attenue">Sexe</dt>
        <dd class="m-0">{{ SEXES[complete.sex] ?? 'Inconnu' }}</dd>
      </dl>

      <p
        v-if="confiance !== null"
        class="mt-3 rounded-[8px] px-2.5 py-2 text-xs leading-relaxed"
        :class="{
          'border border-alerte-bord bg-alerte-doux': confiance.ton === 'mauvais',
          'border border-ok-bord bg-ok-doux': confiance.ton === 'bon',
          'border border-ligne': confiance.ton === 'neutre',
        }"
      >
        {{ confiance.texte }}
      </p>

      <!-- Unions -->
      <template v-if="complete.unions.length > 0">
        <h3 class="mb-1.5 mt-4 text-[11px] uppercase tracking-wider text-attenue">
          Union<template v-if="complete.unions.length > 1">s — {{ complete.unions.length }}</template>
        </h3>
        <div
          v-for="union in complete.unions"
          :key="union.familyId"
          class="my-2 border-l-2 pl-2.5"
          :class="union.divorced ? 'border-alerte' : 'border-ligne'"
        >
          <button
            v-if="union.conjoint !== null"
            type="button"
            class="cursor-pointer border-0 bg-transparent p-0 text-left text-[13px] text-accent hover:underline"
            @click="emit('aller', union.conjoint.personId)"
          >
            {{ union.conjoint.displayName }}
          </button>
          <span v-else class="text-xs text-attenue">conjoint inconnu</span>
          <div v-if="union.marriage !== null" class="text-xs text-attenue">
            ⚭ {{ ligneDeDate(union.marriage) }}
          </div>
          <div v-if="union.divorced" class="text-xs text-alerte">
            divorce<template v-if="union.divorce !== null"> {{ texteDeDate(union.divorce) }}</template>
          </div>
          <div v-if="union.enfants.length > 0" class="text-xs text-attenue">
            {{ union.enfants.length }} enfant<template v-if="union.enfants.length > 1">s</template>
          </div>
        </div>
      </template>

      <!-- Parenté -->
      <template v-if="complete.parents.length > 0">
        <h3 class="mb-1.5 mt-4 text-[11px] uppercase tracking-wider text-attenue">Parents</h3>
        <ul class="m-0 list-none p-0 text-[13px]">
          <li v-for="p in complete.parents" :key="p.personId" class="py-0.5">
            <button
              type="button"
              class="cursor-pointer border-0 bg-transparent p-0 text-left text-accent hover:underline"
              @click="emit('aller', p.personId)"
            >
              {{ p.displayName }}
            </button>
          </li>
        </ul>
      </template>

      <template v-if="complete.fratrie.length > 0">
        <h3 class="mb-1.5 mt-4 text-[11px] uppercase tracking-wider text-attenue">
          Frères et sœurs
        </h3>
        <ul class="m-0 list-none p-0 text-[13px]">
          <li v-for="s in complete.fratrie" :key="s.personId" class="py-0.5">
            <button
              type="button"
              class="cursor-pointer border-0 bg-transparent p-0 text-left text-accent hover:underline"
              @click="emit('aller', s.personId)"
            >
              {{ s.displayName }}
            </button>
          </li>
        </ul>
      </template>

      <!-- Faits -->
      <template v-if="complete.faits.length > 0">
        <h3 class="mb-1.5 mt-4 text-[11px] uppercase tracking-wider text-attenue">Faits</h3>
        <ul class="m-0 list-none p-0 text-[13px]">
          <li v-for="f in complete.faits" :key="f.id" class="py-0.5">
            {{ f.label ?? f.type }}
            <span v-if="f.happened !== null" class="text-xs text-attenue">
              — {{ ligneDeDate(f.happened) }}
            </span>
          </li>
        </ul>
      </template>

      <!-- Sources -->
      <template v-if="citations.length > 0">
        <h3 class="mb-1.5 mt-4 text-[11px] uppercase tracking-wider text-attenue">
          Source<template v-if="citations.length > 1">s — {{ citations.length }}</template>
        </h3>
        <div
          v-for="c in citations"
          :key="c.id"
          class="my-2 border-l-[3px] py-0.5 pl-2.5 text-xs"
          :class="(c.quay ?? 0) >= 2 ? 'border-ok' : (c.quay ?? 0) >= 1 ? 'border-accent' : 'border-alerte'"
        >
          <div class="font-semibold leading-tight">{{ c.source?.title ?? 'Source' }}</div>
          <div v-if="c.source?.author" class="mt-0.5 text-attenue">{{ c.source.author }}</div>
          <div v-if="c.page" class="mt-0.5 break-words text-attenue">{{ c.page }}</div>
          <div v-else-if="c.dataText" class="mt-0.5 break-words text-attenue">{{ c.dataText }}</div>
          <span
            v-if="c.quayLabel"
            class="mt-1 inline-block rounded-pastille bg-accent-doux px-1.5 py-px text-[10.5px] font-semibold text-accent"
          >
            {{ c.quayLabel }}
          </span>
          <div v-if="c.url" class="mt-0.5">
            <a :href="c.url" target="_blank" rel="noopener" class="break-all text-accent">
              consulter en ligne ↗
            </a>
          </div>
          <img
            v-for="m in c.medias"
            :key="m.mediaId"
            :src="m.vignetteUrl"
            alt="Image d’archive"
            class="mt-1.5 block w-full cursor-zoom-in rounded-[6px] border border-ligne"
            loading="lazy"
            decoding="async"
            @click="loupe = { url: m.vignetteUrl.replace('/vignette', '/fichier'), legende: 'Image d’archive' }"
          />
        </div>
      </template>

      <!-- Notes -->
      <template v-if="complete.notes.length > 0">
        <h3 class="mb-1.5 mt-4 text-[11px] uppercase tracking-wider text-attenue">Notes</h3>
        <p
          v-for="n in complete.notes"
          :key="n.id"
          class="my-1.5 border-l-[3px] border-ligne py-0.5 pl-2.5 text-xs"
        >
          {{ n.body }}
        </p>
      </template>

      <RouterLink
        :to="{ name: 'personne.detail', params: { id: complete.id } }"
        class="mt-5 inline-block text-[13px] text-accent hover:underline"
      >
        Ouvrir la fiche complète
      </RouterLink>
    </template>

    <!--
      Dernier recours : ni chargement, ni erreur, ni fiche reconnue. Cela veut
      dire que la réponse n'a pas la forme attendue — jamais que le lecteur n'a
      pas le droit de voir, cas qui arrive en `visibilite: "restreinte"` et qui
      est traité plus haut. On le dit franchement plutôt que de laisser un
      panneau vide, qui se lit comme une panne, et surtout plutôt que d'inventer
      un contenu par défaut.
    -->
    <p v-else class="mt-6 text-[13px] text-attenue">
      Cette fiche n’a pas pu être affichée. Refermez le panneau et réessayez ;
      si cela persiste, signalez-le à un administrateur.
    </p>
  </aside>

  <!-- La loupe : une seule image d'origine décodée à la fois. -->
  <div
    v-if="loupe !== null"
    class="fixed inset-0 z-50 flex cursor-zoom-out items-center justify-center bg-black/85 p-5"
    role="dialog"
    aria-label="Image en grand"
    @click="loupe = null"
  >
    <img
      :src="loupe.url"
      :alt="loupe.legende"
      class="max-h-full max-w-full rounded-[8px] bg-white"
    />
  </div>
</template>
