<?php
/**
 * Task Controller
 */

class TaskController
{
    /**
     * Get SQL condition for plants user can access (owned + shared via household)
     */
    private function getAccessiblePlantCondition(int $userId): string
    {
        $stmt = db()->prepare('SELECT household_id FROM household_members WHERE user_id = ?');
        $stmt->execute([$userId]);
        $households = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($households)) {
            return "p.user_id = {$userId}";
        }

        $householdList = implode(',', array_map('intval', $households));
        return "(p.user_id = {$userId} OR p.id IN (SELECT plant_id FROM household_plants WHERE household_id IN ({$householdList})))";
    }

    /**
     * Check if user can access a specific plant (owns it or has household access)
     */
    private function canAccessPlant(int $plantId, int $userId): bool
    {
        // Check ownership
        $stmt = db()->prepare('SELECT user_id FROM plants WHERE id = ?');
        $stmt->execute([$plantId]);
        $plant = $stmt->fetch();

        if (!$plant) return false;
        if ($plant['user_id'] == $userId) return true;

        // Check household access
        $stmt = db()->prepare('
            SELECT 1 FROM household_plants hp
            JOIN household_members hm ON hp.household_id = hm.household_id
            WHERE hp.plant_id = ? AND hm.user_id = ?
        ');
        $stmt->execute([$plantId, $userId]);
        return (bool)$stmt->fetch();
    }

    /**
     * Get disabled task types for a user
     */
    private function getDisabledTaskTypes(int $userId): array
    {
        $stmt = db()->prepare('SELECT task_type FROM task_type_settings WHERE user_id = ? AND enabled = 0');
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Build SQL exclusion clause for disabled task types
     */
    private function getDisabledTaskTypeCondition(int $userId): string
    {
        $disabled = $this->getDisabledTaskTypes($userId);
        if (empty($disabled)) {
            return '1=1';
        }
        $quoted = implode(',', array_map(fn($t) => "'" . addslashes($t) . "'", $disabled));
        return "t.task_type NOT IN ({$quoted})";
    }

    /**
     * Get today's tasks
     */
    public function today(array $params, array $body, ?int $userId): array
    {
        $today = date('Y-m-d');
        $accessCondition = $this->getAccessiblePlantCondition($userId);
        $taskTypeCondition = $this->getDisabledTaskTypeCondition($userId);

        // Daily reconciliation: if a plant has a pending water/fertilize task AND was
        // recently watered/fertilized (via care_log), skip the task if it's too soon
        $this->reconcileOverdueTasks($userId, $accessCondition);

        $stmt = db()->query("
            SELECT t.*,
                   p.name as plant_name,
                   p.location as plant_location,
                   p.species as plant_species,
                   p.light_condition as plant_light_condition,
                   CAST(COALESCE(p.is_propagation, 0) AS INTEGER) as plant_is_propagation,
                   p.soil_type as plant_soil_type,
                   (SELECT filename FROM photos WHERE plant_id = p.id ORDER BY uploaded_at DESC LIMIT 1) as plant_thumbnail,
                   CASE WHEN p.user_id = {$userId} THEN 1 ELSE 0 END as is_owned,
                   CASE WHEN t.completed_by_user_id IS NOT NULL THEN
                       (SELECT COALESCE(u.display_name, SUBSTR(u.email, 1, INSTR(u.email, '@') - 1))
                        FROM users u WHERE u.id = t.completed_by_user_id)
                   ELSE NULL END as completed_by_name
            FROM tasks t
            JOIN plants p ON t.plant_id = p.id
            WHERE {$accessCondition}
              AND t.due_date <= '{$today}'
              AND t.skipped_at IS NULL
              AND p.archived_at IS NULL
              AND {$taskTypeCondition}
            ORDER BY
                CASE WHEN t.completed_at IS NULL THEN 0 ELSE 1 END,
                CASE t.priority
                    WHEN 'urgent' THEN 1
                    WHEN 'high' THEN 2
                    WHEN 'normal' THEN 3
                    WHEN 'low' THEN 4
                    ELSE 5
                END,
                t.due_date ASC
        ");

        $tasks = $stmt->fetchAll();

        return ['tasks' => $tasks];
    }

    /**
     * Get upcoming tasks (next 7 days)
     */
    public function upcoming(array $params, array $body, ?int $userId): array
    {
        $today = date('Y-m-d');
        $endDate = date('Y-m-d', strtotime('+7 days'));
        $accessCondition = $this->getAccessiblePlantCondition($userId);
        $taskTypeCondition = $this->getDisabledTaskTypeCondition($userId);

        $stmt = db()->query("
            SELECT t.*,
                   p.name as plant_name,
                   p.location as plant_location,
                   p.species as species,
                   p.pot_size as pot_size,
                   p.soil_type as soil_type,
                   (SELECT filename FROM photos WHERE plant_id = p.id ORDER BY uploaded_at DESC LIMIT 1) as plant_thumbnail,
                   CASE WHEN p.user_id = {$userId} THEN 1 ELSE 0 END as is_owned
            FROM tasks t
            JOIN plants p ON t.plant_id = p.id
            WHERE {$accessCondition}
              AND t.due_date BETWEEN '{$today}' AND '{$endDate}'
              AND t.completed_at IS NULL
              AND t.skipped_at IS NULL
              AND {$taskTypeCondition}
            ORDER BY t.due_date ASC, t.priority ASC
        ");

        return ['tasks' => $stmt->fetchAll()];
    }

    /**
     * Get tasks for specific plant
     */
    public function forPlant(array $params, array $body, ?int $userId): array
    {
        $plantId = $params['id'];

        // Verify plant access (owned or shared via household)
        if (!$this->canAccessPlant($plantId, $userId)) {
            return ['status' => 404, 'data' => ['error' => 'Plant not found']];
        }

        $taskTypeCondition = $this->getDisabledTaskTypeCondition($userId);

        $stmt = db()->prepare("
            SELECT t.*,
                   CASE WHEN t.completed_by_user_id IS NOT NULL THEN
                       (SELECT COALESCE(u.display_name, SUBSTR(u.email, 1, INSTR(u.email, '@') - 1))
                        FROM users u WHERE u.id = t.completed_by_user_id)
                   ELSE NULL END as completed_by_name
            FROM tasks t
            WHERE t.plant_id = ?
              AND t.skipped_at IS NULL
              AND {$taskTypeCondition}
            ORDER BY
                CASE WHEN t.completed_at IS NULL THEN 0 ELSE 1 END,
                t.due_date ASC
            LIMIT 20
        ");
        $stmt->execute([$plantId]);

        return ['tasks' => $stmt->fetchAll()];
    }

    /**
     * Complete a task
     */
    public function complete(array $params, array $body, ?int $userId): array
    {
        $taskId = $params['id'];
        $notes = $body['notes'] ?? null;
        $checkData = $body['check_data'] ?? null;

        // Get task details
        $stmt = db()->prepare('
            SELECT t.*, p.user_id as owner_id
            FROM tasks t
            JOIN plants p ON t.plant_id = p.id
            WHERE t.id = ?
        ');
        $stmt->execute([$taskId]);
        $task = $stmt->fetch();

        if (!$task) {
            return ['status' => 404, 'data' => ['error' => 'Task not found']];
        }

        // Verify access (owned or shared via household)
        if (!$this->canAccessPlant($task['plant_id'], $userId)) {
            return ['status' => 404, 'data' => ['error' => 'Task not found']];
        }

        // Mark as completed with attribution
        $stmt = db()->prepare('UPDATE tasks SET completed_at = datetime("now"), notes = ?, completed_by_user_id = ? WHERE id = ?');
        $stmt->execute([$notes, $userId, $taskId]);

        // Log to care log with performer attribution and check data
        $checkDataJson = $checkData ? json_encode($checkData) : null;
        $stmt = db()->prepare('
            INSERT INTO care_log (plant_id, task_id, action, notes, performed_by_user_id, check_data)
            VALUES (?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $task['plant_id'],
            $taskId,
            $task['task_type'],
            $notes,
            $userId,
            $checkDataJson
        ]);

        $this->runCompletionSideEffects($task);

        // Get updated task
        $stmt = db()->prepare('SELECT * FROM tasks WHERE id = ?');
        $stmt->execute([$taskId]);
        $updatedTask = $stmt->fetch();

        // Analyze check data if this is a check task
        $insights = [];
        $carePlanUpdated = false;
        if ($task['task_type'] === 'check' && $checkData) {
            $analysisResult = $this->analyzeCheckDataAndUpdateCarePlan($checkData, $task['plant_id'], $userId);
            $insights = $analysisResult['insights'];
            $carePlanUpdated = $analysisResult['care_plan_updated'];
        }

        $response = ['task' => $updatedTask];
        if (!empty($insights)) {
            $response['insights'] = $insights;
        }
        if ($carePlanUpdated) {
            $response['care_plan_updated'] = true;
        }

        return $response;
    }

    /**
     * Analyze check data for concerning patterns
     */
    private function analyzeCheckData(array $checkData, int $plantId, int $userId): array
    {
        $insights = [];
        $triggerAI = false;
        $concernCount = 0;

        // Local pattern detection - immediate alerts

        // Pests detected - urgent
        if (!empty($checkData['pests_observed'])) {
            $pestMsg = 'Pests detected!';
            if (!empty($checkData['pest_notes'])) {
                $pestMsg .= ' (' . $checkData['pest_notes'] . ')';
            }
            $pestMsg .= ' Consider treatment options.';
            $insights[] = ['type' => 'urgent', 'message' => $pestMsg];
            $triggerAI = true;
            $concernCount++;
        }

        // Low health rating - warning
        $health = $checkData['general_health'] ?? 5;
        if ($health <= 2) {
            $insights[] = ['type' => 'warning', 'message' => 'Plant health is poor. Consider reviewing care routine and environment.'];
            $triggerAI = true;
            $concernCount++;
        } elseif ($health === 3) {
            $concernCount++;
        }

        // Moisture extremes
        $moisture = $checkData['moisture_level'] ?? 5;
        if ($moisture <= 2) {
            $insights[] = ['type' => 'info', 'message' => 'Soil is very dry. May need watering soon.'];
            $concernCount++;
        } elseif ($moisture >= 9) {
            $insights[] = ['type' => 'warning', 'message' => 'Soil is very wet. Watch for signs of overwatering.'];
            $concernCount++;
        }

        // Stress indicators
        if (!empty($checkData['yellowing_leaves'])) {
            $insights[] = ['type' => 'info', 'message' => 'Yellowing leaves may indicate overwatering, nutrient deficiency, or natural aging.'];
            $concernCount++;
        }

        if (!empty($checkData['brown_tips'])) {
            $insights[] = ['type' => 'info', 'message' => 'Brown leaf tips often indicate low humidity, underwatering, or salt buildup.'];
            $concernCount++;
        }

        // Multiple concerns warrant AI analysis
        if ($concernCount >= 2) {
            $triggerAI = true;
        }

        // Trigger AI analysis for deeper insights if needed
        if ($triggerAI) {
            try {
                $aiInsight = $this->getAICheckInsight($checkData, $plantId, $userId);
                if ($aiInsight) {
                    $insights[] = $aiInsight;
                }
            } catch (Exception $e) {
                error_log('AI check analysis failed: ' . $e->getMessage());
                // Don't fail the whole response if AI fails
            }
        }

        // Positive feedback if everything looks good
        if (empty($insights) && $health >= 4 && !empty($checkData['new_growth'])) {
            $insights[] = ['type' => 'success', 'message' => 'Plant is thriving! New growth observed and health looks great.'];
        }

        return $insights;
    }

    /**
     * Analyze check data and potentially trigger care plan update
     * This wraps analyzeCheckData and adds care plan regeneration logic
     */
    private function analyzeCheckDataAndUpdateCarePlan(array $checkData, int $plantId, int $userId): array
    {
        $insights = $this->analyzeCheckData($checkData, $plantId, $userId);
        $carePlanUpdated = false;

        // Determine if the care plan should be updated based on check data
        $shouldUpdatePlan = false;

        $moisture = $checkData['moisture_level'] ?? null;
        $health = $checkData['general_health'] ?? 3;

        // Very dry soil (moisture <= 2) - may need more frequent watering
        if ($moisture !== null && $moisture <= 2) {
            // Check if next water task is far away
            $stmt = db()->prepare('
                SELECT due_date FROM tasks
                WHERE plant_id = ? AND task_type = "water" AND completed_at IS NULL AND skipped_at IS NULL
                ORDER BY due_date ASC LIMIT 1
            ');
            $stmt->execute([$plantId]);
            $nextWater = $stmt->fetch();

            if ($nextWater) {
                $daysUntilWater = (strtotime($nextWater['due_date']) - strtotime('today')) / 86400;
                // If soil is bone dry and watering is 3+ days away, update plan
                if ($daysUntilWater >= 3) {
                    $shouldUpdatePlan = true;
                    $insights[] = [
                        'type' => 'warning',
                        'message' => "Soil is very dry but next watering isn't for {$daysUntilWater} days. Regenerating care plan to adjust watering frequency.",
                        'suggestion' => ['action' => 'adjust_watering', 'details' => 'Care plan will be updated with more frequent watering']
                    ];
                }
            }
        }

        // Wet soil (moisture >= 7) - auto-skip today's water task if one exists
        if ($moisture !== null && $moisture >= 7) {
            $today = date('Y-m-d');
            $stmt = db()->prepare('
                SELECT id, plant_id, task_type, due_date, recurrence, care_plan_id, instructions, priority
                FROM tasks
                WHERE plant_id = ? AND task_type = "water"
                  AND due_date <= ? AND completed_at IS NULL AND skipped_at IS NULL
                LIMIT 1
            ');
            $stmt->execute([$plantId, $today]);
            $todayWater = $stmt->fetch();

            if ($todayWater) {
                // Auto-skip the water task
                $stmt = db()->prepare('UPDATE tasks SET skipped_at = datetime("now"), skip_reason = ? WHERE id = ?');
                $stmt->execute(['Auto-skipped: check reported high moisture (' . $moisture . '/10)', $todayWater['id']]);

                // Generate next occurrence so the schedule continues (use skip mode for shorter delay)
                if ($todayWater['recurrence']) {
                    $this->generateNextOccurrence($todayWater, 'skip');
                }

                $insights[] = [
                    'type' => 'info',
                    'message' => 'Soil moisture is high (' . $moisture . '/10). Today\'s watering task has been automatically skipped.'
                ];
            }

            // Very wet (>= 9) and next watering is soon - consider plan update
            if ($moisture >= 9) {
                $stmt = db()->prepare('
                    SELECT due_date FROM tasks
                    WHERE plant_id = ? AND task_type = "water" AND completed_at IS NULL AND skipped_at IS NULL
                    ORDER BY due_date ASC LIMIT 1
                ');
                $stmt->execute([$plantId]);
                $nextWater = $stmt->fetch();

                if ($nextWater) {
                    $daysUntilWater = (strtotime($nextWater['due_date']) - strtotime('today')) / 86400;
                    if ($daysUntilWater <= 2) {
                        $shouldUpdatePlan = true;
                        $insights[] = [
                            'type' => 'warning',
                            'message' => 'Soil is very wet and watering is scheduled soon. Regenerating care plan to reduce watering frequency.',
                            'suggestion' => ['action' => 'adjust_watering', 'details' => 'Care plan will be updated with less frequent watering']
                        ];
                    }
                }
            }
        }

        // Critical health (health <= 2) or pests - always review care plan
        if ($health <= 2 || !empty($checkData['pests_observed'])) {
            $shouldUpdatePlan = true;
        }

        // Trigger care plan regeneration
        if ($shouldUpdatePlan) {
            try {
                $carePlanController = new CarePlanController();
                $carePlanController->generateCarePlan($plantId, $userId);
                $carePlanUpdated = true;

                if (!$this->hasInsightType($insights, 'warning') && !$this->hasInsightType($insights, 'urgent')) {
                    $insights[] = [
                        'type' => 'info',
                        'message' => 'Care plan has been automatically updated based on your check data.',
                        'suggestion' => ['action' => 'none', 'details' => null]
                    ];
                }
            } catch (Exception $e) {
                error_log('Auto care plan update failed: ' . $e->getMessage());
            }
        }

        return ['insights' => $insights, 'care_plan_updated' => $carePlanUpdated];
    }

    /**
     * Check if insights array contains a specific type
     */
    private function hasInsightType(array $insights, string $type): bool
    {
        foreach ($insights as $insight) {
            if (($insight['type'] ?? '') === $type) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get AI-powered insight for check data
     */
    private function getAICheckInsight(array $checkData, int $plantId, int $userId): ?array
    {
        // Get plant info
        $stmt = db()->prepare('SELECT name, species, notes FROM plants WHERE id = ?');
        $stmt->execute([$plantId]);
        $plant = $stmt->fetch();

        if (!$plant) {
            return null;
        }

        // Get recent check history
        $stmt = db()->prepare('
            SELECT check_data, performed_at
            FROM care_log
            WHERE plant_id = ? AND action = "check" AND check_data IS NOT NULL
            ORDER BY performed_at DESC
            LIMIT 5
        ');
        $stmt->execute([$plantId]);
        $rawChecks = $stmt->fetchAll();

        // Decode JSON check_data for each record
        $recentChecks = [];
        foreach ($rawChecks as $check) {
            $decoded = json_decode($check['check_data'], true);
            if ($decoded) {
                $decoded['performed_at'] = $check['performed_at'];
                $recentChecks[] = $decoded;
            }
        }

        // Get current care tasks
        $stmt = db()->prepare('
            SELECT task_type, due_date, recurrence
            FROM tasks
            WHERE plant_id = ? AND completed_at IS NULL
            ORDER BY due_date
            LIMIT 10
        ');
        $stmt->execute([$plantId]);
        $rawTasks = $stmt->fetchAll();

        // Extract recurrence_interval from JSON recurrence field
        $currentTasks = [];
        foreach ($rawTasks as $task) {
            $recurrence = json_decode($task['recurrence'], true);
            $currentTasks[] = [
                'task_type' => $task['task_type'],
                'due_date' => $task['due_date'],
                'recurrence_interval' => $recurrence['interval'] ?? null
            ];
        }

        // Use user's configured AI service
        $aiService = AIServiceFactory::getForUser($userId);
        $result = $aiService->analyzeCheckData($checkData, $recentChecks, $plant, $currentTasks);

        if ($result) {
            ClaudeService::logUsage($userId, 'check_analysis', true, null, $aiService->getModel());
            return $result;
        }

        return null;
    }

    /**
     * Complete multiple tasks at once (bulk action)
     */
    public function bulkComplete(array $params, array $body, ?int $userId): array
    {
        $taskIds = $body['task_ids'] ?? [];
        $notes = $body['notes'] ?? null;

        if (empty($taskIds) || !is_array($taskIds)) {
            return ['status' => 400, 'data' => ['error' => 'No task IDs provided']];
        }

        // Limit to 50 tasks per request
        $taskIds = array_slice($taskIds, 0, 50);

        $completed = [];
        $failed = [];

        foreach ($taskIds as $taskId) {
            // Get task details
            $stmt = db()->prepare('
                SELECT t.*, p.user_id as owner_id
                FROM tasks t
                JOIN plants p ON t.plant_id = p.id
                WHERE t.id = ?
            ');
            $stmt->execute([$taskId]);
            $task = $stmt->fetch();

            if (!$task || !$this->canAccessPlant($task['plant_id'], $userId)) {
                $failed[] = $taskId;
                continue;
            }

            if ($task['completed_at']) {
                // Already completed, skip
                $completed[] = $taskId;
                continue;
            }

            // Mark as completed with attribution
            $stmt = db()->prepare('UPDATE tasks SET completed_at = datetime("now"), notes = ?, completed_by_user_id = ? WHERE id = ?');
            $stmt->execute([$notes, $userId, $taskId]);

            // Log to care log with performer attribution
            $stmt = db()->prepare('
                INSERT INTO care_log (plant_id, task_id, action, notes, performed_by_user_id)
                VALUES (?, ?, ?, ?, ?)
            ');
            $stmt->execute([
                $task['plant_id'],
                $taskId,
                $task['task_type'],
                $notes,
                $userId
            ]);

            // Generate next occurrence if recurring
            if ($task['recurrence']) {
                $this->generateNextOccurrence($task);
            }

            $completed[] = $taskId;
        }

        return [
            'completed' => $completed,
            'failed' => $failed,
            'count' => count($completed)
        ];
    }

    /**
     * Skip multiple tasks at once (bulk action)
     */
    public function bulkSkip(array $params, array $body, ?int $userId): array
    {
        $taskIds = $body['task_ids'] ?? [];
        $reason = $body['reason'] ?? null;

        if (empty($taskIds) || !is_array($taskIds)) {
            return ['status' => 400, 'data' => ['error' => 'No task IDs provided']];
        }

        // Limit to 50 tasks per request
        $taskIds = array_slice($taskIds, 0, 50);

        $skipped = [];
        $failed = [];

        foreach ($taskIds as $taskId) {
            // Get task details
            $stmt = db()->prepare('
                SELECT t.*, p.user_id as owner_id
                FROM tasks t
                JOIN plants p ON t.plant_id = p.id
                WHERE t.id = ?
            ');
            $stmt->execute([$taskId]);
            $task = $stmt->fetch();

            if (!$task || !$this->canAccessPlant($task['plant_id'], $userId)) {
                $failed[] = $taskId;
                continue;
            }

            if ($task['skipped_at'] || $task['completed_at']) {
                // Already skipped or completed, skip
                $skipped[] = $taskId;
                continue;
            }

            // Mark as skipped
            $stmt = db()->prepare('UPDATE tasks SET skipped_at = datetime("now"), skip_reason = ? WHERE id = ?');
            $stmt->execute([$reason, $taskId]);

            // Log to care log with performer attribution
            $stmt = db()->prepare('
                INSERT INTO care_log (plant_id, task_id, action, notes, outcome, performed_by_user_id)
                VALUES (?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([
                $task['plant_id'],
                $taskId,
                'skipped_' . $task['task_type'],
                $reason,
                'neutral',
                $userId
            ]);

            // Generate next occurrence if recurring
            if ($task['recurrence']) {
                $this->generateNextOccurrence($task);
            }

            $skipped[] = $taskId;
        }

        return [
            'skipped' => $skipped,
            'failed' => $failed,
            'count' => count($skipped)
        ];
    }

    /**
     * Skip a task
     */
    public function skip(array $params, array $body, ?int $userId): array
    {
        $taskId = $params['id'];
        $reason = $body['reason'] ?? null;

        // Get task details
        $stmt = db()->prepare('
            SELECT t.*, p.user_id as owner_id
            FROM tasks t
            JOIN plants p ON t.plant_id = p.id
            WHERE t.id = ?
        ');
        $stmt->execute([$taskId]);
        $task = $stmt->fetch();

        if (!$task) {
            return ['status' => 404, 'data' => ['error' => 'Task not found']];
        }

        // Verify access (owned or shared via household)
        if (!$this->canAccessPlant($task['plant_id'], $userId)) {
            return ['status' => 404, 'data' => ['error' => 'Task not found']];
        }

        // Mark as skipped
        $stmt = db()->prepare('UPDATE tasks SET skipped_at = datetime("now"), skip_reason = ? WHERE id = ?');
        $stmt->execute([$reason, $taskId]);

        // Log to care log with performer attribution
        $stmt = db()->prepare('
            INSERT INTO care_log (plant_id, task_id, action, notes, outcome, performed_by_user_id)
            VALUES (?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $task['plant_id'],
            $taskId,
            'skipped_' . $task['task_type'],
            $reason,
            'neutral',
            $userId
        ]);

        // Generate next occurrence if recurring, using skip-specific delays
        if ($task['recurrence']) {
            $this->generateNextOccurrence($task, 'skip');
        }

        return ['task' => $task];
    }

    /**
     * Get AI-generated recommendations for a specific task
     */
    public function recommendations(array $params, array $body, ?int $userId): array
    {
        $taskId = $params['id'];

        // Get task with full plant details
        $stmt = db()->prepare('
            SELECT t.*,
                   p.id as plant_id,
                   p.name as plant_name,
                   p.species,
                   p.pot_size,
                   p.soil_type,
                   p.light_condition,
                   p.health_status,
                   p.notes as plant_notes,
                   p.user_id,
                   l.name as location_name,
                   cp.season,
                   cp.ai_reasoning
            FROM tasks t
            JOIN plants p ON t.plant_id = p.id
            LEFT JOIN locations l ON p.location_id = l.id
            LEFT JOIN care_plans cp ON cp.plant_id = p.id AND cp.is_active = 1
            WHERE t.id = ?
        ');
        $stmt->execute([$taskId]);
        $task = $stmt->fetch();

        if (!$task) {
            return ['status' => 404, 'data' => ['error' => 'Task not found']];
        }

        // Verify access (owned or shared via household)
        if (!$this->canAccessPlant($task['plant_id'], $userId)) {
            return ['status' => 404, 'data' => ['error' => 'Task not found']];
        }

        // Get recent care history for this plant
        $stmt = db()->prepare('
            SELECT action, performed_at, notes, outcome
            FROM care_log
            WHERE plant_id = ?
            ORDER BY performed_at DESC
            LIMIT 10
        ');
        $stmt->execute([$task['plant_id']]);
        $careHistory = $stmt->fetchAll();

        // Get recent AI analysis from photos (contains health assessment)
        $stmt = db()->prepare('
            SELECT ai_analysis, uploaded_at
            FROM photos
            WHERE plant_id = ? AND ai_analysis IS NOT NULL
            ORDER BY uploaded_at DESC
            LIMIT 3
        ');
        $stmt->execute([$task['plant_id']]);
        $healthHistory = $stmt->fetchAll();

        // Parse health status from AI analysis
        $healthHistory = array_map(function($photo) {
            $analysis = json_decode($photo['ai_analysis'], true);
            return [
                'health_assessment' => $analysis['health_status'] ?? null,
                'uploaded_at' => $photo['uploaded_at']
            ];
        }, $healthHistory);
        $healthHistory = array_filter($healthHistory, fn($h) => $h['health_assessment']);

        // Try to get weather data (if location available)
        $weather = $this->getWeatherContext();

        // Generate AI recommendations
        $recommendations = $this->generateAIRecommendations($task, $careHistory, $healthHistory, $weather);

        return [
            'recommendations' => $recommendations,
            'task' => $task,
            'weather' => $weather
        ];
    }

    /**
     * Get current weather context
     */
    private function getWeatherContext(): ?array
    {
        // For now, return seasonal context based on date
        // Could integrate with weather API later
        $month = (int)date('n');
        $season = match(true) {
            $month >= 3 && $month <= 5 => 'spring',
            $month >= 6 && $month <= 8 => 'summer',
            $month >= 9 && $month <= 11 => 'fall',
            default => 'winter'
        };

        $seasonalContext = [
            'spring' => [
                'season' => 'spring',
                'growth_phase' => 'active growth beginning',
                'watering_adjustment' => 'gradually increase watering',
                'fertilizing' => 'begin regular fertilizing',
                'notes' => 'Plants are waking up from dormancy. Watch for new growth.'
            ],
            'summer' => [
                'season' => 'summer',
                'growth_phase' => 'peak growth',
                'watering_adjustment' => 'water more frequently, check soil daily in heat',
                'fertilizing' => 'continue regular fertilizing',
                'notes' => 'High heat may stress plants. Watch for wilting.'
            ],
            'fall' => [
                'season' => 'fall',
                'growth_phase' => 'slowing growth',
                'watering_adjustment' => 'gradually reduce watering',
                'fertilizing' => 'reduce or stop fertilizing',
                'notes' => 'Plants preparing for dormancy. Less water needed.'
            ],
            'winter' => [
                'season' => 'winter',
                'growth_phase' => 'dormancy or slow growth',
                'watering_adjustment' => 'water sparingly, let soil dry more',
                'fertilizing' => 'stop fertilizing for most plants',
                'notes' => 'Low light and dry indoor air. Watch humidity.'
            ]
        ];

        return $seasonalContext[$season];
    }

    /**
     * Generate AI recommendations for the task
     */
    private function generateAIRecommendations(array $task, array $careHistory, array $healthHistory, ?array $weather): array
    {
        // Build context for AI
        $context = $this->buildTaskContext($task, $careHistory, $healthHistory, $weather);

        // Try to get AI service
        try {
            $aiService = $this->getAIService();
            if ($aiService) {
                return $this->callAIForRecommendations($aiService, $task, $context);
            }
        } catch (\Exception $e) {
            error_log("AI recommendation error: " . $e->getMessage());
        }

        // Fallback to smart defaults if no AI available
        return $this->generateFallbackRecommendations($task, $weather);
    }

    /**
     * Build context string for AI
     */
    private function buildTaskContext(array $task, array $careHistory, array $healthHistory, ?array $weather): string
    {
        $context = "## Plant Information\n";
        $context .= "- Name: {$task['plant_name']}\n";
        $context .= "- Species: " . ($task['species'] ?: 'Unknown') . "\n";
        $context .= "- Current Health: " . ($task['health_status'] ?: 'Unknown') . "\n";
        $context .= "- Pot Size: " . ($task['pot_size'] ?: 'Medium') . "\n";
        $context .= "- Soil Type: " . ($task['soil_type'] ?: 'Standard potting mix') . "\n";
        $context .= "- Light Condition: " . ($task['light_condition'] ?: 'Unknown') . "\n";
        $context .= "- Location: " . ($task['location_name'] ?: 'Unknown') . "\n";

        if ($task['plant_notes']) {
            $context .= "- Owner's Notes: {$task['plant_notes']}\n";
        }

        // Care plan details from AI reasoning
        if (!empty($task['ai_reasoning'])) {
            $context .= "\n## Current Care Plan\n";
            $context .= "- Season: " . ($task['season'] ?: 'Not specified') . "\n";
            $context .= "- AI Care Notes: {$task['ai_reasoning']}\n";
        }

        // Recent care history
        if (!empty($careHistory)) {
            $context .= "\n## Recent Care History\n";
            foreach (array_slice($careHistory, 0, 5) as $log) {
                $date = date('M j', strtotime($log['performed_at']));
                $context .= "- $date: {$log['action']}";
                if ($log['notes']) $context .= " - {$log['notes']}";
                if ($log['outcome']) $context .= " (outcome: {$log['outcome']})";
                $context .= "\n";
            }
        }

        // Health history from photos
        if (!empty($healthHistory)) {
            $context .= "\n## Recent Health Assessments\n";
            foreach ($healthHistory as $assessment) {
                $date = date('M j', strtotime($assessment['uploaded_at']));
                $context .= "- $date: {$assessment['health_assessment']}\n";
            }
        }

        // Weather/seasonal context
        if ($weather) {
            $context .= "\n## Current Conditions\n";
            $context .= "- Season: {$weather['season']}\n";
            $context .= "- Growth Phase: {$weather['growth_phase']}\n";
            $context .= "- Seasonal Note: {$weather['notes']}\n";
        }

        return $context;
    }

    /**
     * Get AI service instance
     */
    private function getAIService(): ?object
    {
        // Check if user has API keys configured
        $stmt = db()->prepare('SELECT setting_value FROM user_settings WHERE setting_key IN (?, ?) LIMIT 1');
        $stmt->execute(['claude_api_key', 'openai_api_key']);
        $setting = $stmt->fetch();

        if (!$setting) {
            return null;
        }

        // Decrypt and create service
        $encryptedKey = $setting['setting_value'];
        $apiKey = EncryptionService::decrypt($encryptedKey);

        // Determine which service based on key format
        if (strpos($apiKey, 'sk-ant-') === 0) {
            return new ClaudeService($apiKey);
        } else {
            return new OpenAIService($apiKey);
        }
    }

    /**
     * Call AI service for recommendations
     */
    private function callAIForRecommendations($aiService, array $task, string $context): array
    {
        $taskType = $task['task_type'];
        $plantName = $task['plant_name'];

        $prompt = "You are a plant care expert. Based on the following information about \"$plantName\", provide specific, actionable recommendations for the upcoming $taskType task.

$context

## Task Details
- Task Type: $taskType
- Due Date: {$task['due_date']}
- Priority: " . ($task['priority'] ?: 'normal') . "
" . ($task['instructions'] ? "- Current Instructions: {$task['instructions']}\n" : "") . "

Please provide personalized recommendations in the following JSON format:
{
  \"summary\": \"A brief 1-2 sentence summary of what to do\",
  \"steps\": [\"Step 1...\", \"Step 2...\", \"Step 3...\"],
  \"amount\": \"Specific amounts if applicable (e.g., water amount, fertilizer dilution)\",
  \"timing\": \"Best time of day or conditions for this task\",
  \"warnings\": [\"Any cautions or things to watch for\"],
  \"tips\": [\"Optional helpful tips specific to this plant\"]
}

Be specific to THIS plant's species ({$task['species']}), current health ({$task['health_status']}), and conditions. Do not give generic advice.";

        // Build full plant data for the chat method
        $plant = [
            'name' => $task['plant_name'],
            'species' => $task['species'],
            'pot_size' => $task['pot_size'],
            'soil_type' => $task['soil_type'],
            'light_condition' => $task['light_condition'],
            'health_status' => $task['health_status'],
            'notes' => $task['plant_notes'],
            'location_name' => $task['location_name']
        ];

        try {
            $response = $aiService->chat(
                $plant,
                [['role' => 'user', 'content' => $prompt]],
                [] // Additional context - already included in prompt
            );

            // Parse JSON from response
            $content = $response['content'] ?? '';

            // Extract JSON from response (it might be wrapped in markdown code blocks)
            if (preg_match('/```(?:json)?\s*([\s\S]*?)```/', $content, $matches)) {
                $content = $matches[1];
            }

            $recommendations = json_decode(trim($content), true);

            if ($recommendations && isset($recommendations['summary'])) {
                $recommendations['source'] = 'ai';
                return $recommendations;
            }
        } catch (\Exception $e) {
            error_log("AI recommendation call failed: " . $e->getMessage());
        }

        // Return fallback if AI fails
        return $this->generateFallbackRecommendations($task, null);
    }

    /**
     * Generate fallback recommendations when AI is unavailable
     */
    private function generateFallbackRecommendations(array $task, ?array $weather): array
    {
        $taskType = $task['task_type'];
        $species = strtolower($task['species'] ?? '');
        $potSize = $task['pot_size'] ?? 'medium';
        $healthStatus = $task['health_status'] ?? 'healthy';

        $recommendations = [
            'source' => 'fallback',
            'summary' => '',
            'steps' => [],
            'amount' => '',
            'timing' => 'Morning is generally best',
            'warnings' => [],
            'tips' => []
        ];

        // Adjust for health status
        if ($healthStatus === 'struggling' || $healthStatus === 'critical') {
            $recommendations['warnings'][] = "This plant is currently $healthStatus - proceed carefully and monitor closely after care.";
        }

        // Seasonal adjustments
        if ($weather) {
            $recommendations['tips'][] = "Seasonal note ({$weather['season']}): {$weather['watering_adjustment']}";
        }

        switch ($taskType) {
            case 'water':
                $amounts = ['small' => '100-200ml', 'medium' => '300-500ml', 'large' => '500-750ml', 'xlarge' => '1-1.5L'];
                $recommendations['summary'] = "Water {$task['plant_name']} thoroughly until water drains from the bottom.";
                $recommendations['amount'] = $amounts[$potSize] ?? $amounts['medium'];
                $recommendations['steps'] = [
                    "Check soil moisture 1-2 inches deep with your finger or moisture meter",
                    "If dry, water slowly around the base of the plant",
                    "Continue until water drains from drainage holes",
                    "Empty saucer after 30 minutes to prevent root rot"
                ];
                if (str_contains($species, 'succulent') || str_contains($species, 'cactus')) {
                    $recommendations['warnings'][] = "Succulents prefer to dry out completely between waterings";
                    $recommendations['amount'] = "Soak thoroughly, then wait until bone dry";
                }
                break;

            case 'fertilize':
                $strengths = ['small' => '1/4', 'medium' => '1/2', 'large' => '1/2-full', 'xlarge' => 'full'];
                $recommendations['summary'] = "Apply balanced fertilizer at {$strengths[$potSize]} strength to support growth.";
                $recommendations['amount'] = "{$strengths[$potSize]} strength dilution";
                $recommendations['steps'] = [
                    "Ensure soil is moist before fertilizing (water lightly first if dry)",
                    "Mix fertilizer at recommended dilution",
                    "Apply evenly around the soil surface",
                    "Water again lightly to help distribute nutrients"
                ];
                if ($weather && $weather['season'] === 'winter') {
                    $recommendations['warnings'][] = "Most plants don't need fertilizer in winter - consider skipping";
                }
                break;

            case 'trim':
                $recommendations['summary'] = "Remove dead or yellowing leaves and shape {$task['plant_name']} as needed.";
                $recommendations['steps'] = [
                    "Use clean, sharp scissors or pruning shears",
                    "Remove any yellow, brown, or dead leaves at their base",
                    "Trim leggy growth to encourage bushier shape",
                    "Cut just above a leaf node for best regrowth"
                ];
                $recommendations['tips'][] = "Healthy cuttings can often be propagated in water!";
                break;

            case 'repot':
                $newSizes = ['small' => 'medium', 'medium' => 'large', 'large' => 'xlarge'];
                $recommendations['summary'] = "Move {$task['plant_name']} to a slightly larger pot with fresh soil.";
                $recommendations['amount'] = "New pot should be 1-2 inches larger in diameter";
                $recommendations['steps'] = [
                    "Water the plant 1-2 days before repotting",
                    "Prepare new pot with drainage and fresh potting mix",
                    "Gently remove plant and loosen root ball",
                    "Place in new pot at same depth, fill with soil",
                    "Water thoroughly and keep in indirect light for a week"
                ];
                break;

            case 'mist':
                $recommendations['summary'] = "Mist {$task['plant_name']} to increase humidity around the leaves.";
                $recommendations['steps'] = [
                    "Use room temperature water in a spray bottle",
                    "Mist around and above the plant, not directly on leaves",
                    "Focus on the air around the plant"
                ];
                $recommendations['timing'] = "Morning is best - leaves need time to dry before evening";
                if (str_contains($species, 'succulent') || str_contains($species, 'cactus')) {
                    $recommendations['warnings'][] = "Skip misting! Succulents and cacti prefer dry conditions.";
                }
                break;

            case 'rotate':
                $recommendations['summary'] = "Turn {$task['plant_name']} 1/4 turn for even light exposure.";
                $recommendations['steps'] = [
                    "Rotate the pot 90 degrees (1/4 turn)",
                    "Always rotate in the same direction",
                    "Mark the pot if needed to track rotation"
                ];
                $recommendations['tips'][] = "This prevents lopsided growth toward the light source";
                break;

            case 'check':
                $recommendations['summary'] = "Inspect {$task['plant_name']} for overall health and any issues.";
                $recommendations['steps'] = [
                    "Check leaves (top and bottom) for pests or spots",
                    "Feel soil moisture level",
                    "Look for new growth or changes",
                    "Check for yellowing, browning, or drooping"
                ];
                $recommendations['tips'][] = "Take a photo to track changes over time";
                break;

            default:
                $recommendations['summary'] = "Complete the {$taskType} task for {$task['plant_name']}.";
                $recommendations['steps'] = ["Follow standard care practices for this task type"];
        }

        return $recommendations;
    }

    /**
     * Get AI-suggested schedule adjustment based on skip reason
     */
    public function adjustSchedule(array $params, array $body, ?int $userId): array
    {
        $taskId = $params['id'];
        $reason = $body['reason'] ?? '';

        // Get task with full details
        $stmt = db()->prepare('
            SELECT t.*,
                   p.id as plant_id,
                   p.name as plant_name,
                   p.species,
                   p.pot_size,
                   p.soil_type,
                   p.light_condition,
                   p.health_status,
                   p.user_id,
                   l.name as location_name
            FROM tasks t
            JOIN plants p ON t.plant_id = p.id
            LEFT JOIN locations l ON p.location_id = l.id
            WHERE t.id = ?
        ');
        $stmt->execute([$taskId]);
        $task = $stmt->fetch();

        if (!$task) {
            return ['status' => 404, 'data' => ['error' => 'Task not found']];
        }

        // Verify access (owned or shared via household)
        if (!$this->canAccessPlant($task['plant_id'], $userId)) {
            return ['status' => 404, 'data' => ['error' => 'Task not found']];
        }

        // Get current recurrence
        $recurrence = json_decode($task['recurrence'], true);
        $currentInterval = $recurrence['interval'] ?? 7;

        // Get skip history for this plant and task type
        $stmt = db()->prepare('
            SELECT COUNT(*) as skip_count,
                   GROUP_CONCAT(notes) as reasons
            FROM care_log
            WHERE plant_id = ?
              AND action LIKE ?
              AND performed_at > datetime("now", "-30 days")
        ');
        $stmt->execute([$task['plant_id'], 'skipped_%']);
        $skipHistory = $stmt->fetch();

        // Try AI service for smart suggestion
        try {
            $aiService = $this->getAIService();
            if ($aiService) {
                $suggestion = $this->getAIScheduleSuggestion($aiService, $task, $reason, $currentInterval, $skipHistory);
                if ($suggestion) {
                    return $suggestion;
                }
            }
        } catch (\Exception $e) {
            error_log("AI schedule adjustment error: " . $e->getMessage());
        }

        // Fallback logic
        return $this->getFallbackScheduleSuggestion($task, $reason, $currentInterval);
    }

    /**
     * Get AI-powered schedule suggestion
     */
    private function getAIScheduleSuggestion($aiService, array $task, string $reason, int $currentInterval, array $skipHistory): ?array
    {
        $prompt = "You are a plant care expert. A user is skipping a {$task['task_type']} task for their {$task['species']} plant.

Current schedule: Every {$currentInterval} days
Skip reason: {$reason}
Plant details:
- Species: {$task['species']}
- Pot size: {$task['pot_size']}
- Soil type: {$task['soil_type']}
- Light: {$task['light_condition']}
- Health: {$task['health_status']}
- Location: {$task['location_name']}

Recent skip history (last 30 days): {$skipHistory['skip_count']} skips
Previous skip reasons: " . ($skipHistory['reasons'] ?: 'None') . "

Based on this information, suggest whether the schedule should be adjusted. Respond in JSON format:
{
  \"should_adjust\": true/false,
  \"new_interval\": <number of days, or null if no change>,
  \"suggestion\": \"<brief explanation for the user, 1-2 sentences>\",
  \"reasoning\": \"<internal reasoning>\"
}

Consider:
- If soil is still moist, the plant may need less frequent watering
- Seasonal changes affect watering needs
- The skip history pattern (frequent skips suggest schedule is too aggressive)
- Species-specific needs";

        $plant = [
            'name' => $task['plant_name'],
            'species' => $task['species'],
            'pot_size' => $task['pot_size'],
            'soil_type' => $task['soil_type'],
            'light_condition' => $task['light_condition'],
            'health_status' => $task['health_status']
        ];

        try {
            $response = $aiService->chat(
                $plant,
                [['role' => 'user', 'content' => $prompt]],
                []
            );

            $content = $response['content'] ?? '';

            // Extract JSON
            if (preg_match('/```(?:json)?\s*([\s\S]*?)```/', $content, $matches)) {
                $content = $matches[1];
            }

            $result = json_decode(trim($content), true);

            if ($result && isset($result['suggestion'])) {
                return [
                    'suggestion' => $result['suggestion'],
                    'new_interval' => $result['new_interval'],
                    'should_adjust' => $result['should_adjust'] ?? false,
                    'adjustment' => [
                        'type' => 'interval',
                        'value' => $result['new_interval'],
                        'task_type' => $task['task_type']
                    ],
                    'source' => 'ai'
                ];
            }
        } catch (\Exception $e) {
            error_log("AI schedule suggestion failed: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Fallback schedule suggestion when AI is unavailable
     */
    private function getFallbackScheduleSuggestion(array $task, string $reason, int $currentInterval): array
    {
        $newInterval = $currentInterval;
        $suggestion = '';

        // Simple heuristics based on reason
        if (strpos(strtolower($reason), 'moist') !== false || strpos(strtolower($reason), 'wet') !== false) {
            $newInterval = min($currentInterval + 2, $currentInterval * 1.5);
            $suggestion = "Since the soil is still moist, I suggest extending watering to every " . round($newInterval) . " days.";
        } elseif (strpos(strtolower($reason), 'recently') !== false || strpos(strtolower($reason), 'already') !== false) {
            $newInterval = $currentInterval + 1;
            $suggestion = "Extending the schedule slightly to every " . round($newInterval) . " days to avoid over-caring.";
        } elseif (strpos(strtolower($reason), 'stressed') !== false) {
            $suggestion = "When a plant is stressed, maintaining the current schedule is often best. Monitor closely.";
        } else {
            $suggestion = "Based on your feedback, consider adjusting to every " . ($currentInterval + 1) . " days.";
            $newInterval = $currentInterval + 1;
        }

        return [
            'suggestion' => $suggestion,
            'new_interval' => round($newInterval),
            'should_adjust' => true,
            'adjustment' => [
                'type' => 'interval',
                'value' => round($newInterval),
                'task_type' => $task['task_type']
            ],
            'source' => 'fallback'
        ];
    }

    /**
     * Apply schedule adjustment and skip task
     */
    public function applyAdjustment(array $params, array $body, ?int $userId): array
    {
        $taskId = $params['id'];
        $reason = $body['reason'] ?? '';
        $adjustment = $body['adjustment'] ?? null;

        // Get task details
        $stmt = db()->prepare('
            SELECT t.*, p.user_id as owner_id, p.id as plant_id
            FROM tasks t
            JOIN plants p ON t.plant_id = p.id
            WHERE t.id = ?
        ');
        $stmt->execute([$taskId]);
        $task = $stmt->fetch();

        if (!$task) {
            return ['status' => 404, 'data' => ['error' => 'Task not found']];
        }

        // Verify access (owned or shared via household)
        if (!$this->canAccessPlant($task['plant_id'], $userId)) {
            return ['status' => 404, 'data' => ['error' => 'Task not found']];
        }

        // Update the recurrence interval if adjustment provided
        if ($adjustment && isset($adjustment['value'])) {
            $recurrence = json_decode($task['recurrence'], true) ?: ['type' => 'days', 'interval' => 7];
            $recurrence['interval'] = (int)$adjustment['value'];

            // Update future tasks of same type
            $stmt = db()->prepare('
                UPDATE tasks
                SET recurrence = ?
                WHERE plant_id = ?
                  AND task_type = ?
                  AND completed_at IS NULL
                  AND skipped_at IS NULL
            ');
            $stmt->execute([
                json_encode($recurrence),
                $task['plant_id'],
                $task['task_type']
            ]);

            // Update care plan if exists - use dynamic field based on task type
            $allowedTaskTypes = ['water', 'fertilize', 'mist', 'rotate', 'check'];
            if (in_array($task['task_type'], $allowedTaskTypes)) {
                $carePlanField = $task['task_type'] . '_frequency_days';
                try {
                    $stmt = db()->prepare("
                        UPDATE care_plans
                        SET $carePlanField = ?, updated_at = datetime('now')
                        WHERE plant_id = ? AND is_active = 1
                    ");
                    $stmt->execute([(int)$adjustment['value'], $task['plant_id']]);
                } catch (\Exception $e) {
                    // Field might not exist, ignore
                }
            }
        }

        // Skip the current task
        $stmt = db()->prepare('UPDATE tasks SET skipped_at = datetime("now"), skip_reason = ? WHERE id = ?');
        $stmt->execute([$reason . ' (schedule adjusted)', $taskId]);

        // Log to care log
        $stmt = db()->prepare('
            INSERT INTO care_log (plant_id, task_id, action, notes, outcome)
            VALUES (?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $task['plant_id'],
            $taskId,
            'schedule_adjusted_' . $task['task_type'],
            $reason . ' - Adjusted to every ' . ($adjustment['value'] ?? '?') . ' days',
            'positive'
        ]);

        // Generate next occurrence with new interval
        if ($task['recurrence']) {
            $newRecurrence = json_decode($task['recurrence'], true) ?: [];
            $newRecurrence['interval'] = (int)($adjustment['value'] ?? $newRecurrence['interval'] ?? 7);
            $task['recurrence'] = json_encode($newRecurrence);
            $this->generateNextOccurrence($task);
        }

        return [
            'success' => true,
            'message' => 'Schedule updated and task skipped',
            'new_interval' => $adjustment['value'] ?? null
        ];
    }

    /**
     * Generate next occurrence of recurring task
     * @param string $mode 'complete' or 'skip' — skip uses shorter type-based delays
     */
    /**
     * Run the side effects that should follow ANY task completion, regardless of
     * who completed it (owner, sitter, etc.): generate the next occurrence so the
     * recurring chain continues, and auto-skip the sibling water task if a
     * fertilize task was just completed (fertilizer is mixed with water).
     *
     * Callers are responsible for marking the task completed and writing the
     * care_log entry before invoking this — those steps differ slightly between
     * the owner flow (attribution + check data) and the sitter flow (token-based).
     */
    public function runCompletionSideEffects(array $task): void
    {
        if ($task['recurrence']) {
            $this->generateNextOccurrence($task);
        }

        if ($task['task_type'] === 'fertilize') {
            $siblingStmt = db()->prepare('
                SELECT id, recurrence, due_date, care_plan_id, plant_id, task_type, instructions, priority FROM tasks
                WHERE plant_id = ? AND task_type = "water"
                  AND completed_at IS NULL AND skipped_at IS NULL
                ORDER BY due_date ASC LIMIT 1
            ');
            $siblingStmt->execute([$task['plant_id']]);
            $siblingTask = $siblingStmt->fetch();
            if ($siblingTask) {
                $skipStmt = db()->prepare('UPDATE tasks SET skipped_at = datetime("now"), skip_reason = ? WHERE id = ?');
                $skipStmt->execute(['Auto-skipped: plant fertilized (fertilizer mixed with water)', $siblingTask['id']]);
                if ($siblingTask['recurrence']) {
                    $this->generateNextOccurrence($siblingTask, 'skip');
                }
            }
        }
    }

    private function generateNextOccurrence(array $task, string $mode = 'complete'): void
    {
        // Required fields for the INSERT — surface bugs loudly instead of failing
        // silently when callers pass a partial row (NOT NULL violations are swallowed
        // under PDO::ERRMODE_SILENT).
        foreach (['plant_id', 'task_type'] as $required) {
            if (empty($task[$required])) {
                error_log("generateNextOccurrence: missing required field '$required' for task " . ($task['id'] ?? 'unknown'));
                return;
            }
        }

        $recurrence = json_decode($task['recurrence'], true);
        if (!$recurrence) return;

        $interval = $recurrence['interval'] ?? 7;
        $type = $recurrence['type'] ?? 'days';

        if ($mode === 'skip') {
            // When skipping, use type-specific delays (shorter than full interval)
            $skipDelays = [
                'water' => 1,        // Next day — plant still needs water
                'mist' => 1,         // Next day — humidity-sensitive
                'change_water' => 2, // 2 days — propagation water freshness
                'check' => 3,        // 3 days — routine monitoring
                'fertilize' => 7,    // 1 week — not time-critical
                'rotate' => 7,       // 1 week — gradual process
                'trim' => 7,         // 1 week — can wait
                'prune' => 7,        // 1 week — can wait
                'repot' => 14,       // 2 weeks — major task
                'pot_up' => 14,      // 2 weeks — major task
                'check_roots' => 14, // 2 weeks — infrequent
            ];
            $skipDays = $skipDelays[$task['task_type']] ?? 3;
            // Use the shorter of skip delay or full interval
            $effectiveDelay = min($skipDays, $interval);
            $nextDate = date('Y-m-d', strtotime("today +$effectiveDelay days"));
        } else {
            // When completing, space by full interval from whichever is later
            $fromDueDate = strtotime($task['due_date'] . " +$interval $type");
            // Ensure at least $interval days from today (prevents back-to-back
            // tasks when completed late)
            $fromToday = strtotime("today +$interval $type");
            $nextDate = date('Y-m-d', max($fromDueDate, $fromToday));
        }

        // Check if ANY pending task of this type already exists for this plant
        $stmt = db()->prepare('
            SELECT id FROM tasks
            WHERE plant_id = ?
              AND task_type = ?
              AND completed_at IS NULL
              AND skipped_at IS NULL
            LIMIT 1
        ');
        $stmt->execute([$task['plant_id'], $task['task_type']]);
        if ($stmt->fetch()) {
            // A pending task of this type already exists, skip creation
            return;
        }

        $stmt = db()->prepare('
            INSERT INTO tasks (care_plan_id, plant_id, task_type, due_date, recurrence, instructions, priority)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $task['care_plan_id'],
            $task['plant_id'],
            $task['task_type'],
            $nextDate,
            $task['recurrence'],
            $task['instructions'],
            $task['priority']
        ]);
    }

    /**
     * Daily reconciliation: check all pending tasks against actual care history.
     * If a plant was recently watered/fertilized but still has a pending water task
     * that's too soon, push it out. Also skip redundant water+fertilize on same day.
     */
    private function reconcileOverdueTasks(int $userId, string $accessCondition): void
    {
        $today = date('Y-m-d');

        // Find all pending water/fertilize tasks for user's plants
        $stmt = db()->query("
            SELECT t.id, t.plant_id, t.task_type, t.due_date, t.recurrence
            FROM tasks t
            JOIN plants p ON t.plant_id = p.id
            WHERE {$accessCondition}
              AND t.task_type IN ('water', 'fertilize')
              AND t.completed_at IS NULL
              AND t.skipped_at IS NULL
              AND t.due_date <= '{$today}'
              AND p.archived_at IS NULL
            ORDER BY t.plant_id, t.task_type
        ");
        $pendingTasks = $stmt->fetchAll();

        foreach ($pendingTasks as $task) {
            $recurrence = json_decode($task['recurrence'], true);
            $interval = $recurrence['interval'] ?? 7;

            // Which care actions count as having satisfied this task? Fertilizing
            // also waters the plant, so a water task is deferred by either action.
            // Watering does NOT fertilize, so a fertilize task is only deferred by
            // an actual fertilize. Counting waterings here re-deferred a fertilize
            // task by a full fertilize interval the moment it came due, on every
            // load of the Today view, so it could never stay due long enough to be
            // seen. 0.14.3 made the sibling auto-skip one-directional but left this
            // path symmetric, which is why fertilize tasks stayed invisible.
            $satisfyingActions = $task['task_type'] === 'fertilize'
                ? ['fertilize']
                : ['water', 'fertilize'];
            $placeholders = implode(',', array_fill(0, count($satisfyingActions), '?'));

            $logStmt = db()->prepare("
                SELECT MAX(performed_at) as last_action
                FROM care_log
                WHERE plant_id = ? AND action IN ({$placeholders})
            ");
            $logStmt->execute(array_merge([$task['plant_id']], $satisfyingActions));
            $lastAction = $logStmt->fetch();

            if ($lastAction && $lastAction['last_action']) {
                $daysSinceAction = (int)((strtotime($today) - strtotime($lastAction['last_action'])) / 86400);

                if ($daysSinceAction < $interval) {
                    // Plant was recently cared for — push this task out
                    $newDueDate = date('Y-m-d', strtotime($lastAction['last_action'] . " +{$interval} days"));
                    if ($newDueDate > $today) {
                        $updateStmt = db()->prepare('UPDATE tasks SET due_date = ? WHERE id = ?');
                        $updateStmt->execute([$newDueDate, $task['id']]);
                    }
                }
            }
        }

        // Remove same-day water+fertilize conflicts: if both pending for same plant,
        // skip the water task (fertilize takes priority since it includes watering)
        $stmt2 = db()->query("
            SELECT t.id, t.plant_id, t.task_type, t.recurrence
            FROM tasks t
            JOIN plants p ON t.plant_id = p.id
            WHERE {$accessCondition}
              AND t.task_type IN ('water', 'fertilize')
              AND t.completed_at IS NULL AND t.skipped_at IS NULL
              AND t.due_date <= '{$today}'
              AND p.archived_at IS NULL
        ");
        $remaining = $stmt2->fetchAll();

        // Group by plant
        $byPlant = [];
        foreach ($remaining as $t) {
            $byPlant[$t['plant_id']][$t['task_type']] = $t;
        }

        foreach ($byPlant as $plantId => $types) {
            if (isset($types['water']) && isset($types['fertilize'])) {
                // Both due — skip the water task
                $waterTask = $types['water'];
                $skipStmt = db()->prepare('UPDATE tasks SET skipped_at = datetime("now"), skip_reason = ? WHERE id = ?');
                $skipStmt->execute(['Auto-skipped: fertilize task covers watering', $waterTask['id']]);
                if ($waterTask['recurrence']) {
                    $this->generateNextOccurrence($waterTask, 'skip');
                }
            }
        }
    }
}
