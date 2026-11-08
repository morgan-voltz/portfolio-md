<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd;

use Voltz\PortfolioMd\Admin\EditScreen;
use Voltz\PortfolioMd\Admin\EditorAssets;
use Voltz\PortfolioMd\Admin\EditorMetaBox;
use Voltz\PortfolioMd\Admin\ImportPage;
use Voltz\PortfolioMd\Cli\ImportCommand;
use Voltz\PortfolioMd\Cli\LanguagesCommand;
use Voltz\PortfolioMd\Cli\SkillsCommand;
use Voltz\PortfolioMd\Cli\TranslationsCommand;
use Voltz\PortfolioMd\Domain\ContentType;
use Voltz\PortfolioMd\Meta\CommonMetaRegistrar;
use Voltz\PortfolioMd\Meta\InternalMetaRegistrar;
use Voltz\PortfolioMd\Meta\ProjectMetaRegistrar;
use Voltz\PortfolioMd\Parsing\ContentRenderer;
use Voltz\PortfolioMd\Parsing\FrontmatterParser;
use Voltz\PortfolioMd\Parsing\GutenbergTransformer;
use Voltz\PortfolioMd\Parsing\MarkdownParser;
use Voltz\PortfolioMd\Parsing\SkillsGridLoader;
use Voltz\PortfolioMd\PostTypes\PostTypeRegistrar;
use Voltz\PortfolioMd\Rest\Controller\CollectionController;
use Voltz\PortfolioMd\Rest\Controller\PreviewController;
use Voltz\PortfolioMd\Rest\Controller\SitemapController;
use Voltz\PortfolioMd\Rest\Controller\SyncController;
use Voltz\PortfolioMd\Rest\DocumentPresenter;
use Voltz\PortfolioMd\Rest\RouteRegistrar;
use Voltz\PortfolioMd\Service\ConflictResolver;
use Voltz\PortfolioMd\Service\ContentImportService;
use Voltz\PortfolioMd\Service\ContentSyncService;
use Voltz\PortfolioMd\Service\PreviewService;
use Voltz\PortfolioMd\Service\SkillTermSynchronizer;
use Voltz\PortfolioMd\Service\SystemClock;
use Voltz\PortfolioMd\Service\TranslationReconciler;
use Voltz\PortfolioMd\Service\LanguageReadiness;
use Voltz\PortfolioMd\Service\PublishedLanguages;
use Voltz\PortfolioMd\Storage\PolylangLanguageGateway;
use Voltz\PortfolioMd\Storage\PolylangMenuBindings;
use Voltz\PortfolioMd\Storage\PolylangPostTypes;
use Voltz\PortfolioMd\Storage\PolylangPublication;
use Voltz\PortfolioMd\Storage\WordPressPublishedLanguagesStore;
use Voltz\PortfolioMd\Storage\YoastPublication;
use Voltz\PortfolioMd\Storage\WordPressContentCatalog;
use Voltz\PortfolioMd\Storage\WordPressContentRepository;
use Voltz\PortfolioMd\Storage\WordPressSkillTermGateway;
use Voltz\PortfolioMd\Taxonomies\TaxonomyRegistrar;

/**
 * Composition root du plugin Portfolio MD.
 *
 * Cette classe est l'unique endroit où :
 *   - les services sont instanciés ;
 *   - les hooks WordPress sont accrochés.
 *
 * Cette centralisation évite l'anti-pattern fréquent dans les plugins
 * WordPress, où chaque fichier enregistre ses propres hooks globalement,
 * créant un graphe de dépendances invisible et difficile à tester.
 *
 * Quand de nouveaux services seront ajoutés (pipeline de conversion
 * Markdown, endpoints REST, etc.), ils seront instanciés dans le
 * constructeur et leurs hooks accrochés dans boot().
 */
final class Plugin
{
    /**
     * Chemin absolu du fichier portfolio-md.php (point d'entrée).
     */
    private string $pluginFile;

    /**
     * Service responsable de l'enregistrement des custom post types.
     */
    private PostTypeRegistrar $postTypeRegistrar;

    /**
     * Service responsable de l'enregistrement des taxonomies.
     */
    private TaxonomyRegistrar $taxonomyRegistrar;

    /**
     * Service responsable de l'enregistrement des meta communs aux articles et projets.
     */
    private CommonMetaRegistrar $commonMetaRegistrar;

    /**
     * Service responsable de l'enregistrement des meta spécifiques aux projets.
     */
    private ProjectMetaRegistrar $projectMetaRegistrar;

    /**
     * Service responsable de l'enregistrement des meta internes du plugin.
     */
    private InternalMetaRegistrar $internalMetaRegistrar;

    /**
     * The import use case, shared by every door (WP-CLI, admin); built on
     * first use because most requests never import anything.
     */
    private ?ContentImportService $importService = null;

    /** The Markdown → Gutenberg renderer, shared by the import and the preview. */
    private ?ContentRenderer $renderer = null;

    /** The post store of the import, shared with the Git synchronization, which retires posts through it. */
    private ?WordPressContentRepository $repository = null;

    /** The bridge to Polylang (ADR-020), shared by the import and the reconciliation command. */
    private ?PolylangLanguageGateway $languageGateway = null;

    /** The option that says which languages are open to the public (ADR-022), and its hooks. */
    private ?WordPressPublishedLanguagesStore $publishedLanguagesStore = null;

    /** The reader of that option, shared by the Polylang and Yoast adapters and the languages command. */
    private ?PublishedLanguages $publishedLanguages = null;

    /** The public read side, shared by the REST routes, the languages command and the Yoast adapter (ADR-022, point 6). */
    private ?WordPressContentCatalog $catalog = null;

    public function __construct(string $pluginFile)
    {
        $this->pluginFile = $pluginFile;

        // Instanciation des services du plugin.
        // À mesure que de nouveaux services s'ajoutent (pipeline, REST, etc.),
        // ils sont instanciés ici.
        $this->postTypeRegistrar = new PostTypeRegistrar();
        $this->taxonomyRegistrar = new TaxonomyRegistrar();
        $this->commonMetaRegistrar = new CommonMetaRegistrar();
        $this->projectMetaRegistrar = new ProjectMetaRegistrar();
        $this->internalMetaRegistrar = new InternalMetaRegistrar();
    }

    /**
     * Démarre le plugin : accroche les hooks WordPress vers les services.
     */
    public function boot(): void
    {
        // Polylang reads this filter early (it caches the list after `after_setup_theme`):
        // hooked at load, harmless when Polylang is absent (ADR-020, lot 2).
        (new PolylangPostTypes())->register();

        // A closed language is served but not announced (ADR-022): the option that says
        // which languages are open, and what Polylang and Yoast must not do about the
        // others. Filters only they apply: harmless when either is absent.
        $this->publishedLanguagesStore()->register();
        (new PolylangPublication($this->publishedLanguages()))->register();
        (new YoastPublication($this->publishedLanguages(), $this->catalog()))->register();

        // Hook 'init' : on enregistre les post types, les taxonomies ET les
        // metas à ce moment, dans l'ordre sûr recommandé par WordPress :
        // les post types d'abord, puis les taxonomies qui s'y attachent,
        // puis les metas. Tous les registrars utilisent priority=10 (défaut),
        // ce qui signifie qu'ils sont appelés dans l'ordre où ils ont été
        // enregistrés ici.
        add_action('init', [$this->postTypeRegistrar, 'register']);
        add_action('init', [$this->taxonomyRegistrar, 'register']);
        add_action('init', [$this->commonMetaRegistrar, 'register']);
        add_action('init', [$this->projectMetaRegistrar, 'register']);
        add_action('init', [$this->internalMetaRegistrar, 'register']);

        // Hooks de cycle de vie du plugin (activation, désactivation).
        register_activation_hook($this->pluginFile, [$this, 'onActivation']);
        register_deactivation_hook($this->pluginFile, [$this, 'onDeactivation']);

        // Commandes WP-CLI : seulement quand le plugin tourne sous WP-CLI.
        if (defined('WP_CLI') && WP_CLI) {
            $this->registerCliCommands();
        }

        // The REST routes: built on `rest_api_init` only, since most requests never reach the API.
        add_action('rest_api_init', [$this, 'registerRoutes']);

        // The admin doors: only on admin requests (edit screens, the paste page).
        if (is_admin()) {
            $this->registerAdmin();
        }
    }

    /**
     * Action `rest_api_init`: the routes of 01-plugin-php.md §7 under
     * `portfolio/v1`. The preview of the admin editor, which renders a
     * document with the same parser and renderer as the import; the
     * public read side, one collection per kind of document and the
     * sitemap, all served by the catalog (Service) over WP_Query (Storage);
     * and the Git door, which brings the files of a push through the same
     * import service as the other doors.
     */
    public function registerRoutes(): void
    {
        $preview = new PreviewService(new FrontmatterParser(), $this->renderer(), new WordPressSkillTermGateway());
        $catalog = $this->catalog();
        $presenter = new DocumentPresenter();
        $sync = new ContentSyncService(
            $this->importService(),
            $this->repository(),
            new SkillTermSynchronizer(new WordPressSkillTermGateway()),
            new SkillsGridLoader(),
        );

        (new RouteRegistrar(
            new PreviewController($preview),
            new CollectionController(ContentType::Article, $catalog, $presenter),
            new CollectionController(ContentType::Project, $catalog, $presenter),
            new CollectionController(ContentType::Page, $catalog, $presenter),
            new SitemapController($catalog, $presenter),
            new SyncController($sync, new SystemClock()),
        ))->register();
    }

    /**
     * Hooks the two admin doors of 01-plugin-php.md §6: the Markdown box on
     * the edit screens of articles and projects (with the screen trimmed of
     * the fields the document owns, and the editor and preview script on
     * top) and the paste page under the plugin menu. Both call the same
     * import service as the WP-CLI command.
     */
    private function registerAdmin(): void
    {
        (new EditScreen())->hook();
        (new EditorMetaBox($this->importService()))->hook();
        (new ImportPage($this->importService()))->hook();
        (new EditorAssets($this->pluginFile))->hook();
    }

    /**
     * Assembles the import chain once: frontmatter parser + Markdown →
     * Gutenberg renderer (Parsing) → import service (Service) → post
     * repository and skill term gateway (Storage). Cheap to build: the
     * syntax highlighter loads its grammars at the first render only.
     */
    private function importService(): ContentImportService
    {
        return $this->importService ??= new ContentImportService(
            new FrontmatterParser(),
            $this->renderer(),
            $this->repository(),
            new WordPressSkillTermGateway(),
            new ConflictResolver(),
            new SystemClock(),
            $this->languageGateway(),
        );
    }

    /**
     * The language bridge (ADR-020): Polylang when it answers, nothing
     * otherwise, decided at each call by the adapter itself, since Polylang's
     * API does not exist yet when the plugin loads.
     */
    private function languageGateway(): PolylangLanguageGateway
    {
        return $this->languageGateway ??= new PolylangLanguageGateway();
    }

    private function publishedLanguagesStore(): WordPressPublishedLanguagesStore
    {
        return $this->publishedLanguagesStore ??= new WordPressPublishedLanguagesStore();
    }

    private function catalog(): WordPressContentCatalog
    {
        return $this->catalog ??= new WordPressContentCatalog();
    }

    private function publishedLanguages(): PublishedLanguages
    {
        return $this->publishedLanguages ??= new PublishedLanguages($this->publishedLanguagesStore());
    }

    private function repository(): WordPressContentRepository
    {
        return $this->repository ??= new WordPressContentRepository();
    }

    private function renderer(): ContentRenderer
    {
        return $this->renderer ??= new ContentRenderer(new MarkdownParser(), new GutenbergTransformer());
    }

    /**
     * Enregistre les commandes `wp portfolio …`.
     *
     * Les services sont assemblés ici, comme les registrars : la commande
     * elle-même ne fait que traduire les arguments et afficher le rapport.
     * Le graphe des compétences est explicite : loader YAML (Parsing) →
     * synchroniseur (Service) → passerelle de termes WordPress (Storage) ;
     * celui de l'import est partagé avec l'admin, voir importService().
     */
    private function registerCliCommands(): void
    {
        \WP_CLI::add_command('portfolio skills', new SkillsCommand(
            new SkillsGridLoader(),
            new SkillTermSynchronizer(new WordPressSkillTermGateway()),
        ));

        \WP_CLI::add_command('portfolio import', new ImportCommand($this->importService()));

        // The guarantee of ADR-020 against Polylang's own screens: the stored language is pushed back.
        \WP_CLI::add_command('portfolio translations', new TranslationsCommand(new TranslationReconciler(
            $this->repository(),
            $this->repository(),
            $this->languageGateway(),
            $this->languageGateway(),
        )));

        // Opening a language to the public (ADR-022): the grid reads the public catalog, the
        // same one the REST routes serve, so that "ready" means "what the site would serve".
        \WP_CLI::add_command('portfolio languages', new LanguagesCommand(
            $this->publishedLanguages(),
            new LanguageReadiness($this->catalog(), new PolylangMenuBindings()),
        ));
    }

    /**
     * Appelé une fois quand l'admin clique sur "Activer" dans la liste
     * des extensions WordPress.
     *
     * Trois étapes :
     *   1. Enregistrer post types, taxonomies et metas (sinon les rewrite
     *      rules flushées juste après n'incluraient pas les nouvelles URLs).
     *   2. Insérer les termes fermés : statuts de projet et référentiels
     *      de compétences.
     *   3. Flush les rewrite rules pour que les URLs /articles/, /projets/,
     *      /tag/, /statut/, /competences/ soient reconnues immédiatement.
     */
    public function onActivation(): void
    {
        $this->postTypeRegistrar->register();
        $this->taxonomyRegistrar->register();
        $this->taxonomyRegistrar->ensureProjectStatusTerms();
        $this->taxonomyRegistrar->ensureSkillReferentialTerms();

        // Les meta n'ont pas d'impact sur les rewrite rules, mais on les enregistre
        // par cohérence avec les autres services.
        $this->commonMetaRegistrar->register();
        $this->projectMetaRegistrar->register();
        $this->internalMetaRegistrar->register();

        flush_rewrite_rules();
    }

    /**
     * Appelé une fois quand l'admin clique sur "Désactiver".
     *
     * Note : on ne supprime pas les contenus créés (articles, projets,
     * termes de taxonomie). La désactivation d'un plugin ne doit jamais
     * détruire de données. Seule la désinstallation complète (uninstall.php
     * à venir plus tard si nécessaire) le ferait.
     */
    public function onDeactivation(): void
    {
        flush_rewrite_rules();
    }
}
