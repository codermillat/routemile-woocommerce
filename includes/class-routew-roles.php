<?php
if (!defined('ABSPATH')) {
    exit;
}
// phpcs:disable WordPress.Security.NonceVerification.Missing
// save_agent_phone_field() runs inside WordPress core's user-edit profile
// form (show_user_profile / edit_user_profile hooks). WP core verifies its
// own user-edit nonce before this callback fires; the `edit_user` capability
// check above also gates write access.

/**
 * Manages custom user roles for the plugin.
 *
 * @since      1.0.0
 * @package    RouteMile
 * @author     MD MILLAT HOSEN <https://millat.is-a.dev/>
 */
class ROUTEW_Roles
{

    /**
     * Initialize the class and set its properties.
     *
     * @since    1.0.0
     */
    public function __construct()
    {
        add_action('init', array($this, 'add_delivery_boy_role'));
        add_action('init', array($this, 'ensure_admin_cap'), 20);
        add_action('init', array($this, 'ensure_delivery_role_cap'), 20);
        // Agent mobile number — shown to the customer on order tracking,
        // used by the admin's per-agent performance table.
        add_action('show_user_profile', array($this, 'render_agent_phone_field'));
        add_action('edit_user_profile', array($this, 'render_agent_phone_field'));
        add_action('personal_options_update', array($this, 'save_agent_phone_field'));
        add_action('edit_user_profile_update', array($this, 'save_agent_phone_field'));
    }

    /**
     * Add the custom "Delivery Boy" role.
     *
     * Deliberately minimal. `read` alone is what grants basic wp-admin
     * access; `edit_posts` is NOT required for that and would needlessly
     * let riders edit posts, so it is not granted (1.2.15). Delivery
     * features are unlocked through the custom routew_delivery_access
     * capability instead. Only affects fresh installs — add_role() is
     * skipped when the role already exists; the transient-gated
     * ensure_delivery_role_cap() below keeps existing installs in sync
     * for routew_delivery_access.
     *
     * @since    1.0.0
     */
    public function add_delivery_boy_role()
    {
        // Check if the role already exists to avoid errors on reactivation.
        $role = get_role('delivery_boy');
        if ($role) {
            return;
        }

        add_role(
            'delivery_boy',
            __('Delivery Boy', 'routemile-for-woocommerce'),
            array(
                'read' => true,  // Core capability — grants basic wp-admin access on its own
                'upload_files' => false,
                'delete_posts' => false,
                'routew_delivery_access' => true,  // Custom capability for delivery access
            )
        );

        // Grant delivery access to administrators for testing purposes
        $admin_role = get_role('administrator');
        if ($admin_role) {
            $admin_role->add_cap('routew_delivery_access');
        }
    }

    /**
     * Ensure administrator role has delivery access capability.
     * 
     * Uses transient caching to avoid database queries on every request.
     *
     * @since    1.0.0
     */
    public function ensure_admin_cap()
    {
        // Only check once per day to avoid DB queries on every request
        if (get_transient('routew_admin_cap_checked')) {
            return;
        }

        $admin_role = get_role('administrator');
        if ($admin_role && !$admin_role->has_cap('routew_delivery_access')) {
            $admin_role->add_cap('routew_delivery_access');
        }

        set_transient('routew_admin_cap_checked', true, DAY_IN_SECONDS);
    }

    /**
     * Ensure delivery_boy role has delivery access capability.
     * 
     * Uses transient caching to avoid database queries on every request.
     *
     * @since    1.0.0
     */
    public function ensure_delivery_role_cap()
    {
        // Only check once per day to avoid DB queries on every request
        if (get_transient('routew_delivery_cap_checked')) {
            return;
        }

        $role = get_role('delivery_boy');
        if ($role && !$role->has_cap('routew_delivery_access')) {
            $role->add_cap('routew_delivery_access');
        }

        set_transient('routew_delivery_cap_checked', true, DAY_IN_SECONDS);
    }

    /**
     * Render the "Agent mobile number" profile field.
     *
     * Shown on the profile of delivery_boy users (and on any profile for
     * admins, so an owner can pre-fill it before the role is granted).
     * The number is displayed to customers on order tracking once an
     * agent is assigned, so it must be a real, dialable mobile number.
     *
     * @param WP_User $user Profile being edited.
     * @since 1.4.0
     */
    public function render_agent_phone_field($user)
    {
        if (!current_user_can('edit_user', $user->ID)) {
            return;
        }

        $is_agent = in_array('delivery_boy', (array) $user->roles, true);
        if (!$is_agent && !current_user_can('manage_options')) {
            return;
        }

        $phone = get_user_meta($user->ID, 'routew_agent_phone', true);
        ?>
        <h2><?php esc_html_e('RouteMile Agent', 'routemile-for-woocommerce'); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th>
                    <label for="routew_agent_phone"><?php esc_html_e('Agent mobile number', 'routemile-for-woocommerce'); ?></label>
                </th>
                <td>
                    <input type="tel" id="routew_agent_phone" name="routew_agent_phone" class="regular-text"
                        value="<?php echo esc_attr($phone); ?>"
                        autocomplete="tel" inputmode="tel" />
                    <p class="description">
                        <?php esc_html_e('Shown to the customer on order tracking while this agent has the order. Use the full dialable number, e.g. +91 98765 43210.', 'routemile-for-woocommerce'); ?>
                    </p>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Save the agent mobile number from the profile form.
     *
     * @param int $user_id User ID being saved.
     * @since 1.4.0
     */
    public function save_agent_phone_field($user_id)
    {
        if (!current_user_can('edit_user', $user_id)) {
            return;
        }

        if (!isset($_POST['routew_agent_phone'])) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WordPress core user-edit form provides its own nonce before reaching this hook
        $phone = sanitize_text_field(wp_unslash($_POST['routew_agent_phone']));
        if ('' === $phone) {
            delete_user_meta($user_id, 'routew_agent_phone');
            return;
        }

        update_user_meta($user_id, 'routew_agent_phone', $phone);
    }
}

new ROUTEW_Roles();

// phpcs:enable
