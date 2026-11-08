<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Parsing;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Voltz\PortfolioMd\Domain\InvalidTagsFileException;
use Voltz\PortfolioMd\Domain\Language;
use Voltz\PortfolioMd\Domain\Patterns;
use Voltz\PortfolioMd\Domain\TagNames;
use Voltz\PortfolioMd\Domain\TechTagName;

/**
 * Reads content/tags.yaml (ADR-024): a `tags` mapping of slug to either a
 * plain name, the same in every language, or a mapping with the French
 * `name` and an `i18n` block holding the German and English wordings. The
 * same rules as the skills grid for a wording: plain text on one line, at
 * most what a term name holds, nothing WordPress would store changed.
 */
final class TagsFileLoader
{
    private const TAG_KEYS = ['name', 'i18n'];

    private const REWRITTEN_BY_WORDPRESS = '/[<>&]|[\x00-\x1F\x7F]|%[0-9A-Fa-f]{2}| {2}/';

    public function load(string $path): TagNames
    {
        if (!is_file($path) || !is_readable($path)) {
            throw InvalidTagsFileException::because("file \"{$path}\" is not readable");
        }

        $yaml = file_get_contents($path);
        if ($yaml === false) {
            throw InvalidTagsFileException::because("file \"{$path}\" is not readable");
        }

        return $this->fromYaml($yaml);
    }

    public function fromYaml(string $yaml): TagNames
    {
        try {
            $data = Yaml::parse($yaml);
        } catch (ParseException $exception) {
            throw InvalidTagsFileException::because('invalid YAML (' . $exception->getMessage() . ')');
        }

        if (!is_array($data)) {
            throw InvalidTagsFileException::because('the file must be a YAML mapping with a "tags" mapping');
        }

        return $this->fromArray($data);
    }

    /**
     * @param array<mixed> $data
     */
    public function fromArray(array $data): TagNames
    {
        $this->onlyKeys($data, ['tags'], 'the file');
        $raw = $data['tags'] ?? null;
        if (!is_array($raw) || $raw === [] || array_is_list($raw)) {
            throw InvalidTagsFileException::because('"tags" must be a non-empty mapping of slug to name');
        }

        $tags = [];
        foreach ($raw as $slug => $entry) {
            $slug = (string) $slug;
            if (preg_match(Patterns::SLUG, $slug) !== 1 || strlen($slug) > Patterns::SLUG_MAX_LENGTH) {
                throw InvalidTagsFileException::because("\"{$slug}\" is not a slug of at most " . Patterns::SLUG_MAX_LENGTH . ' characters (lowercase letters, digits and single hyphens)');
            }
            if (is_string($entry)) {
                $tags[] = new TechTagName($slug, $this->wording($entry, "tag {$slug}"));
                continue;
            }
            if (!is_array($entry) || array_is_list($entry)) {
                throw InvalidTagsFileException::because("tag {$slug} must be a name or a mapping with \"name\" and \"i18n\"");
            }
            $this->onlyKeys($entry, self::TAG_KEYS, "tag {$slug}");
            $name = $entry['name'] ?? null;
            if (!is_string($name)) {
                throw InvalidTagsFileException::because("tag {$slug}: \"name\" must be a non-empty string");
            }
            $tags[] = new TechTagName($slug, $this->wording($name, "tag {$slug}"), $this->translations($entry, "tag {$slug}"));
        }

        return new TagNames($tags);
    }

    /**
     * @param array<mixed> $entry
     *
     * @return array<string, string>
     */
    private function translations(array $entry, string $where): array
    {
        if (!array_key_exists('i18n', $entry)) {
            return [];
        }
        $languages = array_map(static fn (Language $language): string => $language->value, self::otherLanguages());
        $block = $entry['i18n'];
        if (!is_array($block) || array_is_list($block)) {
            throw InvalidTagsFileException::because("{$where}: \"i18n\" must be a mapping with the languages " . implode(', ', $languages));
        }
        $this->onlyKeys($block, $languages, "{$where}, i18n");

        $translations = [];
        foreach (self::otherLanguages() as $language) {
            $wording = $block[$language->value] ?? null;
            if (!is_string($wording)) {
                throw InvalidTagsFileException::because("{$where}: \"i18n\" lacks the language \"{$language->value}\"");
            }
            $translations[$language->value] = $this->wording($wording, "{$where}, i18n.{$language->value}");
        }

        return $translations;
    }

    /**
     * @return list<Language>
     */
    private static function otherLanguages(): array
    {
        return array_values(array_filter(Language::cases(), static fn (Language $language): bool => !$language->isDefault()));
    }

    private function wording(string $raw, string $where): string
    {
        $wording = trim($raw);
        if ($wording === '') {
            throw InvalidTagsFileException::because("{$where}: the name must be a non-empty string");
        }
        if (mb_strlen($wording) > Patterns::TERM_NAME_MAX_LENGTH) {
            throw InvalidTagsFileException::because("{$where}: the name exceeds " . Patterns::TERM_NAME_MAX_LENGTH . ' characters, the most a term name holds');
        }
        if (preg_match(self::REWRITTEN_BY_WORDPRESS, $wording) === 1) {
            throw InvalidTagsFileException::because("{$where}: the name must be plain text on one line (no markup, no ampersand, no control character, no %xx octet, no double space): WordPress would store it changed");
        }

        return $wording;
    }

    /**
     * @param array<mixed> $raw
     * @param list<string> $allowed
     */
    private function onlyKeys(array $raw, array $allowed, string $where): void
    {
        $unknown = array_diff(array_map('strval', array_keys($raw)), $allowed);
        if ($unknown !== []) {
            throw InvalidTagsFileException::because("{$where} has unknown key(s): " . implode(', ', $unknown) . ' (allowed: ' . implode(', ', $allowed) . ')');
        }
    }
}
