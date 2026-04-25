# 05 — Un cœur, plusieurs interfaces

**Temps de lecture** : environ 9 minutes
**Dernière mise à jour** : 22 avril 2026

---

Imagine qu'un an après avoir terminé ce projet, je décide de construire une application desktop pour administrer mon portfolio hors ligne. Je voyage souvent en train sans connexion, et j'aimerais pouvoir y écrire des articles, les relire, les taguer, les prévisualiser — puis synchroniser tout ça avec le serveur distant quand je retrouve du réseau. Question concrète : combien de temps va-t-il me falloir pour construire cette application ?

La réponse dépend entièrement de la façon dont j'ai structuré mon code initialement. Si ma logique métier est dispersée dans des endpoints HTTP et des contrôleurs web, comme dans l'exemple négatif du chapitre précédent, je dois tout réécrire pour la nouvelle application. Je reconstruis la gestion des articles, je reconstruis le parsing du Markdown, je reconstruis la validation des tags, je reconstruis toutes les règles métier qui vivent aujourd'hui dans mon code web. Des semaines entières de travail.

Mais si ma logique métier vit dans un projet pur et isolé comme `Portfolio.Application`, alors la nouvelle application peut simplement la **référencer et l'utiliser telle quelle**. Quelques jours de travail au lieu de plusieurs semaines, concentrés sur ce qui est vraiment nouveau — l'interface desktop elle-même, les écrans, la logique de synchronisation offline — sans rien réécrire de ce qui existe déjà.

Cette différence d'un facteur dix en temps de développement, c'est l'effet le plus concret de la Clean Architecture. Le chapitre quatre a posé les mécanismes. Celui-ci explique ce qu'ils rendent possible.

## Le principe : un cœur, plusieurs périphéries

Dans une application traditionnelle, le code est organisé **par fonctionnalité**. Tu as une fonctionnalité « gestion des articles » qui contient l'écran de liste, le formulaire d'édition, les appels à la base, les validations, tout mélangé au même endroit. Une autre fonctionnalité « gestion des projets » fait pareil dans son coin. Chaque fonctionnalité est autosuffisante, mais refait dans son périmètre le même genre de travail que ses voisines, et reste captive du contexte où elle a été écrite.

Dans une application Clean Architecture, le code est organisé **par nature de responsabilité**. D'un côté, la logique métier — ce que fait l'application. De l'autre, les détails techniques — comment elle communique avec le monde extérieur. Entre les deux, les couches d'adaptation qui font le pont.

Cette réorganisation change complètement les possibilités. Parce que la logique métier ne connaît pas le mode de communication, elle devient **indépendante de ce mode**. Et parce qu'elle en est indépendante, elle peut en servir **plusieurs simultanément**. Une même fonction « récupérer tous les articles taggés Rust » peut être appelée depuis un endpoint HTTP, depuis une méthode d'une application desktop, depuis un script en ligne de commande, depuis un test automatisé. Chacun de ces appels produit exactement le même résultat, parce que la fonction ne se préoccupe pas de qui l'appelle ni pourquoi.

## Ce qui est partagé exactement

Il est important de préciser ce qui circule entre les interfaces et ce qui reste spécifique, parce que cette frontière éclaire toute l'architecture.

Le projet `Portfolio.Application` est partagé intégralement. Il contient les modèles de données comme `Article`, `Project` ou `Tag`, les interfaces qui décrivent les services externes nécessaires comme `IWordPressClient` ou `IArticleCache`, et les cas d'usage qui orchestrent tout ça comme `GetArticleBySlug` ou `ListArticles`. Toute cette matière reste rigoureusement identique quelle que soit l'interface qui la consomme. Cent pour cent partagé.

Le projet `Portfolio.Infrastructure` est partagé selon les besoins. Les implémentations concrètes qu'il contient — le client HTTP pour parler à WordPress, le cache mémoire, le renderer Markdig — peuvent être réutilisées par plusieurs interfaces qui ont les mêmes besoins techniques. Une application desktop pourrait réutiliser exactement le même `WordPressClient` pour parler au serveur distant, tout en ajoutant ses propres implémentations pour ses besoins spécifiques, comme un cache local persisté sur disque au lieu d'un cache en mémoire volatile.

Chaque interface a son propre projet dédié qui n'est pas partagé avec les autres. `Portfolio.Api` contient les endpoints HTTP et leurs DTOs. Un hypothétique `Portfolio.Desktop` contiendrait les écrans Avalonia et leurs ViewModels. Un `Portfolio.Cli` contiendrait les commandes en ligne de commande et leur parseur d'arguments. Ces projets sont spécifiques à leur mode de présentation et ne partagent entre eux que leur référence commune aux couches inférieures.

Le ratio de partage tourne généralement autour de soixante-dix à quatre-vingts pour cent du code métier réutilisé, vingt à trente pour cent de code spécifique à l'interface. Pour un projet de notre taille, ça se traduirait concrètement par quelques centaines de lignes spécifiques à une nouvelle interface, contre plusieurs milliers de lignes réutilisées gratuitement — un rapport de valeur très favorable qui se creuse à chaque interface additionnelle.

## Un exemple concret : le ViewModel desktop

Pour sentir comment ça fonctionne en pratique, regarde à quoi ressemblerait une classe dans l'hypothétique projet `Portfolio.Desktop`. Dans une application Avalonia qui suit le pattern MVVM dont on a parlé avec la documentation d'architecture, tu aurais une classe `ArticleListViewModel` qui alimente l'écran de liste des articles.

```csharp
// Dans Portfolio.Desktop/ViewModels/ArticleListViewModel.cs
public partial class ArticleListViewModel : ObservableObject
{
    // La dépendance au cas d'usage qui vient du projet Application
    private readonly ListArticles _listArticles;

    // Collection observable liée à la vue XAML via MVVM
    [ObservableProperty]
    private ObservableCollection<ArticleDto> articles = new();

    // Commande déclenchée par l'utilisateur (par exemple bouton Recharger)
    [RelayCommand]
    private async Task LoadAsync()
    {
        // Appel au cas d'usage du projet Application : même code que l'API !
        var result = await _listArticles.ExecuteAsync();
        Articles = new ObservableCollection<ArticleDto>(result);
    }
}
```

Regarde bien ce qui se passe ici. La classe est manifestement spécifique à Avalonia — elle hérite d'`ObservableObject`, elle utilise les attributs `[ObservableProperty]` et `[RelayCommand]` qui sont des conventions spécifiques au MVVM. Mais la **logique métier** qu'elle exécute, cet appel à `_listArticles.ExecuteAsync()`, est exactement celle appelée par l'endpoint HTTP de l'API. Aucune duplication, aucune adaptation, aucun pont à construire entre les deux mondes.

Le ViewModel ne fait que deux choses spécifiques à son contexte. D'abord, il présente les données dans un format que l'interface graphique sait consommer — une `ObservableCollection` qui notifie les changements à la vue XAML chaque fois qu'un élément est ajouté ou supprimé. Ensuite, il expose une commande `LoadAsync` que l'utilisateur peut déclencher en cliquant sur un bouton de rafraîchissement. Tout le reste — la façon dont les articles sont vraiment récupérés, éventuellement cachés, parsés — vient du cœur et ne concerne pas le ViewModel.

Si un bug est découvert dans la logique de récupération des articles, il est corrigé une seule fois dans `Portfolio.Application`, et l'API comme le desktop bénéficient de la correction sans intervention supplémentaire. C'est le genre de propriété qui paraît abstraite jusqu'à ce qu'on l'ait vécue une fois — et à ce moment, on ne veut plus jamais coder autrement.

## L'expérience inverse : sans Clean Architecture

Pour bien mesurer le bénéfice, fais mentalement l'expérience inverse. Imagine que le code de notre API ait été écrit dans le style de la première section du chapitre quatre — fonction qui mélange requête base de données, parsing Markdown, construction de réponse HTTP, tout dans le même bloc monolithique.

Pour construire l'application desktop, tu aurais deux mauvaises options. La première est de copier-coller le code de l'API dans le nouveau projet et de modifier les parties qui doivent changer — supprimer le retour HTTP, remplacer par des notifications de ViewModel, adapter ici, adapter là. Tu te retrouves avec deux copies du même code métier qui vont fatalement diverger avec le temps. Un bug corrigé d'un côté ne l'est pas de l'autre. Une nouvelle fonctionnalité ajoutée d'un côté manque de l'autre. La maintenance de deux copies jumelles devient un cauchemar quotidien.

La seconde option est d'extraire la logique métier du code existant pendant que tu construis la nouvelle application. Tu refactores l'API pour en sortir la logique vers un projet séparé, puis tu consommes ce projet depuis la nouvelle application. Le résultat final est bon, mais le coût est énorme — tu dois d'abord comprendre un code existant que tu ne connais peut-être plus, identifier ce qui est métier et ce qui est technique dans chaque fonction, extraire, déplacer, retester tout ce qui était en place. Des semaines de travail avant même de commencer à produire la vraie valeur ajoutée de la nouvelle application.

Tu peux maintenant formuler le bénéfice de la Clean Architecture en une phrase directe : **elle t'évite de faire ce refactor dans l'urgence parce que tu l'as fait une fois pour toutes au départ**. L'investissement initial en discipline architecturale te fait économiser du temps à chaque nouvelle interface que tu ajouteras dans la vie du projet, et ces économies cumulées dépassent vite l'investissement initial.

## Pourquoi ça compte au-delà de notre projet

Cette capacité à ajouter des interfaces sans duplication n'est pas qu'une curiosité technique de notre projet de portfolio. C'est un pattern qu'on retrouve dans la quasi-totalité des produits logiciels de taille significative.

Pense à Spotify. Il y a une application web, une application desktop Windows, une application desktop macOS, une application mobile iOS, une application mobile Android, une application TV, une intégration dans les voitures, des SDKs pour tierces parties. Toutes ces interfaces accèdent à la même bibliothèque musicale, aux mêmes playlists, aux mêmes recommandations personnalisées. Derrière, il y a forcément une logique métier partagée — sinon chaque plateforme devrait réimplémenter les règles de recommandation, la gestion des abonnements, le calcul des statistiques d'écoute. Le coût serait monstrueux et les incohérences entre plateformes seraient immédiatement visibles pour les utilisateurs qui passent d'un appareil à l'autre.

Pense à GitLab. Tu peux y accéder via le site web, via la ligne de commande `glab`, via les intégrations dans ton IDE, via des clients tiers qui appellent son API publique. Chaque interface a son mode d'interaction, mais la notion de « merge request » ou de « pipeline CI » est identique partout. La séparation entre le cœur métier de GitLab et ses interfaces est ce qui rend cette cohérence possible à grande échelle.

Tu ne construiras peut-être jamais toi-même un produit à la taille de Spotify ou GitLab. Mais tu travailleras très probablement sur des projets qui devront un jour accueillir une nouvelle interface — une app mobile qui s'ajoute à une app web, un outil CLI qui s'ajoute à une API, une intégration tierce qui s'ajoute à un produit existant. Quand ce jour arrivera, tu remercieras la discipline architecturale que tu auras mise en place au départ, ou tu maudiras son absence.

## Ce que tu retiendras de ce chapitre

Si tu ne devais retenir qu'une seule idée, ce serait celle-ci : **la Clean Architecture ne sert pas qu'à rendre le code plus propre, elle sert à multiplier les usages possibles du même code sans multiplier le code écrit**. Un cœur bien isolé est un cœur qui peut servir plusieurs interfaces simultanément. Un cœur mélangé à ses interfaces est un cœur prisonnier d'une interface unique, condamné à être réécrit chaque fois qu'un nouveau besoin se présente.

La leçon transférable au-delà de notre projet est que la propreté architecturale n'est pas un luxe esthétique. C'est un investissement qui produit des dividendes à chaque extension future du projet. Plus le projet dure et évolue, plus l'investissement initial est rentable. C'est pour cette raison que les projets matures dans l'industrie convergent presque tous, d'une manière ou d'une autre, vers des architectures qui séparent le métier des interfaces — que ce soit Clean Architecture, Hexagonal Architecture, Ports and Adapters, ou leurs variantes. Les noms diffèrent, les subtilités aussi, mais le principe directeur est le même.

## Ce que tu liras dans le chapitre suivant

Le chapitre six va s'attaquer à WordPress sous un angle qu'on a peu abordé jusqu'ici. Non plus comme contrainte académique ou comme composant externe à intégrer, mais comme **système extensible qu'on peut architecturer proprement de l'intérieur**. Beaucoup de développeurs considèrent WordPress comme une boîte noire qu'on configure, ou comme un CMS qu'on étend par des plugins bricolés au fil des besoins. On verra qu'une autre approche est possible — celle qui traite le plugin WordPress comme un vrai projet logiciel avec ses propres principes de design. C'est là que les patterns découverts dans les chapitres précédents — séparation des préoccupations, indépendance de la logique métier, injection de dépendances — trouvent à s'appliquer dans un écosystème qu'on n'associe habituellement pas à cette rigueur.

---

**Chapitre précédent** : [04 — La Clean Architecture expliquée simplement](04-clean-architecture-expliquee-simplement.md)
**Chapitre suivant** : [06 — WordPress sous le capot](06-wordpress-sous-le-capot.md) *(à rédiger)*

**Retour à** : [documentation pédagogique](README.md) • [documentation d'architecture](../docs/architecture/00-overview.md)
