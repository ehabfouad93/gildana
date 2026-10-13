<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

/**
 * AJAX endpoint for the feed's per-mention actions.
 * Returns the re-rendered card so the browser can swap it in — one renderer,
 * no client-side templating to drift out of sync.
 */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false, 'error' => 'POST only'], 405);
if (!verify_csrf_soft())                   json_out(['ok' => false, 'error' => 'Invalid security token'], 403);

$cid = (int) $CLIENT['id'];
$id  = (int) ($_POST['id'] ?? 0);

// Tenancy check: the client_id filter is what stops one client touching another's row.
$mention = db_row("SELECT * FROM mentions WHERE id = ? AND client_id = ?", [$id, $cid]);
if (!$mention) json_out(['ok' => false, 'error' => 'Mention not found'], 404);

$action  = (string) ($_POST['action'] ?? '');
$value   = (string) ($_POST['value'] ?? '');
$message = '';

switch ($action) {
    case 'read':
        db_run("UPDATE mentions SET is_read = 1 - is_read WHERE id = ? AND client_id = ?", [$id, $cid]);
        break;

    case 'star':
        db_run("UPDATE mentions SET is_starred = 1 - is_starred WHERE id = ? AND client_id = ?", [$id, $cid]);
        break;

    case 'hide':
        db_run("UPDATE mentions SET is_hidden = 1 WHERE id = ? AND client_id = ?", [$id, $cid]);
        json_out(['ok' => true, 'card' => '', 'message' => t('ui.deleted')]);
        // no break — json_out exits

    case 'classify':
        if (!in_array($value, ['positive', 'negative', 'neutral'], true)) {
            json_out(['ok' => false, 'error' => 'Unknown sentiment'], 400);
        }
        // A person's call is final: is_manual locks the engine out of this row
        // for good, until someone explicitly asks for a re-run.
        $scores = ['positive' => 1.0, 'neutral' => 0.0, 'negative' => -1.0];
        db_run(
            "UPDATE mentions
                SET sentiment = ?, sentiment_score = ?, sentiment_confidence = 1.000,
                    sentiment_method = 'manual', sentiment_reason = 'Set manually',
                    sentiment_at = NOW(), is_manual = 1, overridden_by = ?, classify_state = 'done'
              WHERE id = ? AND client_id = ?",
            [$value, $scores[$value], (int) $ME['id'], $id, $cid]
        );
        $message = t('ui.saved');
        break;

    case 'reset':
        // Hand the row back to the engine: it will be picked up by the next
        // lexicon pass and re-escalated to the model if it is unclear.
        db_run(
            "UPDATE mentions
                SET is_manual = 0, overridden_by = NULL, sentiment_method = 'none',
                    sentiment_reason = '', ai_attempts = 0, classify_state = 'pending'
              WHERE id = ? AND client_id = ?",
            [$id, $cid]
        );
        trigger_worker();
        $message = t('src.queued');
        break;

    default:
        json_out(['ok' => false, 'error' => 'Unknown action'], 400);
}

$fresh = db_row("SELECT * FROM mentions WHERE id = ? AND client_id = ?", [$id, $cid]);
$terms = array_column(keywords_active($cid), 'term');

json_out([
    'ok'      => true,
    'card'    => $fresh ? mention_card($fresh, $terms) : '',
    'message' => $message,
]);
