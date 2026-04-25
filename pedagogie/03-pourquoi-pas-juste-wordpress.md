# 03 — Pourquoi pas juste WordPress

**Temps de lecture** : environ 9 minutes
**Dernière mise à jour** : 22 avril 2026

---

Il y a une question que je me suis posée régulièrement en construisant ce projet, et que tu te poses peut-être aussi en lisant cette documentation : pourquoi ne pas se contenter de WordPress ? WordPress fait très bien son travail depuis plus de vingt ans, il anime plus de quarante pour cent du web public, il dispose d'un écosystème de plugins gigantesque qui résout à peu près tous les problèmes qu'un site peut rencontrer. Ajouter une API C# et un front React séparé, c'est presque doubler la surface technique du projet. Est-ce que le jeu en vaut la chandelle ?

La question est légitime, et je veux y répondre honnêtement plutôt que de défendre mon choix par principe.

## Ce que WordPress fait très bien

Commençons par ce qui mérite d'être reconnu clairement. WordPress est remarquable à plusieurs égards, et faire l'impasse sur ces qualités serait malhonnête.

L'admin est mature et pensé pour des non-développeurs. Quelqu'un qui n'a jamais codé peut apprendre à publier un article sur WordPress en une après-midi. Le workflow éditorial — brouillons, révisions, programmation de publication, prévisualisation — a été raffiné par des années d'usage en production sur des millions de sites. L'interface Gutenberg, qu'on l'apprécie ou non esthétiquement, est le fruit d'un travail ergonomique sérieux.

L'écosystème de plugins couvre à peu près tous les besoins envisageables. Besoin de SEO ? Yoast. Besoin d'e-commerce ? WooCommerce. Besoin de formulaires complexes ? Gravity Forms. Besoin de gestion de membres avec des niveaux d'accès ? MemberPress. Pour beaucoup de sites, WordPress seul suffit largement — et c'est une très bonne chose, parce que la simplicité a de la valeur réelle. Beaucoup de projets qui échouent en production ont échoué précisément parce qu'ils ont voulu en faire trop, là où un WordPress nu aurait fait le travail.

La communauté est vivante, le code est ouvert en licence GPL, et la maturité du projet signifie que tu trouves une réponse à n'importe quelle question en trente secondes sur Stack Overflow. Dans beaucoup de contextes, choisir WordPress seul serait la bonne réponse architecturale, et le dire n'enlève rien à la légitimité de l'avoir écarté dans le nôtre.

## Le vrai problème : le couplage entre stockage et rendu

Là où WordPress devient limitant, c'est quand tu veux découpler ton contenu de son mode d'affichage. Par défaut, WordPress entrelace intimement le stockage et le rendu. Ton contenu vit dans la base de données sous forme de HTML avec des commentaires Gutenberg spécifiques. Les thèmes lisent ce HTML et produisent des pages. Les plugins se branchent dans le cycle de rendu pour ajouter des transformations. L'ensemble forme un système où chaque composant connaît les détails internes des autres.

Ce couplage est pragmatique — il permet à WordPress d'être installable rapidement et de tourner avec une configuration minimale. Mais il crée une contrainte invisible : **ton contenu ne peut vraiment exister que dans WordPress**. Si tu veux l'afficher autrement, sur une technologie différente, dans un contexte que WordPress n'a pas prévu, tu dois passer par WordPress d'une manière ou d'une autre. Le contenu est captif de son moteur de rendu.

Pour la majorité des sites — un blog, une vitrine, une boutique classique — ce couplage est invisible et sans conséquence réelle. Personne ne cherche à afficher son site WordPress d'une seconde façon en parallèle. Mais dès que tu as précisément ce besoin de dualité, tu vas buter sur cette limite structurelle.

## Ce que notre architecture résout concrètement

Le projet que tu consultes a précisément besoin de cette dualité. Une forme WordPress classique pour satisfaire la contrainte académique et porter le référencement naturel. Une forme React moderne pour le portfolio technique qui servira aux recruteurs. Deux audiences distinctes, deux expériences distinctes, mais un seul contenu qui les alimente.

Avec un WordPress pur, cette dualité est difficile à obtenir proprement. On peut construire un thème qui gère les deux présentations, mais le thème devient un monstre qui essaie d'être deux sites en un et aucun des deux ne finit satisfaisant. On peut faire du multisite WordPress, mais alors on duplique le contenu et on se retrouve avec deux bases à maintenir synchronisées manuellement. On peut utiliser l'API REST native de WordPress pour qu'un front React la consomme, mais cette API retourne du HTML Gutenberg déjà rendu que React devrait re-parser péniblement — on gagne peu comparé à un simple `iframe`.

Notre architecture résout le problème en introduisant une séparation franche : le Markdown est la source, WordPress en dérive une représentation HTML Gutenberg pour son propre rendu, et l'API C# sert cette même source (sous sa forme Markdown originelle) à un front React qui applique son propre rendu indépendant. Chaque interface est optimisée pour son audience sans avoir conscience de l'autre. Le contenu n'est plus captif, il est partagé.

## Pourquoi pas un headless CMS existant

Une objection pertinente se pose à ce stade. Si on veut découpler stockage et rendu, pourquoi ne pas utiliser un *headless CMS* conçu pour ça dès le départ ? Il en existe plusieurs : Contentful, Strapi, Sanity, Directus. Ce sont des produits faits exactement pour ce besoin de séparer la gestion du contenu de son affichage, avec des admins pensés pour des rédacteurs non-techniques et des APIs optimisées pour alimenter des fronts modernes.

Il y a deux raisons de ne pas les avoir choisis ici. La première est la contrainte académique : WordPress est imposé par le cahier des charges, et utiliser Strapi à la place contournerait la règle plutôt que de la résoudre. La seconde est pédagogique : construire un plugin qui transforme WordPress en quasi-*headless* m'apprend énormément sur les mécaniques internes de WordPress, sur les patterns d'extension, sur la façon dont un CMS mature gère ses hooks et son cycle de vie. Avec un Strapi pris sur étagère, je gagnerais du temps mais je passerais à côté de tout cet apprentissage.

Dans un contexte professionnel différent — une équipe qui doit livrer vite, un projet où WordPress n'est pas imposé, un besoin où l'écosystème WordPress n'apporte rien de particulier — un headless CMS serait souvent le meilleur choix. Dans notre contexte spécifique, ne pas l'utiliser est un choix cohérent. Mais je voulais que tu saches que cette alternative existe et qu'elle serait pertinente dans d'autres situations.

## Est-ce de la sur-ingénierie

C'est la question critique à traiter frontalement. Trois composants applicatifs (plugin WordPress, API C#, front React) pour publier des articles qui pourraient tenir dans un WordPress nu, est-ce que ce n'est pas trop ?

Ma réponse honnête est que **si l'objectif de ce projet était uniquement de publier des articles, oui, ce serait de la sur-ingénierie caractérisée**. Si j'avais une contrainte de temps serrée — une semaine pour livrer — je ne construirais jamais ça. Si j'étais dans une équipe qui maintient un site en production avec du trafic réel, je ne construirais pas ça non plus, parce que chaque composant ajouté est un composant à surveiller, à sécuriser, à maintenir dans le temps. La sur-ingénierie n'est pas un crime esthétique, c'est un coût réel payé en dette technique.

Mais l'objectif de ce projet-ci n'est pas uniquement de publier des articles. C'est aussi de démontrer une compétence architecturale à des recruteurs, d'apprendre des patterns qui se transposent à d'autres projets, et de construire une base de compétences réutilisable pour la suite de ma carrière. Dans ce contexte-là, la sur-ingénierie apparente devient un investissement pédagogique assumé. Je sacrifie un peu de pragmatisme court-terme contre beaucoup d'apprentissage long-terme.

Ce qu'il faut retenir de ce raisonnement dépasse notre projet spécifique : **une architecture n'est jamais bonne ou mauvaise dans l'absolu, elle est bonne ou mauvaise par rapport à ses objectifs**. Face à une architecture qui te semble trop complexe pour ce qu'elle produit, la bonne question n'est pas « est-ce que c'est trop compliqué ? » mais « pour quels objectifs ce niveau de complexité a-t-il du sens ? ». Si tu trouves des objectifs qui le justifient, tu as devant toi une architecture investie. Si tu n'en trouves pas, tu as effectivement une architecture sur-dimensionnée.

Pour notre projet, les objectifs justifient le niveau de complexité. Je peux le défendre sereinement à n'importe quel relecteur technique, parce que j'ai fait l'exercice de regarder honnêtement les alternatives avant de m'engager dans cette voie.

## Ce que tu liras dans le chapitre suivant

Le chapitre quatre entre dans le cœur technique de l'architecture : la Clean Architecture expliquée simplement, à partir d'exemples concrets du projet. On va regarder ce qu'est ce pattern, pourquoi il est utile pour les applications qui doivent durer, et comment trois dossiers bien nommés dans une solution .NET peuvent changer radicalement la maintenabilité d'un code à long terme. C'est le chapitre où le « pourquoi c'est compliqué » devient « pourquoi cette complexité rend le code plus simple en réalité ».

---

**Chapitre précédent** : [02 — Le Markdown comme format pivot](02-le-markdown-comme-format-pivot.md)
**Chapitre suivant** : [04 — La Clean Architecture expliquée simplement](04-clean-architecture-expliquee-simplement.md) *(à rédiger)*

**Retour à** : [documentation pédagogique](README.md) • [documentation d'architecture](../architecture/00-overview.md)
