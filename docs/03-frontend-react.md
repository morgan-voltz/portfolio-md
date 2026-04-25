# Architecture du front React `portfolio-frontend`

**Fichier** : `docs/architecture/03-frontend-react.md`
**Rôle** : référence technique complète du front React
**Prérequis** : avoir lu [`00-overview.md`](00-overview.md)
**Lectorat** : contributeur front, Morgan en phase d'implémentation, relecteur technique
**Temps de lecture** : environ vingt minutes
**Dernière mise à jour** : 22 avril 2026

---

## À quoi sert ce document

Ce document décrit l'architecture du front React qui constitue le portfolio technique. Il couvre les choix de stack et leurs alternatives, la structure du projet, la consommation de l'API C#, le rendu du contenu des articles, le mécanisme de chargement à la demande des enrichissements techniques, et la stratégie de déploiement.

Comme les autres documents d'architecture, les exemples de code sont illustratifs et courts. Le code réel est écrit par Morgan avec l'aide de Claude Code dans le respect des règles posées dans `CLAUDE.md`.

---

## 1. Rôle du front dans le système

Le front React est le portfolio technique destiné aux recruteurs et à toute personne qui s'intéresse au travail de Morgan sous un angle technique. Il est volontairement séparé du site WordPress pour plusieurs raisons qui méritent d'être énoncées clairement.

Cette séparation démontre d'abord une compétence fullstack moderne : maîtriser à la fois un backend (ici en C#) et un frontend (ici en React) est une attente courante dans les offres de développeur, et avoir deux portfolios distincts rend cette compétence immédiatement visible. Elle permet ensuite d'utiliser des technologies que WordPress ne permettrait pas naturellement — animations riches, interactions sophistiquées, enrichissements techniques qui dépassent les capacités d'un thème WordPress classique. Enfin, elle offre un laboratoire où Morgan peut expérimenter librement sans risquer de casser le site académique qui doit rester stable pour la soutenance.

Le front ne parle qu'à l'API C#. Il n'a aucune connaissance de l'existence de WordPress dans le système, et c'est voulu : si un jour le backend WordPress était remplacé par autre chose, le front ne s'en apercevrait pas.

---

## 2. Le stack technique

Le choix du stack mérite une justification parce que l'écosystème React en 2026 propose plusieurs voies et que la décision oriente le projet pour plusieurs années.

### 2.1. Les choix retenus

Le socle est **React 18 avec TypeScript**. TypeScript n'est plus optionnel dans un projet sérieux : le typage statique attrape des classes entières de bugs avant même l'exécution, rend la documentation intégrée au code via les inférences de type, et facilite les refactors à grande échelle.

Le bundler est **Vite**. Il remplace avantageusement Create React App (déprécié depuis 2023) et Webpack direct (trop complexe à configurer pour un projet de cette taille). Vite utilise esbuild en dev pour un temps de démarrage quasi-instantané et Rollup en build pour une production optimisée. Son écosystème de plugins est riche et son ergonomie remarquable.

Le routing est géré par **React Router en version 6**. C'est la solution de facto pour une SPA, mature, avec une API moderne basée sur les composants. Les alternatives comme TanStack Router sont prometteuses mais moins établies.

La consommation de l'API et le cache client sont gérés par **TanStack Query** (anciennement React Query). C'est devenu le standard absolu pour gérer les données serveur dans une application React : il encapsule le caching, les états de chargement et d'erreur, les retries, les invalidations, le prefetching. Tenter de réimplémenter ces mécaniques à la main est une erreur classique qui consomme des semaines de développement pour un résultat inférieur.

Le styling utilise **Tailwind CSS**. Le choix se justifie par la vitesse de développement (classes utilitaires appliquées directement aux éléments), la cohérence visuelle (les tokens de design — couleurs, espacements, typographie — sont centralisés dans la config Tailwind), et l'excellent support Vite. Les alternatives comme les CSS Modules ou CSS-in-JS restent viables mais créent plus de friction sur un projet de cette taille.

### 2.2. Les alternatives envisagées et rejetées

**Next.js** aurait apporté le rendu côté serveur, l'App Router, et une stack complète opinionated. Mais pour un portfolio où le SEO est assuré par le site WordPress parallèle et où le trafic est modéré, ses bénéfices ne justifient pas sa complexité. Next.js est un excellent choix pour une application e-commerce ou un média qui dépend du SEO natif ; pour notre cas, c'est surdimensionné.

**Remix** propose une philosophie « web platform first » qui séduit par sa cohérence, mais l'écosystème est moins mainstream que Next et la courbe d'apprentissage non-négligeable. Pas adapté pour un premier vrai projet React moderne.

**Astro** est excellent pour des sites majoritairement statiques avec des îlots d'interactivité. Un portfolio avec des fiches de projets serait un bon candidat Astro. Mais on veut ici un apprentissage en profondeur de React, et Astro permet justement de minimiser la part de React — à l'opposé de notre objectif pédagogique.

**Pas de state manager global** (Redux, Zustand, Jotai). TanStack Query gère l'état serveur, et l'état local est géré par `useState` et `useReducer` dans les composants concernés. Cette absence volontaire suit le principe que l'état global est souvent une solution à un problème qu'on a créé en sous-utilisant TanStack Query ou en remontant l'état trop haut dans l'arbre des composants.

---

## 3. Structure de dossiers

L'organisation reflète la séparation des préoccupations : ce qui concerne l'API, ce qui concerne les routes, ce qui concerne la présentation réutilisable.

```
frontend/
│
├── index.html                         # Point d'entrée HTML minimal
├── package.json
├── tsconfig.json
├── vite.config.ts
├── tailwind.config.ts
├── postcss.config.js
│
├── src/
│   ├── main.tsx                       # Bootstrap React + Router + Query
│   ├── App.tsx                        # Layout global, navigation
│   │
│   ├── routes/                        # Un fichier par page
│   │   ├── Home.tsx
│   │   ├── ArticleList.tsx
│   │   ├── ArticleDetail.tsx
│   │   ├── ProjectList.tsx
│   │   ├── ProjectDetail.tsx
│   │   └── About.tsx
│   │
│   ├── api/                           # Tout ce qui parle à l'API C#
│   │   ├── client.ts                  # Configuration fetch + base URL
│   │   ├── articles.ts                # Fonctions de requête articles
│   │   ├── projects.ts                # Fonctions de requête projets
│   │   └── types.ts                   # Types TS des DTOs reçus
│   │
│   ├── hooks/                         # Hooks TanStack Query
│   │   ├── useArticle.ts
│   │   ├── useArticles.ts
│   │   ├── useProject.ts
│   │   └── useProjects.ts
│   │
│   ├── components/
│   │   ├── layout/                    # Header, footer, nav
│   │   ├── ui/                        # Boutons, cartes, badges réutilisables
│   │   └── content/                   # Rendu d'article, bloc de code, etc.
│   │
│   └── lib/
│       ├── enrichments/               # Lazy loading Mermaid, KaTeX
│       │   ├── mermaid.ts
│       │   └── katex.ts
│       └── format/                    # Dates, temps de lecture, etc.
│
└── public/                            # Assets statiques (favicon, images)
```

L'organisation par dossier `routes/`, `api/`, `hooks/`, `components/` n'est pas la seule valable — une alternative serait le *feature-based folder* où chaque fonctionnalité (articles, projects) aurait son propre dossier complet. Pour un projet de cette taille, l'organisation par type reste lisible. Si le projet grossissait significativement, un passage en feature-based deviendrait pertinent.

---

## 4. Les routes et leur organisation

La structure de navigation du portfolio est volontairement sobre. Six pages couvrent tous les besoins.

`/` est la page d'accueil. Elle présente Morgan en quelques lignes, met en avant les derniers articles et les projets en cours, et sert de porte d'entrée pour les visiteurs qui arrivent directement.

`/articles` est la liste complète des articles, paginée, filtrable par tag et catégorie via des paramètres d'URL (`?tag=rust&page=2`). Les filtres d'URL permettent le partage de vues filtrées et la navigation arrière fonctionnelle.

`/articles/:slug` est la page de détail d'un article, avec son contenu complet rendu en HTML et les éventuels enrichissements chargés à la demande (voir section six).

`/projets` et `/projets/:slug` font la même chose pour les projets, avec en plus des filtres par statut (en cours, terminé) et par technologie.

`/about` est une page statique de présentation plus longue, pour les visiteurs qui veulent en savoir plus.

`/competences` est une route additionnelle qui répond à la contrainte académique de rattachement des compétences aux projets. Elle affiche la liste des compétences du référentiel UHA 4.0 et, pour chaque compétence, les projets qui la démontrent. Cette route transforme une exigence formelle du cahier des charges en démonstration structurée qui sert aussi les recruteurs — un visiteur qui veut évaluer tes capacités sur un domaine précis (par exemple l'analyse de problématique complexe ou l'intégration de systèmes hétérogènes) y trouve directement la liste des réalisations pertinentes. La route consomme l'endpoint `GET /api/skills` de l'API C#.

Sur les pages `/projets` et `/projets/:slug`, les composants d'affichage doivent traiter deux informations spécifiques liées à la contrainte académique. Chaque fiche projet affiche la liste des compétences rattachées sous forme de badges (récupérées depuis `skills` du DTO). Le projet marqué `isFilRouge: true` bénéficie d'un traitement visuel distinctif — mise en avant sur la page d'accueil, carte plus imposante sur la liste des projets, éventuel badge « Projet fil rouge » sur sa fiche — qui reflète son statut éditorial particulier dans le cursus.

Les routes sont déclarées dans `App.tsx` via React Router. Chaque route correspond à un composant de `src/routes/` qui compose le layout global et le contenu spécifique.

---

## 5. Consommation de l'API avec TanStack Query

Le pattern de consommation suit les conventions établies par TanStack Query. Chaque type de requête est encapsulé dans un hook custom qui le rend trivialement réutilisable dans les composants.

```typescript
// Dans src/hooks/useArticle.ts
export function useArticle(slug: string) {
  return useQuery({
    queryKey: ['article', slug],
    queryFn: () => fetchArticle(slug),
    staleTime: 5 * 60 * 1000, // 5 minutes avant refetch auto
  });
}

// Dans src/routes/ArticleDetail.tsx
export function ArticleDetail() {
  const { slug } = useParams();
  const { data: article, isLoading, error } = useArticle(slug!);

  if (isLoading) return <LoadingSpinner />;
  if (error) return <ErrorBanner />;
  if (!article) return <NotFound />;

  return <ArticleView article={article} />;
}
```

Le `queryKey` est la clé d'identification de la requête dans le cache TanStack Query. La convention est de commencer par le nom de la ressource (`'article'`) suivi des paramètres qui distinguent la requête (ici `slug`). Cette hiérarchie permet des invalidations ciblées — on peut invalider toutes les requêtes articles avec `queryClient.invalidateQueries({ queryKey: ['article'] })`.

Le `staleTime` contrôle le temps pendant lequel une entrée de cache est considérée fraîche. Pendant cette période, les navigations vers la même page ne redéclenchent pas de requête réseau. Pour un portfolio où le contenu change peu, cinq minutes est raisonnable ; on peut l'ajuster selon l'observation.

La configuration globale de TanStack Query vit dans `src/main.tsx` où le `QueryClient` est instancié et fourni aux composants via `QueryClientProvider`. Les defaults s'appliquent à toutes les requêtes sauf surcharge explicite dans un `useQuery`.

---

## 6. Le rendu du contenu des articles

L'API C# retourne le HTML déjà rendu par Markdig. Le front reçoit ce HTML et doit l'afficher. Cette opération mérite une discussion parce qu'elle implique `dangerouslySetInnerHTML`, qui est une API React volontairement inquiétante par son nom.

### 6.1. L'usage de `dangerouslySetInnerHTML` et sa justification

React refuse par défaut d'interpréter du HTML comme markup — tout ce qu'on passe à un composant est rendu comme du texte littéral. Cette rigueur protège contre les attaques XSS quand on affiche du contenu utilisateur. Pour passer outre, il faut explicitement utiliser `dangerouslySetInnerHTML`, dont le nom rappelle la responsabilité qu'on prend.

Dans notre cas, l'usage est sûr pour une raison précise : **le HTML que nous recevons est généré par notre propre backend à partir d'un Markdown que Morgan écrit lui-même**. La chaîne de confiance est complète de bout en bout. Il n'y a pas de contenu utilisateur arbitraire qui transiterait.

Néanmoins, deux précautions restent en place. Premièrement, le parsing Markdown côté C# (via Markdig) est configuré pour ne pas interpréter le HTML inline dans le Markdown — si Morgan écrivait `<script>alert('hi')</script>` dans un article, ce bout de texte serait échappé et rendu comme du texte visible, pas exécuté. Deuxièmement, la politique de sécurité de contenu (CSP) du site interdit l'exécution de scripts inline non explicitement autorisés, ce qui ajoute une barrière supplémentaire.

### 6.2. Le composant `ArticleView`

Le composant qui affiche un article est une fonction simple qui prend l'article et rend son contenu dans un conteneur stylé.

```tsx
// Dans src/components/content/ArticleView.tsx
export function ArticleView({ article }: { article: Article }) {
  const contentRef = useRef<HTMLDivElement>(null);

  // Enrichissements à la demande (voir section 7)
  useEnrichments(article, contentRef);

  return (
    <article className="prose prose-lg max-w-none">
      <h1>{article.title}</h1>
      <ArticleMeta article={article} />
      <div
        ref={contentRef}
        dangerouslySetInnerHTML={{ __html: article.html }}
      />
    </article>
  );
}
```

La classe `prose` vient du plugin Tailwind Typography qui style automatiquement les éléments HTML standards (h2, p, ul, blockquote, pre, etc.) avec une typographie lisible. Elle évite d'avoir à écrire du CSS pour chaque balise rendue par Markdig. Le `ref` sur la div permet aux enrichissements de post-traiter le DOM après rendu.

---

## 7. Lazy loading des enrichissements techniques

Certains articles contiennent des diagrammes Mermaid ou des formules mathématiques KaTeX. Ces librairies pèsent plusieurs centaines de kilo-octets chacune. Les charger sur toutes les pages serait un gâchis — la majorité des articles n'en ont pas besoin. La stratégie est d'importer ces librairies dynamiquement uniquement sur les articles qui les déclarent.

Le frontmatter de chaque article contient les flags `needs_mermaid` et `needs_katex` (voir `01-plugin-php.md` section 4.4). Ces flags sont propagés jusqu'au DTO reçu par le front. Le hook `useEnrichments` inspecte ces flags et charge conditionnellement les librairies.

```typescript
// Dans src/lib/enrichments/mermaid.ts
let mermaidPromise: Promise<typeof import('mermaid')> | null = null;

export function loadMermaid() {
  // On mémoïse la promesse pour éviter les téléchargements multiples
  mermaidPromise ??= import('mermaid').then(m => {
    m.default.initialize({ startOnLoad: false, theme: 'default' });
    return m;
  });
  return mermaidPromise;
}
```

Le `import()` dynamique est un standard ECMAScript que Vite transforme en *code split* au build. Concrètement, Mermaid se retrouve dans un chunk séparé du bundle principal. Le navigateur ne le télécharge que lorsque le `import()` est exécuté, c'est-à-dire uniquement sur les pages qui l'appellent.

La même logique s'applique à KaTeX. Une fois chargée, la librairie reste en mémoire pour la session — un second article Mermaid visité immédiatement après n'entraîne pas de second téléchargement.

Le rendu des diagrammes Mermaid se fait en post-traitement : une fois le HTML inséré dans la div, le hook parcourt les blocs `<pre class="language-mermaid">` et appelle Mermaid pour les convertir en SVG. Même approche pour KaTeX sur les blocs `<span class="math">`.

Cette stratégie de *progressive enhancement* garantit que les articles simples restent ultra-légers (pas de librairies inutiles chargées) tout en permettant des articles techniquement riches sans compromis.

---

## 8. Design et thème

Le design du portfolio technique est encore à préciser avec Morgan pendant la phase d'implémentation. Les principes directeurs initiaux sont la sobriété (contenu mis en valeur, typographie soignée, pas de décoration qui détournerait l'attention), le support natif du mode sombre (préférence système respectée via `prefers-color-scheme`), et la performance perçue (animations légères avec `prefers-reduced-motion` respecté).

La palette et la typographie exactes seront choisies au moment de l'implémentation. Tailwind permet de centraliser ces choix dans `tailwind.config.ts`, ce qui facilite les itérations visuelles sans toucher aux composants.

Un document `docs/frontend/design-tokens.md` pourra être ajouté plus tard si la complexité du design le justifie — pour l'instant, la config Tailwind fera office de source de vérité pour les tokens.

---

## 9. Déploiement

La stratégie de déploiement suit l'approche JAMstack standard : le build produit un bundle statique (HTML, JS, CSS, images) qui est servi via un CDN.

**Cloudflare Pages** est le choix recommandé pour trois raisons. C'est gratuit pour des projets personnels avec des quotas largement suffisants pour un portfolio. Le déploiement se déclenche automatiquement à chaque push sur la branche `main` via l'intégration GitHub. Le CDN Cloudflare est un des plus performants au monde, avec des points de présence partout.

Netlify et Vercel sont des alternatives équivalentes fonctionnellement. Cloudflare a l'avantage d'un modèle économique plus durable (pas de freemium qui pousse au paid) et d'une meilleure neutralité géopolitique pour un projet qui valorise la souveraineté numérique.

La configuration de build est minimale : `npm run build` produit un dossier `dist/` que la plateforme sert. La commande de build et le dossier de sortie sont déclarés dans l'interface de la plateforme ou dans un fichier `netlify.toml` / `wrangler.toml` selon la plateforme.

Les **variables d'environnement** nécessaires sont essentiellement l'URL de l'API C# (`VITE_API_URL`). Elles sont configurées dans l'interface de la plateforme et injectées au build. Vite expose uniquement les variables préfixées `VITE_` au code client, pour éviter les fuites accidentelles de secrets.

Le domaine personnalisé (par exemple `portfolio-tech.morgan.dev`) est configuré via DNS pointant vers les CNAMEs fournis par la plateforme, qui gère automatiquement les certificats HTTPS.

---

## 10. Tests

La stratégie de tests pour un front React de cette taille peut rester mesurée.

Les **tests unitaires** avec Vitest couvrent les utilitaires purs (formatage de dates, calculs) et les fonctions de transformation de données. Vitest est à Vite ce que Jest est à Webpack : le runner de tests intégré qui partage la configuration et les transformations du bundler.

Les **tests de composants** avec `@testing-library/react` couvrent les composants importants : `ArticleView`, les filtres de liste, le mécanisme de pagination. Le principe de Testing Library est de tester ce que l'utilisateur voit et fait (« cherche le titre avec ce texte, clique sur ce bouton, vérifie que le résultat apparaît »), pas les détails d'implémentation. Cette philosophie produit des tests plus résistants aux refactors.

Les **tests end-to-end** avec Playwright peuvent être ajoutés plus tard pour valider les flux critiques (arriver sur la page d'accueil, cliquer sur un article, vérifier son affichage complet, revenir à la liste, filtrer par tag). Pour la phase initiale, ils ne sont pas prioritaires — les tests de composants suffisent à attraper la majorité des régressions visibles.

---

## 11. Navigation

| Document | Contenu |
|---|---|
| [`00-overview.md`](00-overview.md) | Vue d'ensemble du projet |
| [`01-plugin-php.md`](01-plugin-php.md) | Le plugin WordPress, origine du contenu |
| [`02-api-csharp.md`](02-api-csharp.md) | L'API C# que ce front consomme |
| [`99-decisions.md`](99-decisions.md) | Journal des décisions architecturales |
| [`../../CLAUDE.md`](../../CLAUDE.md) | Règles de collaboration avec Claude Code |

---

*Document évolutif. Les choix techniques listés ici sont la base de départ et peuvent être révisés au fil de l'implémentation, auquel cas les changements sont consignés dans le journal des décisions.*
