<?php

namespace App\Console\Commands;

use App\Services\SkillLibraryImporter;
use Illuminate\Console\Command;
use RuntimeException;

class ImportSkills extends Command
{
    protected $signature = 'skills:import {--path= : Import from this directory instead of the configured one}';

    protected $description = 'Import the nonprofit skills library into the catalogue';

    public function handle(SkillLibraryImporter $importer): int
    {
        $path = $this->option('path') ?: (string) config('skills.path');

        try {
            $result = $importer->import($path);
        } catch (RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Imported {$result['imported']} skills from {$path}");

        $this->table(
            ['Created', 'Updated', 'Unchanged', 'Cross-references'],
            [[$result['created'], $result['updated'], $result['unchanged'], $result['links']]],
        );

        if ($result['changes'] === []) {
            return self::SUCCESS;
        }

        // A level change alters the gate on work organizations may already be
        // running, so it is reported loudly rather than folded into the counts.
        $this->newLine();
        $this->components->warn(count($result['changes']).' skill(s) changed supervision level:');

        foreach ($result['changes'] as $change) {
            $direction = $change->isEscalation() ? 'tightened' : 'loosened';

            $this->line(sprintf(
                '  %s  %s → %s (%s)',
                $change->skill->slug,
                $change->from_level->value,
                $change->to_level->value,
                $direction,
            ));
        }

        return self::SUCCESS;
    }
}
