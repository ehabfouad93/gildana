<?php
declare(strict_types=1);

/**
 * Offline test suite.
 *
 * Plain PHP with no PHPUnit, matching the platform's zero-dependency rule, and
 * deliberately needing **no database and no API keys** — it exercises the parts
 * that are easy to break and hard to notice: feed parsing, Arabic normalization,
 * negation, the AI escalation threshold, and dedupe hashing.
 *
 *   php tests/run.php
 *   php tests/run.php --explain "الخدمة مش وحشة بس التأخير فظيع"
 */

$root = dirname(__DIR__);
require $root . '/includes/helpers.php';
require $root . '/includes/i18n.php';
require $root . '/includes/sentiment.php';
require $root . '/includes/keywords.php';

// http.php and ingest.php call config() only at request time, so they are safe
// to load without a config.php present.
require $root . '/includes/http.php';

const FIXTURES = __DIR__ . '/fixtures';

/* ── tiny harness ─────────────────────────────────────────────────────── */

$GLOBALS['passed'] = 0;
$GLOBALS['failed'] = [];

function ok(string $name, bool $condition, string $detail = ''): void
{
    if ($condition) {
        $GLOBALS['passed']++;
        echo "  \033[32m✓\033[0m {$name}\n";
    } else {
        $GLOBALS['failed'][] = $name . ($detail !== '' ? ' — ' . $detail : '');
        echo "  \033[31m✗\033[0m {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

function is_same(string $name, $expected, $actual): void
{
    ok($name, $expected === $actual,
        $expected === $actual ? '' : 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}

function section(string $title): void { echo "\n\033[1m{$title}\033[0m\n"; }

function fixture(string $name): string
{
    $f = FIXTURES . '/' . $name;
    if (!is_file($f)) { echo "  missing fixture: {$name}\n"; exit(1); }
    return (string) file_get_contents($f);
}

/* ── --explain: score one string and show the working ─────────────────── */

$argvList = $argv ?? [];
if (in_array('--explain', $argvList, true)) {
    $i    = (int) array_search('--explain', $argvList, true);
    $text = (string) ($argvList[$i + 1] ?? '');
    if ($text === '') { echo "Usage: php tests/run.php --explain \"some text\"\n"; exit(1); }

    $lang = sent_detect_lang($text);
    $res  = sent_lexicon_score($text, $lang);

    echo "Text       : {$text}\n";
    echo "Normalized : " . sent_normalize($text) . "\n";
    echo "Language   : {$lang}\n";
    echo "Label      : {$res['label']}\n";
    echo "Score      : {$res['score']}\n";
    echo "Confidence : {$res['confidence']}"
       . ($res['confidence'] < SENT_MIN_CONFIDENCE ? "  → would escalate to AI\n" : "  → lexicon is final\n");
    echo "Hits       :\n";
    foreach ($res['hits'] as $h) {
        printf("  %-20s %+.2f%s\n", $h['term'], $h['w'], $h['negated'] ? '  (negated)' : '');
    }
    exit(0);
}

/* ── feed parsing ─────────────────────────────────────────────────────── */

section('Feed parsing');

$gn = lh_parse_feed(fixture('google_news.xml'));
ok('RSS 2.0 parses', $gn['ok'], $gn['error']);
is_same('RSS 2.0 item count', 2, count($gn['items']));
is_same('RSS 2.0 title', 'Gildana launches new campaign platform - Masrawy', $gn['items'][0]['title']);
is_same('RSS 2.0 publisher from <source>', 'Masrawy', $gn['items'][0]['source_name']);
is_same('RSS 2.0 source url', 'https://www.masrawy.com', $gn['items'][0]['source_url']);
ok('RSS 2.0 description is stripped of markup',
    !str_contains($gn['items'][0]['content'], '<a '), $gn['items'][0]['content']);
is_same('RSS 2.0 pubDate → UTC', '2025-09-09 08:30:00', $gn['items'][0]['published_at']);

// Atom's default namespace is the classic trap: a naive $xml->entry finds
// nothing at all, so this is the regression that matters most here.
$atom = lh_parse_feed(fixture('atom.xml'));
ok('Atom parses (default namespace)', $atom['ok'], $atom['error']);
is_same('Atom item count', 2, count($atom['items']));
is_same('Atom prefers rel="alternate" over rel="self"',
    'https://example.com/posts/working-with-gildana', $atom['items'][0]['url']);
is_same('Atom author name', 'Sara Ali', $atom['items'][0]['author_name']);
is_same('Atom updated → UTC', '2025-09-08 14:20:00', $atom['items'][0]['published_at']);
is_same('Atom falls back to a link with no rel',
    'https://example.com/posts/second', $atom['items'][1]['url']);

$rdf = lh_parse_feed(fixture('rdf.xml'));
ok('RSS 1.0 / RDF parses', $rdf['ok'], $rdf['error']);
is_same('RDF item count', 1, count($rdf['items']));
is_same('RDF dc:creator', 'Editor', $rdf['items'][0]['author_name']);
is_same('RDF dc:date → UTC', '2025-09-06 10:00:00', $rdf['items'][0]['published_at']);

$html = lh_parse_feed('<!DOCTYPE html><html><body>Not a feed</body></html>');
ok('An HTML error page is reported clearly, not as a parse error',
    !$html['ok'] && str_contains($html['error'], 'web page'), $html['error']);

$empty = lh_parse_feed('');
ok('Empty response is an error, not a crash', !$empty['ok']);

/* ── JSON connector shapes ────────────────────────────────────────────── */

section('JSON connector payloads');

$reddit = json_decode(fixture('reddit.json'), true);
$kids   = $reddit['data']['children'] ?? [];
is_same('Reddit listing count', 2, count($kids));
is_same('Reddit stable id', 't3_abc123', $kids[0]['data']['name']);
is_same('Reddit permalink → absolute URL',
    'https://www.reddit.com/r/egypt/comments/abc123/anyone_used_gildana/',
    'https://www.reddit.com' . $kids[0]['data']['permalink']);
is_same('Reddit created_utc → UTC datetime',
    '2025-09-09 08:00:00', gmdate('Y-m-d H:i:s', (int) $kids[0]['data']['created_utc']));

$serp = json_decode(fixture('serpapi.json'), true);
is_same('SerpApi news_results count', 1, count($serp['news_results']));
is_same('SerpApi source name', 'AdNews', $serp['news_results'][0]['source']['name']);

/* ── Arabic normalization ─────────────────────────────────────────────── */

section('Arabic normalization');

is_same('Strips tashkeel',            'جيلدانا', sent_normalize('جِيلدَانا'));
is_same('Folds hamza forms (أ إ آ)',  'احمد',    sent_normalize('أحمد'));
is_same('Folds ta-marbuta (ة → ه)',   'خدمه',    sent_normalize('خدمة'));
is_same('Folds alef maqsura (ى → ي)', 'علي',     sent_normalize('على'));
is_same('Strips tatweel',             'جميل',    sent_normalize('جـمـيـل'));
is_same('Arabic-Indic digits → ASCII','2025',    sent_normalize('٢٠٢٥'));
is_same('Collapses 3+ repeats',       'حلوو',    sent_normalize('حلووووو'));
is_same('Lowercases Latin',           'gildana', sent_normalize('GILDANA'));
is_same('Drops URLs',                 'check',   sent_normalize('check https://example.com/x'));

/* ── sentiment scoring ────────────────────────────────────────────────── */

section('Sentiment — polarity');

is_same('Arabic praise → positive',   'positive', sent_lexicon_score('الخدمة ممتازة والتعامل راقي جدا', 'ar')['label']);
is_same('Egyptian praise → positive', 'positive', sent_lexicon_score('المكان تحفة والشغل جامد أوي', 'ar')['label']);
is_same('Arabic complaint → negative','negative', sent_lexicon_score('خدمة سيئة جدا والتعامل وحش خالص', 'ar')['label']);
is_same('Egyptian complaint → negative','negative', sent_lexicon_score('نصابين وحرامية، أسوأ تجربة', 'ar')['label']);
is_same('English praise → positive',  'positive', sent_lexicon_score('Absolutely excellent service, highly recommend', 'en')['label']);
is_same('English complaint → negative','negative', sent_lexicon_score('Terrible experience, a complete waste of money', 'en')['label']);
is_same('Plain fact → neutral',       'neutral',  sent_lexicon_score('The company opened a branch in Cairo today.', 'en')['label']);

section('Sentiment — negation');

is_same('English "not great" flips',  'negative', sent_lexicon_score('The service was not great at all', 'en')['label']);
is_same('Arabic "مش حلو" flips',      'negative', sent_lexicon_score('المنتج مش حلو', 'ar')['label']);
is_same('Arabic "مش وحش" flips up',   'positive', sent_lexicon_score('المنتج مش وحش', 'ar')['label']);

// Egyptian fuses the negation into the word (ما...ش), where a lookback for a
// separate negator sees nothing. sent_strip_circumfix() is what catches it.
is_same('Circumfix ماحبتهوش → stem',  'حب',       sent_strip_circumfix('ماحبتهوش'));
ok('Circumfix negation is detected at all',
    sent_strip_circumfix('ماحبتهوش') !== null);
ok('A non-circumfix word is left alone',
    sent_strip_circumfix('مدرسة') === null);

$softened = sent_lexicon_score('The service was not great', 'en');
$strong   = sent_lexicon_score('The service was terrible', 'en');
ok('Negation softens as well as flips ("not great" ≠ "terrible")',
    abs((float) $softened['score']) < abs((float) $strong['score']),
    "not great={$softened['score']} vs terrible={$strong['score']}");

section('Sentiment — confidence and AI escalation');

$clear = sent_lexicon_score('خدمة ممتازة جدا، تعامل راقي وأنصح بيه بشدة', 'ar');
ok('A clear opinion clears the escalation threshold',
    (float) $clear['confidence'] >= SENT_MIN_CONFIDENCE,
    'confidence=' . $clear['confidence']);

$bare = sent_lexicon_score('افتتحت الشركة فرعا جديدا في القاهرة اليوم', 'ar');
ok('A bare news line escalates to AI',
    (float) $bare['confidence'] < SENT_MIN_CONFIDENCE,
    'confidence=' . $bare['confidence']);

$sarcasm = sent_lexicon_score('ممتاز جدا هههه', 'ar');
ok('A sarcasm marker lowers confidence enough to escalate',
    (float) $sarcasm['confidence'] < SENT_MIN_CONFIDENCE,
    'confidence=' . $sarcasm['confidence']);

$mixed = sent_lexicon_score('المنتج حلو بس الخدمة سيئة', 'ar');
ok('A mixed opinion escalates rather than guessing',
    (float) $mixed['confidence'] < SENT_MIN_CONFIDENCE,
    'confidence=' . $mixed['confidence']);

$emoji = sent_lexicon_score('😡😡 أسوأ خدمة', 'ar');
is_same('Emoji contribute polarity', 'negative', $emoji['label']);

ok('Score stays inside -1..1',
    (float) $strong['score'] >= -1.0 && (float) $strong['score'] <= 1.0);

/* ── keyword matching ─────────────────────────────────────────────────── */

section('Keyword matching');

$kw = ['term' => 'جيلدانا', 'match_mode' => 'phrase', 'required_json' => null, 'excluded_json' => null];
ok('Matches across Arabic spelling variants',
    keyword_matches($kw, 'شركة جِيلدانا للتسويق', ''));

$exact = ['term' => 'amer', 'match_mode' => 'exact', 'required_json' => null, 'excluded_json' => null];
ok('Whole-word mode does not match inside another word',
    !keyword_matches($exact, 'I bought a new camera today', ''));
ok('Whole-word mode still matches the word itself',
    keyword_matches($exact, 'AMER Group announced a project', ''));

$excl = ['term' => 'gildana', 'match_mode' => 'phrase',
         'required_json' => null, 'excluded_json' => json_encode(['recipe'])];
ok('An excluded word drops the mention',
    !keyword_matches($excl, 'Gildana recipe for success', ''));

$req = ['term' => 'gildana', 'match_mode' => 'phrase',
        'required_json' => json_encode(['egypt']), 'excluded_json' => null];
ok('A required word must be present',
    !keyword_matches($req, 'Gildana opened in Dubai', ''));
ok('A required word that is present passes',
    keyword_matches($req, 'Gildana opened in Egypt', ''));

$all = ['term' => 'brand monitoring', 'match_mode' => 'all_words',
        'required_json' => null, 'excluded_json' => null];
ok('all_words matches regardless of order',
    keyword_matches($all, 'monitoring tools for your brand', ''));

/* ── dedupe hashing ───────────────────────────────────────────────────── */

section('Dedupe');

require $root . '/includes/ingest.php';

$a = ingest_hash('google_news', 'https://example.com/article?utm_source=x&utm_medium=y', '');
$b = ingest_hash('google_news', 'https://example.com/article', '');
is_same('Tracking params do not create a duplicate', $a, $b);

$c = ingest_hash('google_news', 'https://www.example.com/article/', '');
is_same('www and a trailing slash do not create a duplicate', $a, $c);

$d = ingest_hash('google_news', 'https://example.com/other', '');
ok('Different articles hash differently', $a !== $d);

$e1 = ingest_hash('reddit', 'https://www.reddit.com/r/x/comments/abc/', 't3_abc123');
$e2 = ingest_hash('reddit', 'https://www.reddit.com/r/x/comments/abc/?sort=new', 't3_abc123');
is_same('A platform id wins over the URL', $e1, $e2);

$f1 = ingest_hash('reddit',      'https://example.com/a', '');
$f2 = ingest_hash('google_news', 'https://example.com/a', '');
ok('The same URL from two connectors is kept separately (uq_dedupe is per connector)',
    $f1 === $f2);

is_same('An empty URL and id hashes to nothing', '', ingest_hash('rss', '', ''));

/* ── canonical URL ────────────────────────────────────────────────────── */

section('URL canonicalisation');

is_same('Drops fbclid', 'https://example.com/p', canonical_url('https://example.com/p?fbclid=123'));
is_same('Keeps meaningful query params',
    'https://example.com/p?id=7', canonical_url('https://example.com/p?id=7&utm_campaign=z'));
is_same('Lowercases the host', 'https://example.com/p', canonical_url('https://EXAMPLE.com/p'));
is_same('Leaves a non-URL alone', 'not a url', canonical_url('not a url'));

/* ── summary ──────────────────────────────────────────────────────────── */

$failed = $GLOBALS['failed'];
echo "\n" . str_repeat('─', 60) . "\n";
if (!$failed) {
    echo "\033[32mAll {$GLOBALS['passed']} checks passed.\033[0m\n";
    exit(0);
}
echo "\033[31m" . count($failed) . " of " . ($GLOBALS['passed'] + count($failed)) . " checks failed:\033[0m\n";
foreach ($failed as $f) echo "  • {$f}\n";
exit(1);
