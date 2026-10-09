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
        'status' => [
            'sent' => 'Thanks — your message has been sent.',
            'invalid' => 'Please correct these fields and send the form again: {fields}.',
            'expired' => 'Your session had expired. Please send the form again.',
            'error' => 'Your message was not sent. Please try again later.',
        ],
        'types' => [
            'question' => 'Question',
            'bug' => 'Bug report',
            'idea' => 'Idea or suggestion',
            'other' => 'Other',
        ],
    ],
];
