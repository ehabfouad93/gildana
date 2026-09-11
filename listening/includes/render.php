<?php
declare(strict_types=1);

/**
 * Server-rendered fragments shared by the pages and the AJAX endpoints.
 *
 * The feed's actions POST to mention_action.php and get the re-rendered card
 * back as HTML, which the JS swaps in. One renderer, one source of truth — no
 * client-side templating layer to keep in sync.
 */

/**
 * One mention in the feed.
 *
 * @param array $mention the mentions row
 * @param array $terms   keyword terms to highlight
 * @param bool  $compact drop the action bar (dashboard previews, reports)
 */
function mention_card(array $mention, array $terms = [], bool $compact = false): string
{
    $id       = (int) $mention['id'];
    $title    = trim((string) $mention['title']);
    $body     = trim((string) ($mention['snippet'] ?: $mention['content']));
    $url      = (string) $mention['url'];
    $conn     = (string) $mention['connector'];
    $meta     = listen_connector($conn);
    $connLbl  = $meta['label'] ?? $conn;

    $sentiment  = (string) $mention['sentiment'];
    $method     = (string) $mention['sentiment_method'];
    $confidence = (float) $mention['sentiment_confidence'];
    $isManual   = (int) $mention['is_manual'] === 1;

    $classes = 'mention' . ((int) $mention['is_read'] === 0 ? ' unread' : '');

    $out  = '<article class="' . $classes . '" data-mention-id="' . $id . '">';

    /* ── top line: source, time, sentiment ── */
    $out .= '<div class="mention-top">';
    $out .= '<span class="pill gray">' . e($connLbl) . '</span>';
    if ((string) $mention['domain'] !== '') {
        $out .= '<span>' . e((string) $mention['domain']) . '</span>';
    }
    $out .= '<span>·</span><span>' . e(time_ago((string) ($mention['published_at'] ?: $mention['fetched_at']))) . '</span>';
    $out .= '<span style="margin-inline-start:auto">' . sentiment_pill($sentiment, $method, $confidence) . '</span>';
    $out .= '</div>';

    /* ── title and body ──
       dir="auto" matters here: mention text is user data in mixed scripts, so an
       English press release inside an Arabic UI must still render left-to-right,
       and an Egyptian comment inside an English UI right-to-left. */
    if ($title !== '') {
        $safe = keyword_highlight(e($title), $terms);
        $out .= '<div class="mention-title bidi" dir="auto">';
        $out .= $url !== ''
            ? '<a href="' . e($url) . '" target="_blank" rel="noopener noreferrer">' . $safe . '</a>'
            : $safe;
        $out .= '</div>';
    }
    if ($body !== '') {
        $out .= '<div class="mention-body bidi" dir="auto">'
              . keyword_highlight(e(excerpt($body, 320)), $terms) . '</div>';
    }

    /* ── metadata ── */
    $bits = [];
    if ((string) $mention['author_name'] !== '') {
        $author = e((string) $mention['author_name']);
        if ((string) $mention['author_url'] !== '') {
            $author = '<a href="' . e((string) $mention['author_url']) . '" target="_blank" rel="noopener noreferrer">' . $author . '</a>';
        }
        $bits[] = e(t('mn.author')) . ': ' . $author;
    }
    if ((int) $mention['reach'] > 0)  $bits[] = e(t('mn.reach')) . ': ' . e(fmt_num((int) $mention['reach']));
    if ((int) $mention['likes'] > 0)  $bits[] = '♥ ' . e(fmt_num((int) $mention['likes']));
    if ((int) $mention['views'] > 0)  $bits[] = '▶ ' . e(fmt_num((int) $mention['views']));
    if ($bits) $out .= '<div class="mention-meta">' . implode('<span>·</span>', $bits) . '</div>';

    // Be honest on the card when the engine was unsure — an unreviewed guess
    // should not look like a settled fact.
    if (!$isManual && $confidence > 0 && $confidence < SENT_MIN_CONFIDENCE) {
        $out .= '<div class="mention-meta"><span class="pill gold">' . e(t('sent.low_conf')) . '</span></div>';
    }

    /* ── actions ── */
    if (!$compact) {
        $out .= '<div class="mention-foot">';
        $out .= '<span class="sent-set">';
        foreach (['positive' => '👍', 'neutral' => '😐', 'negative' => '👎'] as $val => $glyph) {
            $on = $sentiment === $val ? ' on' : '';
            $out .= '<button type="button" class="icon-btn' . $on . '" data-mention-action="classify"'
                  . ' data-value="' . $val . '" title="' . e(t('sent.' . $val)) . '">' . $glyph . '</button>';
        }
        $out .= '</span>';

        $out .= '<button type="button" class="icon-btn' . ((int) $mention['is_starred'] ? ' on' : '')
              . '" data-mention-action="star" title="' . e(t((int) $mention['is_starred'] ? 'mn.unstar' : 'mn.star')) . '">★</button>';
        $out .= '<button type="button" class="icon-btn" data-mention-action="read" title="'
              . e(t((int) $mention['is_read'] ? 'mn.unread' : 'mn.read')) . '">'
              . ((int) $mention['is_read'] ? '◻' : '◼') . '</button>';
        $out .= '<button type="button" class="icon-btn" data-mention-action="hide" title="' . e(t('mn.hide')) . '">✕</button>';

        if ($isManual) {
            $out .= '<button type="button" class="btn btn-sm btn-ghost" data-mention-action="reset">'
                  . e(t('sent.rerun')) . '</button>';
        }
        if ($url !== '') {
            $out .= '<a class="btn btn-sm btn-ghost" href="' . e($url) . '" target="_blank" rel="noopener noreferrer">'
                  . e(t('mn.open')) . ' ↗</a>';
        }
        $out .= '</div>';
    }

    return $out . '</article>';
}

/** A KPI tile. */
function stat_tile(string $label, string $value, string $tone = '', string $sub = ''): string
{
    return '<div class="stat-tile"><span class="lbl">' . e($label) . '</span>'
         . '<span class="val ' . e($tone) . '">' . e($value) . '</span>'
         . ($sub !== '' ? '<span class="sub">' . e($sub) . '</span>' : '')
         . '</div>';
}

/** Pagination strip. Caps the link count so a huge archive stays usable. */
function pager(int $page, int $pages, array $query = []): string
{
    if ($pages <= 1) return '';
    $out  = '<div class="pager">';
    $show = min($pages, 30);
    for ($i = 1; $i <= $show; $i++) {
        $query['page'] = $i;
        $cls = $i === $page ? ' class="on"' : '';
        $out .= '<a href="?' . e(http_build_query($query)) . '"' . $cls . '>' . $i . '</a>';
    }
    if ($pages > $show) $out .= '<span class="text-muted">…</span>';
    return $out . '</div>';
}
