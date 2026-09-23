/**
 * Point d'entrée unique du client d'API.
 *
 * Les écrans importent depuis `@/lib/api`, jamais depuis un fichier interne :
 * cela laisse la liberté de réorganiser le dossier sans toucher à trente vues.
 */

export * from './types'
export {
  ErreurApi,
  analyserErreur,
  conduiteATenir,
  estErreurApi,
  estRejouable,
  libelleDeConduite,
  messageDErreur,
  CODE_AUTHENTIFICATION_REQUISE,
  CODE_CIBLE_DISPARUE,
  CODE_DEMANDE_CADUQUE,
  CODE_REGIME_EN_ATTENTE,
  CODE_RESEAU,
  CODE_VERROU_CONCURRENT,
  CODE_VUE_COMME_ACTIVE,
} from './erreurs'
export type { AnalyseErreur, ConduiteATenir } from './erreurs'
export { appelBrut, construireUrl, requeteApi, surSessionPerdue } from './client'
export type { MethodeHttp, OptionsRequete, ReponseBrute } from './client'
export { etagConnu, oublierEtag, requeteRevalidee } from './revalidation'
export type { ResultatRevalide } from './revalidation'
export * from './routes'
/* Module `Tree` : fiche, famille, recherche, détail d'un nœud en attente
   (§ 5.3, § 5.4, § 5.5, § 6.6). `routes.ts` ne porte que le graphe. */
export * from './arbre'
export * from './inscription'
/* Module `Timeline` : la vue chronologique et sa préférence (§ 9). */
export * from './chronologie'
/* Module `Almanach` : la carte des lieux et les anniversaires (§ 19, § 20). Une
   seule charge par écran — les filtres et les mois sont des réglages de rendu. */
export * from './evenements'
/* Module `Proposal` : la file, le diff, valider et refuser (§ 6). */
export * from './propositions'
/* Module `Media` : portraits et preuves — téléverser, lister, servir, supprimer
   (§ 7). Aucune URL de fichier ne s'y fabrique : le serveur écrit `url` et
   `vignetteUrl` dans la charge du média, et c'est ce qui applique le cercle. */
export * from './medias'
/* Module `Question` : la page « Questions » — les trous de l'arbre que ce
   compte-là pourrait combler, et les trois façons d'y répondre (ADR 0008).
   Le `GET` de cette page ÉCRIT côté serveur : lire l'en-tête avant de
   l'appeler ailleurs qu'au montage de son écran. */
export * from './questions'
/* Modules `Core` et `Membership`, côté administrateur : invitations,
   utilisateurs, bascule « voir comme un membre » (§ 3, § 10). */
export * from './administration'
