<?php
if (!defined('ABSPATH')) {
	exit;
}
// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_query, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
// Agent cash settlement engine: every meta_key/meta_value lookup is a
// settlement ledger query against `_routew_agent_*` user/order meta, which
// is the documented way to query per-agent state in WP. Inherent to
// settlement bookkeeping; no schema alternative. Re-enabled at EOF.

/**
 * Cash settlement engine for delivery agents.
 *
 * Workflow: the AGENT initiates a settlement request for their full
 * outstanding balance ("I am handing over ₹X"); a manager/admin
 * (edit_shop_orders) ACCEPTS or REJECTS it. Money leaves the agent's
 * balance only when a request is ACCEPTED — a pending request freezes
 * further requests, and a rejected one returns the amount to view.
 *
 * Ledger identity (holds at every state):
 *     unsettled = Σ delivered-COD totals − Σ ACCEPTED settlements
 * Pending/rejected entries are bookkeeping only; they never change the
 * balance. The request amount is computed server-side from that ledger,
 * so form input can never influence it.
 *
 * @since      1.4.0
 * @package    RouteMile
 * @author     MD Millat Hosen <https://millat.is-a.dev/>
 */
class ROUTEW_Agent_Cash
{

	const STATUS_PENDING = 'pending';
	const STATUS_ACCEPTED = 'accepted';
	const STATUS_REJECTED = 'rejected';

	/**
	 * Maximum entries kept in the per-store settlement ledger before
	 * older rows are dropped on the next write. Interim cap; the proper
	 * fix is a CPT (one row per settlement) — see v1.6.0 plans.
	 *
	 * 2 000 covers ~5 years of one-per-business-day entries per agent.
	 * (AUDIT-FIXES M7)
	 */
	const SETTLEMENT_LEDGER_CAP = 2000;

	/**
	 * Cash-settlement ledger option name.
	 *
	 * @since 1.4.0
	 * @return string
	 */
	public static function settlements_option()
	{
		return 'routew_cash_settlements';
	}

	/**
	 * All recorded cash settlements, newest first.
	 *
	 * Each entry: {id, agent_id, amount, requested_by, requested_at,
	 * status, reviewed_by, reviewed_at}. Legacy entries written by the
	 * pre-workflow build ({by_id, at}, no status) are normalised on read:
	 * they were self-recorded completions, so they count as accepted.
	 *
	 * @since 1.4.0
	 * @return array
	 */
	public static function get_settlements()
	{
		$settlements = get_option(self::settlements_option(), array());
		if (!is_array($settlements)) {
			return array();
		}

		$normalised = array();
		foreach ($settlements as $entry) {
			if (!is_array($entry) || !isset($entry['id'], $entry['agent_id'], $entry['amount'])) {
				continue; // malformed — skip rather than warn.
			}
			// Legacy shape: by_id/at instead of requested_by/requested_at,
			// and no status. They were recorded as completed settlements.
			if (isset($entry['by_id']) && !isset($entry['requested_by'])) {
				$entry['requested_by'] = $entry['by_id'];
				$entry['status'] = self::STATUS_ACCEPTED;
			}
			if (isset($entry['at']) && !isset($entry['requested_at'])) {
				$entry['requested_at'] = $entry['at'];
				$entry['reviewed_at'] = $entry['at'];
			}
			$entry['status'] = isset($entry['status']) ? (string) $entry['status'] : self::STATUS_ACCEPTED;
			$entry['requested_by'] = absint($entry['requested_by'] ?? 0);
			$entry['requested_at'] = absint($entry['requested_at'] ?? 0);
			$entry['reviewed_by'] = absint($entry['reviewed_by'] ?? 0);
			$entry['reviewed_at'] = absint($entry['reviewed_at'] ?? 0);
			$normalised[] = $entry;
		}
		return $normalised;
	}

	/**
	 * Find a settlement entry by id.
	 *
	 * @param string $id Settlement id.
	 * @return int Array key in get_settlements(), or -1.
	 * @since 1.4.0
	 */
	public static function find_index($id)
	{
		foreach (self::get_settlements() as $i => $entry) {
			if ((string) $entry['id'] === (string) $id) {
				return $i;
			}
		}
		return -1;
	}

	/**
	 * Cash summary for one agent.
	 *
	 * @param int $agent_id Delivery agent user ID.
	 * @return array{
	 *   collected_total: float, settled_total: float, unsettled: float,
	 *   pending: array|null, last_accepted: array|null,
	 *   unsettled_orders: array}
	 * @since 1.4.0
	 */
	public static function get_agent_cash_summary($agent_id)
	{
		$agent_id = absint($agent_id);
		$collected_total = 0.0;
		$unsettled_orders = array();
		$settled_total = 0.0;
		$pending = null;
		$last_accepted = null;

		foreach (self::get_settlements() as $entry) {
			if (absint($entry['agent_id']) !== $agent_id) {
				continue;
			}
			if (self::STATUS_ACCEPTED === $entry['status']) {
				$settled_total += (float) $entry['amount'];
				if (null === $last_accepted || (int) $entry['reviewed_at'] > (int) $last_accepted['reviewed_at']) {
					$last_accepted = $entry;
				}
			} elseif (self::STATUS_PENDING === $entry['status'] && null === $pending) {
				$pending = $entry;
			}
		}

		$delivered = wc_get_orders(array(
			'limit' => -1,
			'meta_key' => '_routew_delivery_boy_id',
			'meta_value' => $agent_id,
			'status' => array('completed'),
			'orderby' => 'date',
			'order' => 'DESC',
		));

		// Orders completed after the last ACCEPTED handover make up the
		// current outstanding balance; with no accepted settlement yet,
		// every delivered COD order does.
		$cutoff = $last_accepted ? (int) $last_accepted['reviewed_at'] : 0;
		foreach ($delivered as $order) {
			if ('cod' !== $order->get_payment_method()) {
				continue;
			}
			$collected_total += (float) $order->get_total();
			$completed = $order->get_date_completed();
			$ts = $completed ? $completed->getTimestamp() : 0;
			if ($ts > $cutoff) {
				$unsettled_orders[] = array(
					'id' => $order->get_id(),
					'number' => $order->get_order_number(),
					'total' => (float) $order->get_total(),
					'completed' => $ts,
				);
			}
		}

		return array(
			'collected_total' => $collected_total,
			'settled_total' => $settled_total,
			'unsettled' => max(0.0, $collected_total - $settled_total),
			'pending' => $pending,
			'last_accepted' => $last_accepted,
			'unsettled_orders' => $unsettled_orders,
		);
	}

	/**
	 * Agent initiates a settlement request for their FULL outstanding
	 * balance. Blocked while another request is already pending.
	 *
	 * @param int $agent_id     Agent whose cash is being handed over.
	 * @param int $requested_by Requesting user (the agent).
	 * @return float|null       The requested amount, or null when nothing to settle / already pending.
	 * @since 1.4.0
	 */
	public static function create_request($agent_id, $requested_by)
	{
		$summary = self::get_agent_cash_summary($agent_id);
		if ($summary['unsettled'] <= 0 || $summary['pending']) {
			return null;
		}

		// TOCTOU lock: a double-click on "Request handover" raced against
		// its own first read-modify-write and inserted two STATUS_PENDING
		// entries. The transient is short-lived (5s is plenty for this
		// single read-write) and is dropped on completion or failure.
		// (AUDIT-FIXES M5)
		$lock_key = 'routew_settle_lock_' . absint($agent_id);
		if (false !== get_transient($lock_key)) {
			return null;
		}
		set_transient($lock_key, 1, 5);

		try {
			$settlements = self::get_settlements();
			array_unshift($settlements, array(
				'id' => uniqid('fxwset_', true),
				'agent_id' => absint($agent_id),
				'amount' => (float) $summary['unsettled'],
				'requested_by' => absint($requested_by),
				'requested_at' => time(),
				'status' => self::STATUS_PENDING,
				'reviewed_by' => 0,
				'reviewed_at' => 0,
			));
			// TODO(m7): settle ledger is currently a single wp_options entry
			// capped at SETTLEMENT_LEDGER_CAP. Long-tenured agents were
			// seeing wrong "Cash to hand over" balances because the cap
			// dropped older entries on every write. Bumped from 200 to
			// SETTLEMENT_LEDGER_CAP entries (interim fix for v1.5.0); the
			// proper fix is a custom post type (routew_cash_settlement) per
			// agent, planned for v1.6.0. (AUDIT-FIXES M7)
			update_option(self::settlements_option(), array_slice($settlements, 0, self::SETTLEMENT_LEDGER_CAP), false);

			return (float) $summary['unsettled'];
		} finally {
			delete_transient($lock_key);
		}
	}

	/**
	 * Manager/admin decision on a pending request.
	 *
	 * @param string $id          Settlement id.
	 * @param string $decision    'accepted' or 'rejected'.
	 * @param int    $reviewed_by Deciding user.
	 * @return array|null         [agent_id, amount] on success, null when not found/not pending.
	 * @since 1.4.0
	 */
	public static function review_request($id, $decision, $reviewed_by)
	{
		if (!in_array($decision, array(self::STATUS_ACCEPTED, self::STATUS_REJECTED), true)) {
			return null;
		}

		// Compare-and-swap: only flip a row that is still PENDING. Concurrent
		// accept/reject would otherwise lose the second update (the first
		// writer's delete_meta_data + write, plus the second writer's
		// independently-read stale copy, leads to one of the status writes
		// disappearing). (AUDIT-FIXES M6)
		$lock_key = 'routew_review_lock_' . sanitize_text_field((string) $id);
		if (false !== get_transient($lock_key)) {
			return null;
		}
		set_transient($lock_key, 1, 5);

		try {
			$settlements = self::get_settlements();
			$index = -1;
			foreach ($settlements as $i => $entry) {
				if ((string) $entry['id'] === (string) $id && self::STATUS_PENDING === $entry['status']) {
					$index = $i;
					break;
				}
			}
			if ($index < 0) {
				return null;
			}

			$settlements[$index]['status'] = $decision;
			$settlements[$index]['reviewed_by'] = absint($reviewed_by);
			$settlements[$index]['reviewed_at'] = time();
			update_option(self::settlements_option(), $settlements, false);

			return array(
				'agent_id' => absint($settlements[$index]['agent_id']),
				'amount' => (float) $settlements[$index]['amount'],
			);
		} finally {
			delete_transient($lock_key);
		}
	}
}

new ROUTEW_Agent_Cash();

// phpcs:enable
