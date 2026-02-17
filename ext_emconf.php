<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'TypoGraph',
    'description' => 'TYPO3 GraphQL Endpoint Extension',
    'category' => 'plugin',
    'author' => 'Frodo Podschwadek',
    'author_email' => 'frodo.podschwadek@adwmainz.de',
    'state' => 'dev',
    'author_company' => 'Academy of Sciences and Literature | Mainz',
    'version' => '0.20.0',
    'constraints' => [
        'depends' => [
            'typo3' => '12.4.0-12.4.99',
        ],
        'conflicts' => [
        ],
        'suggests' => [
        ],
    ],
];
