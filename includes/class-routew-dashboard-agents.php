<?php
if (!defined('ABSPATH')) {
	exit;
}
/**
 * Delivery-agent work tracking + cash settlement rendering.
 *
 * Sibling of ROUTEW_Dashboard_Render (v1.2.9 split pattern): renders the
 * per-agent performance table, the settle-cash action, the per-agent
 * unsettled-order breakdown, and the recent-settlements ledger on the
 * admin Deliveries dashboard. Numbers reuse
 * ROUTEW_Delivery_Boy_View::build_dashboard_state() and
 * ROUTEW_Agent_Cash::get_agent_cash_summary() so admin and agent
 * dashboards always agree.
 *
 * @since      1.4.0
 * @package    RouteMile
 * @author     MD Millat Hosen <https://millat.is-a.dev/>
 */
class ROUTEW_Dashboard_Agents
{

	/**
	 * Render the whole section (called from ROUTEW_Dashboard_Render).
	 *
	 * @since 1.4.0
	 */
	public static function render()
	{
		$agents = get_users(array('role' => 'delivery_boy', 'orderby' => 'display_name'));
		if (empty($agents)) {
			return;
		}
		?>
		<div class="routew-dashboard-section">
			<h2><?php esc_html_e('Delivery Agents — Work Tracking', 'routemile-woocommerce'); ?></h2>
			<table class="wp-list-table widefat fixed striped routew-agent-performance">
				<thead>
					<tr>
						<th><?php esc_html_e('Agent', 'routemile-woocommerce'); ?></th>
						<th><?php esc_html_e('Mobile', 'routemile-woocommerce'); ?></th>
						<th><?php esc_html_e('Active now', 'routemile-woocommerce'); ?></th>
						<th><?php esc_html_e('Delivered today', 'routemile-woocommerce'); ?></th>
						<th><?php esc_html_e('Cash collected today', 'routemile-woocommerce'); ?></th>
						<th><?php esc_html_e('Cash to hand over', 'routemile-woocommerce'); ?></th>
						<th><?php esc_html_e('All-time delivered', 'routemile-woocommerce'); ?></th>
						<th><?php esc_html_e('Settle cash', 'routemile-woocommerce'); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($agents as $agent): ?>
						<?php
						$state = class_exists('ROUTEW_Delivery_Boy_View')
							? ROUTEW_Delivery_Boy_View::build_dashboard_state($agent->ID)
							: array('counts' => array('new' => 0, 'in_progress' => 0, 'delivered' => 0), 'today' => array('delivered' => 0, 'collected' => 0));
						$cash = class_exists('ROUTEW_Agent_Cash') ? ROUTEW_Agent_Cash::get_agent_cash_summary($agent->ID) : array('unsettled' => 0, 'unsettled_orders' => array(), 'pending' => null, 'last_accepted' => null);
						$mobile = trim((string) get_user_meta($agent->ID, 'routew_agent_phone', true));
						if ('' === $mobile) {
							$mobile = trim((string) get_user_meta($agent->ID, 'billing_phone', true));
						}
						$active = absint($state['counts']['new']) + absint($state['counts']['in_progress']);
						?>
						<tr>
							<td><strong><?php echo esc_html($agent->display_name); ?></strong></td>
							<td>
								<?php if ($mobile): ?>
									<a href="tel:<?php echo esc_attr(preg_replace('/[^0-9+]/', '', $mobile)); ?>"><?php echo esc_html($mobile); ?></a>
								<?php else: ?>
									<span class="routew-muted"><?php esc_html_e('Not set', 'routemile-woocommerce'); ?></span>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html($active); ?></td>
							<td><?php echo esc_html($state['today']['delivered']); ?></td>
							<td><?php echo wp_kses_post(wc_price($state['today']['collected'], array('currency' => get_woocommerce_currency()))); ?></td>
							<td>
								<strong><?php echo wp_kses_post(wc_price($cash['unsettled'], array('currency' => get_woocommerce_currency()))); ?></strong>
								<?php if (!empty($cash['unsettled_orders'])): ?>
									<details class="routew-settle-details">
										<summary>
											<?php
											// translators: %d = number of orders.
											printf(esc_html__('%d order(s) in this balance', 'routemile-woocommerce'), count($cash['unsettled_orders']));
											?>
										</summary>
										<ul class="routew-settle-orders">
											<?php foreach ($cash['unsettled_orders'] as $uo): ?>
												<li>
													<a href="<?php echo esc_url(admin_url('admin.php?page=wc-orders&action=edit&id=' . absint($uo['id']))); ?>">#<?php echo esc_html($uo['number']); ?></a>
													— <?php echo wp_kses_post(wc_price($uo['total'], array('currency' => get_woocommerce_currency()))); ?>
													<span class="routew-muted">(<?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), (int) $uo['completed'])); ?>)</span>
												</li>
											<?php endforeach; ?>
										</ul>
									</details>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html($state['counts']['delivered']); ?></td>
							<td>
								<?php if ($cash['pending']): ?>
									<em class="routew-muted"><?php esc_html_e('Hand-over pending approval', 'routemile-woocommerce'); ?></em>
								<?php elseif ($cash['unsettled'] > 0): ?>
									<span class="routew-muted">
										<?php
										// translators: %s = formatted amount.
										printf(esc_html__('Agent holds %s — awaiting their request', 'routemile-woocommerce'), wp_kses_post(wc_price($cash['unsettled'], array('currency' => get_woocommerce_currency()))));
										?>
									</span>
								<?php elseif ($cash['last_accepted']): ?>
									<span class="routew-muted">
										<?php
										$approver = get_user_by('id', absint($cash['last_accepted']['reviewed_by']));
										/* translators: 1: date/time, 2: approver name. */
										printf(esc_html__('Settled %1$s (by %2$s)', 'routemile-woocommerce'),
											esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), (int) $cash['last_accepted']['reviewed_at'])),
											esc_html($approver ? $approver->display_name : __('manager', 'routemile-woocommerce'))
										);
										?>
									</span>
								<?php else: ?>
									<span class="routew-muted">—</span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description">
				<?php esc_html_e('Active = assigned + picked up. Agents see the same numbers for themselves on their dashboard and can self-report a handover there.', 'routemile-woocommerce'); ?>
			</p>

			<?php self::render_recent_settlements(); ?>
		</div>
		<?php
	}

	/**
	 * Cash hand-over requests + history (newest first, last 15).
	 * Pending rows carry Accept / Reject actions for managers/admins.
	 *
	 * @since 1.4.0
	 */
	private static function render_recent_settlements()
	{
		if (!class_exists('ROUTEW_Agent_Cash')) {
			return;
		}

		$settlements = array_slice(ROUTEW_Agent_Cash::get_settlements(), 0, 15);
		?>
		<h3><?php esc_html_e('Cash hand-overs — requests & history', 'routemile-woocommerce'); ?></h3>
		<table class="wp-list-table widefat fixed striped routew-settlements">
			<thead>
				<tr>
					<th><?php esc_html_e('Requested', 'routemile-woocommerce'); ?></th>
					<th><?php esc_html_e('Agent', 'routemile-woocommerce'); ?></th>
					<th><?php esc_html_e('Amount', 'routemile-woocommerce'); ?></th>
					<th><?php esc_html_e('Status', 'routemile-woocommerce'); ?></th>
					<th><?php esc_html_e('Reviewed by', 'routemile-woocommerce'); ?></th>
					<th><?php esc_html_e('Actions', 'routemile-woocommerce'); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if (empty($settlements)): ?>
					<tr><td colspan="6"><?php esc_html_e('No hand-over requests yet. Agents send these from their dashboard when they hand cash to the store.', 'routemile-woocommerce'); ?></td></tr>
				<?php endif; ?>
				<?php foreach ($settlements as $entry): ?>
					<?php
					$agent = get_user_by('id', absint($entry['agent_id']));
					$requester = get_user_by('id', absint($entry['requested_by']));
					$reviewer = absint($entry['reviewed_by']) ? get_user_by('id', absint($entry['reviewed_by'])) : null;
					$is_pending = ROUTEW_Agent_Cash::STATUS_PENDING === $entry['status'];
					?>
					<tr class="<?php echo $is_pending ? 'routew-settlement-pending' : ''; ?>">
						<td><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), (int) $entry['requested_at'])); ?></td>
						<td><strong><?php echo esc_html($agent ? $agent->display_name : ('#' . absint($entry['agent_id']))); ?></strong></td>
						<td><strong><?php echo wp_kses_post(wc_price($entry['amount'], array('currency' => get_woocommerce_currency()))); ?></strong></td>
						<td>
							<span class="routew-settle-status routew-settle-status--<?php echo esc_attr($entry['status']); ?>">
								<?php
								if (ROUTEW_Agent_Cash::STATUS_PENDING === $entry['status']) {
									esc_html_e('Pending approval', 'routemile-woocommerce');
								} elseif (ROUTEW_Agent_Cash::STATUS_ACCEPTED === $entry['status']) {
									esc_html_e('Accepted', 'routemile-woocommerce');
								} else {
									esc_html_e('Rejected', 'routemile-woocommerce');
								}
								?>
							</span>
						</td>
						<td>
							<?php if ($reviewer): ?>
								<?php echo esc_html($reviewer->display_name); ?><br>
								<span class="routew-muted"><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), (int) $entry['reviewed_at'])); ?></span>
							<?php else: ?>
								<span class="routew-muted">—</span>
							<?php endif; ?>
						</td>
						<td>
							<?php if ($is_pending && current_user_can('edit_shop_orders')): ?>
								<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-right:6px;">
									<?php wp_nonce_field('routew_review_cash_' . $entry['id']); ?>
									<input type="hidden" name="action" value="routew_review_settlement" />
									<input type="hidden" name="settlement_id" value="<?php echo esc_attr($entry['id']); ?>" />
									<input type="hidden" name="decision" value="accepted" />
									<button type="submit" class="button button-small button-primary"><?php esc_html_e('Accept', 'routemile-woocommerce'); ?></button>
								</form>
								<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;">
									<?php wp_nonce_field('routew_review_cash_' . $entry['id']); ?>
									<input type="hidden" name="action" value="routew_review_settlement" />
									<input type="hidden" name="settlement_id" value="<?php echo esc_attr($entry['id']); ?>" />
									<input type="hidden" name="decision" value="rejected" />
									<button type="submit" class="button button-small routew-reject-btn"><?php esc_html_e('Reject', 'routemile-woocommerce'); ?></button>
								</form>
							<?php elseif ($is_pending): ?>
								<span class="routew-muted"><?php esc_html_e('Awaiting a manager', 'routemile-woocommerce'); ?></span>
							<?php else: ?>
								<span class="routew-muted">—</span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description">
			<?php esc_html_e('Agents request a hand-over of their collected cash; accepting clears it from their balance. Rejecting keeps the amount on the agent\'s balance.', 'routemile-woocommerce'); ?>
		</p>
		<?php
	}
}

new ROUTEW_Dashboard_Agents();
