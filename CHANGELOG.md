# Changelog

All notable changes to Sunwise are documented in this file.

## [0.14.9] - 2026-09-05

### Fixed
- **Fertilize tasks still never appeared after 0.14.3** - 0.14.3 removed the batching gate and made the sibling auto-skip one-directional, but daily reconciliation was still symmetric: it treated any *watering* as having satisfied a pending *fertilize* task. The moment a fertilize task came due, reconciliation saw a recent watering and pushed it out a full fertilize interval — on every load of the Today view. A fertilize task could never stay due long enough to be seen. Reconciliation now only defers a fertilize task when the plant was actually fertilized; water tasks still accept either action, since fertilizing waters the plant too

### Added
- **Fertilize due date repair script** - `api/scripts/repair_fertilize_dates.php` recomputes due dates for fertilize tasks that reconciliation pushed into the future, using each plant's real fertilize history. Plants never fertilized, or last fertilized at least one interval ago, become due today; plants fertilized more recently get the date they should have had. Dry run by default, `--apply` to write, safe to run more than once

## [0.14.8] - 2026-05-18

### Fixed
- **Sitter share view showed every task in the entire window on day one** - Previous query returned anything with `due_date <= end_date`, so on June 3 the sitter saw all of the next 14 days at once. Now bounded by today, so each visit shows only what's currently due (plus rolled-over overdue). New due tasks surface naturally as the days pass

### Changed
- **Completed section shows only this sitter's completions** - Was returning the plant's full historical completion log. Now filters to tasks marked "Completed by sitter" since the session start date

## [0.14.7] - 2026-05-18

### Changed
- **Share view now matches the owner's task UI** - Same doodle icons, sage/charcoal/cream palette, card layout, task-type sort order, and priority/overdue badges as the owner's Today view. Sitter no longer feels like a different app
- **Tap any plant thumbnail to enlarge** - Both task thumbnails and the Plants reference grid open a full-size lightbox, matching the owner view

## [0.14.6] - 2026-05-18

### Fixed
- **Share view "Unassigned" location for every plant** - Sitter queries selected `p.location` (legacy text column) instead of joining `locations` via `location_id`. Plants migrated to the structured location system showed empty. Both the plants and tasks queries now `COALESCE(l.name, p.location)`
- **Sitter completions broke the recurring chain** - When the sitter completed a task via their share URL, the task was marked done but no next occurrence was generated and no sibling-skip ran. Extracted the post-completion side effects into a shared `TaskController::runCompletionSideEffects` and call it from both owner and sitter flows
- **Pre-session overdue tasks were hidden from the sitter** - Tasks the owner left pending before the share window started weren't visible to the sitter. Query now includes anything with `due_date <= end_date` so missed work rolls over into the sitter's window

## [0.14.5] - 2026-05-18

### Fixed
- **Share URL date range was off by one day** - `new Date('2026-06-03')` parses as UTC midnight; in any timezone west of UTC, `toLocaleDateString` then rendered the previous day. Switched to local-date parsing for both the header range and per-task due dates

### Changed
- **Share view now groups tasks and plants by location** - Easier for the sitter to find plants in the house by working through one room at a time. Plants without a location land under "Unassigned"
- **Removed per-task instructions in the share view** - Cleaner list focused on what to do, not how

## [0.14.4] - 2026-05-18

### Fixed
- **Care plan generation crashed for users with no AI settings row** - `PDOStatement::fetch()` returns `false` when no row matches, but the downstream `?array` type hint rejected it. Coerce to null so the lookup falls back through the normal provider/server-key chain

## [0.14.3] - 2026-05-17

### Fixed
- **Fertilizer tasks were silently hidden** - "Today" view required ≥3 fertilize tasks due on the same day or none would render. Removed the batching gate so fertilize tasks appear normally when due
- **Water-task chain broke after high-moisture check auto-skip** - The auto-skip path fetched only `id` and `recurrence` for the water task and passed that incomplete row to the next-occurrence generator. The follow-up INSERT failed silently on NOT NULL constraints, so no replacement task was created. The SELECT now returns all required columns, and the generator now logs (instead of silently failing) when a caller passes a partial row
- **Watering a plant no longer skips its pending fertilize task** - Sibling auto-skip was bidirectional; every routine watering pushed the next fertilize task ~7 days out, so fertilize never naturally came due. Sibling skip is now one-directional: completing a fertilize task still skips the pending water (fertilizer is mixed with water), but the reverse no longer happens
- **Care-plan regeneration is now atomic** - The previous flow deactivated existing plans and deleted pending tasks BEFORE the AI call. Any error in between left plants orphaned with no active plan and no tasks. Regeneration is now wrapped in a transaction with the AI call moved earlier — on any failure the previous plan and tasks are preserved

## [0.14.2] - 2026-05-07

### Fixed
- **Daily email respects task-type settings** - Daily summary emails now honor the user's per-task-type notification preferences and skip dead plants

### Changed
- **Deployment moved to GitHub Actions** - Releases published on GitHub now trigger an automated deploy workflow (build, sync frontend/api, clear caches, run migrations) using SSH multiplexing to stay under shared-hosting connection limits

## [0.14.1] - 2026-04-06

### Fixed
- **Fertilize batching no longer pulls forward** - Fertilize tasks only appear when naturally due (at or past their scheduled date), never pulled forward from the future. Batch threshold reduced to 3 tasks
- **Daily task reconciliation** - When loading today's tasks, water/fertilize tasks are checked against actual care history. If a plant was recently watered or fertilized within its interval, the task is automatically pushed out to the correct future date
- **Water/fertilize are bidirectionally linked** - Completing either task auto-skips and reschedules the other, since fertilizer is mixed with water. Same-day water+fertilize conflicts are resolved automatically
- **Duplicate task prevention strengthened** - `generateNextOccurrence` now checks for any pending task of the same type per plant, not just same-date duplicates

## [0.14.0] - 2026-03-23

### Added
- **Fertilize batching** - Fertilize tasks are grouped when 3+ are naturally due at the same time
- **Fertilize auto-skips watering** - When a plant is fertilized, its water task is automatically skipped since fertilizer is mixed with water. Next watering rescheduled normally

## [0.13.3] - 2026-03-23

### Added
- **Sitter task type selection** - Choose which task types (water, mist, rotate, etc.) to include when creating a sitter link
- **Larger plant photos in task list** - Plant thumbnails doubled in size (96x96) in both main task list and sitter view

### Changed
- **Background check analysis** - Check task modal closes immediately; AI analysis and care plan updates run in the background with results shown as toast notifications
- **Differentiated skip rescheduling** - Skipped tasks reschedule based on urgency: water/mist next day, check 3 days, fertilize/rotate/trim 1 week, repot 2 weeks

### Fixed
- **Check task duplication** - Care plan regeneration no longer creates duplicate check tasks; respects recently completed task dates when scheduling
- **7-day minimum check interval** - AI can no longer set check task intervals shorter than 7 days, preventing excessive daily checking
- **Check task redistribution** - Evenly spread check tasks across the week instead of clustering on specific days

## [0.13.2] - 2026-03-10

### Fixed
- **Back-to-back task scheduling** - Fixed recurring tasks (especially watering) being scheduled too soon when completed late. Next occurrence now uses `max(due_date + interval, today + interval)` to ensure proper spacing
- **Wet check auto-skips watering** - When a check task reports soil moisture >= 7/10, any pending water task for that plant is automatically skipped with a notification. Very wet soil (>= 9) with upcoming watering also triggers care plan regeneration
- **Public gallery showing archived plants** - Gallery page no longer includes plants that have been moved to the graveyard
- **Archived plant task cleanup** - Pending tasks for archived plants are now properly cleaned up

## [0.13.1] - 2026-02-10

### Fixed
- **Water propagation detection** - Fixed check tasks incorrectly showing "water propagation" for normal potted plants by using explicit integer comparison instead of truthy check
- **Plant data type normalization** - Backend now casts `is_propagation`, `has_grow_light`, and other integer fields to proper types before JSON encoding
- **Task query SQL error** - Fixed escaped quote syntax (`\'@\'`) in task queries that caused "unrecognized token" errors and prevented tasks from loading
- **OpenAI API compatibility** - Updated to use `max_completion_tokens` instead of deprecated `max_tokens` parameter for GPT-5.2, fixing species identification and care plan generation
- **Propagation task validation** - Care plan generator now blocks invalid task types for propagations (no "water" tasks for water propagations, no "change_water" for soil propagations)
- **Form validation conflict** - Added `novalidate` to plant form to prevent browser native validation from blocking submissions
- **Care plan evaluator improvements** - Now also detects plants with no care plan at all and plans missing check tasks

## [0.13.0] - 2026-02-08

### Added
- **Plant rotation setting** - Option to disable rotation tasks for plants that don't need it (hanging plants, symmetrical plants):
  - New "Needs rotation for even growth" checkbox on add/edit plant
  - AI care plans respect the setting and skip rotate tasks when disabled
  - New database column `can_rotate` on plants table
- **Rebuild care plan button** - One-tap button on plant detail page to trigger AI care plan regeneration
- **Scheduled care plan evaluations** - New weekly cron job (`/cron/evaluate-plans`) that automatically regenerates stale care plans:
  - Catches expired plans (past valid_until date)
  - Refreshes old plans (over 60 days)
  - Rebuilds depleted plans (no pending tasks remaining)
  - Updates plans when season changes
- **Auto care plan updates on check** - Check task completion now triggers automatic care plan regeneration when:
  - Soil is very dry but watering is scheduled far in the future
  - Soil is very wet with watering scheduled soon
  - Plant health is critical or pests are detected
- **Chat action loading indicator** - Loading overlay shows "Applying changes..." when applying suggested actions from AI chat

### Changed
- **Disabled task types now hidden from all views** - When a task type is disabled in settings (e.g., misting), those tasks are filtered from today's tasks, upcoming tasks, and plant task lists. Tasks remain in the database so re-enabling shows them again.
- **Care schedule section redesigned** - Shows next due dates per task type with overdue highlighting
- Light meter reading moved from check tasks to plant add/edit (where it belongs)

### Fixed
- **OpenAI species identification missing candidates** - Fixed bug where OpenAI users never saw the species picker with confidence levels (the `candidates` array was missing from the prompt)
- **PlantController INSERT missing fields** - `baseline_light_reading` and `can_rotate` were missing from the plant INSERT statement

## [0.12.0] - 2026-01-30

### Added
- **Baseline light meter reading** - Add optional foot-candle reading when creating or editing plants:
  - Helps AI optimize care schedules based on actual light measurements
  - Shows reference ranges for low, medium, bright, and direct light
  - New database field `baseline_light_reading` for plants

### Fixed
- **Propagation care plan for soil propagations** - Fixed issue where soil-based propagations (rooting medium) could incorrectly get "change water" tasks:
  - AI now explicitly knows if propagation is in water or soil
  - Soil propagations correctly get "water" tasks instead of "change_water"

## [0.11.1] - 2026-01-28

### Added
- **Light range guidance in check tasks** - When entering a light meter reading, see recommended foot-candle ranges:
  - Plant-specific ranges based on light condition (low/medium/high/full sun)
  - Shows ideal range plus too-low/acceptable/too-bright thresholds
  - Falls back to general ranges when plant light preference is unknown

## [0.11.0] - 2026-01-24

### Added
- **Smart Check Analysis** - After completing a plant check, get automatic insights:
  - Local pattern detection for immediate alerts (pests, low health, moisture extremes)
  - AI-powered analysis when concerning patterns are found
  - Insights displayed with visual indicators (urgent/warning/success/info)
  - Specific recommendations based on check data and history
- Check completion modal now shows insights before closing

## [0.10.1] - 2026-01-24

### Fixed
- Care info now uses user's configured AI provider (was hardcoded to Claude)
- Updated fertilize icon to nature-care design
- Updated check_roots icon to soil design

## [0.10.0] - 2026-01-24

### Added
- **Enhanced check tasks** - Capture structured plant health data during check tasks:
  - Moisture level (1-10 scale with dry/moist/wet indicators)
  - Light readings in foot-candles
  - Observations: new growth, yellowing leaves, brown tips, pests, dusty/dirty
  - Overall health rating (1-5)
  - Optional notes
- Time of check is recorded for context (e.g., light readings at midday vs evening)
- AI now uses check readings to optimize care plans and identify health trends

## [0.9.6] - 2026-01-24

### Fixed
- Rotate task icon now uses correct URL
- Skip task modal header now shows the task icon instead of JSON object data

## [0.9.5] - 2026-01-20

### Added
- **Bulk skip tasks** - Skip all tasks of a type at once (e.g., skip all mist tasks when it's too cold)
- "Skip All" button appears alongside "Complete All" on the dashboard when grouped by location or task type

## [0.9.4] - 2026-01-20

### Changed
- **User notes now override species defaults** - AI will follow your explicit care preferences (e.g., "water 2x/week")
- Care plan generation now calculates due dates based on when tasks were last completed, not arbitrary future dates
- Overdue tasks are now scheduled for today when regenerating care plans

### Fixed
- "Database is locked" error when updating care plans from chat - enabled WAL mode and busy timeout
- Tasks that should be overdue are now properly marked as due today

## [0.9.3] - 2026-01-19

### Fixed
- Care plan regeneration now works correctly (was failing due to missing database column)
- Added missing `light_level` column to locations table

## [0.9.2] - 2026-01-19

### Changed
- Tasks within each location are now sorted by task type (water, mist, fertilize, etc.) for a more logical workflow

## [0.9.1] - 2026-01-17

### Added
- **AI Status Banner**: Dashboard shows warnings when AI service has issues or cron jobs haven't run
- **Cron Job Monitoring**: New `/cron/status` endpoint for tracking cron job execution history
- Cron jobs now log execution with success/failure tracking

### Changed
- Improved care schedule updates from AI chat - now directly updates task frequency instead of regenerating entire care plan
- PlantShareView now handles route params more robustly with watchers
- Better error handling for non-JSON responses in share view

### Fixed
- Care plan generation properly passes userId for AI logging attribution

## [0.9.0] - 2026-01-14

### Added
- **Species Care Guides**: Comprehensive care information for each plant species
  - View detailed care guides from the task list (info icon next to plant name)
  - Includes light, water, humidity, temperature, soil, and fertilizer requirements
  - Shows toxicity warnings, common issues, propagation tips, and fun facts
  - Care guides are generated automatically when adding plants or confirming species
- **AI Usage Logging**: Track all AI operations with detailed activity log
  - View AI connection status in Settings (Connected/Error/Not Configured)
  - See recent AI activity with success/failure indicators
  - Error count badge for issues in the last 24 hours
  - Test AI connection button to verify API key validity
- New API endpoints for AI status monitoring (`/settings/ai/status`, `/settings/ai/log`, `/settings/ai/test`)
- Species care info endpoint (`/plants/{id}/care-info`)

### Changed
- Species confirmation is now required when adding plants (removed skip option)
- Added helpful links to Google Lens and PlantNet for plant identification
- Improved species picker UI with better guidance for manual entry
- All AI operations now log success/failure for debugging

### Fixed
- Care plan generation now properly tracks user attribution for AI logging

## [0.8.1] - 2026-01-07

### Fixed
- HouseholdController now properly returns `is_owner` flag for household ownership detection
- HouseholdController returns `is_self` flag for member list so users can identify themselves
- Updated household icons to use home icon instead of family icon

## [0.8.0] - 2026-01-07

### Added
- **Household Plant Sharing**: Invite family members or roommates to help manage your plants
  - Create households and invite members via email
  - Choose to share all plants or select specific ones
  - Members can complete tasks on shared plants
  - Attribution shows who completed each task and care action
- New HouseholdView for managing households, members, and shared plants
- Invitation acceptance flow for joining households
- Household link in Settings quick links section

### Changed
- PlantController now supports shared plant access via households
- TaskController includes shared plants in task lists with attribution
- CareLogController logs who performed each action
- TaskItem component displays who completed tasks
- CareLogEntry component shows performer attribution

## [0.7.3] - 2025-01-06

### Added
- Daily email reminder feature for care tasks

## [0.7.2] - 2025-01-05

### Changed
- Task list redesign with search and grouping
- Various bug fixes

## [0.6.1] - 2025-01-04

### Added
- Plant sharing with social preview images (OG tags)

## [0.6.0] - 2025-01-03

### Added
- Task types customization
- Pot inventory management
- Plant graveyard for archived plants
- AI model selection in settings
