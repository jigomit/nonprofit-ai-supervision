<?php

namespace App\Services;

use App\Enums\AttachmentExtraction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Throwable;
use ZipArchive;

/**
 * Pulls the words out of an uploaded file.
 *
 * Deliberately text, not the file itself. Anthropic will take a PDF natively,
 * but no other provider in this application will and a local model certainly
 * will not — so sending the file would make attachments work for one
 * organization's choice of provider and silently not for another's. Text
 * reaches every model, and keeps TaskPromptBuilder provider-agnostic, which is
 * what has made adding providers cheap.
 *
 * @phpstan-type Extraction array{extraction: AttachmentExtraction, text: string, truncated: bool, failure_reason: string|null}
 */
class AttachmentTextExtractor
{
    /**
     * @return Extraction
     */
    public function extract(UploadedFile $file): array
    {
        try {
            $text = $this->read($file);
        } catch (Throwable $e) {
            return $this->result(AttachmentExtraction::Failed, '', false, Str::limit($e->getMessage(), 200));
        }

        if ($text === null) {
            return $this->result(AttachmentExtraction::Unsupported);
        }

        $text = $this->tidy($text);

        // A scan or a photograph of a document yields nothing here. Saying so
        // is the whole point: a person who believes the model read their 990
        // will review the draft as though it did.
        if ($text === '') {
            return $this->result(AttachmentExtraction::Empty);
        }

        $limit = (int) config('signoff.attachments.max_characters', 40000);
        $truncated = mb_strlen($text) > $limit;

        return $this->result(
            AttachmentExtraction::Extracted,
            $truncated ? mb_substr($text, 0, $limit) : $text,
            $truncated,
        );
    }

    /**
     * Null means this application cannot read the format at all.
     */
    protected function read(UploadedFile $file): ?string
    {
        $path = $file->getRealPath();
        $extension = strtolower($file->getClientOriginalExtension());

        if ($path === false) {
            return null;
        }

        return match (true) {
            $extension === 'pdf' => $this->fromPdf($path),
            in_array($extension, ['docx', 'xlsx', 'pptx'], true) => $this->fromOfficeArchive($path, $extension),
            in_array($extension, ['txt', 'md', 'markdown', 'csv', 'tsv', 'json', 'log'], true) => (string) file_get_contents($path),
            default => null,
        };
    }

    /**
     * Uses poppler's pdftotext where the host has it. No PHP library is
     * pulled in for this: the binary is one apt-get on a server, and a parser
     * dependency is a permanent cost for a format most uploads will not be.
     */
    protected function fromPdf(string $path): ?string
    {
        $binary = (string) config('signoff.attachments.pdftotext', 'pdftotext');

        $result = Process::timeout(60)->run([$binary, '-layout', '-nopgbrk', $path, '-']);

        if ($result->failed()) {
            // A missing binary is a host that cannot read PDFs, not a broken
            // file — and the person should be told which.
            return null;
        }

        return $result->output();
    }

    /**
     * Office formats are zip archives of XML, so the text comes out with the
     * tools PHP already has rather than a library.
     */
    protected function fromOfficeArchive(string $path, string $extension): ?string
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            return null;
        }

        $parts = match ($extension) {
            'docx' => ['word/document.xml'],
            'pptx' => $this->slideNames($zip),
            default => $this->sheetNames($zip),
        };

        $text = [];

        foreach ($parts as $part) {
            $xml = $zip->getFromName($part);

            if (is_string($xml) && $xml !== '') {
                $text[] = $this->xmlToText($xml);
            }
        }

        $zip->close();

        return implode("\n", $text);
    }

    /**
     * A spreadsheet's words live in the shared string table; the cells hold
     * indexes into it. Good enough to give the model the labels and the
     * numbers, which is what a budget export is for.
     *
     * @return array<int, string>
     */
    protected function sheetNames(ZipArchive $zip): array
    {
        $parts = ['xl/sharedStrings.xml'];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);

            if (str_starts_with($name, 'xl/worksheets/sheet')) {
                $parts[] = $name;
            }
        }

        return $parts;
    }

    /**
     * @return array<int, string>
     */
    protected function slideNames(ZipArchive $zip): array
    {
        $parts = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);

            if (str_starts_with($name, 'ppt/slides/slide')) {
                $parts[] = $name;
            }
        }

        return $parts;
    }

    protected function xmlToText(string $xml): string
    {
        // Paragraph and row ends become newlines before the tags are stripped,
        // or a table arrives as one run-on line.
        $xml = preg_replace('/<\/(w:p|a:p|row|w:tr)>/', "\n", $xml) ?? $xml;
        $xml = preg_replace('/<\/(w:tc|c)>/', "\t", $xml) ?? $xml;

        return html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    protected function tidy(string $text): string
    {
        $text = str_replace("\r\n", "\n", $text);
        $text = preg_replace('/[ \t]+$/m', '', $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * @return Extraction
     */
    protected function result(
        AttachmentExtraction $extraction,
        string $text = '',
        bool $truncated = false,
        ?string $failureReason = null,
    ): array {
        return [
            'extraction' => $extraction,
            'text' => $text,
            'truncated' => $truncated,
            'failure_reason' => $failureReason,
        ];
    }
}
