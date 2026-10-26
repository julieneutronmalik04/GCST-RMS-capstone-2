<?php

function overdueConfig(): array {
    return require __DIR__ . '/../config/overdue.php';
}

function overdueDeadlineSql(string $alias = ''): string {
    $prefix = $alias === '' ? '' : $alias . '.';
    return "TIMESTAMP(COALESCE({$prefix}due_date, {$prefix}expected_return_date), COALESCE({$prefix}expected_return_time, '23:59:59'))";
}

function overdueDaysSql(string $alias = ''): string {
    return 'GREATEST(CEIL(TIMESTAMPDIFF(SECOND, ' . overdueDeadlineSql($alias) . ', CURRENT_TIMESTAMP) / 86400), 0)';
}

/** Mark due-time overdue loans, accrue fees, then dispatch outside the transaction. */
function refreshOverdue(PDO $pdo, bool $notify = true, ?callable $sender = null): array {
    $ownTransaction = !$pdo->inTransaction();
    if ($ownTransaction) $pdo->beginTransaction();
    try {
        $new = $pdo->query("SELECT b.id, b.borrower_name, b.equipment_id
            FROM borrowed b
            WHERE b.status = 'Borrowed' AND " . overdueDeadlineSql('b') . " < CURRENT_TIMESTAMP FOR UPDATE")->fetchAll();
        $pdo->exec("UPDATE borrowed SET status = 'Overdue'
            WHERE status = 'Borrowed' AND " . overdueDeadlineSql() . ' < CURRENT_TIMESTAMP');
        $rate = overdueConfig()['daily_penalty'];
        $penalties = $pdo->prepare("UPDATE borrowed
            SET penalty_amount = ROUND(" . overdueDaysSql() . " * ?, 2)
            WHERE status = 'Overdue'");
        $penalties->execute([$rate]);
        foreach ($new as $loan) {
            $equipment = $pdo->prepare('SELECT equip_code FROM equipment WHERE id=?');
            $equipment->execute([$loan['equipment_id']]);
            $loan['equip_code'] = $equipment->fetchColumn();
            foreach (['maintenance_notifications', 'admin_notifications'] as $table) {
                $pdo->prepare("INSERT INTO $table (type, title, body, link_ref)
                    VALUES ('overdue', 'Overdue equipment', ?, ?)")->execute([
                    $loan['borrower_name'] . ' has overdue equipment: ' . $loan['equip_code'] . '.',
                    'borrow:' . $loan['id'],
                ]);
            }
        }
        if ($ownTransaction) $pdo->commit();
    } catch (Throwable $e) {
        if ($ownTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    $result = ['newly_overdue' => count($new), 'daily_penalty' => $rate];
    // A caller-owned transaction must commit before it explicitly dispatches notifications.
    if ($notify && $ownTransaction) {
        try { $result['notifications'] = dispatchOverdueNotifications($pdo, 50, $sender); }
        catch (Throwable $error) {
            error_log('Automatic overdue dispatch: ' . $error->getMessage());
            $result['notification_error'] = 'Overdue records were updated, but notification dispatch needs retry.';
        }
    }
    return $result;
}

function getOverdueRecords(PDO $pdo): array {
    return $pdo->query("SELECT b.id, b.student_faculty_id, b.borrower_name, b.department,
            b.due_date, b.expected_return_time, b.penalty_amount, b.status, e.equip_code, e.name AS equipment_name,
            r.contact_number, r.email, " . overdueDaysSql('b') . " AS days_overdue,
            n.id AS sms_log_id, n.status AS sms_status, n.notification_date AS sms_notification_date,
            CURRENT_DATE AS database_today, n.processed_at AS sms_processed_at, n.error_message AS sms_error
        FROM borrowed b
        JOIN equipment e ON e.id = b.equipment_id
        LEFT JOIN borrowers r ON r.student_faculty_id = b.student_faculty_id
        LEFT JOIN overdue_sms_log n ON n.id = (SELECT MAX(n2.id) FROM overdue_sms_log n2 WHERE n2.borrowed_id = b.id)
        WHERE b.status = 'Overdue' ORDER BY b.due_date, b.id")->fetchAll();
}

/** Read the exact latest saved message. Preview never queues or sends SMS. */
function getOverdueSmsPreview(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare("SELECT n.id AS log_id,n.borrowed_id,n.message,n.contact_number,n.status,n.notification_date,n.processed_at,e.equip_code,b.borrower_name
        FROM overdue_sms_log n JOIN borrowed b ON b.id=n.borrowed_id JOIN equipment e ON e.id=b.equipment_id
        WHERE n.borrowed_id=? ORDER BY n.id DESC LIMIT 1");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/** Live gateway adapter. A provider acknowledgement is not a delivery receipt. */
function sendOverdueSms(string $phone, string $message, ?callable $transport = null): array {
    $config = overdueConfig();
    $phone = preg_replace('/[\s()\-]+/', '', trim($phone));
    if (!preg_match('/^\+?[0-9]{10,15}$/', $phone)) {
        return ['status' => 'Skipped', 'error_message' => 'A valid contact number is required.'];
    }
    if ($config['sms_driver'] === 'log') return ['status' => 'Simulated'];
    if ($config['sms_driver'] !== 'semaphore' || !$config['semaphore_api_key']) {
        return ['status' => 'Failed', 'error_message' => 'SMS provider is not configured.'];
    }
    if ($transport === null && !function_exists('curl_init')) return ['status' => 'Failed', 'error_message' => 'PHP cURL is unavailable.'];
    $fields = ['apikey' => $config['semaphore_api_key'], 'number' => $phone, 'message' => $message];
    if ($config['semaphore_sender_name']) $fields['sendername'] = $config['semaphore_sender_name'];
    $url = 'https://api.semaphore.co/api/v4/messages';
    if ($transport !== null) {
        // Dependency injection is used only by local tests; HTTP callers cannot supply it.
        $response = $transport($url, $fields);
        $body = $response['body']; $code = (int)$response['http_code'];
    } else {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        $body = curl_exec($curl);
        $code = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
    }
    // A timeout/server error can happen after acceptance: never auto-resend an uncertain SMS.
    if ($body === false || $code >= 500) {
        return ['status' => 'Unknown', 'error_message' => 'Provider result is uncertain; check the provider before retrying.'];
    }
    if ($code < 200 || $code >= 300) return ['status' => 'Failed', 'error_message' => 'SMS provider rejected the request.'];
    $data = json_decode($body, true);
    $result = $data[0] ?? null;
    if (is_array($result) && !empty($result['message_id']) && in_array(strtolower($result['status'] ?? ''), ['queued', 'pending', 'sent'], true)) {
        return ['status' => 'Accepted', 'provider_reference' => (string) $result['message_id']];
    }
    if (is_array($result) && in_array(strtolower($result['status'] ?? ''), ['failed', 'refunded'], true)) {
        return ['status' => 'Failed', 'error_message' => 'SMS provider rejected the message.'];
    }
    return ['status' => 'Unknown', 'error_message' => 'Unexpected provider response; check the provider before retrying.'];
}

/** At most one accepted warning per loan/day, even with concurrent requests. */
function notifyOverdueBorrower(PDO $pdo, int $id, bool $retry = false, ?callable $sender = null): array {
    if ($pdo->inTransaction()) throw new LogicException('SMS dispatch requires its own transaction.');
    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare('SELECT id FROM borrowed WHERE id=? FOR UPDATE');
        $lock->execute([$id]);
        $stmt = $pdo->prepare("SELECT b.id, b.borrower_name, b.status, e.equip_code, r.contact_number,
                r.first_name, r.last_name
            FROM borrowed b JOIN equipment e ON e.id = b.equipment_id
            LEFT JOIN borrowers r ON r.student_faculty_id = b.student_faculty_id
            WHERE b.id = ?");
        $stmt->execute([$id]);
        $loan = $stmt->fetch();
        if (!$loan || $loan['status'] !== 'Overdue') throw new InvalidArgumentException('Only overdue loans can receive reminders.');
        $name = $loan['first_name'] !== null ? trim($loan['first_name'] . ' ' . $loan['last_name']) : $loan['borrower_name'];
        $message = "Hello $name, your borrowed equipment {$loan['equip_code']} is overdue. Please return it immediately to avoid penalties.";
        $pdo->prepare("INSERT INTO overdue_sms_log (borrowed_id, notification_date, message)
            VALUES (?, CURRENT_DATE, ?) ON DUPLICATE KEY UPDATE id = id")->execute([$id, $message]);
        $logStmt = $pdo->prepare('SELECT * FROM overdue_sms_log WHERE borrowed_id = ? AND notification_date = CURRENT_DATE FOR UPDATE');
        $logStmt->execute([$id]);
        $log = $logStmt->fetch();
        if ($log['status'] !== 'Pending' && (!$retry || !in_array($log['status'], ['Failed', 'Skipped', 'Simulated'], true))) {
            $pdo->commit();
            return ['status' => $log['status'], 'duplicate' => true];
        }
        $pdo->prepare("UPDATE overdue_sms_log SET status = 'Processing', contact_number = ?, message = ?,
            error_message = NULL, provider_reference = NULL, processed_at = NULL WHERE id = ?")
            ->execute([$loan['contact_number'], $message, $log['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    try {
        $result = ($sender ?? 'sendOverdueSms')((string) ($loan['contact_number'] ?? ''), $message);
    } catch (Throwable $e) {
        $result = ['status' => 'Unknown', 'error_message' => 'SMS result is uncertain; check the provider before retrying.'];
        error_log('Overdue SMS error: ' . $e->getMessage());
    }
    $pdo->prepare('UPDATE overdue_sms_log SET status = ?, provider_reference = ?, error_message = ?, processed_at = NOW() WHERE id = ?')
        ->execute([$result['status'], $result['provider_reference'] ?? null, $result['error_message'] ?? null, $log['id']]);
    return $result + ['duplicate' => false];
}

function dispatchOverdueNotifications(PDO $pdo, int $limit = 50, ?callable $sender = null): array {
    $config = overdueConfig();
    // Leave the queue untouched until a key exists, so the next configured check can send.
    if ($sender === null && $config['sms_driver'] === 'semaphore' && $config['semaphore_api_key'] === '') return [];
    $promotePreview = $config['sms_driver'] === 'semaphore' && $config['semaphore_api_key'] !== '';
    $stmt = $pdo->prepare("SELECT b.id FROM borrowed b
        LEFT JOIN overdue_sms_log n ON n.borrowed_id = b.id AND n.notification_date = CURRENT_DATE
        WHERE b.status = 'Overdue' AND (n.id IS NULL OR n.status = 'Pending'" . ($promotePreview ? " OR n.status = 'Simulated'" : '') . ") ORDER BY b.due_date, b.id LIMIT ?");
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $counts = [];
    foreach ($ids as $id) {
        try {
            $result = notifyOverdueBorrower($pdo, (int) $id, $promotePreview, $sender);
            $counts[$result['status']] = ($counts[$result['status']] ?? 0) + 1;
        } catch (InvalidArgumentException $e) {
            // A loan may have been returned after the queue was read.
        }
    }
    return $counts;
}
