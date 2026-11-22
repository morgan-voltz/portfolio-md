# Architecture de l'API C# `Portfolio.Api`

**Fichier** : `docs/architecture/02-api-csharp.md`
**Rôle** : référence technique complète de l'API .NET
**Prérequis** : avoir lu [`00-overview.md`](00-overview.md)
**Lectorat** : contributeur .NET, Morgan en phase d'implémentation, relecteur technique
**Temps de lecture** : environ vingt-cinq minutes
**Dernière mise à jour** : 22 novembre 2026 (lot 4 d'ADR-026 : le rendu Markdown, section 6 réécrite, sections 3, 10 et 11 complétées ; lot 3 : le client du plugin, sections 3, 3.3, 10 et 11 ; lot 2 : le cœur de l'API, sections 3, 4, 9.2, 10 et 11 réécrites d'après le code ; ADR-026 : un catalogue en mémoire remplace le cache par clé, bandeau de renvoi en tête, sections à réécrire lot par lot ; ADR-025 : .NET 11 et C# 15 à la place de .NET 10 ; le 8 novembre, ADR-023 : .NET 10, en-têtes transmis, HTTPS par Traefik, santé et observation, configuration par l'environnement ; libellés dans la langue demandée et clé `official` des compétences, ADR-020 lot 4 ; la langue dans les routes du plugin et dans les clés de cache, ADR-021 ; contrat réel des routes du plugin le 29 octobre, phase 5 ; version d'origine du 22 avril 2026)

> **À lire d'abord : ADR-026 (22 novembre 2026) révise ce document.** L'API ne tient plus un cache par clé rempli à la demande : elle garde en mémoire un catalogue complet du contenu publié, qu'un service de fond recharge quand le `/sitemap` change, et une requête ne déclenche jamais d'appel à WordPress. Depuis les lots 2 à 5a, les sections 3 à 6, 9.2, 10 et 11 décrivent le code, comme les paragraphes de la section 8 sur la santé et la configuration. La section 7 et le reste de la section 8 décrivent encore l'ancienne conception ; ils seront réécrits avec les lots qui les concernent : les routes au lot 5b, l'exploitation au lot 7. Là où ce document et ADR-026 se contredisent, ADR-026 fait foi ([`99-decisions.md`](99-decisions.md)).

---

## À quoi sert ce document

Ce document détaille l'architecture interne de l'API C# qui sert de gateway entre le plugin WordPress et le front React. Il couvre l'organisation en trois projets, le pipeline typique d'une requête, la stratégie de cache, le parsing Markdown côté C#, les endpoints publics exposés, et la façon dont cette architecture se prépare à accueillir d'autres interfaces (desktop, mobile, CLI) sans duplication de logique.

Comme pour `01-plugin-php.md`, les exemples de code présents ici sont des signatures ou des fragments illustratifs destinés à clarifier l'architecture. Ils ne constituent pas des implémentations prêtes à copier-coller. Le code réel vit dans `api/`.

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

Le troisième rôle est personnel et il teinte les choix techniques qui suivent. L'API est l'endroit où Morgan apprend .NET 11 moderne : Minimal APIs, injection de dépendances native, HttpClient typé, tests en xUnit, patterns de Clean Architecture. Certaines décisions qu'on prend ici — utiliser Minimal APIs plutôt que des Controllers classiques, par exemple — sont motivées par cette dimension d'apprentissage plutôt que par une supériorité technique absolue.

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

La solution `api/Portfolio.slnx` contient trois projets qui forment un graphe de dépendances strict, un projet de tests pour chacun, et une bibliothèque que les tests partagent. L'arbre montre le code des lots 1 à 5a ; les lignes entre parenthèses annoncent ce qu'apportent les lots suivants d'ADR-026.

```
api/
├── Portfolio.slnx
├── global.json, Directory.Build.props, Directory.Packages.props
│
├── Portfolio.Api/                     # Entrée HTTP, ASP.NET Core Minimal APIs
│   ├── Program.cs                     # Composition root : services, routes, démarrage
│   ├── PortfolioOptions.cs            # Les réglages PORTFOLIO__… (patron Options, §8)
│   ├── CatalogRefreshWorker.cs        # Le service de fond qui lance le rafraîchissement (§5)
│   └── CatalogReadinessCheck.cs       # /health/ready : prêt dès le premier catalogue (§8)
│                                      # (lot 5b : routes des collections ;
│                                      #  lot 7 : validation des options, CORS, limitation de débit, métriques)
│
├── Portfolio.Application/             # Le cœur : aucun paquet, aucun autre projet
│   ├── Documents/                     # Document, hiérarchie fermée : Article, Project, Page,
│   │                                  #   et leurs valeurs : Tag, Skill, Status, ProjectStatus
│   │                                  #   (ProjectStatusSlugs : en-cours <-> EnCours…)
│   ├── Catalog.cs                     # L'instantané immuable du contenu publié
│   ├── CollectionSnapshot.cs          # Une collection de l'instantané : listes et index des slugs
│   ├── CatalogStore.cs                # L'édition courante du catalogue, remplacée d'un seul geste
│   ├── CatalogEdition.cs              # Un catalogue, le sitemap dont il vient, l'heure de sa construction
│   ├── CatalogQuery.cs, PagedList.cs  # Ce qu'un lecteur demande, ce qu'il reçoit
│   ├── LanguageLists.cs               # Les trois listes d'une collection, entrée du catalogue
│   ├── CatalogBuildOutcome.cs         # Un catalogue, ou des rangs qui ne s'alignent pas
│   ├── Language.cs, LanguageCodes.cs  # Les langues du site, et leurs codes fr, de, en
│   ├── CollectionKind.cs, Slugs.cs
│   ├── Ports/                         # Ce que le cœur attend du monde extérieur
│   │   ├── IWordPressClient.cs        #   le plugin (implémenté au lot 3)
│   │   ├── IMarkdownRenderer.cs       #   le rendu HTML (implémenté au lot 4, §6)
│   │   ├── Fetch.cs                   #   Fetch<T> : la réponse, ou SourceUnavailable
│   │   └── Sitemap.cs
│   └── UseCases/                      # Un cas d'usage par opération, paramétré par la collection
│       ├── GetDocumentBySlug.cs       #   rend DocumentLookup : Document | NotFound | CatalogNotReady
│       ├── ListDocuments.cs           #   rend DocumentListing : PagedList<Document> | CatalogNotReady
│       ├── RefreshCatalog.cs          #   le rafraîchissement (§5)
│       └── RefreshOutcome.cs          #   ce qu'il rend : Unchanged | Reloaded | SourceUnavailable
│                                      # (lot 6 : ListTagUsage, ListSkillUsage)
│
├── Portfolio.Infrastructure/          # Les implémentations des ports
│   ├── WordPress/                     # Le client du plugin (lot 3)
│   │   ├── WordPressClient.cs         #   implémente IWordPressClient avec un HttpClient
│   │   ├── PluginJson.cs              #   le JSON du plugin, tel qu'il arrive
│   │   ├── PluginMapping.cs           #   ce JSON traduit en documents du cœur, et vérifié
│   │   └── FirewallChallenge.cs       #   la page du pare-feu de l'hébergeur, reconnue
│   └── Markdown/                      # Le rendu (lot 4)
│       └── MarkdigRenderer.cs         #   implémente IMarkdownRenderer avec Markdig (§6)
│
├── Portfolio.Application.Tests/       # Le cœur, et la règle des dépendances
├── Portfolio.Infrastructure.Tests/    # Le client et le rendu, sur des réponses enregistrées du plugin (Recorded/)
├── Portfolio.Api.Tests/               # L'API démarrée en mémoire (WebApplicationFactory)
├── Portfolio.TestSupport/             # Ce que les tests partagent : doubles écrits à la main, documents de test
└── deploy/                            # Image et compose de production (ADR-023)
```

Les dossiers `Models/` et `Abstractions/` de la version d'avril sont devenus `Documents/` et `Ports/`, le vocabulaire d'ADR-026 ; les cas d'usage jumeaux par collection (`GetArticleBySlug`, `GetProjectBySlug`…) sont devenus un cas d'usage par opération.

### 3.1. `Portfolio.Api` : la surface HTTP

Ce projet contient uniquement ce qui concerne la communication HTTP, et la composition. Aujourd'hui il sert `GET /health/live` (lot 1) et `GET /health/ready` (lot 5a, §8), et fait tourner le service de fond qui rafraîchit le catalogue (§5) ; les routes des trois collections arrivent au lot 5b, déclarées en Minimal APIs. Une route de détail tiendra en quelques lignes, de cette forme :

```csharp
// Forme visée d'une route de détail (lot 5b), à titre d'illustration
group.MapGet("/{slug}", (string slug, GetDocumentBySlug getDocument) =>
    getDocument.Execute(CollectionKind.Project, slug, Language.Fr) switch
    {
        Document document => Results.Ok(DocumentDto.From(document)),
        NotFound => Results.Problem(statusCode: StatusCodes.Status404NotFound),
        CatalogNotReady => Results.Problem(statusCode: StatusCodes.Status503ServiceUnavailable),
    });
```

La route ne contient aucune logique métier : elle traduit un résultat du cœur en réponse HTTP. Ce résultat est une union de C# 15 (§3.2), et le `switch` doit en traiter les trois cas, sinon le code ne compile pas : le compilateur signale le cas oublié par un avertissement (CS8509), que ce projet traite comme une erreur. Une route ne peut donc pas oublier le 503 d'une API qui vient de démarrer. La lecture du paramètre `language`, et le 400 d'une valeur invalide, viendront avec la route.

Les **DTOs** (`Dtos/`) sont des objets qui représentent la forme exacte du JSON échangé avec les clients HTTP. Ils sont distincts des modèles de `Portfolio.Application`, pour une raison importante : les modèles du cœur évoluent selon la logique métier, les DTOs évoluent selon les contrats publics de l'API. Mélanger les deux couple ces deux rythmes d'évolution et crée des cassures incontrôlées côté front.

Le **`Program.cs`** joue le rôle de *composition root* : c'est l'unique endroit qui instancie les services, configure l'injection de dépendances, enregistre les endpoints, et lance l'application. En Clean Architecture c'est aussi le seul endroit qui connaît à la fois `Application` et `Infrastructure`, puisqu'il doit mapper les interfaces aux implémentations.

### 3.2. `Portfolio.Application` : le cœur métier

Ce projet est le centre de l'architecture et il a une propriété cruciale : **il ne dépend d'aucun paquet, d'aucun autre projet, d'aucune fonctionnalité ASP.NET**. Les seules dépendances admises sont les types de base de .NET (`System.*`). Le compilateur refuserait d'ailleurs un `using Microsoft.AspNetCore.Http;` dans ce projet, qui ne référence pas ASP.NET Core. Le piège est ailleurs : .NET 11 range neuf bibliothèques `Microsoft.Extensions.*` dans le framework de base (ADR-025), si bien qu'un `ILogger` y compile sans la moindre référence de paquet. Deux tests de `DependencyRuleTests` ferment cette porte : le premier lit les assemblages que le code du cœur utilise et n'admet que `System.*`, le second lit ce que la restauration a résolu pour lui et n'admet ni paquet ni projet (ADR-026, notes du lot 1).

Le cœur contient quatre sortes d'éléments.

Les **documents** (`Documents/`). `Document` est un `closed record class` de C# 15 : une hiérarchie fermée, dont les seules sous-classes sont `Article`, `Project` et `Page`, parce qu'aucun autre assemblage ne peut en dériver. Un `switch` qui traite ces trois cas n'a donc pas besoin de bras par défaut, et une quatrième sorte de document ferait échouer la compilation de chaque `switch` qui l'oublie (vérifié : avertissement CS8509, traité en erreur). Un document est tel qu'une liste du plugin le sert : `RequestedLanguage` est la langue de cette liste, `Lang` celle du document, et `IsFallback` se calcule de l'écart entre les deux, pour ne jamais les contredire. Les libellés (`Tag.Name`, `Skill.Label`, `Status.Label`) arrivent déjà dans la langue demandée. Ce sont des `record` pour leur immuabilité ; leur égalité « par valeur » s'arrête aux listes et aux dictionnaires, que C# compare par référence, si bien que les tests vérifient qu'un document rendu est le même objet que celui donné au catalogue (`Assert.Same`), jamais que deux documents sont égaux. Un enregistrement n'est d'ailleurs immuable qu'en surface : ses listes ne le sont que si personne d'autre ne les tient. Un document recopie donc les listes et le dictionnaire qu'on lui donne, dans l'accesseur `init` de chaque propriété (`init => field = [.. value];`, où `field`, apparu avec C# 14, désigne le champ caché de la propriété) : ce que l'appelant fait ensuite de ses propres collections ne change plus ni le document, ni le catalogue qui le tient.

Le **catalogue**. `Catalog` est l'instantané immuable du contenu publié : `Catalog.Build` le construit à partir des neuf listes du plugin (trois collections, trois langues), et `CatalogStore` tient l'édition courante, une `CatalogEdition` : le catalogue, le sitemap dont il a été construit et l'heure de sa construction, que le rafraîchissement compare (§5). La section quatre suit une lecture de bout en bout.

Les **ports** (`Ports/`) sont des interfaces qui définissent ce dont le cœur a besoin du monde extérieur, sans savoir comment ce besoin sera satisfait. `IWordPressClient` demande le `/sitemap` du plugin et la liste complète d'une collection dans une langue, mais ne dit rien de HTTP, de WordPress ni de JSON. Sa réponse est un `Fetch<T>`, une *union* de C# 15 : une valeur qui est exactement l'un des types qu'elle énumère, ici ce qu'on a demandé ou `SourceUnavailable` avec sa raison (erreur de réseau, délai dépassé, statut d'échec, page du pare-feu de l'hébergeur). Le cœur apprend ainsi une panne sans attraper d'exception. `IMarkdownRenderer` rend un Markdown en HTML ; il reçoit la langue du document, dont il a besoin pour réécrire les liens entre documents (ADR-026, point 8). Leurs implémentations concrètes vivent dans `Portfolio.Infrastructure` : le client du plugin depuis le lot 3 (§3.3), le rendu depuis le lot 4 (§6).

Cette inversion est le principe central de la Clean Architecture. Le cœur dicte ce qu'il veut via des interfaces. La périphérie s'adapte au cœur en implémentant ces interfaces. La dépendance logique va du cœur vers la périphérie (le cœur dit « j'ai besoin de ça »), mais la dépendance de compilation va de la périphérie vers le cœur (la périphérie référence le projet du cœur pour implémenter ses interfaces).

Les **cas d'usage** (`UseCases/`) : un par opération, paramétré par la collection, plutôt qu'un jumeau par collection (ADR-026, point 6). `GetDocumentBySlug` et `ListDocuments` lisent le catalogue courant et rien d'autre, et rendent une union : `DocumentLookup` (le document, `NotFound` ou `CatalogNotReady`) et `DocumentListing` (une page, ou `CatalogNotReady`). Leur méthode `Execute` est synchrone : lire un instantané en mémoire n'attend rien. `RefreshCatalog` construit les catalogues à partir du plugin (§5) ; il est asynchrone, lui, puisqu'il attend le plugin. `ListTagUsage` et `ListSkillUsage`, les agrégats, arrivent au lot 6.

### 3.3. `Portfolio.Infrastructure` : les implémentations concrètes

Ce projet contient les implémentations réelles des ports déclarés dans `Application`, par sous-dossier : `WordPress/` pour le client du plugin (lot 3), `Markdown/` pour le rendu côté C# (lot 4). Il n'y a plus de dossier `Caching/` : l'état vit dans le `CatalogStore` du cœur (ADR-026, point 6).

**Le client du plugin** (`WordPressClient`) implémente `IWordPressClient`. C'est un client typé : au lot 5, la couche HTTP lui donnera un `HttpClient` dont l'adresse de base est la racine des routes du plugin, `https://morganvoltz.dev/wp-json/portfolio/v1/`, sans préfixe de langue et avec sa barre oblique finale ; sans elle, une requête relative `projects` remplacerait `v1` au lieu de le suivre, et le constructeur refuse donc une autre adresse. Il lit une liste en entier, cent documents par page, et vérifie que le total annoncé par `X-WP-Total` tient jusqu'à la dernière page et que les documents lus y correspondent ; sinon le contenu a changé pendant la lecture. Il ne demande que `language`, `page` et `per_page` (§7), et se présente par son `User-Agent`, `portfolio-md-api/1.0`, comme le fait le script de synchronisation.

Le JSON est lu en snake_case vers des enregistrements internes à ce projet (`PluginJson.cs`), que le cœur ne voit jamais. Chaque clé que le plugin écrit toujours y est obligatoire, même celles qui peuvent valoir `null` : une clé renommée par une version future du plugin fait échouer la lecture, au lieu de devenir `null` partout sans bruit. `PluginMapping` traduit ensuite ces enregistrements en documents du cœur, en vérifiant ce dont le catalogue dépend : un document de la bonne collection, servi dans la langue demandée (un proxy qui perdrait le paramètre `language` ferait servir du français partout), un `is_fallback` cohérent avec les deux langues, des langues et des états de projet connus. Les codes de langue et les slugs des états viennent du cœur (`LanguageCodes`, `ProjectStatusSlugs`), que les routes du lot 5 réutiliseront.

Le client ne lève jamais d'exception pour une défaillance du plugin. Pas de réponse, pas de réponse en vingt secondes, un statut d'échec, un corps qui n'est pas du JSON, la page du pare-feu de l'hébergeur (reconnue comme le fait le script de synchronisation, et nommée en une ligne), un JSON qui ne tient pas le contrat, une liste qui change pendant sa lecture : chaque cas donne un `SourceUnavailable` avec un motif pour le journal, et le rafraîchissement du lot 5 garde le catalogue qu'il a. Une liste est lue en entier ou pas du tout, puisque `Catalog.Build` exige trois listes alignées. Seule l'annulation demandée par l'appelant, quand l'API s'arrête, est levée. Pas de nouvel essai ici : la boucle du rafraîchissement en tient lieu (ADR-026, point 7).

Chaque implémentation dépend de bibliothèques concrètes, confinées à ce projet : elles ne remontent jamais jusqu'à `Application`. Le client n'en ajoute aucune, puisque `HttpClient` et `System.Text.Json` font partie de la bibliothèque de base ; `MarkdigRenderer` utilise Markdig (§6), le seul paquet du projet.

### 3.4. La règle des dépendances

Le graphe de dépendances entre les trois projets suit une règle absolue : **`Application` ne dépend de rien, `Api` et `Infrastructure` dépendent de `Application`, aucune dépendance circulaire n'est permise**.

```
  Portfolio.Api  ─────►  Portfolio.Application  ◄─────  Portfolio.Infrastructure
```

Cette règle est matérialisée dans les fichiers `.csproj` via les `ProjectReference`. `Portfolio.Api.csproj` référence à la fois `Application` et `Infrastructure` (il compose les deux au démarrage). `Portfolio.Infrastructure.csproj` référence uniquement `Application` (pour voir les interfaces à implémenter). `Portfolio.Application.csproj` ne référence aucun autre projet, ce que les tests de la règle des dépendances vérifient à chaque construction (§3.2).

L'architecte en toi doit relire cette règle et vérifier mentalement sa cohérence : si `Application` dépendait de `Infrastructure`, le cœur dépendrait des détails, ce qui casserait toute l'indépendance qu'on cherche à obtenir. Si `Application` dépendait d'`Api`, le métier serait couplé à HTTP, ce qui empêcherait la réutilisation multi-frontend évoquée en section neuf.

---

## 4. Walkthrough : lire un document dans le catalogue

Ce parcours traverse le cœur et sert de fil rouge pour comprendre comment les pièces collaborent. On suit `GET /api/projects/momocad-de?language=fr`, une requête que le plugin sert déjà en production (il rend la fiche française `momocad`). Les étapes deux à quatre sont le code du lot 2 ; la première appartient aux lots 3 à 5a (§5), la dernière au lot 5b.

**Étape un : le catalogue est construit, en dehors de toute requête (lots 3 à 5a, §5).** Un service de fond lit le `/sitemap` du plugin, puis, pour chaque collection et chaque langue, la liste complète qu'il sert (`IWordPressClient.ListAsync`). Le plugin ancre ses trois listes sur les documents français (ADR-021) : une entrée par document français, au même rang dans les trois listes, chaque liste servant à ce rang le membre du groupe dans sa langue, ou celui vers lequel elle se replie quand la traduction manque. Les trois listes d'une collection entrent dans un `LanguageLists`, et `Catalog.Build` en fait un instantané. Il vérifie d'abord qu'elles s'alignent (même longueur, même clé de traduction à chaque rang) ; sinon le contenu a bougé pendant la lecture, et `Build` rend `RanksMisaligned` au lieu d'un catalogue, le service gardant l'ancien jusqu'au tic suivant. Le nouveau catalogue remplace l'ancien dans le `CatalogStore` par une seule écriture (`Volatile.Write`) : une requête en cours garde celui qu'elle a lu, la suivante voit le nouveau, et aucune ne voit la moitié de chacun, puisqu'un catalogue ne change plus une fois construit.

**Étape deux : la route appelle le cas d'usage.** La route reçoit le slug et la langue, et appelle `GetDocumentBySlug.Execute(CollectionKind.Project, "momocad-de", Language.Fr)`. Le cas d'usage lit le catalogue courant une seule fois. S'il n'y en a pas encore, parce que l'API vient de démarrer et n'a pas encore pu lire le plugin, il rend `CatalogNotReady`.

```csharp
public DocumentLookup Execute(CollectionKind collection, string slug, Language language)
{
    if (store.Current is not { } catalog)
    {
        return new CatalogNotReady();
    }

    return catalog.Find(collection, slug, language) is { } document ? document : new NotFound();
}
```

**Étape trois : l'index trouve le document.** `Catalog.Find` s'adresse à la collection demandée, qui consulte un index par langue construit une fois pour toutes avec l'instantané. Cet index mène de chaque slug connu d'un groupe au document servi dans cette langue. Les slugs connus d'un rang sont la réunion, sur les trois langues, du slug de chaque document servi à ce rang et des slugs que listent ses `translations` : pour MoMoCAD, `momocad`, `momocad-de` et `momocad-en`. Dans l'index français, les trois mènent à la fiche française ; dans l'index allemand, à la fiche allemande. `momocad-de` en français rend donc `momocad`, comme le plugin. Les pages, qui ont un slug par langue (`cv`, `lebenslauf`, `resume`), suivent la même règle sans traitement particulier.

Un seul cas demande un arbitrage : deux documents d'une même langue qui partagent une clé de traduction, une anomalie que `wp portfolio translations reconcile` signale. Le plugin sert alors le plus ancien, celui qui a le plus petit identifiant WordPress ; l'index fait de même pour les slugs que les deux revendiquent (`CollectionSnapshot.Claim`), quel que soit l'ordre des listes. Quand les deux sont français, chacun a son rang et garde son propre slug, comme dans le plugin. Dans une autre langue, le plus jeune n'est servi par aucune liste : le catalogue ne connaît pas son slug, que la route de détail du plugin sert encore, et le rafraîchissement du lot 5 le signalera dans son journal, comme toute ligne du `/sitemap` absente de l'index. C'est le seul usage de l'identifiant WordPress, qui ne sort jamais de l'API (ADR-026, point 5).

**Étape quatre : le cas d'usage rend une union.** `Find` rend le document ou `null`, et le cas d'usage en fait un `DocumentLookup` : le document, ou `NotFound`. Aucun appel à WordPress, aucune attente : une lecture dans un `FrozenDictionary`, un dictionnaire figé, optimisé pour une table qui ne change plus après sa construction.

**Étape cinq : la route traduit en HTTP (lot 5b).** Un `switch` sur l'union, qui doit en traiter les trois cas (§3.1) : le document devient un DTO sérialisé en JSON, `NotFound` un 404, `CatalogNotReady` un 503, tous deux en `ProblemDetails` (ADR-026, point 5).

Une liste suit le même chemin, avec `ListDocuments` et un `CatalogQuery` : la langue, la page, la taille de page et les filtres `tag`, `status` et `skill`. Le constructeur de `CatalogQuery` refuse ce qui sort des règles du plugin (une page avant la première, une taille hors de 1 à 100, un tag ou une compétence qui n'a pas la forme d'un slug), refus que la route traduira en 400. Le catalogue retient les rangs dont le document **français** passe les filtres, comme le plugin, puisqu'une traduction garde les tags et les compétences de son original ; il coupe la page, puis prend aux rangs retenus les documents de la langue demandée. Une page au-delà de la dernière rend une liste vide avec les vrais totaux, là où le plugin répond 400 : un écart voulu (ADR-026, point 5).

Un détail conceptuellement important : pendant toute cette chaîne, `Portfolio.Application` ne sait rien de HTTP ni de WordPress. Il lit un instantané en mémoire et parle à des ports. C'est `Portfolio.Infrastructure` qui implémentera ces ports en termes de `HttpClient` et de Markdig, et `Portfolio.Api` qui câblera le tout dans `Program.cs`.

Ce découplage permet de tester le cœur sans serveur HTTP ni WordPress : les tests construisent des listes de documents à la main (`TestData/TestDocuments.cs`), en font un catalogue et l'interrogent. Ils s'exécutent en moins d'une seconde.

---

## 5. Le rafraîchissement du catalogue

L'API ne garde pas un cache rempli à la demande : elle tient en mémoire une réplique complète du contenu publié, et un service de fond la tient à jour (ADR-026, points 1 et 2). Une requête ne déclenche donc jamais d'appel à WordPress, et une panne de WordPress ne coûte que de la fraîcheur. Le cache par clé de la version d'avril (`IMemoryCache` derrière une interface `IArticleCache`, invalidé clé par clé) a été écarté par ADR-026 : le catalogue est plus simple à raisonner, et il ne peut pas servir une liste et un détail qui se contredisent. Cette section décrit le code du lot 5a.

### 5.1. Le rythme : un service de fond

`CatalogRefreshWorker`, dans `Portfolio.Api`, est un `BackgroundService` d'ASP.NET Core : un objet que l'hôte démarre avec l'API et qui tourne en boucle tant qu'elle vit. Il lance un premier tic dès le démarrage, puis un tic toutes les `PORTFOLIO__CACHE__SITEMAPREFRESHSECONDS` secondes (60 par défaut). Tant qu'aucun catalogue n'existe, les essais se suivent après 5, 15, 45, puis toutes les 60 secondes : l'API qui vient de démarrer devient prête dès que le plugin répond, sans le harceler s'il ne répond pas.

Trois précautions, chacune vérifiée par un test (§11) :

- **Chaque tic est attrapé en entier.** Sous .NET 11, une exception qui s'échappe d'un service de fond arrête tout l'hôte (ADR-025) : un bogue inattendu dans un tic écrit une erreur au journal, et le tic suivant vient à son heure. Seule l'annulation demandée par l'arrêt de l'API passe, sans bruit.
- **Le service demande un `RefreshCatalog` neuf à chaque tic**, dans une portée (*scope*) de l'injection de dépendances qui lui est propre, au lieu de le recevoir dans son constructeur. L'hôte construit ses services de fond pendant qu'il démarre : construire alors le client du plugin, avec une adresse absente ou mal formée, empêcherait l'API de démarrer, `/health/live` compris. Demandée dans le tic, la même erreur n'est qu'une ligne d'erreur à chaque tic, qui nomme la variable à renseigner, et `/health/ready` reste à 503. Le client, que fabrique `IHttpClientFactory`, est d'ailleurs fait pour vivre peu.
- **L'attente passe par un `TimeProvider`**, l'horloge abstraite de .NET : en production l'horloge du système, dans les tests une horloge manuelle que le test avance, pour vérifier la cadence sans attendre.

### 5.2. Un tic : comparer, recharger, remplacer

`RefreshCatalog`, dans `Portfolio.Application`, fait le travail d'un tic. Il lit d'abord le `/sitemap` du plugin. Si l'ensemble de ses lignes (collection, slug, langue, clé de traduction, `updated`) est celui dont le catalogue courant a été construit, et que ce catalogue a moins de `PORTFOLIO__CACHE__FULLREFRESHMINUTES` minutes (360 par défaut), il ne fait rien. Les lignes se comparent comme deux ensembles, sans tenir compte de leur ordre, et entières, jamais par leurs seuls instants (ADR-026, contexte) : une ligne qui apparaît, change ou disparaît suffit. Le rechargement périodique rattrape ce que le `/sitemap` ne montre pas : un tag ou une compétence renommés changent des documents sans toucher une seule ligne.

Sinon il recharge tout : pour chaque collection, les listes française, allemande et anglaise, l'une après l'autre, neuf lectures en tout. Le premier échec arrête le tic : aucun visiteur n'attend ces lectures, et le pare-feu de l'hébergeur, qui répond parfois à une rafale par sa page de contrôle, préfère qu'on lui demande une chose à la fois. Les trois listes d'une collection doivent s'aligner (même longueur, même clé de traduction à chaque rang, §4) ; sinon le contenu a bougé pendant la lecture, et le tic s'arrête aussi, avant tout rendu.

Chaque document est ensuite rendu en HTML une fois (§6), avec sa propre langue. Le plugin envoie un document de repli une fois par liste qui le sert, chaque copie portant la langue de sa liste ; le rendu ne dépend que du Markdown et de la langue du document, si bien qu'un rang rend chaque paire une seule fois, et que chaque copie reçoit le même HTML en gardant sa langue. Quand un rendu lève une exception, le rang entier est écarté, dans les trois langues : retirer un seul document désalignerait les listes, que `Catalog.Build` refuse, et chaque rang s'ancre sur son document français. Chaque document du rang est noté comme écarté, avec sa raison : son propre rendu, ou ceux des documents du rang qui ont échoué, tous nommés. Les autres rangs restent.

Le nouveau catalogue remplace alors l'ancien d'un seul geste (`CatalogStore.Replace`, une écriture `Volatile`), avec le sitemap dont il vient et l'heure de sa construction : c'est la `CatalogEdition` que le tic suivant compare. Enfin `RefreshCatalog` relève les autres lignes du sitemap qui ne mènent à aucun document du nouveau catalogue, celle d'un document sans original français par exemple (ADR-026, point 3) : chaque ligne absente du catalogue reçoit ainsi un avertissement, et un seul.

Tout échec de l'amont laisse le catalogue tel qu'il était : erreur de réseau, délai de vingt secondes dépassé, statut d'échec, corps qui n'est pas du JSON, page du pare-feu, listes désalignées. Avant le premier catalogue, il n'y a rien à garder, et `/health/ready` reste à 503.

### 5.3. Ce que dit le journal

Le cœur n'a pas de journal : `Portfolio.Application` n'utilise aucun type `Microsoft.Extensions.*`, ce qu'un test vérifie (§3.4). `RefreshCatalog` rend donc une union, `RefreshOutcome`, qui porte ce que le service de fond doit écrire :

| Cas | Ce qui s'est passé | Ce que le service écrit |
|---|---|---|
| `Unchanged` | même sitemap, catalogue assez jeune | une ligne de débogage |
| `Reloaded` | un nouveau catalogue, avec la liste des documents écartés (chacun avec sa raison) et celle des autres lignes du sitemap sans document | une information, puis un avertissement par document écarté et par ligne sans document |
| `SourceUnavailable` | le plugin n'a pas pu être lu ; le catalogue reste | un avertissement, avec la raison |

Une exception hors de ces trois cas devient une erreur, avec sa pile. Comme toute union, `RefreshOutcome` se lit par une expression `switch`, que le compilateur vérifie exhaustive.

---

## 6. Parsing Markdown côté C#

Le Markdown source arrive depuis WordPress sous forme de texte brut (frontmatter plus corps). L'API a deux options pour le rendre : laisser le front s'en charger avec une librairie JavaScript côté navigateur, ou le rendre côté serveur et envoyer du HTML.

Le choix fait ici est de **rendre côté serveur**. Les raisons : la charge est portée par le serveur plutôt que par chaque navigateur visiteur, le HTML est prêt à la livraison sans *flash of unstyled content*, les lecteurs d'écran et les moteurs d'indexation voient un contenu immédiatement lisible, et les enrichissements (ancres sur les titres, liens internes transformés) se font à un endroit unique côté C#.

La librairie retenue est **Markdig** (1.6.0), standard de fait dans l'écosystème .NET ; seul `Portfolio.Infrastructure` la référence. `MarkdigRenderer` (`Portfolio.Infrastructure/Markdown/`, lot 4) implémente le port `IMarkdownRenderer`, `string Render(string markdown, Language documentLanguage)` : la langue du document sert aux liens entre documents qui n'en nomment pas. La classe porte le nom de la bibliothèque qu'elle utilise, comme `WordPressClient` celui de ce qu'il lit : une autre bibliothèque ferait une autre implémentation du même port.

**Les réglages** (ADR-026, point 8). Six reproduisent le GFM du plugin : le frontmatter écarté, les tableaux, les cases à cocher, le texte barré, les liens automatiques, et le HTML brut neutralisé, si bien que du balisage tapé dans le Markdown s'affiche comme du texte, comme sur le site WordPress. Un septième s'en écarte : chaque titre reçoit un identifiant (`<h2 id="résumé">`, puis `résumé-1` pour un doublon), vers lequel le front pourra pointer. Comparé au rendu réel du plugin, exécuté sur les 54 documents publiés, celui de l'API n'en diffère que par ces identifiants, avant la passe sur les liens. Différences connues, qu'aucun document publié n'utilise aujourd'hui : Markdig ne fait pas d'une adresse e-mail nue un lien (le contenu écrit `[adresse](mailto:adresse)`) ; un bloc de HTML brut, affiché comme du texte des deux côtés, est enveloppé ici dans un paragraphe ; les tableaux s'alignent par `style` plutôt que par `align`, les listes de tâches portent des classes et écrivent leurs cases autrement ; les blocs de code ne sont pas colorés, le front le fera, et leur langue est gardée telle qu'écrite (`language-JS`), là où le plugin la met en minuscules.

**Une passe unique sur les liens et les images**, à branches exclusives, dans cet ordre :

1. une adresse qui commence par `//` désigne un autre site : elle reste telle quelle, comme tout lien externe ;
2. un lien vers un autre document du contenu (`../projects/momocad.md`, `../../de/projects/momocad.md`, préfixe `./` et fragment compris) devient une route du futur front, selon un gabarit, `/{language}/{collection}/{slug}` par défaut, soit `/fr/projects/momocad` : `{language}` est la langue que le lien nomme, sinon celle du document, et `{collection}` le dossier qu'il nomme. Comme dans le plugin, seuls les liens sont réécrits, jamais la source d'une image, et une forme mal écrite (une langue que le site n'a pas, des majuscules, une chaîne de requête, un slug hors motif) reste telle quelle : l'erreur reste visible au lieu de devenir une adresse plausible mais fausse ;
3. une adresse absolue du site (`/fr/projets/`) désigne une page WordPress, que le front ne sert pas : elle reçoit l'origine du site, `https://morganvoltz.dev` ;
4. un schéma hors de la liste blanche (`http`, `https`, `mailto`, `tel`) fait perdre son adresse au lien, qui devient un élément `<a>` sans `href` autour de son texte, comme le plugin le rend : un navigateur ne le traite pas comme un lien, là où `href=""` en ferait un lien vers la page courante. Une image garde son texte de remplacement et une source vide ; un lien automatique (`<javascript:…>`), dont le texte est l'adresse, devient de même un `<a>` sans `href` autour de ce texte ;
5. le reste (un schéma admis, une ancre, un fichier relatif) est laissé tel quel.

Le schéma est lu comme un navigateur le lit (norme WHATWG URL), sur une copie de l'adresse : caractères de contrôle et espaces retirés aux deux bouts, tabulations et sauts de ligne retirés partout. Markdig a déjà décodé les entités, si bien que `java&#x0A;script:` arrive avec un vrai saut de ligne, qu'un navigateur retirerait avant de lire `javascript:` ; la copie normalisée le reconnaît donc. Ce qui précède le premier deux-points sans avoir la forme d'un schéma (`#note:a`, `1http:`) n'en est pas un. La liste blanche est plus stricte que le plugin, qui ne refuse que `javascript`, `vbscript`, `file` et `data` (sauf les images en `data:image/…`) : un lien `ftp:` ou une image en `data:` perdent leur adresse dans l'API.

**Configuration et usage concurrent.** Le constructeur reçoit une adresse quelconque du site WordPress, dont il ne garde que l'origine (celle des routes du plugin convient), et le gabarit des liens entre documents, qui doit contenir `{slug}` ; les lots 5 et 7 les liront de la configuration. Le gabarit par défaut reprend la lettre d'ADR-026 ; la forme réelle des routes du front sera fixée en phase 7 (§4 de `03-frontend-react.md` parle encore de `/projets`), et il suffira alors de changer le gabarit. Une seule instance sert tous les documents, depuis plusieurs fils à la fois : le pipeline Markdig est construit une fois et seulement lu, et chaque appel construit son propre arbre, son propre `HtmlRenderer` et son propre `StringWriter`, qui ne doivent jamais être partagés (un `HtmlRenderer` partagé mélange les sorties sans lever d'exception).

Si un jour on veut offrir un format de sortie alternatif (par exemple retourner l'AST Markdig pour un rendu React côté front), on ajoutera une surcharge à l'interface et une implémentation. La structure de Clean Architecture rend cet ajout local, sans impact sur le reste.

---

## 7. Endpoints publics exposés

L'API expose plusieurs endpoints sous le préfixe `/api/`. Leur forme mime celle de l'API WordPress sous-jacente mais peut s'en écarter quand c'est utile pour le front.

Tous les endpoints de lecture prennent `language=fr|de|en` (ADR-021), relayé tel quel au plugin ; absent, l'API demande `fr`. La règle vaut des deux côtés : le `WordPressClient` envoie **`language`**, jamais `lang` (le paramètre de Polylang) ni `Accept-Language`, n'envoie jamais un `language=` vide (le plugin répond 400 : une langue indéfinie côté C# se traduit par l'absence du paramètre), ne retire jamais un paramètre qu'il ne connaît pas d'une URL qu'il relaie (un normaliseur qui le ferait fusionnerait les langues), et appelle l'URL de base du site **sans préfixe de langue** (`/wp-json/portfolio/v1/…`, jamais `/de/wp-json/…`, qui est une page 404 du site). Ce que le plugin répond est décrit dans `01-plugin-php.md` §7.1 : une liste ancrée sur les documents français, servie dans la langue demandée avec les traductions manquantes en repli signalé, et un détail qui ne répond jamais 404 pour un slug existant. Les DTO relaient les cinq clés du plugin jusqu'au front, en camelCase : `lang`, `translationKey`, `translations`, `isFallback`, `requestedLanguage`. Depuis le lot 4 d'ADR-020 (8 novembre 2026), `status.label` et `skills[].label` arrivent dans la langue demandée (`language`), même sur un document servi en repli : l'API les relaie tels quels, sans table de traduction côté C#, et le cache par `(collection, language, …)` suffit à les séparer. Chaque compétence porte `official` : vrai pour l'intitulé français d'un référentiel officiel, faux pour les intitulés de 4.0.2 et pour toute traduction ; le DTO le relaie en `official`, pour que le front le dise. Les noms des tags restent ceux des termes, en français.

`GET /api/articles` retourne la liste paginée des articles avec un filtre optionnel par tag (le plugin l'offre tel quel, `?tag=<slug>` ; il n'y a pas de catégorie dans ce projet, ADR-015). Une recherche textuelle, si elle vient, sera calculée côté API : le plugin n'en expose pas. La pagination utilise les paramètres `page` et `per_page`, ceux du plugin (10 par défaut, 100 au plus par page, totaux dans les en-têtes `X-WP-Total` et `X-WP-TotalPages`, pages voisines dans `Link`).

`GET /api/articles/{slug}` retourne un article complet avec HTML rendu. Un query param optionnel `?format=markdown` permet de demander le Markdown source plutôt que le HTML — utile pour un éventuel outil qui voudrait retraiter le contenu.

`GET /api/projects` et `GET /api/projects/{slug}` font la même chose pour les projets ; le plugin offre en plus les filtres `?status=<slug>` et `?skill=<slug>`. Les pages À propos et CV sont disponibles côté plugin aussi (`/pages`, `/pages/{slug}`) ; leur exposition par l'API (`GET /api/pages/{slug}`) sera décidée en phase 6, avec le front qui en aura besoin.

`GET /api/tags` retourne la liste de tous les tags avec leur compte d'articles associés — pratique pour alimenter un menu de filtrage côté front.

`GET /api/stack` retourne la liste des technologies utilisées sur des projets, avec leur compte — équivalent pour les projets.

`GET /api/skills` retourne la liste des compétences académiques rattachées à des projets, avec leur compte et leur intitulé complet. Cet endpoint alimente une éventuelle page « Compétences » sur le front React qui affiche, pour chaque compétence du référentiel UHA 4.0, la liste des projets qui la démontrent — une vue inverse précieuse pour un recruteur qui voudrait évaluer un domaine particulier de compétences. Une requête complémentaire `GET /api/projects?skill=<slug>` permet de filtrer les projets par compétence démontrée ; le plugin offre déjà ce filtre sous le même nom. Les trois agrégats (`/api/tags`, `/api/stack`, `/api/skills`) n'ont en revanche pas de source côté plugin (`01-plugin-php.md` §7.1, ADR-018) : l'API les calculera à partir des listes complètes (quelques dizaines de documents, une page de cent suffit), ou le plugin gagnera un endpoint si le besoin se confirme — à trancher en phase 6.

Les DTOs `ProjectDto` exposés par ces endpoints portent deux champs spécifiques liés à la contrainte académique : `skills` (liste d'objets avec `slug`, `code`, `label` et `official`, le libellé dans la langue demandée) et `isFilRouge` (booléen qui distingue le projet fil rouge 4.0.2 pour permettre au front d'appliquer un affichage éditorial distinctif).

La forme des réponses JSON est volontairement stable et versionnée via le préfixe `/api/` (qu'on pourrait faire évoluer vers `/api/v1/`, `/api/v2/` si un jour un breaking change devient nécessaire). Les DTOs sont sérialisés avec la configuration `JsonSerializerOptions` standard de .NET, en camelCase pour respecter les conventions JavaScript du front.

---

## 8. Sécurité

L'API est publique en lecture et n'a pas besoin d'authentification pour l'usage normal. Quelques précautions restent de rigueur.

**Rate limiting** sur les endpoints publics pour éviter qu'un script abuse. ASP.NET Core fournit un middleware de rate limiting natif (`AddRateLimiter`) qui permet de limiter par IP. Une limite raisonnable (par exemple cent requêtes par minute par IP) ne gêne aucun usage légitime et bloque les abus. En production l'API est derrière Traefik (ADR-023) : elle lit les en-têtes transmis par le proxy (`ASPNETCORE_FORWARDEDHEADERS_ENABLED=true` dans le compose), sans quoi toutes les requêtes porteraient l'adresse de Traefik et tomberaient dans le même seau.

**CORS** configuré strictement pour n'autoriser que le ou les domaines du front React en production. En développement local, on autorise `localhost` sur plusieurs ports. La configuration est centralisée dans `Program.cs`.

**Pas de secrets dans les logs.** Le middleware de logging est configuré pour ne pas capturer les en-têtes d'authentification éventuels, ni les bodies des requêtes contenant des données sensibles. En gateway simple, le risque est faible, mais la discipline se pose dès le début.

**HTTPS obligatoire en production**, assuré par Traefik, qui termine le TLS et redirige HTTP vers HTTPS (ADR-023) : derrière lui l'API n'écoute qu'en HTTP sur `8080`, et `UseHttpsRedirection` ne sert qu'en développement local, où le middleware s'adapte.

**Pas d'exposition du token WordPress.** Le token qui permet à l'API d'appeler `/sync` du plugin (si jamais l'API devait pousser quelque chose vers WordPress, ce qui n'est pas le cas actuel) serait stocké dans les secrets ASP.NET Core (`user-secrets` en dev, variables d'environnement ou coffre-fort en production). Jamais dans `appsettings.json` committé.

**Santé et observation (ADR-023).** `GET /health/live` répond 200 dès que le processus tourne ; `GET /health/ready` répond 503 jusqu'au premier catalogue complet, les neuf listes du plugin et pas seulement son `/sitemap` (ADR-026, point 4), puis 200 pour toujours : une panne ultérieure de WordPress fait servir le catalogue tel quel plutôt que couper l'API, et l'âge du catalogue, publié en métrique au lot 7, montrera une panne qui dure. `/health/live` n'exécute aucune vérification, `/health/ready` seulement celle du catalogue (`CatalogReadinessCheck`) : une vérification ajoutée plus tard ne fermera aucune des deux routes par surprise. Traefik sonde `/health/ready` et Uptime Kuma la surveille. `GET /metrics` (format Prometheus) est servi **sur un second port, `9090`**, que Kestrel écoute en plus de `8080` (`ASPNETCORE_HTTP_PORTS=8080;9090`) et qu'aucune route publique n'atteint : Prometheus le scrute sur le réseau interne du VPS. Les journaux vont sur la sortie standard ; la version de l'API est écrite dans le journal de démarrage. Pas de `HEALTHCHECK` dans l'image : la santé se lit de l'extérieur. Le détail côté serveur est dans [`04-deployment.md`](04-deployment.md) §3.

**Configuration par l'environnement (ADR-023).** Patron Options, préfixe `PORTFOLIO__`, `__` comme séparateur de section, une liste en clés indexées : `PORTFOLIO__WORDPRESSBASEURL` (la racine des routes du plugin, `https://morganvoltz.dev/wp-json/portfolio/v1/`), `PORTFOLIO__ALLOWEDORIGINS__0`, `__1`… (les origines CORS ; `https://app.morganvoltz.dev` en production, `localhost` en développement), `PORTFOLIO__CACHE__SITEMAPREFRESHSECONDS` (le rythme de relecture du `/sitemap`, 60 secondes par défaut, §5) et `PORTFOLIO__CACHE__FULLREFRESHMINUTES` (l'âge au-delà duquel le catalogue est rechargé en entier, 360 minutes par défaut). Les réglages ont leurs valeurs par défaut dans `PortfolioOptions`, sauf l'adresse du plugin. Les deux périodes sont vérifiées au démarrage : une période de rafraîchissement hors de 1 à 86 400 secondes, ou un rechargement complet à moins d'une minute, empêchent l'API de démarrer, avec un message qui nomme la variable ; zéro ferait interroger le plugin sans pause, une valeur négative arrêterait l'API après son premier catalogue. Les autres réglages ne sont vérifiés qu'au lot 7 : sans l'adresse du plugin, l'API démarre, mais chaque tic écrit l'erreur qui nomme la variable, et `/health/ready` reste à 503. En production, l'API refusera de démarrer sans origine CORS : mieux vaut un conteneur qui s'arrête qu'un front bloqué en silence. Aucun secret aujourd'hui ; le jour où il y en a un, il va dans le `.env` du serveur, mode 600.

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
    private readonly ListDocuments _listDocuments; // Du projet Application

    [ObservableProperty]
    private ObservableCollection<Document> articles = new();

    [RelayCommand]
    private void Load()
    {
        Articles = _listDocuments.Execute(CollectionKind.Article, new CatalogQuery()) switch
        {
            PagedList<Document> page => new ObservableCollection<Document>(page.Items),
            CatalogNotReady => new ObservableCollection<Document>(), // contenu pas encore chargé
        };
    }
}
```

Ce ViewModel ne contient **aucune** logique métier. Il orchestre la présentation (notifier les changements à la vue XAML) et délègue tout le reste au cas d'usage `ListDocuments`, le même que celui qu'appellera `Portfolio.Api`. Comme pour la route HTTP, le compilateur signale un `switch` sur l'union `DocumentListing` qui oublierait le moment où aucun catalogue n'est encore chargé.

Point d'attention : dans cette configuration, le desktop pourrait vouloir parler directement au WordPress (via l'implémentation existante de `IWordPressClient`) plutôt que de passer par l'API C# déployée ; il lui faudrait alors faire tourner lui-même le rafraîchissement (`RefreshCatalog`, lot 5) qui remplit son `CatalogStore`. C'est une question à trancher au moment où le besoin apparaîtra. Les deux configurations sont possibles parce que `Portfolio.Infrastructure` est référencé séparément.

### 9.3. Autres évolutions imaginables

`Portfolio.Mobile` en MAUI, selon les mêmes principes, pour un consultation nomade du portfolio.

`Portfolio.Cli` qui offrirait des commandes d'administration — par exemple « liste tous les articles sans tag » ou « exporte tous les projets en fichiers Markdown » — en pilotant `Portfolio.Application` depuis la ligne de commande.

`Portfolio.Functions` si Morgan voulait expérimenter Azure Functions ou AWS Lambda pour exposer certains cas d'usage sans serveur, en réutilisant encore `Portfolio.Application`.

Aucune de ces évolutions n'est prévue dans la feuille de route actuelle, mais chacune devient triviale à ajouter parce que le cœur métier existe déjà, testé et indépendant.

---

## 10. Dépendances NuGet

Les paquets nécessaires pour l'API restent peu nombreux ; leurs versions sont centralisées dans `api/Directory.Packages.props`, une seule par paquet pour toute la solution.

`Microsoft.AspNetCore.App` est le framework d'ASP.NET Core, que `Portfolio.Api.csproj` reçoit par `Sdk="Microsoft.NET.Sdk.Web"` : ce n'est pas un paquet à déclarer.

Le projet `Portfolio.Application` n'a **aucune** dépendance : ni paquet, ni autre projet, et deux tests le vérifient (§3.2). Si un paquet s'avérait nécessaire, il faudrait se demander si l'abstraction est correcte : en général, un paquet dans le cœur est le symptôme d'une responsabilité qui devrait être dans l'infrastructure. Le `FrozenDictionary` de l'index et l'expression régulière des slugs, générée à la compilation (`[GeneratedRegex]`), viennent de la bibliothèque de base.

`Markdig` 1.6.0 est la seule dépendance externe de `Portfolio.Infrastructure`, pour le rendu (lot 4, §6) ; le client du plugin (lot 3) n'en demande aucune, `HttpClient` et `System.Text.Json` venant de la bibliothèque de base. Les paquets de l'exploitation (métriques, OpenAPI) viendront au lot 7. Il n'y a plus de cache : `Microsoft.Extensions.Caching.Memory` et `IMemoryCache` ne servent pas.

En dépendances de test : `xunit.v3.mtp-v2`, xUnit v3 sur Microsoft.Testing.Platform, et `Microsoft.AspNetCore.Mvc.Testing` pour `WebApplicationFactory`. Ni bibliothèque de simulation ni bibliothèque d'assertions (ADR-026, point 10) : des doubles de test écrits à la main et les assertions de xUnit. NSubstitute, FluentAssertions et WireMock.Net, que citait la version d'avril de ce document, ne sont pas nécessaires.

---

## 11. Tests

La structure en trois projets rend la stratégie de tests directe. Les projets de tests tournent sur xUnit v3 et Microsoft.Testing.Platform : `dotnet test --solution Portfolio.slnx` les exécute tous, et l'intégration continue fait de même sur chaque pull request qui touche `api/` (lot 1). En phase de développement, chaque projet peut être testé seul pour un cycle de retour rapide.

**`Portfolio.Application.Tests`** couvre le cœur, sans aucun environnement externe. Les tests construisent des listes de documents à la main (`TestDocuments`, dans `Portfolio.TestSupport`), en font un catalogue et l'interrogent : l'alignement des listes, la recherche par slug sous toutes ses formes (le slug du document, celui d'une autre langue, un repli, les pages qui ont un slug par langue, les doublons dans les deux ordres), les listes filtrées et paginées, les règles de `CatalogQuery`, les cas d'usage avant et après le premier catalogue. Leurs noms sont des phrases (`A_slug_of_one_language_finds_the_document_served_in_another`), pour se lire comme une spécification. Ils vérifient l'identité des documents rendus (`Assert.Same`), et examinent les unions par filtrage de motif, jamais par `GetType()`, qui rendrait le type de l'union et non celui du cas (ADR-026, point 6). Le même projet porte les tests de la règle des dépendances (§3.4), et ceux du rafraîchissement (lot 5a), avec un faux plugin, un faux rendu et une horloge manuelle : le premier tic, un sitemap identique ou réordonné, une ligne modifiée, ajoutée ou retirée, le rechargement périodique et sa borne, chaque échec de l'amont, des listes désalignées, un rendu qui échoue (le rang écarté, chacun de ses documents avec sa raison, un document de repli une seule fois), chaque copie gardant la langue de sa liste, deux traductions au même Markdown rendues chacune dans sa langue, une ligne orpheline, l'annulation. Exécution en une seconde.

**`Portfolio.Infrastructure.Tests`** teste le client du plugin sans appeler le site. Un faux plugin écrit à la main (`FakePlugin`, un `HttpMessageHandler`) rejoue les réponses que le vrai plugin a données le 22 novembre 2026, enregistrées dans `Recorded/` avec les requêtes exactes du client (son README dit comment les refaire), et retient les requêtes reçues, pour qu'un test vérifie ce que le client demande. La page du pare-feu, qu'on ne peut pas provoquer, y est la même reconstitution minimale que dans les tests du script de synchronisation. Les échecs (délai, réseau, statut, contrat rompu, liste qui change) sont fabriqués à partir de ces réponses. Deux tests branchent le client sur le cœur : les neuf listes enregistrées forment un catalogue aligné, qui répond comme les routes de détail du plugin ont répondu le même jour, et chaque ligne du sitemap enregistré y mène à un document. Le rendu (lot 4) y est testé de deux façons : des Markdowns d'entrée et le HTML exact attendu, branche par branche de la passe sur les liens, vecteurs d'attaque compris ; et le Markdown des 54 documents enregistrés, où chaque lien entre documents doit mener à la route du document qu'il nomme, chaque lien absolu du site au même chemin sur WordPress, et où aucun lien ne doit perdre son adresse.

**`Portfolio.Api.Tests`** teste l'assemblage complet via `WebApplicationFactory<Program>`, qui démarre l'API en mémoire sans serveur HTTP réel et permet d'envoyer des requêtes en client virtuel. Tous ses tests passent par `PortfolioApiFactory`, qui garde la vraie composition mais remplace le plugin par le faux client en mémoire et l'horloge par l'horloge manuelle : aucun test ne peut atteindre le vrai site, et le service de fond n'attend que si le test fait passer le temps. Le test apprend qu'un tic est fini quand le service demande sa prochaine attente, et il en lit la durée. Il vérifie ainsi la cadence (5, 15, 45 puis 60 secondes avant le premier catalogue, la période des réglages ensuite), le rechargement à l'âge des réglages, `/health/ready` (503, puis 200 pour toujours), une exception attrapée, le journal d'un rechargement, qu'un arrêt de l'API en plein tic n'est pas écrit comme une erreur, qu'une période hors bornes empêche l'API de démarrer, et qu'une adresse du plugin manquante ne l'en empêche pas. Au lot 5b, il validera les routes, leurs codes de réponse et la forme du JSON.

**`Portfolio.TestSupport`** n'est pas un projet de tests mais une bibliothèque que les tests partagent, sans aucun cadre de test (choix de Morgan, lot 5) : les doubles écrits à la main (`FakeWordPressClient`, `FakeMarkdownRenderer`, `ManualTimeProvider`) et les documents de test (`TestDocuments`). Au lot 5b, les réponses enregistrées du plugin et `FakePlugin` y passeront, pour le test de bout en bout de l'API.

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
