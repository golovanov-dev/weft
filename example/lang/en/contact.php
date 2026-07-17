<?php

return [
    'contact' => [
        'meta' => [
            'title' => 'Feedback',
            'description' => 'Ask a question about Weft, report a bug in the docs or suggest an improvement.',
        ],
        'eyebrow' => 'Feedback',
        'title' => 'Question, bug or idea?',
        'lead' => 'This form is also a live example: honeypot, CSRF, rate limiting and reCAPTCHA v3 all run behind it. Read its source in api/contact.php.',
        'form' => [
            'name' => 'Name',
            'email' => 'Email',
            'type' => 'Type',
            'message' => 'Message',
            'project_url' => 'Related URL',
            'submit' => 'Send',
        ],
        'types' => [
            'question' => 'Question',
            'bug' => 'Bug report',
            'idea' => 'Idea or suggestion',
            'other' => 'Other',
        ],
    ],
];
