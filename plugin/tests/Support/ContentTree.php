<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Tests\Support;

use Symfony\Component\Yaml\Yaml;

/**
 * A throw-away content tree on disk for the tests of the validator: files
 * are written under a temporary directory, with a frontmatter built from a
 * complete, valid sheet and the overrides a test cares about (a key set to
 * null is left out), and the whole tree is removed afterwards.
 */
final class ContentTree
{
    private function __construct(private readonly string $root)
    {
    }

    public static function create(): self
    {
        $root = sys_get_temp_dir() . '/portfolio-md-content-' . bin2hex(random_bytes(6));
        mkdir($root, 0777, true);

        return new self($root);
    }

    public function root(): string
    {
        return $this->root;
    }

    /**
     * @param array<string, mixed> $frontmatter
     */
    public function put(string $relative, array $frontmatter, string $body = ''): void
    {
        $this->putRaw($relative, "---\n" . Yaml::dump($frontmatter, 2) . "---\n" . $body);
    }

    public function putRaw(string $relative, string $content): void
    {
        $path = "{$this->root}/{$relative}";
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $content);
    }

    public function remove(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    /**
     * A complete French project sheet that passes every rule.
     *
     * @param array<string, mixed> $overrides a null value removes the key
     *
     * @return array<string, mixed>
     */
    public static function projectFrontmatter(array $overrides = []): array
    {
        return self::merge([
            'title' => 'MomoCad',
            'slug' => 'momocad',
            'type' => 'project',
            'lang' => 'fr',
            'date' => '2026-10-26',
            'subtitle' => 'Un logiciel de dessin',
            'seo_description' => 'Un éditeur de plans pour la formation.',
            'featured' => true,
            'status' => 'en-cours',
            'role' => 'Développeur unique',
            'period_start' => '2026-09-04',
            'period_end' => '',
            'repo_url' => '',
            'demo_url' => '',
            'client' => '',
            'is_fil_rouge' => false,
            'tags' => ['csharp', 'dotnet'],
            'skills' => ['4.0.2/E07'],
        ], $overrides);
    }

    /**
     * A complete French page that passes every rule.
     *
     * @param array<string, mixed> $overrides a null value removes the key
     *
     * @return array<string, mixed>
     */
    public static function pageFrontmatter(array $overrides = []): array
    {
        return self::merge([
            'title' => 'CV',
            'slug' => 'cv',
            'type' => 'page',
            'lang' => 'fr',
            'date' => '2026-10-26',
            'seo_description' => 'Mon parcours.',
        ], $overrides);
    }

    /** The five sections of a project sheet in a language, plus the fil rouge one on request. */
    public static function projectBody(string $lang = 'fr', bool $filRouge = false): string
    {
        $sections = match ($lang) {
            'de' => ['Das Problem', 'Technische Entscheidungen', 'Was ich gelernt habe', 'In Zahlen', 'Links'],
            'en' => ['The problem', 'Technical decisions', 'What I learned', 'In figures', 'Links'],
            default => ['Le problème', 'Les décisions techniques', "Ce que j'en ai appris", 'En chiffres', 'Liens'],
        };
        if ($filRouge) {
            $sections[] = match ($lang) {
                'de' => 'Zuordnung zu den Etappen des roten Fadens',
                'en' => 'Mapping to the storyline steps',
                default => 'Correspondance avec les étapes du fil rouge',
            };
        }

        return implode('', array_map(static fn (string $title): string => "\n## {$title}\n\nTexte.\n", $sections));
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function merge(array $base, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            if ($value === null) {
                unset($base[$key]);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }
}
