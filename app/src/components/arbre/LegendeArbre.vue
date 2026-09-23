<script setup lang="ts">
/**
 * La légende dépliable, en bas à gauche du canevas.
 *
 * Les échantillons sont de vrais fragments de SVG portant **les classes du
 * canevas** (`fiche h`, `br b-pm`, `ar divorce`…). C'est volontaire : une
 * légende qui redéclare ses couleurs finit par mentir dès la première retouche
 * de `canevas.css`, et personne ne s'en aperçoit. Ces classes vivent dans une
 * feuille globale, pas *scoped* — voir l'en-tête de `canevas.css`.
 *
 * Elles ne sont pas mises en retrait par la recherche ni par le focus : les
 * règles correspondantes sont préfixées par `.canevas`, et cette légende n'est
 * pas dans le canevas.
 */
/**
 * **`enTiroir` : la même légende, à l'autre endroit.** Sur téléphone, la forme
 * flottante était simplement `hidden` — la légende n'existait pas, sans rien
 * pour la remplacer, et les cinq couleurs de branche devenaient indéchiffrables.
 * Elle passe donc dans le tiroir des filtres, où il y a de la place.
 *
 * À `false`, la chaîne de classes est celle d'avant, **caractère pour
 * caractère** : un écran large ne peut pas changer. Et exactement une des deux
 * formes est visible à toute largeur — celle-ci est `md:block`, l'autre vit dans
 * un conteneur `md:hidden`.
 */
const { calqueActif, avecHorsBranche = false, enTiroir = false } = defineProps<{
  /** Le lecteur a des nœuds « en attente » dans son graphe (§ 5.2). */
  calqueActif: boolean
  /**
   * Le graphe porte des personnes **sans branche**.
   *
   * Passé plutôt que supposé : une légende qui annoncerait une région absente de
   * l'arbre ferait chercher au lecteur une couleur qui n'y est pas. Et l'inverse
   * — ne jamais l'annoncer — laisserait sans explication le seul groupe que rien
   * ne relie au reste, celui qu'on remarque justement parce qu'il est à l'écart.
   */
  avecHorsBranche?: boolean
  /** Rendue dans le tiroir des filtres du mobile, et non en flottant. */
  enTiroir?: boolean
}>()

const BRANCHES = [
  { classe: 'b-pp', libelle: 'Romarin — paternelle du père' },
  { classe: 'b-pm', libelle: 'Bourrache — maternelle du père' },
  { classe: 'b-mp', libelle: 'Chardon — paternelle de la mère' },
  { classe: 'b-mm', libelle: 'Tilleul — maternelle de la mère' },
  { classe: 'b-tr', libelle: 'Tronc — l’ego, sa fratrie, son conjoint, et qui entre par alliance' },
  { classe: 'b-cpp', libelle: 'Romarin du conjoint — paternelle de son père' },
  { classe: 'b-cpm', libelle: 'Bourrache du conjoint — maternelle de son père' },
  { classe: 'b-cmp', libelle: 'Chardon du conjoint — paternelle de sa mère' },
  { classe: 'b-cmm', libelle: 'Tilleul du conjoint — maternelle de sa mère' },
] as const

/**
 * Les personnes que **rien ne relie** à l'arbre du lecteur — et depuis l'arbitrage du
 * 23/09/2026, rien d'autre : une belle-famille entrée avec un conjoint prend le bloc de
 * son partenaire, elle ne tombe plus ici. Ce n'est **pas** une
 * branche de plus : le sérialiseur sort `branch: null`, et le moteur les pose à
 * l'écart de tout le reste. Voir `zones.ts`.
 */
const HORS_BRANCHE = { classe: 'b-hors', libelle: 'Sans branche — non rattachée à la racine' } as const

const SEXES = [
  { classe: 'h', libelle: 'Homme' },
  { classe: 'f', libelle: 'Femme' },
  { classe: 'x', libelle: 'Sexe inconnu' },
] as const
</script>

<template>
  <details
    :class="
      enTiroir
        ? 'w-full overflow-hidden rounded-panneau border border-ligne bg-panneau text-xs'
        : 'absolute bottom-3.5 left-3.5 z-10 hidden max-w-[280px] overflow-hidden rounded-panneau border border-ligne bg-panneau text-xs shadow-panneau md:block'
    "
  >
    <summary class="cursor-pointer select-none px-3 py-2 font-semibold text-attenue">
      Légende
    </summary>

    <div class="grid gap-2 border-t border-ligne px-3 pb-3 pt-2.5">
      <h4 class="mt-1 text-[10.5px] font-bold uppercase tracking-wider text-attenue">
        Fiches
      </h4>
      <div v-for="s in SEXES" :key="s.classe" class="flex items-center gap-2 leading-tight">
        <svg width="34" height="16" class="shrink-0 overflow-visible" aria-hidden="true">
          <g :class="['fiche', s.classe]"><rect x="1" y="1" width="32" height="14" rx="3" /></g>
        </svg>
        <span class="text-attenue">{{ s.libelle }}</span>
      </div>

      <div class="flex items-center gap-2 leading-tight">
        <svg width="34" height="16" class="shrink-0 overflow-visible" aria-hidden="true">
          <g class="fiche x prive"><rect x="1" y="1" width="32" height="14" rx="3" /></g>
        </svg>
        <span class="text-attenue">Personne vivante — fiche à diffusion restreinte</span>
      </div>

      <div class="flex items-center gap-2 leading-tight">
        <svg width="34" height="16" class="shrink-0 overflow-visible" aria-hidden="true">
          <g class="fiche x ego"><rect x="1" y="1" width="32" height="14" rx="3" /></g>
        </svg>
        <span class="text-attenue">Racine de l’arbre</span>
      </div>

      <div v-if="calqueActif" class="flex items-center gap-2 leading-tight">
        <svg width="34" height="16" class="shrink-0 overflow-visible" aria-hidden="true">
          <g class="fiche x attente"><rect x="1" y="1" width="32" height="14" rx="3" /></g>
        </svg>
        <span class="text-attenue">Proposée, en attente de validation</span>
      </div>

      <h4 class="mt-1 text-[10.5px] font-bold uppercase tracking-wider text-attenue">
        Branches
      </h4>
      <p class="-mt-1 text-[10.5px] leading-snug text-attenue">
        Les aplats du fond reprennent ces teintes : chacun couvre une région de l’arbre.
      </p>
      <!--
        L'échantillon porte les DEUX formes de la même teinte — le liseré de la
        fiche et l'aplat de la région — parce que c'est ainsi qu'elles se
        présentent à l'écran, et parce que les deux sortent de la même règle
        `.b-…` : elles ne peuvent pas diverger. Voir l'en-tête.
      -->
      <div
        v-for="b in avecHorsBranche ? [...BRANCHES, HORS_BRANCHE] : BRANCHES"
        :key="b.classe"
        class="flex items-center gap-2 leading-tight"
      >
        <svg width="34" height="16" class="shrink-0 overflow-visible" aria-hidden="true">
          <g class="zones"><path :class="['zone', b.classe]" d="M0,1 H34 V15 H0 Z" /></g>
          <rect :class="['br', b.classe]" x="14" y="2" width="4" height="12" />
        </svg>
        <span class="text-attenue">{{ b.libelle }}</span>
      </div>

      <h4 class="mt-1 text-[10.5px] font-bold uppercase tracking-wider text-attenue">
        Unions
      </h4>
      <div class="flex items-center gap-2 leading-tight">
        <svg width="34" height="16" class="shrink-0 overflow-visible" aria-hidden="true">
          <g class="fam"><polygon points="6,8 17,1 28,8 17,15" /></g>
        </svg>
        <span class="text-attenue">Union — le losange porte le couple</span>
      </div>
      <div class="flex items-center gap-2 leading-tight">
        <svg width="34" height="16" class="shrink-0 overflow-visible" aria-hidden="true">
          <g class="fam div">
            <polygon points="6,8 17,1 28,8 17,15" />
            <path class="barre" d="M11,13 L23,3" />
          </g>
        </svg>
        <span class="text-attenue">Divorce</span>
      </div>
      <div class="flex items-center gap-2 leading-tight">
        <svg width="34" height="16" class="shrink-0 overflow-visible" aria-hidden="true">
          <path class="ar divorce" d="M2,8 L32,8" />
        </svg>
        <span class="text-attenue">Lien rompu</span>
      </div>
    </div>
  </details>
</template>
