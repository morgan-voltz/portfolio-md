# 04 — La Clean Architecture expliquée simplement

**Temps de lecture** : environ 12 minutes
**Dernière mise à jour** : 22 avril 2026

---

Quand j'ai commencé à coder, je mettais tout dans le même fichier. Le code qui parlait à la base de données, le code qui validait les entrées utilisateur, le code qui affichait les résultats, tout cohabitait dans quelques fonctions de plusieurs centaines de lignes. Et tu sais quoi ? Ça marchait très bien.

Ça marchait très bien jusqu'au moment où il a fallu changer quelque chose. Un jour, tu veux remplacer MySQL par PostgreSQL, et tu te retrouves à réécrire la moitié de ton application parce que des requêtes SQL sont partout. Un autre jour, tu veux tester une fonction en isolation, et tu découvres qu'elle ne peut pas fonctionner sans une vraie base de données connectée. Plus tard, tu veux ajouter une interface en ligne de commande à ce qui n'était qu'un site web, et tu réalises que toute la logique est entremêlée avec du HTML.

C'est cette série d'expériences légèrement frustrantes qui m'a amené à m'intéresser aux architectures propres. Et c'est une des leçons les plus précieuses que j'ai apprises en programmation : **l'architecture ne sert pas à écrire du code qui marche, elle sert à écrire du code qui peut évoluer sans se casser**.

## Le problème à voir concrètement

Prenons un exemple pour bien sentir le problème. Imagine que tu écris une petite fonction qui récupère un article depuis une base de données et le renvoie à un navigateur. Sans réfléchir à l'architecture, tu écrirais probablement quelque chose comme ça en C# :

```csharp
// Une fonction qui fait tout, sans découpage
public IResult GetArticle(string slug)
{
    // 1. Ouvrir une connexion à la base de données
    var connection = new SqlConnection(connectionString);
    connection.Open();

    // 2. Exécuter la requête SQL pour récupérer l'article
    var command = new SqlCommand(
        "SELECT title, content FROM articles WHERE slug = @slug",
        connection);
    command.Parameters.AddWithValue("@slug", slug);
    var reader = command.ExecuteReader();

    // 3. Si rien trouvé, retourner 404
    if (!reader.Read())
        return Results.NotFound();

    // 4. Lire le Markdown stocké en base
    var title = reader.GetString(0);
    var markdown = reader.GetString(1);

    // 5. Convertir le Markdown en HTML via une librairie externe
    var html = Markdig.Markdown.ToHtml(markdown);

    // 6. Renvoyer la réponse JSON au client HTTP
    return Results.Ok(new { title, html });
}
```

Ce code marche. Il est compact, il est relativement lisible, il fait ce qu'il doit faire. Mais il contient un problème que tu ne vois pas tant que tu n'essaies pas de le faire évoluer.

Que se passe-t-il si tu veux tester cette fonction sans une vraie base de données ? Tu ne peux pas — elle instancie sa propre connexion SQL dans ses premières lignes, codée en dur. Que se passe-t-il si tu veux remplacer Markdig par une autre librairie de rendu Markdown ? Tu dois modifier cette fonction, et toutes les autres qui font du rendu dans ton application. Que se passe-t-il si tu veux que ton application soit appelée depuis autre chose qu'un navigateur — par exemple un outil en ligne de commande ou une application desktop ? Tu dois tout réécrire, parce que la logique métier est enchevêtrée avec le retour HTTP.

Le problème n'est pas que ce code est mal écrit. Il est écrit comme n'importe quel débutant compétent l'écrirait. Le problème est qu'il **mélange des choses qui devraient être séparées**.

## Les deux types de changements qui cohabitent dans une application

Voici l'observation qui débloque tout. Dans n'importe quelle application qui vit dans le temps, il y a deux types de changements qui se produisent à des rythmes complètement différents.

Le premier type, ce sont les **changements métier**. Un article peut acquérir un nouveau champ comme la durée de lecture estimée. Les règles de filtrage évoluent selon les besoins des utilisateurs. Une nouvelle catégorie de contenu apparaît parce que le produit s'enrichit. Ces changements viennent du domaine fonctionnel de l'application, des évolutions de son utilité pour ses utilisateurs.

Le second type, ce sont les **changements techniques**. On migre de MySQL à PostgreSQL parce que la nouvelle équipe préfère Postgres. On remplace Markdig par une autre librairie parce qu'on a trouvé mieux. On passe de REST à GraphQL pour répondre à un front plus complexe. Le framework web qu'on utilisait évolue ou disparaît. Ces changements viennent de l'infrastructure technique, des outils, des technologies qu'on utilise.

Ces deux types de changements ont une nature fondamentalement différente. Les changements métier sont liés à **l'identité de ton application** — ce qu'elle fait. Les changements techniques sont liés à **son implémentation** — comment elle le fait. Si tu mélanges les deux dans le même code, chaque modification d'un côté risque de casser l'autre, et tu te retrouves à faire de la maintenance dans tous les sens à chaque évolution.

L'idée centrale de la Clean Architecture est de **séparer physiquement ces deux types de code dans des zones différentes** de ton projet, et d'imposer des règles strictes sur la façon dont ces zones peuvent communiquer entre elles.

## La règle cardinale : les dépendances pointent vers le centre

Voici le cœur de la Clean Architecture, et c'est la règle la plus importante à internaliser : **les dépendances vont toujours vers le centre**.

Qu'est-ce que ça veut dire concrètement ? Imagine un oignon à plusieurs couches concentriques. Au centre de l'oignon, tu places ta logique métier pure — les règles de ton application, les concepts qui ne dépendent d'aucune technologie. Dans la couche suivante, tu places les cas d'usage — les scénarios concrets de ton application comme « récupérer un article par slug » ou « lister les projets par technologie ». Dans la couche la plus externe, tu places les détails techniques — les bases de données, les serveurs web, les librairies tierces.

La règle est que la **couche externe peut connaître la couche interne, mais jamais l'inverse**. Le cœur de ton application ne doit jamais mentionner une base de données, une librairie externe, ou un framework web. Il ne sait même pas qu'ils existent. Les couches externes, elles, connaissent le cœur et s'y adaptent en implémentant ce dont il a besoin.

Cette règle peut paraître contre-intuitive au premier abord. Naturellement, quand on code, on pense en partant des outils qu'on utilise : « j'ai une base de données SQL, donc mon code va faire des requêtes SQL ». La Clean Architecture inverse cette perspective. Elle te demande de commencer par ce que ton application fait, en ignorant complètement les outils, puis d'adapter ensuite les outils à ce besoin. Le métier dicte, la technique s'adapte.

## Comment ça se traduit dans notre projet concrètement

Dans la solution .NET de notre API, cette philosophie se matérialise par trois projets distincts, chacun portant un rôle précis et n'ayant le droit de dépendre que de certains autres.

Le projet `Portfolio.Application` est le cœur de l'oignon. Il contient les modèles métier comme `Article`, `Project`, `Tag`, et les cas d'usage comme `GetArticleBySlug` ou `ListProjects`. Il ne dépend d'aucune bibliothèque externe autre que les types de base de .NET. Si tu ouvrais les fichiers de ce projet, tu ne trouverais aucun `using Microsoft.AspNetCore.*`, aucun `using System.Data.SqlClient`, rien qui ressemble à une technologie concrète. C'est du code pur, portable, qui pourrait être exécuté dans n'importe quel contexte .NET.

Le projet `Portfolio.Infrastructure` est la couche externe qui implémente les services techniques. Il contient une classe `WordPressClient` qui sait vraiment parler HTTP à l'instance WordPress, une classe `MemoryArticleCache` qui utilise le cache en mémoire de .NET, une classe `MarkdigRenderer` qui utilise la librairie Markdig pour convertir le Markdown en HTML. C'est ici que vivent tous les détails techniques et toutes les dépendances externes.

Le projet `Portfolio.Api` est le point d'entrée HTTP. Il contient les endpoints Minimal API, les DTOs de requête et de réponse, les middlewares. Il ne fait lui-même aucune logique métier — il traduit juste entre le monde HTTP et les cas d'usage définis dans le projet `Application`.

## L'inversion de dépendance en pratique

Mais alors, comment `Application` fait-il pour récupérer des données depuis WordPress sans dépendre de HTTP ? Par un mécanisme qui porte un nom un peu intimidant mais dont l'idée est simple : **l'inversion de dépendance**.

Plutôt que le cœur appelle directement une classe technique, il définit une *interface* qui décrit ce qu'il attend comme service.

```csharp
// Dans Portfolio.Application/Abstractions/IWordPressClient.cs
public interface IWordPressClient
{
    // Le cœur dit : « j'ai besoin d'un truc qui sait faire ça »
    Task<Article?> GetArticleBySlugAsync(string slug);
    Task<IReadOnlyList<Article>> ListArticlesAsync();
}
```

Cette interface dit au reste du monde « j'ai besoin d'un service qui sait me donner des articles par slug et me lister tous les articles ». Elle ne dit absolument rien sur *comment* ce service fait son travail — HTTP, base de données directe, fichiers plats, appels à une API tierce, peu importe. Le projet `Application` ne dépend que de cette interface abstraite, jamais d'une implémentation concrète.

Dans le projet `Portfolio.Infrastructure`, on écrit ensuite l'implémentation réelle qui respecte cette interface :

```csharp
// Dans Portfolio.Infrastructure/WordPress/WordPressClient.cs
public class WordPressClient : IWordPressClient
{
    // Implémentation concrète qui utilise HttpClient pour appeler WordPress
    // ... (code qui fait des requêtes HTTP, parse le JSON, etc.) ...
}
```

Enfin, au démarrage de l'application, dans `Program.cs`, on connecte les deux mondes en disant à .NET « quand quelqu'un demande un `IWordPressClient`, donne-lui une instance de `WordPressClient` ». C'est le seul endroit du code où les deux mondes se rencontrent, et c'est par choix.

Cette indirection peut paraître une complexité gratuite au premier regard. Pourquoi passer par une interface alors qu'on pourrait appeler directement la classe concrète ? Parce que cette indirection a une conséquence puissante : **la logique métier de `Application` peut être testée sans jamais instancier un vrai `WordPressClient`**. Dans un test unitaire, tu crées un faux client — un *mock* — qui implémente `IWordPressClient` en renvoyant des données prédéfinies, et tu vérifies que ton cas d'usage se comporte correctement dans différentes situations. Les tests s'exécutent en millisecondes au lieu de secondes, ils ne dépendent d'aucun serveur WordPress, et ils ne cassent pas quand le réseau est instable ou quand la base de données est en maintenance.

## Les bénéfices concrets à long terme

On parle souvent de Clean Architecture en termes théoriques, mais je veux te décrire ce que ça change concrètement dans ta vie de développeur.

Tu peux **remplacer un composant technique sans toucher au métier**. Si demain tu décides d'utiliser Redis au lieu du cache en mémoire pour gérer le cache des articles, tu écris une nouvelle implémentation de `IArticleCache` dans le projet `Infrastructure`, tu changes une ligne dans `Program.cs`, et c'est fini. Le projet `Application` ne change pas, tes tests existants ne changent pas, tes cas d'usage restent rigoureusement identiques. Essaie d'imaginer le même changement sur le code de la première section de ce chapitre — tu aurais à modifier chaque fonction qui utilisait le cache, partout dans l'application.

Tu peux **tester ta logique sans environnement**. Tes tests unitaires tournent sur `Application` en injectant des doubles de tests pour les interfaces. Pas besoin de démarrer WordPress, pas besoin de connecter à une base de données, pas besoin de serveur web. Quelques millisecondes par test, et tu peux en lancer des milliers en quelques secondes sur ta machine de développement.

Tu peux **ajouter une nouvelle interface sans dupliquer la logique**. C'est l'insight qu'on a déjà mentionné dans les chapitres précédents et qui sera le sujet central du chapitre suivant : si tu veux demain un outil CLI ou une app desktop, tu référence `Application` depuis ce nouveau projet, tu écris la couche d'interface correspondante, et toute la logique métier reste partagée. Une seule source de vérité métier, plusieurs façons d'y accéder.

Le code devient **compréhensible pour quelqu'un qui arrive sur le projet**. Un nouveau contributeur peut lire `Application` pour comprendre *ce que fait* l'application, sans être distrait par les détails techniques. Puis lire `Infrastructure` pour voir *comment* c'est implémenté concrètement dans ce contexte spécifique. La séparation physique des préoccupations rend le projet navigable, même pour quelqu'un qui le découvre.

## Les coûts honnêtes de cette approche

Je ne veux pas te vendre la Clean Architecture comme une solution miracle. Elle a des coûts réels qu'il faut voir pour faire un choix éclairé.

Elle demande **plus de fichiers**. Au lieu d'une unique classe `ArticleController` qui fait tout, tu as une interface, une implémentation concrète, un cas d'usage, un DTO de réponse, et la configuration qui les relie dans `Program.cs`. Pour un petit projet avec peu de logique métier, cette multiplication peut paraître disproportionnée par rapport au bénéfice.

Elle demande **une discipline constante**. La règle des dépendances n'est pas magique — rien ne t'empêche techniquement d'ajouter un `using Microsoft.AspNetCore.Http;` dans ton projet `Application` si tu as la flemme de créer une interface. La seule chose qui préserve la propreté de l'architecture, c'est ta discipline et celle de tes collaborateurs. Des outils d'analyse statique comme les règles d'architecture de JetBrains Rider ou NDepend peuvent aider à imposer ces règles, mais ils ne remplacent pas une conscience claire de ce qu'on fait.

Elle demande **une phase d'apprentissage**. Les premiers projets qu'on structure en Clean Architecture demandent du temps de réflexion : « est-ce que cette classe va dans `Application` ou dans `Infrastructure` ? », « est-ce que ce détail est du métier ou de la technique ? », « dois-je créer une interface pour ça ou est-ce que c'est exagéré ? ». Ces questions deviennent naturelles avec l'expérience, mais au début, elles ralentissent visiblement.

Pour les petites applications qui ne dureront pas longtemps, ces coûts sont probablement disproportionnés par rapport aux bénéfices. Pour les projets qui vont vivre sur plusieurs années, qui vont accueillir plusieurs contributeurs, qui vont devoir évoluer avec des technologies qui elles-mêmes évoluent, l'investissement initial est largement rentabilisé dans la durée.

## Ce que tu liras dans le chapitre suivant

Le chapitre cinq développera la conséquence la plus intéressante de cette architecture : comment un même cœur métier peut alimenter plusieurs interfaces complètement différentes — web, desktop, mobile, ligne de commande — sans rien dupliquer. C'est le pattern « un cœur, plusieurs interfaces » que je considère comme l'insight architectural le plus original de ce projet, celui qui justifie tout le reste. Tu verras comment un futur portfolio desktop en Avalonia pourrait venir se greffer sur l'architecture existante et ajouter ses propres écrans sans réécrire une seule ligne de la logique métier.

---

**Chapitre précédent** : [03 — Pourquoi pas juste WordPress](03-pourquoi-pas-juste-wordpress.md)
**Chapitre suivant** : [05 — Un cœur, plusieurs interfaces](05-un-coeur-plusieurs-interfaces.md) *(à rédiger)*

**Retour à** : [documentation pédagogique](README.md) • [documentation d'architecture](../architecture/00-overview.md)
