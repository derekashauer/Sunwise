<?php
/**
 * One-time repair: pull back fertilize tasks pushed into the future.
 *
 * Between 0.14.1 and 0.14.2 the water/fertilize interlink was bidirectional and
 * task reconciliation treated a watering as satisfying a fertilize task. Every
 * watering therefore pushed each plant's pending fertilize task further out, so
 * it never came due. The scheduling logic is fixed in 0.14.2, but tasks already
 * in the database still carry the inflated due dates the bug assigned them.
 *
 * This script recomputes those due dates from actual fertilize history:
 *   - never fertilized, or last fertilized at least one interval ago
 *       -> genuinely due, scheduled for today
 *   - fertilized more recently than that
 *       -> scheduled for (last fertilize + interval), the date it should have had
 *
 * Only pending fertilize tasks dated in the future on non-archived plants are
 * considered, so the script is safe to run more than once: a second run finds
 * nothing left to correct.
 *
 * Usage:
 *   php api/scripts/repair_fertilize_dates.php            # dry run, prints planned changes
 *   php api/scripts/repair_fertilize_dates.php --apply    # write the changes
 */

require __DIR__ . '/../config/config.php';
require __DIR__ . '/../config/database.php';

$apply = in_array('--apply', $argv ?? [], true);
$today = date('Y-m-d');

$stmt = db()->prepare('
    SELECT t.id, t.plant_id, t.due_date, t.recurrence, p.name AS plant_name
    FROM tasks t
    JOIN plants p ON t.plant_id = p.id
    WHERE t.task_type = ?
      AND t.completed_at IS NULL
      AND t.skipped_at IS NULL
      AND t.due_date > ?
      AND p.archived_at IS NULL
    ORDER BY p.name
');
$stmt->execute(['fertilize', $today]);
$tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$tasks) {
    echo "No future-dated pending fertilize tasks found. Nothing to repair.\n";
    exit(0);
}

$lastFertilizeStmt = db()->prepare('
    SELECT MAX(performed_at) AS last_fertilize
    FROM care_log
    WHERE plant_id = ? AND action = ?
');
$updateStmt = db()->prepare('UPDATE tasks SET due_date = ? WHERE id = ?');

$changed = 0;
$unchanged = 0;

foreach ($tasks as $task) {
    $recurrence = json_decode($task['recurrence'], true);
    $interval = $recurrence['interval'] ?? 30;

    $lastFertilizeStmt->execute([$task['plant_id'], 'fertilize']);
    $lastFertilize = $lastFertilizeStmt->fetch(PDO::FETCH_ASSOC)['last_fertilize'] ?? null;

    if ($lastFertilize === null) {
        $newDueDate = $today;
        $why = 'never fertilized';
    } else {
        $daysSince = (int)((strtotime($today) - strtotime($lastFertilize)) / 86400);
        if ($daysSince >= $interval) {
            $newDueDate = $today;
            $why = "last fertilized {$daysSince}d ago, interval {$interval}d";
        } else {
            $newDueDate = date('Y-m-d', strtotime($lastFertilize . " +{$interval} days"));
            $why = "last fertilized {$daysSince}d ago, not yet due";
        }
    }

    // Never push a task further out than the bug already had it.
    if ($newDueDate >= $task['due_date']) {
        $unchanged++;
        continue;
    }

    printf(
        "%-30s %s -> %s  (%s)\n",
        mb_strimwidth($task['plant_name'], 0, 30, ''),
        $task['due_date'],
        $newDueDate,
        $why
    );

    if ($apply) {
        $updateStmt->execute([$newDueDate, $task['id']]);
    }
    $changed++;
}

echo "\n";
echo $apply
    ? "Repaired {$changed} fertilize task(s); left {$unchanged} unchanged.\n"
    : "Dry run: {$changed} fertilize task(s) would be repaired, {$unchanged} left unchanged.\n"
      . "Re-run with --apply to write these changes.\n";
