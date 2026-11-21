<?php
declare(strict_types=1);

namespace RepairSync;

/** Repair lifecycle state machine and customer notification templates (port of server/workflow.js). */
final class Workflow
{
    public const STATUSES = ['booked', 'received', 'diagnosing', 'awaiting_approval', 'repairing', 'quality_check', 'completed', 'released', 'cancelled'];

    public const STATUS_LABEL = [
        'booked' => 'Booked',
        'received' => 'Received at shop',
        'diagnosing' => 'Diagnosing',
        'awaiting_approval' => 'Awaiting your approval',
        'repairing' => 'Under repair',
        'quality_check' => 'Quality check',
        'completed' => 'Ready for pick-up',
        'released' => 'Released to customer',
        'cancelled' => 'Cancelled',
    ];

    /** from => [to => required permission] */
    public const TRANSITIONS = [
        'booked'            => ['received' => 'repair.receive', 'cancelled' => 'repair.cancel'],
        'received'          => ['diagnosing' => 'repair.diagnose', 'cancelled' => 'repair.cancel'],
        'diagnosing'        => ['awaiting_approval' => 'repair.quote', 'cancelled' => 'repair.cancel'],
        'awaiting_approval' => ['repairing' => 'repair.approve_quote', 'diagnosing' => 'repair.diagnose', 'cancelled' => 'repair.cancel'],
        'repairing'         => ['quality_check' => 'repair.work', 'cancelled' => 'repair.cancel'],
        'quality_check'     => ['completed' => 'repair.work', 'repairing' => 'repair.work'],
        'completed'         => ['released' => 'repair.release'],
        'released'          => [],
        'cancelled'         => [],
    ];

    public static function isTerminal(string $s): bool { return $s === 'released' || $s === 'cancelled'; }

    /** Business-rule guards evaluated before a transition. Returns an error string or null. */
    public static function guard(array $repair, string $to, array $ctx = []): ?string
    {
        $itemsTotal = (float)($ctx['itemsTotal'] ?? 0);
        $paid = (float)($ctx['paid'] ?? 0);
        $override = (bool)($ctx['override'] ?? false);
        if ($to === 'awaiting_approval') {
            if (empty($repair['findings']) || strlen(trim($repair['findings'])) < 5) return 'Record the technician findings before sending a quote.';
            if ($itemsTotal <= 0) return 'Add at least one quote item (labour/part) before requesting approval.';
        }
        if ($to === 'quality_check' && empty($repair['assigned_to'])) return 'Assign a technician first.';
        if ($to === 'completed') {
            if (empty($repair['verified_diagnosis'])) return 'Record the verified diagnosis before completing the repair.';
            if (empty($repair['repair_action'])) return 'Record the repair action performed before completing.';
        }
        if ($to === 'released' && $itemsTotal - $paid > 0.009 && !$override) {
            return sprintf('Outstanding balance of PHP %.2f. Record payment before release.', $itemsTotal - $paid);
        }
        return null;
    }

    public static function customerMessage(array $repair, string $to, array $extra = []): string
    {
        $ref = $repair['code'];
        $parts = array_filter([$repair['brand'] ?? null, $repair['model'] ?? null]);
        $dev = $parts ? implode(' ', $parts) : $repair['device_type'];
        $p = 'RepairSync AI: ';
        return match ($to) {
            'booked' => "{$p}Your repair request $ref for your $dev is booked. Bring the device to the shop and show this code.",
            'received' => "{$p}We have received your $dev ($ref). A technician will inspect it shortly.",
            'diagnosing' => "{$p}Your $dev ($ref) is now being diagnosed.",
            'awaiting_approval' => sprintf('%sYour quote for %s is ready (PHP %.2f). Please approve or decline it via your tracking link.', $p, $ref, (float)($extra['total'] ?? 0)),
            'repairing' => "{$p}Repair of your $dev ($ref) has started.",
            'quality_check' => "{$p}Repair done; your $dev ($ref) is undergoing final quality checks.",
            'completed' => "{$p}Good news! Your $dev ($ref) is ready for pick-up." . (((float)($extra['balance'] ?? 0)) > 0 ? sprintf(' Balance due: PHP %.2f.', (float)$extra['balance']) : ''),
            'released' => "{$p}Thank you! $ref has been released. Keep your receipt for warranty.",
            'cancelled' => "{$p}Repair request $ref was cancelled." . (!empty($extra['note']) ? ' Reason: ' . $extra['note'] : ''),
            default => "{$p}Update on $ref: " . (self::STATUS_LABEL[$to] ?? $to) . '.',
        };
    }
}
