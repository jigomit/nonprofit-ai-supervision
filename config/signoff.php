<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Open registration
    |--------------------------------------------------------------------------
    |
    | Closed unless a host asks for it. An account here can spend the
    | organization's own API key and read work waiting for a decision, so
    | pilot organizations are invited to a team rather than signing themselves
    | up. The route stays registered either way, so the frontend bundle does
    | not differ between environments — EnsureRegistrationIsOpen is what
    | actually refuses.
    |
    */

    'registration' => (bool) env('ALLOW_REGISTRATION', false),

    /*
    |--------------------------------------------------------------------------
    | Attachments
    |--------------------------------------------------------------------------
    |
    | Files an organization gives a task to work from: last year's 990, the
    | budget, an export from the CRM. They hold the organization's own data, so
    | they go on a private disk and are only ever read back by that
    | organization's members.
    |
    | Only the text is sent to the model. `max_characters` caps how much of one
    | file goes into a prompt — at roughly four characters to a token, 40,000
    | is about 10,000 tokens, which a small local model cannot hold alongside a
    | long task.
    |
    | PDFs are read with poppler's pdftotext. Where the host does not have it,
    | a PDF is kept and marked unreadable rather than silently contributing
    | nothing.
    |
    */

    'attachments' => [
        'disk' => env('ATTACHMENT_DISK', 'local'),
        'max_files' => (int) env('ATTACHMENT_MAX_FILES', 5),
        'max_kilobytes' => (int) env('ATTACHMENT_MAX_KILOBYTES', 10240),
        'max_characters' => (int) env('ATTACHMENT_MAX_CHARACTERS', 40000),
        'pdftotext' => env('PDFTOTEXT_BINARY', 'pdftotext'),
        'extensions' => [
            'pdf', 'docx', 'xlsx', 'pptx',
            'txt', 'md', 'markdown', 'csv', 'tsv', 'json', 'log',
        ],
    ],

];
