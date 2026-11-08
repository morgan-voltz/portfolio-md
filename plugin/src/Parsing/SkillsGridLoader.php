<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Parsing;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Voltz\PortfolioMd\Domain\InvalidSkillsGridException;
use Voltz\PortfolioMd\Domain\Language;
use Voltz\PortfolioMd\Domain\Patterns;
use Voltz\PortfolioMd\Domain\Referential;
use Voltz\PortfolioMd\Domain\Skill;
use Voltz\PortfolioMd\Domain\SkillsGrid;

/**
 * Reads content/skills.yaml into a validated SkillsGrid.
 *
 * Validation covers what the taxonomy needs to stay consistent: every
 * referential and skill has a code, a slug, a name and a description; slugs
 * are WordPress-safe; a skill code starts with its referential code; codes
 * and slugs are unique across the whole grid, because WordPress enforces
 * slug uniqueness across a taxonomy whatever the parent (wp_unique_term_slug
 * would silently suffix an insert, and wp_update_term refuses the update).
 *
 * Since lot 4 of ADR-020 the grid also carries the languages: a referential
 * says whether its French wordings are `official`, every skill has an `i18n`
 * block with its wording in each other language of the site, and no wording
 * exceeds what a term name may hold. The grid is complete or refused: a
 * missing translation is an error here, never a French wording served by
 * surprise. Unknown keys are refused too, at every level, so that a typo
 * never passes for an optional entry.
 */
final class SkillsGridLoader
{
    private const REFERENTIAL_KEYS = ['code', 'slug', 'name', 'description', 'official', 'skills'];
    private const SKILL_KEYS = ['code', 'slug', 'name', 'description', 'i18n'];
    private const WORDING_KEYS = ['name'];

    /**
     * @throws InvalidSkillsGridException
     */
    public function load(string $path): SkillsGrid
    {
        if (!is_file($path) || !is_readable($path)) {
            throw InvalidSkillsGridException::because("file \"{$path}\" is not readable");
        }

        $yaml = file_get_contents($path);
        if ($yaml === false) {
            throw InvalidSkillsGridException::because("file \"{$path}\" is not readable");
        }

        return $this->fromYaml($yaml);
    }

    /**
     * The text of a skills.yaml, wherever it comes from (a file, a push).
     *
     * @throws InvalidSkillsGridException
     */
    public function fromYaml(string $yaml): SkillsGrid
    {
        try {
            $data = Yaml::parse($yaml);
        } catch (ParseException $exception) {
            throw InvalidSkillsGridException::because('invalid YAML (' . $exception->getMessage() . ')');
        }

        if (!is_array($data)) {
            throw InvalidSkillsGridException::because('the file must be a YAML mapping with a "referentials" list');
        }

        return $this->fromArray($data);
    }

    /**
     * @param array<string, mixed> $data decoded YAML
     *
     * @throws InvalidSkillsGridException
     */
    public function fromArray(array $data): SkillsGrid
    {
        $rawReferentials = $data['referentials'] ?? null;
        if (!is_array($rawReferentials) || !array_is_list($rawReferentials) || $rawReferentials === []) {
            throw InvalidSkillsGridException::because('"referentials" must be a non-empty list');
        }

        $referentials = [];
        $seenCodes = [];
        $seenSlugs = [];

        foreach ($rawReferentials as $index => $raw) {
            if (!is_array($raw)) {
                throw InvalidSkillsGridException::because("referential #{$index} must be a mapping");
            }
            $code = $this->requiredString($raw, 'code', "referential #{$index}");
            if (preg_match(Patterns::REFERENTIAL_CODE, $code) !== 1) {
                throw InvalidSkillsGridException::because("referential code \"{$code}\" must look like 4.0.1");
            }
            $this->onlyKeys($raw, self::REFERENTIAL_KEYS, "referential {$code}");
            $slug = $this->requiredString($raw, 'slug', "referential {$code}");
            if (preg_match(Patterns::SLUG, $slug) !== 1 || strlen($slug) > Patterns::SLUG_MAX_LENGTH) {
                throw InvalidSkillsGridException::because("referential slug \"{$slug}\" is not a slug of at most " . Patterns::SLUG_MAX_LENGTH . ' characters');
            }
            if (isset($seenSlugs[$slug])) {
                throw InvalidSkillsGridException::because("slug \"{$slug}\" is used twice (slugs are unique across the whole taxonomy)");
            }
            $seenSlugs[$slug] = true;
            if (isset($seenCodes[$code])) {
                throw InvalidSkillsGridException::because("code \"{$code}\" is used twice");
            }
            $seenCodes[$code] = true;
            $official = $this->requiredBool($raw, 'official', "referential {$code}");

            $rawSkills = $raw['skills'] ?? null;
            if (!is_array($rawSkills) || !array_is_list($rawSkills) || $rawSkills === []) {
                throw InvalidSkillsGridException::because("referential {$code} must have a non-empty \"skills\" list");
            }

            $skills = [];
            foreach ($rawSkills as $skillIndex => $rawSkill) {
                if (!is_array($rawSkill)) {
                    throw InvalidSkillsGridException::because("skill #{$skillIndex} of referential {$code} must be a mapping");
                }
                $skillCode = $this->requiredString($rawSkill, 'code', "skill #{$skillIndex} of referential {$code}");
                if (preg_match(Patterns::SKILL_CODE, $skillCode) !== 1) {
                    throw InvalidSkillsGridException::because("skill code \"{$skillCode}\" must look like 4.0.1/FR-01");
                }
                if (!str_starts_with($skillCode, $code . '/')) {
                    throw InvalidSkillsGridException::because("skill code \"{$skillCode}\" does not belong to referential {$code}");
                }
                if (isset($seenCodes[$skillCode])) {
                    throw InvalidSkillsGridException::because("code \"{$skillCode}\" is used twice");
                }
                $seenCodes[$skillCode] = true;
                $this->onlyKeys($rawSkill, self::SKILL_KEYS, "skill {$skillCode}");
                $skillSlug = $this->requiredString($rawSkill, 'slug', "skill {$skillCode}");
                if (preg_match(Patterns::SLUG, $skillSlug) !== 1 || strlen($skillSlug) > Patterns::SLUG_MAX_LENGTH) {
                    throw InvalidSkillsGridException::because("skill slug \"{$skillSlug}\" is not a slug of at most " . Patterns::SLUG_MAX_LENGTH . ' characters');
                }
                if (isset($seenSlugs[$skillSlug])) {
                    throw InvalidSkillsGridException::because("slug \"{$skillSlug}\" is used twice (slugs are unique across the whole taxonomy)");
                }
                $seenSlugs[$skillSlug] = true;

                $skills[] = new Skill(
                    $skillCode,
                    $skillSlug,
                    $this->wording($rawSkill, 'name', "skill {$skillCode}"),
                    $this->requiredString($rawSkill, 'description', "skill {$skillCode}"),
                    $this->translations($rawSkill, "skill {$skillCode}"),
                );
            }

            $referentials[] = new Referential(
                $code,
                $slug,
                $this->wording($raw, 'name', "referential {$code}"),
                $this->requiredString($raw, 'description', "referential {$code}"),
                $skills,
                $official,
            );
        }

        return new SkillsGrid($referentials);
    }

    /**
     * The `i18n` block of a skill: one entry per language of the site other
     * than French, each a mapping with the wording under `name`. Every
     * language is required, and none may claim to be official: a
     * translation is never the school's own wording (ADR-020), which the
     * loader says in so many words rather than as an unknown key.
     *
     * @param array<string, mixed> $raw
     *
     * @return array<string, string> language value => wording, in the order of the languages of the site
     */
    private function translations(array $raw, string $where): array
    {
        $languages = array_map(static fn (Language $language): string => $language->value, self::otherLanguages());
        $block = $raw['i18n'] ?? null;
        if (!is_array($block)) {
            throw InvalidSkillsGridException::because("{$where}: \"i18n\" must be a mapping with the languages " . implode(', ', $languages));
        }
        $this->onlyKeys($block, $languages, "{$where}, i18n");

        $translations = [];
        foreach (self::otherLanguages() as $language) {
            $entry = $block[$language->value] ?? null;
            if (!is_array($entry)) {
                throw InvalidSkillsGridException::because("{$where}: \"i18n\" lacks the language \"{$language->value}\"");
            }
            if (array_key_exists('official', $entry)) {
                throw InvalidSkillsGridException::because("{$where}: a translated wording is never official (ADR-020), remove \"official\" from i18n.{$language->value}");
            }
            $this->onlyKeys($entry, self::WORDING_KEYS, "{$where}, i18n.{$language->value}");
            $translations[$language->value] = $this->wording($entry, 'name', "{$where}, i18n.{$language->value}");
        }

        return $translations;
    }

    /**
     * @return list<Language> the languages of the site other than the default one
     */
    private static function otherLanguages(): array
    {
        return array_values(array_filter(Language::cases(), static fn (Language $language): bool => !$language->isDefault()));
    }

    /**
     * A wording a term can hold: a non-empty string within the length of a
     * term name, whatever the language.
     *
     * @param array<string, mixed> $raw
     */
    private function wording(array $raw, string $key, string $where): string
    {
        $wording = $this->requiredString($raw, $key, $where);
        if (mb_strlen($wording) > Patterns::TERM_NAME_MAX_LENGTH) {
            throw InvalidSkillsGridException::because("{$where}: \"{$key}\" exceeds " . Patterns::TERM_NAME_MAX_LENGTH . ' characters, the most a term name holds');
        }

        return $wording;
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function requiredString(array $raw, string $key, string $where): string
    {
        $value = $raw[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw InvalidSkillsGridException::because("{$where}: \"{$key}\" must be a non-empty string");
        }

        return trim($value);
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function requiredBool(array $raw, string $key, string $where): bool
    {
        $value = $raw[$key] ?? null;
        if (!is_bool($value)) {
            throw InvalidSkillsGridException::because("{$where}: \"{$key}\" must be true or false");
        }

        return $value;
    }

    /**
     * @param array<mixed, mixed> $raw
     * @param list<string>        $allowed
     */
    private function onlyKeys(array $raw, array $allowed, string $where): void
    {
        $unknown = array_diff(array_map('strval', array_keys($raw)), $allowed);
        if ($unknown !== []) {
            throw InvalidSkillsGridException::because(
                "{$where}: unknown key(s) \"" . implode('", "', $unknown) . '" (allowed: ' . implode(', ', $allowed) . ')',
            );
        }
    }
}
