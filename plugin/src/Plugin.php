<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd;

use Voltz\PortfolioMd\Cli\SkillsCommand;
use Voltz\PortfolioMd\Meta\CommonMetaRegistrar;
use Voltz\PortfolioMd\Meta\InternalMetaRegistrar;
use Voltz\PortfolioMd\Meta\ProjectMetaRegistrar;
use Voltz\PortfolioMd\Parsing\SkillsGridLoader;
use Voltz\PortfolioMd\PostTypes\PostTypeRegistrar;
use Voltz\PortfolioMd\Service\SkillTermSynchronizer;
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
    }

    /**
     * Enregistre les commandes `wp portfolio …`.
     *
     * Les services sont assemblés ici, comme les registrars : la commande
     * elle-même ne fait que traduire les arguments et afficher le rapport.
     * Le graphe est explicite : loader YAML (Parsing) → synchroniseur
     * (Service) → passerelle de termes WordPress (Storage).
     */
    private function registerCliCommands(): void
    {
        \WP_CLI::add_command('portfolio skills', new SkillsCommand(
            new SkillsGridLoader(),
            new SkillTermSynchronizer(new WordPressSkillTermGateway()),
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
