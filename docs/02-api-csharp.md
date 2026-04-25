# Architecture de l'API C# `Portfolio.Api`

**Fichier** : `docs/architecture/02-api-csharp.md`
**Rôle** : référence technique complète de l'API .NET
**Prérequis** : avoir lu [`00-overview.md`](00-overview.md)
**Lectorat** : contributeur .NET, Morgan en phase d'implémentation, relecteur technique
**Temps de lecture** : environ vingt-cinq minutes
**Dernière mise à jour** : 22 avril 2026

---

## À quoi sert ce document

Ce document détaille l'architecture interne de l'API C# qui sert de gateway entre le plugin WordPress et le front React. Il couvre l'organisation en trois projets, le pipeline typique d'une requête, la stratégie de cache, le parsing Markdown côté C#, les endpoints publics exposés, et la façon dont cette architecture se prépare à accueillir d'autres interfaces (desktop, mobile, CLI) sans duplication de logique.

Comme pour `01-plugin-php.md`, les exemples de code présents ici sont des signatures ou des fragments illustratifs destinés à clarifier l'architecture. Ils ne constituent pas des implémentations prêtes à copier-coller. Le code réel est écrit par Morgan.

---

## 1. Le rôle de l'API

L'API joue trois rôles simultanément, et il est important de les distinguer parce qu'ils justifient des décisions différentes.

### 1.1. Gateway vers WordPress

L'API consomme l'API REST custom exposée par le plugin `portfolio-md` (documentée en section sept de `01-plugin-php.md`). Elle protège le front React d'une connaissance directe de WordPress, ce qui présente plusieurs bénéfices concrets. Le front peut évoluer sans que tout change dès qu'une route WordPress bouge. L'API peut ajouter de l'authentification, du rate limiting, de la transformation de données sans toucher à WordPress. Elle peut aussi, à terme, composer plusieurs sources de données (WordPress plus une base analytique, par exemple) derrière une interface unifiée.

Cette séparation est une application du pattern *backend-for-frontend* (BFF) : le front ne parle jamais directement aux systèmes sources, il parle à une couche intermédiaire taillée pour ses besoins.

### 1.2. Transformer et enrichisseur

L'API ne se contente pas de relayer le contenu de WordPress, elle peut l'enrichir. Le parsing Markdown vers HTML peut être fait côté C# avec Markdig plutôt que côté PHP, ce qui permet à l'API d'ajouter ses propres conventions de rendu (ancres automatiques sur les titres, liens internes transformés, table des matières générée, etc.) sans que le plugin WordPress en sache rien.

Elle peut aussi calculer des champs dérivés à la volée : articles connexes basés sur les tags, temps de lecture recalculé selon les règles du front, statistiques d'utilisation si un jour on en veut.

### 1.3. Laboratoire d'apprentissage .NET

Le troisième rôle est personnel et il teinte les choix techniques qui suivent. L'API est l'endroit où Morgan apprend .NET 8 moderne : Minimal APIs, injection de dépendances native, HttpClient typé, tests en xUnit, patterns de Clean Architecture. Certaines décisions qu'on prend ici — utiliser Minimal APIs plutôt que des Controllers classiques, par exemple — sont motivées par cette dimension d'apprentissage plutôt que par une supériorité technique absolue.

---

## 2. Pourquoi Clean Architecture plutôt qu'autre chose

Ce choix architectural a été discuté en détail entre Morgan et Claude avant la rédaction de ce document. Le résumé de la décision tient en quelques points.

MVVM n'est pas adapté parce que c'est un pattern d'interface utilisateur conçu pour binder une vue graphique à un objet de présentation. L'API n'a pas de vue — elle reçoit du JSON et retourne du JSON. MVVM sera pertinent le jour où un frontend desktop apparaîtra, mais pas pour la partie serveur.

MVC classique au sens ASP.NET Core serait possible mais apporte peu : il organiserait les endpoints en contrôleurs avec des méthodes décorées par des attributs HTTP. Pour une gateway de cette taille, les Minimal APIs donnent le même résultat en beaucoup moins de code.

CQRS avec MediatR est une option populaire dans l'écosystème .NET. Elle sépare les commandes (écritures) des requêtes (lectures) et passe tout par un médiateur. C'est élégant pour des systèmes avec beaucoup de cas d'usage distincts. Pour notre API qui est essentiellement en lecture, avec une poignée de cas d'usage, MediatR ajouterait une couche d'indirection sans bénéfice proportionnel. On reste sur de l'injection de dépendances directe, et si un jour le volume de handlers justifie MediatR, on l'introduira.

Vertical Slice Architecture organise le code par fonctionnalité plutôt que par couche technique. C'est une bonne approche, mais pour une API gateway dont la logique par endpoint est simple et homogène, l'organisation en couches horizontales (Clean Architecture classique) reste plus lisible.

Clean Architecture en version légère (trois projets au lieu des quatre canoniques) est le compromis retenu. Elle donne la séparation claire entre logique métier et détails techniques, elle est testable sans serveur web, elle enseigne des principes transposables à tous les langages, et elle prépare le terrain pour la réutilisation multi-frontend décrite en section neuf.

---

## 3. La structure en trois projets

La solution `Portfolio.sln` contient trois projets qui forment un graphe de dépendances strict.

```
Portfolio.sln
│
├── Portfolio.Api/                     # Entrée HTTP, ASP.NET Core Minimal APIs
│   ├── Portfolio.Api.csproj
│   ├── Program.cs                     # Bootstrap, DI, enregistrement des endpoints
│   ├── Endpoints/                     # Les endpoints groupés par ressource
│   ├── Dtos/                          # Objets de requête et réponse HTTP
│   └── Middleware/                    # Pipeline HTTP custom (auth, logging, etc.)
│
├── Portfolio.Application/             # Cœur métier, aucune dépendance externe
│   ├── Portfolio.Application.csproj
│   ├── Models/                        # Article, Project, Tag, etc.
│   ├── Abstractions/                  # Interfaces des services externes
│   │   ├── IWordPressClient.cs
│   │   ├── IArticleCache.cs
│   │   └── IMarkdownRenderer.cs
│   └── UseCases/                      # Handlers des cas d'usage
│       ├── Articles/
│       │   ├── GetArticleBySlug.cs
│       │   └── ListArticles.cs
│       └── Projects/
│           ├── GetProjectBySlug.cs
│           └── ListProjects.cs
│
└── Portfolio.Infrastructure/          # Implémentations concrètes
    ├── Portfolio.Infrastructure.csproj
    ├── WordPress/
    │   └── WordPressClient.cs         # Implémente IWordPressClient via HttpClient
    ├── Caching/
    │   └── MemoryArticleCache.cs      # Implémente IArticleCache via IMemoryCache
    └── Markdown/
        └── MarkdigRenderer.cs         # Implémente IMarkdownRenderer via Markdig
```

### 3.1. `Portfolio.Api` : la surface HTTP

Ce projet contient uniquement ce qui concerne la communication HTTP. Les endpoints sont déclarés en Minimal APIs dans des fichiers groupés par ressource. Un endpoint typique tient en quelques lignes expressives :

```csharp
// Dans Endpoints/ArticleEndpoints.cs
public static class ArticleEndpoints
{
    public static void MapArticleEndpoints(this IEndpointRouteBuilder app)
    {
        var group = app.MapGroup("/api/articles");

        // La route appelle directement le handler applicatif via DI
        group.MapGet("/{slug}", async (
            string slug,
            GetArticleBySlug handler) =>
        {
            var article = await handler.ExecuteAsync(slug);
            return article is null ? Results.NotFound() : Results.Ok(article);
        });
    }
}
```

Les **DTOs** (`Dtos/`) sont des objets qui représentent la forme exacte du JSON échangé avec les clients HTTP. Ils sont distincts des modèles de `Portfolio.Application`, pour une raison importante : les modèles du cœur évoluent selon la logique métier, les DTOs évoluent selon les contrats publics de l'API. Mélanger les deux couple ces deux rythmes d'évolution et crée des cassures incontrôlées côté front.

Le **`Program.cs`** joue le rôle de *composition root* : c'est l'unique endroit qui instancie les services, configure l'injection de dépendances, enregistre les endpoints, et lance l'application. En Clean Architecture c'est aussi le seul endroit qui connaît à la fois `Application` et `Infrastructure`, puisqu'il doit mapper les interfaces aux implémentations.

### 3.2. `Portfolio.Application` : le cœur métier

Ce projet est le centre de l'architecture et il a une propriété cruciale : **il ne dépend d'aucun framework externe, d'aucune librairie tierce, d'aucune fonctionnalité ASP.NET**. Les seules dépendances admises sont les types de base de .NET (`System.*`, `System.Threading.Tasks`, etc.). Si un `using Microsoft.AspNetCore.*` apparaît dans un fichier de ce projet, c'est une erreur architecturale.

Le projet contient trois types d'éléments. Les **modèles** (`Models/`) sont les objets métier : `Article`, `Project`, `Tag`. Ils sont idéalement implémentés en `record` C# pour leur immutabilité et leur égalité par valeur. Ils ne contiennent que des données et des méthodes qui opèrent sur ces données sans effet de bord externe.

Les **abstractions** (`Abstractions/`) sont des interfaces qui définissent ce dont le cœur a besoin du monde extérieur, sans savoir comment ce besoin sera satisfait. L'interface `IWordPressClient` décrit ce que le cœur attend pour récupérer des articles — « donne-moi un article par slug », « donne-moi une liste paginée » — mais ne dit rien de HTTP, de WordPress, de JSON. L'implémentation concrète de ces interfaces vit dans `Portfolio.Infrastructure`.

Cette inversion est le principe central de la Clean Architecture. Le cœur dicte ce qu'il veut via des interfaces. La périphérie s'adapte au cœur en implémentant ces interfaces. La dépendance logique va du cœur vers la périphérie (le cœur dit « j'ai besoin de ça »), mais la dépendance de compilation va de la périphérie vers le cœur (la périphérie référence le projet du cœur pour implémenter ses interfaces).

Les **UseCases** (`UseCases/`) sont les handlers de cas d'usage. Chaque fichier représente une opération métier complète : `GetArticleBySlug`, `ListArticles`, `ListProjectsByStack`. Un handler est typiquement une classe avec une unique méthode `ExecuteAsync` qui orchestre les interfaces abstraites pour produire un résultat. Le cas d'usage `GetArticleBySlug` est traité en détail en section quatre.

### 3.3. `Portfolio.Infrastructure` : les implémentations concrètes

Ce projet contient les implémentations réelles des interfaces déclarées dans `Application`. L'organisation par sous-dossiers suit les responsabilités externes : `WordPress/` pour tout ce qui parle au plugin WordPress via HTTP, `Caching/` pour la couche de cache, `Markdown/` pour le parsing côté C#.

Chaque implémentation dépend de librairies externes concrètes. `WordPressClient` utilise `HttpClient` (avec la configuration typée d'ASP.NET Core via `AddHttpClient`), `MemoryArticleCache` utilise `IMemoryCache` du framework, `MarkdigRenderer` utilise Markdig avec ses extensions. Ces dépendances concrètes sont confinées à ce projet — elles ne remontent jamais jusqu'à `Application` ou `Api`.

### 3.4. La règle des dépendances

Le graphe de dépendances entre les trois projets suit une règle absolue : **`Application` ne dépend de rien, `Api` et `Infrastructure` dépendent de `Application`, aucune dépendance circulaire n'est permise**.

```
  Portfolio.Api  ─────►  Portfolio.Application  ◄─────  Portfolio.Infrastructure
```

Cette règle est matérialisée dans les fichiers `.csproj` via les `ProjectReference`. `Portfolio.Api.csproj` référence à la fois `Application` et `Infrastructure` (il compose les deux au démarrage). `Portfolio.Infrastructure.csproj` référence uniquement `Application` (pour voir les interfaces à implémenter). `Portfolio.Application.csproj` ne référence aucun autre projet.

L'architecte en toi doit relire cette règle et vérifier mentalement sa cohérence : si `Application` dépendait de `Infrastructure`, le cœur dépendrait des détails, ce qui casserait toute l'indépendance qu'on cherche à obtenir. Si `Application` dépendait d'`Api`, le métier serait couplé à HTTP, ce qui empêcherait la réutilisation multi-frontend évoquée en section neuf.

---

## 4. Walkthrough : le cas d'usage `GetArticleBySlug`

Ce cas d'usage traverse toute l'architecture et sert de fil rouge pédagogique pour comprendre comment les trois projets collaborent. On suit le parcours d'une requête `GET /api/articles/rust-ownership-intuition` depuis son arrivée jusqu'à la réponse.

**Étape un : l'arrivée de la requête dans `Portfolio.Api`.** La requête HTTP entre dans ASP.NET Core, traverse le pipeline de middlewares (CORS, logging, éventuellement l'authentification), et aboutit à l'endpoint Minimal API déclaré dans `ArticleEndpoints.cs`. L'endpoint reçoit en paramètres le `slug` extrait de la route et une instance de `GetArticleBySlug` fournie par l'injection de dépendances.

**Étape deux : l'appel au handler applicatif.** L'endpoint appelle `handler.ExecuteAsync(slug)` et attend son résultat. Remarque importante : l'endpoint ne contient aucune logique métier, il se contente de traduire entre HTTP et service applicatif. Si le handler retourne `null`, l'endpoint répond 404. S'il retourne un article, l'endpoint le sérialise en JSON via `Results.Ok(article)`. C'est tout.

**Étape trois : le handler consulte le cache.** Dans `Portfolio.Application`, la classe `GetArticleBySlug` reçoit en dépendances trois interfaces : `IArticleCache`, `IWordPressClient`, et `IMarkdownRenderer`. Son premier geste est d'interroger le cache.

```csharp
public async Task<ArticleDto?> ExecuteAsync(string slug)
{
    // Étape 3 — Cache hit ?
    var cached = await _cache.GetAsync(slug);
    if (cached is not null)
    {
        return cached;
    }
    // Cache miss, on continue...
}
```

**Étape quatre : appel au client WordPress en cas de cache miss.** Si le cache ne contient pas l'article, le handler appelle `_wordPressClient.GetArticleBySlugAsync(slug)`. L'implémentation concrète de cette interface vit dans `Portfolio.Infrastructure.WordPress.WordPressClient`, qui utilise un `HttpClient` typé pour taper `GET /wp-json/portfolio/v1/articles/{slug}` sur l'instance WordPress configurée. La réponse JSON est désérialisée en un objet `WordPressArticle` (une classe interne à l'infrastructure).

Si WordPress retourne 404, le client retourne `null`. Le handler retourne à son tour `null`, et l'endpoint HTTP conclut avec un 404.

**Étape cinq : la transformation.** Si l'article existe, le handler le transforme en `ArticleDto` prêt pour le front. Cette transformation inclut typiquement le rendu du Markdown en HTML via `_markdownRenderer.Render(article.Markdown)`. Le handler décide aussi comment peupler les champs du DTO à partir des données WordPress : mapping des tags, formatage des dates, calcul du temps de lecture si besoin, ajout d'éventuels liens connexes.

**Étape six : mise en cache et retour.** Le DTO construit est stocké dans le cache avec une clé dérivée du slug et un TTL raisonnable (quelques minutes pour commencer, ajustable). Il est ensuite retourné au endpoint qui le sérialise et l'envoie au client.

Un détail conceptuellement important : pendant toute cette chaîne, `Portfolio.Application` ne sait rien de HTTP ni de WordPress. Le handler parle à des interfaces. C'est `Portfolio.Infrastructure` qui implémente ces interfaces en termes de HttpClient et Markdig. Et c'est `Portfolio.Api` qui câble le tout dans `Program.cs` en disant « l'interface `IWordPressClient` est implémentée par la classe `WordPressClient`, voici sa configuration ».

Ce découplage permet de tester le handler `GetArticleBySlug` sans démarrer de serveur HTTP ni accéder à WordPress : on injecte des doubles de test qui implémentent `IArticleCache`, `IWordPressClient`, et `IMarkdownRenderer`, on appelle `ExecuteAsync`, on vérifie le comportement. Les tests s'exécutent en millisecondes.

---

## 5. Stratégie de cache

Le cache est essentiel pour que l'API absorbe le trafic du front sans marteler le WordPress à chaque requête. La stratégie retenue est simple et progressivement améliorable.

### 5.1. Implémentation initiale : `IMemoryCache`

Pour la version initiale de l'API, un cache en mémoire (`IMemoryCache` fourni nativement par .NET) suffit. Il est rapide, ne demande aucune infrastructure supplémentaire, et convient parfaitement à un processus unique. Les limitations sont connues : la mémoire est finie, le cache est perdu au redémarrage, et il ne peut pas être partagé entre plusieurs instances.

Pour un portfolio avec quelques dizaines d'articles et un trafic modeste, ces limitations n'ont aucun impact. Le jour où l'API tournerait derrière plusieurs instances (scaling horizontal), on migrerait vers un cache distribué — Redis typiquement. Cette migration est peu coûteuse parce que l'interface `IArticleCache` abstrait l'implémentation : il suffit d'ajouter une `RedisArticleCache` dans `Portfolio.Infrastructure` et de changer le câblage dans `Program.cs`.

### 5.2. L'invalidation intelligente via `/sitemap`

Un cache qui ne s'invalide jamais sert du contenu périmé. La stratégie classique est de mettre un TTL court et d'accepter que chaque entrée soit recalculée périodiquement. Cette approche fonctionne mais elle est brutale : un article qui n'a pas changé depuis des mois est quand même reconstruit toutes les quelques minutes.

Une stratégie plus fine exploite l'endpoint `/wp-json/portfolio/v1/sitemap` du plugin WordPress, qui retourne la liste de tous les slugs avec leurs timestamps de dernière modification. Un service de fond dans l'API appelle ce sitemap toutes les trente secondes (ou à l'intervalle qu'on choisira) et compare les timestamps avec ceux des entrées en cache. Les entrées dont le timestamp WordPress a avancé sont invalidées. Les autres restent en cache plus longtemps.

Cette approche a un coût de requête faible (une seule requête au sitemap périodiquement, indépendamment du nombre d'articles) et un bénéfice important : le cache reste efficace, mais la fraîcheur des données est garantie à la granularité du polling. Pour un portfolio où le contenu est mis à jour manuellement, trente secondes de latence est invisible pour l'utilisateur.

Le service qui fait le polling est enregistré comme `BackgroundService` hébergé par ASP.NET Core. C'est un pattern standard pour les tâches de fond associées à un serveur web.

---

## 6. Parsing Markdown côté C#

Le Markdown source arrive depuis WordPress sous forme de string brut (frontmatter plus corps). L'API a deux options pour le rendre : laisser le front s'en charger avec une librairie JavaScript côté navigateur, ou le rendre côté serveur et envoyer du HTML.

Le choix fait ici est de **rendre côté serveur**. Les raisons : la charge est portée par le serveur plutôt que par chaque navigateur visiteur, le HTML est prêt à la livraison sans *flash of unstyled content*, les lecteurs d'écran et les moteurs d'indexation voient un contenu immédiatement lisible, et les enrichissements (ancres sur les titres, liens internes transformés, numérotation automatique) se font à un endroit unique côté C#.

La librairie retenue est **Markdig**, standard de fait dans l'écosystème .NET. Elle supporte GFM nativement et propose un système d'extensions riche. Les extensions activées reproduisent celles configurées côté PHP pour que le rendu soit équivalent : tableaux, listes de tâches, barré, liens auto, identifiants auto-générés sur les titres (`<h2 id="slug-du-titre">`).

L'interface `IMarkdownRenderer` dans `Application` déclare simplement `string Render(string markdown)`. L'implémentation `MarkdigRenderer` dans `Infrastructure` configure Markdig au démarrage et fournit le rendu.

Si un jour on veut offrir un format de sortie alternatif (par exemple retourner l'AST Markdig pour un rendu React côté front), on ajoutera une surcharge à l'interface et une implémentation. La structure de Clean Architecture rend cet ajout local, sans impact sur le reste.

---

## 7. Endpoints publics exposés

L'API expose plusieurs endpoints sous le préfixe `/api/`. Leur forme mime celle de l'API WordPress sous-jacente mais peut s'en écarter quand c'est utile pour le front.

`GET /api/articles` retourne la liste paginée des articles avec filtres optionnels par tag, catégorie, ou recherche textuelle via query params. La pagination utilise les paramètres `page` et `per_page` standards.

`GET /api/articles/{slug}` retourne un article complet avec HTML rendu. Un query param optionnel `?format=markdown` permet de demander le Markdown source plutôt que le HTML — utile pour un éventuel outil qui voudrait retraiter le contenu.

`GET /api/projects` et `GET /api/projects/{slug}` font la même chose pour les projets.

`GET /api/tags` retourne la liste de tous les tags avec leur compte d'articles associés — pratique pour alimenter un menu de filtrage côté front.

`GET /api/stack` retourne la liste des technologies utilisées sur des projets, avec leur compte — équivalent pour les projets.

`GET /api/skills` retourne la liste des compétences académiques rattachées à des projets, avec leur compte et leur intitulé complet. Cet endpoint alimente une éventuelle page « Compétences » sur le front React qui affiche, pour chaque compétence du référentiel UHA 4.0, la liste des projets qui la démontrent — une vue inverse précieuse pour un recruteur qui voudrait évaluer un domaine particulier de compétences. Une requête complémentaire `GET /api/projects?skill=<slug>` permet de filtrer les projets par compétence démontrée.

Les DTOs `ProjectDto` exposés par ces endpoints portent deux champs spécifiques liés à la contrainte académique : `skills` (liste d'objets avec `slug`, `code` et `label`) et `isFilRouge` (booléen qui distingue le projet fil rouge 4.0.2 pour permettre au front d'appliquer un affichage éditorial distinctif).

La forme des réponses JSON est volontairement stable et versionnée via le préfixe `/api/` (qu'on pourrait faire évoluer vers `/api/v1/`, `/api/v2/` si un jour un breaking change devient nécessaire). Les DTOs sont sérialisés avec la configuration `JsonSerializerOptions` standard de .NET, en camelCase pour respecter les conventions JavaScript du front.

---

## 8. Sécurité

L'API est publique en lecture et n'a pas besoin d'authentification pour l'usage normal. Quelques précautions restent de rigueur.

**Rate limiting** sur les endpoints publics pour éviter qu'un script abuse. ASP.NET Core 8 fournit un middleware de rate limiting natif (`AddRateLimiter`) qui permet de limiter par IP. Une limite raisonnable (par exemple cent requêtes par minute par IP) ne gêne aucun usage légitime et bloque les abus.

**CORS** configuré strictement pour n'autoriser que le ou les domaines du front React en production. En développement local, on autorise `localhost` sur plusieurs ports. La configuration est centralisée dans `Program.cs`.

**Pas de secrets dans les logs.** Le middleware de logging est configuré pour ne pas capturer les en-têtes d'authentification éventuels, ni les bodies des requêtes contenant des données sensibles. En gateway simple, le risque est faible, mais la discipline se pose dès le début.

**HTTPS obligatoire en production** via la redirection HTTPS du middleware ASP.NET Core. En développement local, HTTP suffit et le middleware s'adapte.

**Pas d'exposition du token WordPress.** Le token qui permet à l'API d'appeler `/sync` du plugin (si jamais l'API devait pousser quelque chose vers WordPress, ce qui n'est pas le cas actuel) serait stocké dans les secrets ASP.NET Core (`user-secrets` en dev, variables d'environnement ou coffre-fort en production). Jamais dans `appsettings.json` committé.

---

## 9. L'évolution multi-frontend

C'est la section qui justifie le coût architectural du découpage en trois projets. Elle décrit comment la même logique métier peut alimenter plusieurs interfaces différentes, y compris dans le futur.

### 9.1. Le principe

`Portfolio.Application` ne dépend d'aucune technologie d'interface. Ni HTTP, ni XAML, ni console, ni rien. Cette pureté fait qu'il peut être consommé par n'importe quelle interface qui accepterait de référencer le projet et d'implémenter les interfaces d'abstraction dont il a besoin.

Concrètement, si demain Morgan démarre un outil desktop pour administrer son portfolio — par exemple un éditeur local qui synchronise avec le plugin WordPress via SSH ou API — ce projet desktop ajoute une référence à `Portfolio.Application` et à `Portfolio.Infrastructure` (probablement partagée ou adaptée), et construit une interface utilisateur par-dessus.

### 9.2. Exemple concret : un desktop Avalonia avec MVVM

Un futur projet `Portfolio.Desktop` en Avalonia référencerait `Portfolio.Application`. Ses ViewModels seraient de fines couches de présentation qui appellent les services applicatifs.

```csharp
// Dans Portfolio.Desktop/ViewModels/ArticleListViewModel.cs
public partial class ArticleListViewModel : ObservableObject
{
    private readonly ListArticles _listArticles; // Du projet Application

    [ObservableProperty]
    private ObservableCollection<ArticleDto> articles = new();

    [RelayCommand]
    private async Task LoadAsync()
    {
        var result = await _listArticles.ExecuteAsync();
        Articles = new ObservableCollection<ArticleDto>(result);
    }
}
```

Ce ViewModel ne contient **aucune** logique métier. Il orchestre la présentation (notifier les changements à la vue XAML) et délègue tout le reste au handler `ListArticles` qui est le même que celui consommé par `Portfolio.Api`.

Point d'attention : dans cette configuration, le desktop pourrait vouloir parler directement au WordPress (via l'implémentation existante de `IWordPressClient`) plutôt que de passer par l'API C# déployée. C'est une question à trancher au moment où le besoin apparaîtra. Les deux configurations sont possibles parce que `Portfolio.Infrastructure` est référencé séparément.

### 9.3. Autres évolutions imaginables

`Portfolio.Mobile` en MAUI, selon les mêmes principes, pour un consultation nomade du portfolio.

`Portfolio.Cli` qui offrirait des commandes d'administration — par exemple « liste tous les articles sans tag » ou « exporte tous les projets en fichiers Markdown » — en pilotant `Portfolio.Application` depuis la ligne de commande.

`Portfolio.Functions` si Morgan voulait expérimenter Azure Functions ou AWS Lambda pour exposer certains cas d'usage sans serveur, en réutilisant encore `Portfolio.Application`.

Aucune de ces évolutions n'est prévue dans la feuille de route actuelle, mais chacune devient triviale à ajouter parce que le cœur métier existe déjà, testé et indépendant.

---

## 10. Dépendances NuGet

Les packages nécessaires pour l'API restent peu nombreux.

`Microsoft.AspNetCore.App` est la meta-référence standard pour une API ASP.NET Core, incluse automatiquement dans `Portfolio.Api.csproj` via `Sdk="Microsoft.NET.Sdk.Web"`.

`Markdig` est la seule dépendance externe majeure de `Portfolio.Infrastructure`. Version actuellement stable de la 0.x série, à vérifier au moment de l'implémentation.

`Microsoft.Extensions.Caching.Memory` est incluse par défaut dans .NET 8 pour `IMemoryCache`.

En dépendances de test : `xunit` pour les tests, `Microsoft.NET.Test.Sdk` comme runner, `FluentAssertions` pour l'expressivité des assertions, et `NSubstitute` pour les doubles de test (préférable à Moq, plus simple et plus moderne).

Le projet `Portfolio.Application` ne devrait avoir **aucune** dépendance NuGet en dehors des types .NET standards. Si un package s'avérait nécessaire, il faudrait se demander si l'abstraction est correcte — en général, un package dans le cœur est le symptôme d'une responsabilité qui devrait être dans l'infrastructure.

---

## 11. Tests

La structure en trois projets rend la stratégie de tests directe.

**`Portfolio.Application.Tests`** couvre tous les handlers de cas d'usage et les modèles. Ces tests n'ont besoin d'aucun environnement externe. Ils instancient les classes directement, injectent des doubles de test pour les interfaces d'abstraction, et vérifient le comportement. Un test typique : « étant donné un cache qui retourne null et un client WordPress qui retourne un article, le handler appelle le renderer et retourne un DTO avec le bon contenu HTML ». Exécution en millisecondes.

**`Portfolio.Infrastructure.Tests`** teste chaque implémentation d'interface contre sa dépendance externe. Pour `WordPressClient`, on peut utiliser `WireMock.Net` pour simuler un serveur WordPress et vérifier que les requêtes HTTP sont bien formées et les réponses bien désérialisées. Pour `MemoryArticleCache`, on teste directement contre `IMemoryCache`. Pour `MarkdigRenderer`, on teste que certains Markdowns produisent le HTML attendu.

**`Portfolio.Api.Tests`** teste l'assemblage complet via `WebApplicationFactory<T>`, qui démarre l'API en mémoire sans serveur HTTP réel et permet d'envoyer des requêtes en client virtuel. Ces tests valident le wiring complet : les endpoints sont bien enregistrés, les DI resolvent correctement, les DTOs sont sérialisés comme prévu. On peut y injecter des faux clients WordPress pour isoler l'API de sa dépendance externe.

Les trois projets de tests utilisent tous xUnit comme runner. La commande `dotnet test` les exécute tous. En phase de développement, chaque projet peut être testé indépendamment pour un cycle de feedback rapide.

---

## 12. Navigation

| Document | Contenu |
|---|---|
| [`00-overview.md`](00-overview.md) | Vue d'ensemble du projet |
| [`01-plugin-php.md`](01-plugin-php.md) | Le plugin WordPress dont l'API REST est consommée par cette API C# |
| [`03-frontend-react.md`](03-frontend-react.md) | Le front qui consomme cette API |
| [`99-decisions.md`](99-decisions.md) | Journal des décisions architecturales |
| [`../../CLAUDE.md`](../../CLAUDE.md) | Règles de collaboration avec Claude Code |

---

*Document évolutif. Chaque modification structurante de l'API doit être reflétée ici et consignée dans le journal des décisions.*
