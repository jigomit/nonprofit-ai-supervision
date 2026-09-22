<?php

namespace App\Services;

use App\Enums\SupervisionLevel;
use App\Models\Skill;
use App\Models\SupervisionChange;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * Imports the nonprofit skills library into the catalogue.
 *
 * Every skill in the source library is a single self-contained SKILL.md with no
 * bundled scripts or references, which is why the body can simply be stored and
 * later handed to the model as a cached system block.
 *
 * The import is idempotent: it upserts on slug, and records a SupervisionChange
 * whenever a skill's level moves between runs.
 */
class SkillLibraryImporter
{
    /** Characters per token. Markdown skews token-heavy, so this errs high. */
    private const CHARS_PER_TOKEN = 3.5;

    /**
     * @return array{imported:int, created:int, updated:int, unchanged:int, links:int, changes:array<int,SupervisionChange>}
     */
    public function import(?string $path = null): array
    {
        $path = $path ?? (string) config('skills.path');

        if (! is_dir($path)) {
            throw new RuntimeException("Skill library not found at [{$path}]. Clone ".config('skills.repository').' there first.');
        }

        $files = $this->skillFiles($path);

        if ($files === []) {
            throw new RuntimeException("No SKILL.md files found beneath [{$path}].");
        }

        $commit = $this->resolveCommit($path);

        $created = 0;
        $updated = 0;
        $unchanged = 0;
        $changes = [];
        /** @var array<string, array<int, string>> $crossReferences */
        $crossReferences = [];

        DB::transaction(function () use ($files, $commit, &$created, &$updated, &$unchanged, &$changes, &$crossReferences) {
            foreach ($files as $file) {
                $parsed = $this->parse($file);

                $existing = Skill::query()->where('slug', $parsed['slug'])->first();

                if ($existing !== null && $existing->supervision->value !== $parsed['supervision']) {
                    $changes[] = SupervisionChange::create([
                        'skill_id' => $existing->id,
                        'from_level' => $existing->supervision->value,
                        'to_level' => $parsed['supervision'],
                        'source_commit' => $commit,
                    ]);
                }

                $isUnchanged = $existing !== null
                    && $existing->body_hash === $parsed['body_hash']
                    && $existing->supervision->value === $parsed['supervision'];

                $skill = Skill::query()->updateOrCreate(
                    ['slug' => $parsed['slug']],
                    [...$parsed, 'source_commit' => $commit],
                );

                $crossReferences[$parsed['slug']] = $parsed['references'];

                match (true) {
                    $existing === null => $created++,
                    $isUnchanged => $unchanged++,
                    default => $updated++,
                };

                unset($skill);
            }
        });

        $links = $this->syncCrossReferences($crossReferences);

        return [
            'imported' => count($files),
            'created' => $created,
            'updated' => $updated,
            'unchanged' => $unchanged,
            'links' => $links,
            'changes' => $changes,
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function skillFiles(string $path): array
    {
        $files = collect(File::allFiles($path))
            ->filter(fn ($file) => $file->getFilename() === 'SKILL.md')
            ->map(fn ($file) => $file->getPathname())
            ->values()
            ->all();

        sort($files);

        return $files;
    }

    /**
     * Read the checked-out revision so each row can name the source it came
     * from. Absent a git checkout the import still works, just unattributed.
     */
    protected function resolveCommit(string $path): ?string
    {
        $head = rtrim($path, '/').'/.git';

        if (! is_dir($head)) {
            return null;
        }

        $result = @shell_exec('git -C '.escapeshellarg($path).' rev-parse HEAD 2>/dev/null');

        $commit = is_string($result) ? trim($result) : '';

        return preg_match('/^[0-9a-f]{40}$/', $commit) === 1 ? $commit : null;
    }

    /**
     * @return array{slug:string, name:string, description:string, category:string, is_core:bool, supervision:string, supervision_note:string, failure_modes:array<int,string>, deliverables:array<int,string>, body:string, body_hash:string, token_estimate:int, date_added:?string, last_reviewed:?string, license:?string, references:array<int,string>}
     */
    public function parse(string $file): array
    {
        $raw = (string) file_get_contents($file);

        if (preg_match('/^---\r?\n(.*?)\r?\n---\r?\n(.*)$/s', $raw, $matches) !== 1) {
            throw new RuntimeException("No YAML frontmatter in [{$file}].");
        }

        /** @var array<string, mixed> $meta */
        $meta = Yaml::parse($matches[1]) ?? [];
        $body = trim($matches[2]);

        /** @var array<string, mixed> $metadata */
        $metadata = is_array($meta['metadata'] ?? null) ? $meta['metadata'] : [];

        $supervision = (string) ($metadata['supervision'] ?? '');

        if (SupervisionLevel::tryFrom($supervision) === null) {
            throw new RuntimeException("Unknown supervision level [{$supervision}] in [{$file}].");
        }

        // skills/<category>/<slug>/SKILL.md — the directory is authoritative for
        // the slug, matching the library's own rule that `name` mirrors it.
        $slug = basename(dirname($file));
        $category = basename(dirname($file, 2));

        $description = (string) ($meta['description'] ?? '');

        return [
            'slug' => $slug,
            'name' => (string) ($meta['name'] ?? $slug),
            'description' => $description,
            'category' => $category,
            'is_core' => in_array($category, (array) config('skills.core_categories', []), true),
            'supervision' => $supervision,
            'supervision_note' => (string) ($metadata['supervision_note'] ?? ''),
            // Shown to the person before the run, not only to the model.
            'failure_modes' => $this->bulletsUnder($body, 'Common Failure Modes'),
            'deliverables' => $this->bulletsUnder($body, 'Standard Deliverables'),
            'body' => $body,
            'body_hash' => hash('sha256', $body),
            'token_estimate' => (int) ceil(strlen($body) / self::CHARS_PER_TOKEN),
            'date_added' => $this->date($metadata['date_added'] ?? null),
            'last_reviewed' => $this->date($metadata['last_reviewed'] ?? null),
            'license' => isset($meta['license']) ? (string) $meta['license'] : null,
            'references' => $this->extractReferences($description, $slug),
        ];
    }

    /**
     * The bullet points under one heading of a skill body.
     *
     * The library writes these as wrapped markdown list items, often with a
     * bold label, so a continuation line belongs to the item above it rather
     * than starting a new one. Both bullets and numbered lists appear —
     * failure modes are written with dashes throughout, deliverables are
     * numbered in 13 of the 64 skills that have them.
     *
     * @return array<int, string>
     */
    protected function bulletsUnder(string $body, string $heading): array
    {
        $pattern = '/^##\s+'.preg_quote($heading, '/').'\s*$(.*?)(?=^##\s|\z)/ms';

        if (preg_match($pattern, $body, $matches) !== 1) {
            return [];
        }

        $items = [];

        foreach (preg_split('/\R/', trim($matches[1])) ?: [] as $line) {
            if (preg_match('/^\s*(?:[-*+]|\d+[.)])\s+(.*)$/', $line, $bullet) === 1) {
                $items[] = trim($bullet[1]);

                continue;
            }

            // A wrapped continuation of the bullet above.
            if (trim($line) !== '' && $items !== []) {
                $items[array_key_last($items)] .= ' '.trim($line);
            }
        }

        return array_values(array_filter($items));
    }

    /**
     * Pull "(use nonprofit-x)" pointers out of a description. The library uses
     * these to mark boundaries between adjacent skills.
     *
     * @return array<int, string>
     */
    protected function extractReferences(string $description, string $selfSlug): array
    {
        preg_match_all('/\buse (nonprofit-[a-z0-9-]+)/i', $description, $matches);

        return collect($matches[1])
            ->map(fn (string $slug) => strtolower(rtrim($slug, '-')))
            ->reject(fn (string $slug) => $slug === $selfSlug)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Resolve slug pointers to ids once every skill exists, then replace the
     * link table wholesale so removed references disappear.
     *
     * @param  array<string, array<int, string>>  $crossReferences
     */
    protected function syncCrossReferences(array $crossReferences): int
    {
        $ids = Skill::query()->pluck('id', 'slug');
        $rows = [];

        foreach ($crossReferences as $from => $targets) {
            foreach ($targets as $to) {
                if ($ids->has($from) && $ids->has($to)) {
                    $rows[] = [
                        'from_skill_id' => $ids[$from],
                        'to_skill_id' => $ids[$to],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }
        }

        DB::table('skill_links')->delete();

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('skill_links')->insert($chunk);
        }

        return count($rows);
    }

    protected function date(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value)) === 1 ? trim($value) : null;
    }
}
