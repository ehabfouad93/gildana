<?php
declare(strict_types=1);

/**
 * English sentiment lexicon. Same shape as lexicon_ar.php; weights -1.0..+1.0.
 * Keys are lowercased by sent_normalize() at load time.
 */

return [
    'positive' => [
        'excellent' => 0.9, 'outstanding' => 0.9, 'amazing' => 0.85, 'brilliant' => 0.85,
        'great' => 0.75, 'good' => 0.55, 'love' => 0.8, 'loved' => 0.8, 'perfect' => 0.9,
        'recommend' => 0.75, 'recommended' => 0.75, 'impressed' => 0.8, 'helpful' => 0.65,
        'professional' => 0.7, 'reliable' => 0.7, 'fast' => 0.5, 'friendly' => 0.65,
        'beautiful' => 0.7, 'happy' => 0.7, 'satisfied' => 0.75, 'worth it' => 0.75,
        'best' => 0.8, 'flawless' => 0.9, 'seamless' => 0.75, 'thank you' => 0.5,
        'congratulations' => 0.65, 'award' => 0.6, 'winner' => 0.6, 'success' => 0.65,
        'quality' => 0.5, 'smooth' => 0.55, 'responsive' => 0.6, 'well done' => 0.8,
        'highly recommend' => 0.9, 'great service' => 0.9, 'top notch' => 0.85,
    ],

    'negative' => [
        'terrible' => -0.9, 'awful' => -0.9, 'horrible' => -0.9, 'worst' => -0.95,
        'bad' => -0.65, 'poor' => -0.7, 'disappointed' => -0.8, 'disappointing' => -0.8,
        'useless' => -0.85, 'broken' => -0.75, 'failed' => -0.75, 'failure' => -0.75,
        'scam' => -1.0, 'fraud' => -1.0, 'stole' => -0.95, 'theft' => -0.95,
        'rude' => -0.8, 'ignored' => -0.7, 'delay' => -0.55, 'delayed' => -0.6,
        'refund' => -0.5, 'complaint' => -0.65, 'complaining' => -0.65, 'angry' => -0.8,
        'frustrated' => -0.75, 'unacceptable' => -0.85, 'overpriced' => -0.6,
        'expensive' => -0.4, 'avoid' => -0.85, 'never again' => -0.9, 'waste of money' => -0.95,
        'waste of time' => -0.85, 'not worth' => -0.75, 'no response' => -0.75,
        'lawsuit' => -0.8, 'investigation' => -0.6, 'recall' => -0.65, 'warning' => -0.55,
        'poor service' => -0.9, 'bad experience' => -0.85, 'worst experience' => -1.0,
    ],

    'negators' => ['not', "don't", 'dont', "doesn't", 'doesnt', "didn't", 'didnt',
                   'never', 'no', 'nor', 'cannot', "can't", 'cant', "won't", 'wont',
                   'without', 'hardly', 'barely'],

    'intensifiers' => ['very' => 1.5, 'extremely' => 1.7, 'really' => 1.35, 'so' => 1.25,
                       'absolutely' => 1.6, 'totally' => 1.45, 'highly' => 1.4, 'super' => 1.4],

    'diminishers' => ['somewhat' => 0.6, 'slightly' => 0.55, 'a bit' => 0.6,
                      'kind of' => 0.6, 'fairly' => 0.7, 'rather' => 0.7],

    'ambiguous' => ['lol', 'lmao', 'yeah right', 'sure thing', 'as if', 'supposedly'],
];
