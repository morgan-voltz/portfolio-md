# 06 — WordPress sous le capot

**Temps de lecture** : environ 11 minutes
**Dernière mise à jour** : 22 avril 2026

---

Il faut que je te fasse un aveu avant de commencer ce chapitre. Pendant longtemps, j'ai considéré WordPress avec une certaine condescendance. Je voyais les sites WordPress que tout le monde connaît — les blogs bricolés avec quinze plugins qui rament, les thèmes chargés de publicités, les admins qui prennent dix secondes à charger chaque page — et je me disais que c'était un système pour les gens qui ne savent pas coder. Quelque chose qu'on utilise faute de mieux, pas un objet d'étude sérieux pour un développeur qui se respecte.

Cette attitude était bête, et j'en suis revenu depuis. Non pas parce que quelqu'un m'a convaincu par de beaux discours, mais parce que j'ai été obligé par ce projet de regarder vraiment comment WordPress fonctionne à l'intérieur. Et ce que j'y ai trouvé m'a étonné : un système qui tient depuis plus de vingt ans, qui anime plus de quarante pour cent du web, et qui a résolu avec une élégance discrète des problèmes architecturaux que beaucoup de frameworks plus modernes peinent encore à traiter proprement.

Ce chapitre est une invitation à regarder WordPress sans les préjugés qu'on a tous accumulés. Pas pour en devenir un militant, mais pour apprendre de ce qu'il fait bien — parce que les patterns qu'il utilise en interne sont les mêmes qui structurent notre propre plugin, et plus largement les mêmes qu'on retrouve dans la plupart des systèmes extensibles matures.

## WordPress n'est pas un CMS, c'est une plateforme d'extension

Le premier piège, quand on parle de WordPress, est de le ranger dans la catégorie « CMS ». Techniquement c'est vrai — WordPress gère du contenu — mais c'est une définition qui passe à côté de l'essentiel. WordPress est d'abord et avant tout **une plateforme conçue pour être étendue**. Le cœur WordPress lui-même fournit un système relativement simple : des articles, des pages, des commentaires, une admin, un système de thèmes. Tout le reste — les fonctionnalités sophistiquées qu'on associe à WordPress en production — vient de son écosystème de plugins et de thèmes.

Cette distinction compte parce qu'elle change complètement la façon dont on juge le système. Si on évalue WordPress comme CMS, on est vite déçu : son éditeur a des limites, son SEO natif est rudimentaire, sa sécurité par défaut demande du durcissement. Mais si on l'évalue comme **plateforme d'extension**, on voit quelque chose d'autre : un système qui a maintenu pendant deux décennies une compatibilité ascendante remarquable, qui permet à des milliers de développeurs de construire des extensions qui cohabitent pacifiquement, et qui propose des points d'accroche pour à peu près toutes les modifications qu'on peut imaginer.

Un CMS évalue sa qualité sur la richesse de ses fonctionnalités natives. Une plateforme d'extension évalue sa qualité sur la clarté et la puissance de ses mécanismes d'extension. Par ce second critère, WordPress est un réussite durable — et les mécanismes qu'il utilise méritent qu'on les comprenne.

## Les hooks : le système nerveux de WordPress

Au cœur de l'architecture d'extension de WordPress, il y a un concept qu'on appelle les **hooks**. Ce nom ne dit pas grand-chose, et beaucoup de tutoriels passent dessus sans en expliquer vraiment la nature. Voici comment je le formulerais après avoir travaillé avec.

Un hook est un endroit précis dans le code de WordPress où le système **s'arrête momentanément pour demander aux plugins s'ils ont quelque chose à dire**. Quand tu sauvegardes un article, WordPress fait son travail normal — il valide les données, les stocke en base, invalide les caches — mais à plusieurs moments de ce processus, il lance l'équivalent d'un appel dans une salle pleine de plugins : « quelqu'un veut faire quelque chose maintenant ? ». Les plugins qui ont enregistré une fonction sur ce hook spécifique sont alors appelés, chacun leur tour, et peuvent exécuter ce qu'ils veulent — modifier les données avant stockage, envoyer un email, mettre à jour un cache externe, ou simplement logger l'événement.

Il existe deux variantes de hooks, qu'on distingue par leur rôle. Les **actions** sont des points où WordPress notifie qu'un événement s'est produit. Le hook `save_post` déclenche toutes les fonctions enregistrées après qu'un article vient d'être sauvegardé — ces fonctions ne peuvent pas annuler la sauvegarde ni modifier ce qui a été sauvegardé, elles peuvent seulement réagir à l'événement. Les **filtres** sont des points où WordPress demande aux plugins de transformer une valeur avant de l'utiliser. Le filtre `the_content` est appelé chaque fois que WordPress s'apprête à afficher le contenu d'un article, et les plugins peuvent y modifier le HTML avant son rendu — par exemple pour y insérer automatiquement un bloc « articles connexes » en fin d'article.

Ce système paraît simple — trop simple peut-être pour un lecteur habitué à des frameworks modernes. Il l'est effectivement. Mais cette simplicité cache une propriété remarquable : **la liste des hooks est publique et stable**. Depuis WordPress 2.x à aujourd'hui, la grande majorité des hooks qui existaient continuent d'exister sous le même nom et avec le même contrat. Un plugin écrit pour WordPress 3.0 a encore de bonnes chances de fonctionner sur WordPress 6.x avec quelques ajustements mineurs. Peu de systèmes logiciels peuvent se vanter d'une telle stabilité sur quinze ans.

## Comment notre plugin utilise ces hooks

Pour rendre cette abstraction concrète, regarde ce que fait notre plugin `portfolio-md`. Il a besoin d'intervenir à plusieurs moments précis du cycle de vie WordPress, et il le fait en s'accrochant à plusieurs hooks bien identifiés.

Au moment où WordPress démarre, sur le hook `init`, notre plugin enregistre le Custom Post Type `portfolio_project` et la taxonomie `tech_stack`. Ça dit à WordPress : « en plus de tes articles et pages standards, accepte aussi ce nouveau type de contenu que j'invente pour les projets, avec ses propres attributs ». WordPress intègre alors ce nouveau type à toute sa machinerie — il apparaît dans le menu admin, il dispose de son propre écran d'édition, il peut être requêté comme n'importe quel type de post.

Au moment où l'admin WordPress s'apprête à afficher un écran d'édition d'article ou de projet, sur le hook `add_meta_boxes`, notre plugin ajoute sa métabox d'édition Markdown. Cette métabox remplace fonctionnellement l'éditeur Gutenberg natif pour ces types de posts — un autre hook, `use_block_editor_for_post_type`, nous permet de désactiver Gutenberg sur ces types précis.

Au moment où un article est sauvegardé, sur le hook `save_post`, notre plugin se réveille. Il extrait le Markdown depuis la métabox, déclenche le pipeline de conversion complet (parsing du frontmatter YAML, rendu du Markdown en HTML, conversion en blocs Gutenberg), et met à jour les champs WordPress appropriés. Tout ce travail est fait entre le moment où WordPress a reçu les données du formulaire et le moment où il les stocke en base — une fenêtre de quelques millisecondes pendant laquelle notre plugin peut transformer le contenu.

Enfin, au moment où WordPress initialise son API REST, sur le hook `rest_api_init`, notre plugin enregistre ses routes custom `/wp-json/portfolio/v1/*`. WordPress propage alors ces routes dans son système normal de routage, les expose publiquement, et les traite comme si elles étaient natives.

À aucun moment notre plugin n'a « hacké » WordPress. Il a utilisé des points d'extension documentés, stables, et prévus exactement pour ce genre d'usage. C'est la différence entre **bricoler** un système (l'ouvrir par la force pour y insérer du code qui n'était pas prévu) et **étendre** un système (utiliser les mécanismes que le système lui-même expose pour accueillir du code tiers). WordPress nous a offert la seconde voie sur tous nos besoins.

## Le pattern Inversion de Contrôle, mine de rien

Tu ne le sais peut-être pas, mais ce que WordPress fait avec ses hooks est un pattern architectural qui porte un nom dans la littérature : **l'inversion de contrôle**, souvent abrégée IoC. C'est le principe selon lequel, au lieu que ton code appelle le framework pour lui demander ce qu'il doit faire, c'est le framework qui appelle ton code aux moments appropriés. Tu n'écris pas un script qui dirige WordPress — tu écris des fonctions que WordPress appellera quand il jugera pertinent.

Cette inversion a une conséquence subtile mais puissante. Elle permet à des dizaines de plugins écrits par des auteurs qui ne se connaissent pas de cohabiter dans la même installation WordPress sans se marcher sur les pieds. Chaque plugin enregistre ses handlers sur les hooks qui l'intéressent. WordPress appelle ensuite tous les handlers concernés par un événement, dans un ordre déterminé par leur priorité déclarée. Si deux plugins veulent tous les deux modifier le contenu d'un article avant affichage, ils s'accrochent tous les deux au filtre `the_content` avec une priorité, et WordPress les appelle successivement en passant le résultat de l'un comme entrée du suivant.

Ce modèle de composition par ordre d'exécution est élégant par sa simplicité. Il n'y a pas besoin d'un système complexe de déclaration de dépendances entre plugins, pas besoin d'orchestrateur central qui comprendrait chaque plugin. Juste une liste ordonnée de handlers sur chaque hook, et le principe que chaque plugin est responsable de se comporter correctement dans cet environnement partagé.

Les frameworks plus récents ont souvent des systèmes d'extension plus sophistiqués — containers de dépendances, événements typés, middlewares chaînés. Ces systèmes ont leurs mérites, mais ils ont souvent un coût de complexité qui les rend inaccessibles aux développeurs débutants. WordPress a choisi la voie inverse : un mécanisme d'extension délibérément simple, qu'on peut comprendre en une demi-heure, et qui suffit pour quatre-vingts pour cent des cas d'usage. Cette simplicité assumée est probablement une des raisons pour lesquelles l'écosystème WordPress a pu croître jusqu'à sa taille actuelle.

## Appliquer nos propres principes architecturaux dans un plugin

Tout ça étant dit, le fait que WordPress expose des hooks ne signifie pas qu'il faille écrire du code WordPress de n'importe quelle manière. Beaucoup de plugins anciens sont des collections de fonctions globales accrochées à des hooks, sans structure, sans séparation des préoccupations, sans testabilité. Ces plugins marchent, mais ils sont difficiles à maintenir, impossibles à tester proprement, et ils finissent vite en cauchemar quand ils grossissent.

Notre plugin `portfolio-md` prend explicitement le parti inverse. À l'intérieur du plugin, on applique les mêmes principes architecturaux qu'on a détaillés dans les chapitres précédents sur la Clean Architecture. Le code métier vit dans des classes qui ne connaissent pas WordPress — le parser Markdown, le transformeur en blocs Gutenberg, les value objects comme `Article` ou `Frontmatter`. Ces classes peuvent être testées en isolation sans instancier WordPress, exactement comme les handlers de `Portfolio.Application` côté C#.

Les classes qui touchent réellement à WordPress sont confinées dans des dossiers spécifiques — `Storage/` pour les repositories qui parlent à `wp_postmeta`, `Admin/` pour les écrans d'administration, `Rest/` pour les endpoints API. Ces classes sont les adaptateurs qui font le pont entre le cœur métier et l'écosystème WordPress. Elles reçoivent leurs dépendances via le constructeur plutôt que de les instancier elles-mêmes, ce qui permet de les tester en injectant des doubles de tests à la place des vraies instances WordPress.

Les hooks WordPress sont accrochés à des méthodes de ces classes adaptateurs, et jamais à des fonctions globales ni directement à des méthodes du cœur métier. Cette discipline produit un effet simple mais puissant : **les hooks deviennent des traducteurs**, pas des lieux de logique. Quand WordPress appelle notre handler sur `save_post`, ce handler ne fait rien lui-même — il extrait les données du contexte WordPress, construit les objets métier appropriés, et délègue le vrai travail au service applicatif dans `Service/`. Si demain on voulait déclencher le même traitement depuis un script en ligne de commande — par exemple pour importer en masse des fichiers Markdown — on pourrait le faire en instanciant le service directement, sans passer par WordPress du tout.

C'est exactement le même pattern que côté C# avec `Portfolio.Application` consommable depuis plusieurs interfaces. WordPress, ici, est une interface parmi d'autres possibles — celle qui est actuellement implémentée, mais pas la seule envisageable.

## La leçon plus large

Ce chapitre porte un message qui dépasse WordPress spécifiquement, et je voudrais le rendre explicite avant de conclure. **La qualité du code que tu écris pour un système n'est pas déterminée par la qualité du système sous-jacent.** WordPress a une réputation parfois méritée de système où on trouve du code bâclé — mais cette réputation n'est pas une fatalité, c'est une corrélation culturelle.

Tu peux écrire du code WordPress avec les mêmes standards que tu appliquerais à du C# moderne ou à du Rust. Tu peux utiliser PSR-4 et Composer plutôt que d'inclure manuellement des fichiers. Tu peux faire de l'injection de dépendances explicite plutôt que des singletons globaux. Tu peux écrire des tests unitaires sur ton code métier sans démarrer WordPress. Tu peux respecter la séparation des préoccupations entre logique métier et couches techniques. Rien dans WordPress ne t'en empêche — au contraire, ses mécanismes d'extension par hooks se prêtent très bien à cette discipline, à condition qu'on sache où placer la frontière entre ce qui connaît WordPress et ce qui l'ignore.

Le corollaire est vrai aussi dans l'autre sens. Un développeur qui utilise un framework moderne et sophistiqué peut tout à fait produire du code indisciplinée, couplé, non testable. L'outil ne garantit pas la qualité. C'est la discipline du développeur qui fait la différence, et cette discipline se transporte d'un écosystème à l'autre.

Pour moi, le vrai bénéfice d'avoir travaillé WordPress avec cette rigueur n'est pas d'avoir produit un bon plugin — c'est d'avoir confirmé que les principes architecturaux que j'apprenais par ailleurs en C# et en Rust étaient vraiment universels. Ils ne dépendent pas du langage ni du framework. Ils décrivent une façon de penser la construction logicielle qui fonctionne partout où on a le courage de l'appliquer.

## Ce que tu liras dans le chapitre suivant

Le chapitre sept va sortir du technique pour regarder la **méthode** qu'on applique à ce projet. Les conventions de commits, la stratégie de branches, la discipline documentaire, la règle des pull requests même en solo — toutes ces pratiques qu'on associe à des projets d'équipe et qui peuvent sembler superflues pour un projet personnel. On verra pourquoi je les applique quand même, et pourquoi la discipline de méthode est peut-être ce qui distingue le plus nettement un projet qui durera dans le temps d'un projet qui se désagrégera au premier oubli.

---

**Chapitre précédent** : [05 — Un cœur, plusieurs interfaces](05-un-coeur-plusieurs-interfaces.md)
**Chapitre suivant** : [07 — La discipline qui rend un projet durable](07-la-discipline-qui-rend-un-projet-durable.md) *(à rédiger)*

**Retour à** : [documentation pédagogique](README.md) • [documentation d'architecture](../docs/architecture/00-overview.md)
