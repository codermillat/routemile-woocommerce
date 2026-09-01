/**
 * RouteMile receipt template — auto-print on load.
 *
 * Loaded by templates/receipt-template.php via a direct <script src>
 * tag because the receipt page is rendered via include_once + exit()
 * after a nonce + capability check (see print_receipt_shortcode() and
 * print_receipt_template()). The page has no wp_head/wp_footer cycle,
 * so wp_enqueue_script cannot fire here.
 */
window.onload = function () {
    window.print();
};
