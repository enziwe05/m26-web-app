<?php
/*
 * Canonical list of operator "companies" a client can be matched to.
 *
 * Site ownership lives as free text in sites.notes with inconsistent spellings
 * (e.g. "MTN sharing", "MTN Site Sharing"; or "Eswatini Mobile" vs the older
 * "Swazi Mobile"). Each company below lists every note substring that belongs
 * to it, so one dropdown choice can cover all of its spellings.
 *
 * The client record stores the company KEY (e.g. 'esm') in clients.match_keyword.
 *
 * Usage:  $companies = require __DIR__ . '/client_companies.php';
 */
return [
    'mtn'  => ['label' => 'MTN',                                  'match' => ['MTN']],
    'esm'  => ['label' => 'Eswatini Mobile (ESM / Swazi Mobile)', 'match' => ['Eswatini Mobile', 'Swazi Mobile', 'ESM']],
    'eptc' => ['label' => 'EPTC',                                 'match' => ['EPTC']],
    'eec'  => ['label' => 'EEC',                                  'match' => ['EEC']],
    'esj'  => ['label' => 'ESJ',                                  'match' => ['ESJ']],
];
