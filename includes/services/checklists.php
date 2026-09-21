<?php
/**
 * ChecklistsService — the single home for the SOP-checklist rules that other
 * modules have to obey.
 *
 * Contributed as PR #141 by Santhosh Srinivasan; this layer was added during
 * integration so the one rule that reaches outside the module — "a ticket with
 * outstanding mandatory steps does not close" — is written once instead of
 * being re-stated by every caller that can close a ticket.
 *
 * Follows the three service rules (wiki: Service-Layer-Architecture):
 *   1. method(PDO, ActorContext, …) — no superglobals
 *   2. no transport — returns data or throws ServiceError, never echoes
 *   3. typed errors — ServiceError(kind, code, message)
 *
 * ──────────────────────────────────────────────────────────────────────────
 * 🔴 WHY THE GATE IS HERE AND NOT IN THE BROWSER
 *
 * As contributed, the gate was an alert() in assets/js/inbox.js and nothing
 * else. The UI refused to close the ticket; the v1 REST API, bulk actions, the
 * workflow engine and one line of curl all closed it with every mandatory step
 * outstanding. For a feature whose entire purpose is ISO/SOP compliance, an
 * unenforced gate is worse than no gate — it reports control that isn't there.
 *
 * ⚠️ AND WHY IT CAN ALWAYS BE OVERRIDDEN.
 *
 * assets/js/inbox.js already carries the house position, written for #83 and
 * sitting three lines below where the checklist gate was inserted:
 *
 *     "closing a ticket that still has unfinished tasks WARNS, it never blocks
 *      … a warning that cannot be shown must not become a block that cannot be
 *      cleared."
 *
 * That is right, and a hard block breaks it: the analyst who owned step 4 has
 * left, the step is obsolete, and the ticket is stuck forever with no way out
 * but SQL. So the rule is not "you cannot close this". It is:
 *
 *     close it with the steps outstanding and the override is RECORDED —
 *     who closed it, when, and which steps were skipped.
 *
 * Which is what a compliance feature should produce anyway. An auditor does not
 * want a system where the bad outcome was impossible; they want one where it is
 * attributable. Blocking hides the exception, recording it surfaces it.
 * ──────────────────────────────────────────────────────────────────────────
 */

require_once __DIR__ . '/../service_context.php';
require_once __DIR__ . '/../tenant_settings.php';   // ticketChecklistClosureMode()

class ChecklistsService
{
    /**
     * Mandatory steps still outstanding on a ticket.
     *
     * @return array<int, array{checklist: string, step: string, item_id: int}>
     */
    public static function outstandingMandatorySteps(PDO $conn, int $ticketId): array
    {
        if ($ticketId <= 0) return [];

        // Tolerate the tables not existing yet: a site that has never opened the
        // Checklists module must not have its ticket closures start failing.
        try {
            $stmt = $conn->prepare(
                "SELECT i.id AS item_id, i.title AS step, c.title AS checklist, c.closure_mode
                   FROM ticket_checklist_items i
                   JOIN ticket_checklists c ON c.id = i.ticket_checklist_id
                  WHERE c.ticket_id = ?
                    AND i.is_mandatory = 1
                    AND i.is_completed = 0
                  ORDER BY c.id ASC, i.sort_order ASC, i.id ASC"
            );
            $stmt->execute([$ticketId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Called by TicketsService when a ticket moves to a closed status.
     *
     * Records the closure against any outstanding mandatory steps rather than
     * refusing it — see the header. Returns the steps that were outstanding so
     * the caller can tell the analyst what was skipped; empty array = clean close.
     *
     * @return array<int, array{checklist: string, step: string, item_id: int}>
     */
    /**
     * Refuse the closure outright if the operator chose 'block'.
     *
     * 🔴 MUST be called BEFORE the status is written. Called after, a 'block'
     * install would throw having already closed the ticket - the caller sees an
     * error and the database disagrees with it.
     *
     * Tickets → Settings → Checklists decides. Enforced here rather than in the
     * browser so the REST API, bulk actions and automation obey whichever the
     * operator chose; that is the whole reason the rule left inbox.js.
     */
    /**
     * Resolve a checklist's effective closure mode against the company setting.
     */
    public static function effectiveClosureMode(string $mode, PDO $conn, ?int $tenantId = null): string
    {
        // Master company override: if set to 'block_all', all checklists block closure.
        if (function_exists('ticketChecklistClosureMode')) {
            $companyPolicy = ticketChecklistClosureMode($conn, $tenantId);
            if ($companyPolicy === 'block_all' || $companyPolicy === 'block') {
                return 'block';
            }
        }

        // Per-template policy: respect the individual checklist template setting.
        return ($mode === 'block') ? 'block' : 'warn';
    }

    public static function assertClosureAllowed(PDO $conn, int $ticketId, ?int $tenantId = null): void
    {
        $outstanding = self::outstandingMandatorySteps($conn, $ticketId);
        if (!$outstanding) return;

        $blocking = [];
        foreach ($outstanding as $step) {
            $mode = self::effectiveClosureMode($step['closure_mode'] ?? 'inherit', $conn, $tenantId);
            if ($mode === 'block') {
                $blocking[] = $step;
            }
        }

        if (!$blocking) return;

        $grouped = [];
        foreach ($blocking as $r) {
            $cName = $r['checklist'] ?: 'Checklist';
            $grouped[$cName][] = $r['step'];
        }
        $parts = [];
        foreach ($grouped as $cName => $steps) {
            $parts[] = $cName . ' (' . implode(', ', $steps) . ')';
        }
        $summary = implode('; ', $parts);

        throw new ServiceError(
            'validation',
            'mandatory_steps_outstanding',
            'This ticket cannot be closed until mandatory SOP steps are complete: ' . $summary
        );
    }

    public static function recordClosureOverride(PDO $conn, ActorContext $ctx, int $ticketId): array
    {
        $outstanding = self::outstandingMandatorySteps($conn, $ticketId);
        if (!$outstanding) return [];

        $who  = $ctx->actorName !== '' ? $ctx->actorName : ('analyst #' . $ctx->actorId);
        $via  = $ctx->source === 'api' ? 'the API' : 'the web interface';
        $by   = $ctx->source === 'workflow' ? 'Closed by a workflow.' : "Closed by {$who} via {$via}.";
        $list = implode("\n", array_map(
            fn($r) => '  • ' . $r['checklist'] . ' — ' . $r['step'],
            $outstanding
        ));
        $note = "⚠️ Ticket closed with " . count($outstanding) . " mandatory SOP step(s) outstanding.\n"
              . "{$by}\n\n{$list}";

        // An internal note, because that is where this module already writes its
        // audit trail and where an auditor will look. UTC at rest (GH #126).
        //
        // A workflow has no analyst, and ticket_notes.analyst_id is NOT NULL with a
        // foreign key, so a workflow close writes a 'Workflow Note' audit entry
        // instead - the same place the workflow engine's own notes go.
        try {
            if ($ctx->actorId > 0) {
                $conn->prepare(
                    "INSERT INTO ticket_notes (ticket_id, analyst_id, note_text, is_internal, created_datetime)
                     VALUES (?, ?, ?, 1, UTC_TIMESTAMP())"
                )->execute([$ticketId, $ctx->actorId, $note]);
            } else {
                $conn->prepare(
                    "INSERT INTO ticket_audit (ticket_id, analyst_id, field_name, old_value, new_value, created_datetime)
                     VALUES (?, NULL, 'Workflow Note', NULL, ?, UTC_TIMESTAMP())"
                )->execute([$ticketId, $note]);
            }
        } catch (Throwable $e) {
            // A failed audit note must not block the close it is describing, but
            // it must not pass silently either.
            error_log('[checklists] could not record closure override for ticket '
                . $ticketId . ': ' . $e->getMessage());
        }

        return $outstanding;
    }

    // ======================================================================
    //  Lookup lists — checklist categories and suggested roles
    //
    //  Two tables with identical shape (id, name) and identical rules, so one
    //  pair of methods serves both rather than four near-identical copies. The
    //  table is chosen from a whitelist, never from caller input, so `$kind`
    //  can never reach SQL.
    // ======================================================================

    private const LOOKUPS = [
        'category' => ['table' => 'checklist_categories', 'label' => 'Category'],
        'role'     => ['table' => 'checklist_roles',      'label' => 'Role'],
    ];

    private static function lookup(string $kind): array
    {
        if (!isset(self::LOOKUPS[$kind])) {
            throw new ServiceError('validation', 'invalid_field', 'Unknown list: ' . $kind);
        }
        return self::LOOKUPS[$kind];
    }

    /**
     * Create (no id) or rename (id present) a category or role. Returns the id.
     *
     * Renaming matters more than it looks: templates store their category as
     * TEXT (`checklist_templates.category`) and steps store their suggested role
     * the same way, so the rows have to be carried across with the rename or the
     * list silently orphans every template that used the old name.
     */
    public static function saveLookup(PDO $conn, ActorContext $ctx, string $kind, array $in): int
    {
        ['table' => $table, 'label' => $label] = self::lookup($kind);

        $name = trim((string)($in['name'] ?? ''));
        if ($name === '')            throw new ServiceError('validation', 'missing_field', $label . ' name is required.');
        if (mb_strlen($name) > 100)  throw new ServiceError('validation', 'invalid_field', $label . ' name is too long (100 characters maximum).');

        $id = isset($in['id']) ? (int)$in['id'] : 0;

        // Names are UNIQUE in both tables; catch the clash here so the caller
        // gets a sentence rather than a driver error.
        $clash = $conn->prepare("SELECT id FROM `$table` WHERE name = ? AND id <> ? LIMIT 1");
        $clash->execute([$name, $id]);
        if ($clash->fetchColumn() !== false) {
            throw new ServiceError('conflict', 'duplicate', $label . ' "' . $name . '" already exists.');
        }

        if ($id > 0) {
            $cur = $conn->prepare("SELECT name FROM `$table` WHERE id = ? LIMIT 1");
            $cur->execute([$id]);
            $oldName = $cur->fetchColumn();
            if ($oldName === false) throw new ServiceError('not_found', 'not_found', $label . ' not found.');

            if ($oldName !== $name) {
                $conn->beginTransaction();
                try {
                    $conn->prepare("UPDATE `$table` SET name = ? WHERE id = ?")->execute([$name, $id]);
                    // Carry the rows that reference it by name.
                    if ($kind === 'category') {
                        $conn->prepare("UPDATE checklist_templates SET category = ? WHERE category = ?")->execute([$name, $oldName]);
                    } else {
                        $conn->prepare("UPDATE checklist_template_items SET suggested_role = ? WHERE suggested_role = ?")->execute([$name, $oldName]);
                        $conn->prepare("UPDATE ticket_checklist_items  SET suggested_role = ? WHERE suggested_role = ?")->execute([$name, $oldName]);
                    }
                    $conn->commit();
                } catch (Throwable $e) {
                    $conn->rollBack();
                    throw $e;
                }
            }
            return $id;
        }

        $conn->prepare("INSERT INTO `$table` (name, created_datetime) VALUES (?, UTC_TIMESTAMP())")->execute([$name]);
        return (int)$conn->lastInsertId();
    }

    /**
     * Delete a category or role.
     *
     * The rows that reference it by name are left alone deliberately: a template
     * filed under a category you retire keeps saying what it said. Losing the
     * list entry should not silently re-file somebody's SOP.
     */
    public static function deleteLookup(PDO $conn, ActorContext $ctx, string $kind, int $id): void
    {
        ['table' => $table, 'label' => $label] = self::lookup($kind);
        if ($id <= 0) throw new ServiceError('validation', 'invalid_field', 'A valid id is required.');

        $stmt = $conn->prepare("DELETE FROM `$table` WHERE id = ?");
        $stmt->execute([$id]);
        if ($stmt->rowCount() === 0) {
            throw new ServiceError('not_found', 'not_found', $label . ' not found.');
        }
    }
}
