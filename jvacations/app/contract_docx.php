<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
require dirname(__DIR__) . '/includes/docx.php';

/** Download a reservation filled into one of the uploaded Word templates. */
$id  = (int) ($_GET['id'] ?? 0);
$ct  = db_row("SELECT ct.*, p.name AS project_name, p.location AS project_location, u.name AS sales_name
                 FROM contracts ct JOIN projects p ON p.id = ct.project_id LEFT JOIN users u ON u.id = ct.created_by WHERE ct.id = ?", [$id]);
if (!$ct) { http_response_code(404); exit(t('err.not_found')); }
$c = client_or_403((int) $ct['client_id'], $ME);
if (!can_view_contract($ME, $c)) { http_response_code(403); exit(t('err.forbidden')); }

$tpl  = db_row("SELECT * FROM contract_templates WHERE id = ? AND active = 1", [(int) ($_GET['tpl'] ?? 0)]);
$path = $tpl ? templates_dir() . '/' . basename($tpl['file_name']) : '';
if (!$tpl || !is_file($path)) { http_response_code(404); exit(t('err.not_found')); }

// Values and the schedule table are written in the template's language.
$ui = $_SESSION['locale'] ?? null;
$_SESSION['locale'] = $tpl['language'];
$rows  = db_all("SELECT seq, due_date, amount FROM instalments WHERE contract_id = ? ORDER BY seq", [$id]);
$bytes = docx_fill($path, contract_vars($ct), docx_schedule_table($rows, (string) $ct['currency'], $tpl['language'] === 'ar'));
if ($ui === null) unset($_SESSION['locale']); else $_SESSION['locale'] = $ui;

log_event((int) $ct['client_id'], 'contract_printed', $tpl['name']);
header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('Content-Disposition: attachment; filename="contract-' . preg_replace('/[^A-Za-z0-9-]/', '', $ct['contract_no']) . '.docx"');
header('Content-Length: ' . strlen($bytes));
echo $bytes;
