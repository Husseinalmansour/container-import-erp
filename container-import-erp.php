<?php
/**
 * Plugin Name: Container Import ERP System
 * Description: ERP-style container import costing system with CCC & CSLC landed cost allocation.
 * Version: 3.0.0
 * Author: Hussein Al-Mansour
 * Author URI: https://hussein.pro
 * License: GPL2+
 */

if (!defined('ABSPATH')) {
    exit; // Prevent direct access.
}

/**
 * Main Plugin Class (Singleton)
 *
 * Handles:
 * - Database table creation
 * - Admin UI
 * - Landed cost calculations
 * - Print export
 */
final class CIEP_Container_Import_ERP {

    /**
     * Singleton instance
     *
     * @var CIEP_Container_Import_ERP|null
     */
    private static $instance = null;

    /**
     * Database table name
     *
     * @var string
     */
    private $table_name;

    /**
     * Get singleton instance
     *
     * @return CIEP_Container_Import_ERP
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     * Private to prevent direct instantiation
     */
    private function __construct() {

        global $wpdb;
        $this->table_name = $wpdb->prefix . 'container_products';

        register_activation_hook(__FILE__, [$this, 'create_tables']);

        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('admin_init', [$this, 'handle_print_export']);
    }

    /* =====================================================
       ACTIVATION: CREATE DATABASE TABLE
    ===================================================== */

    /**
     * Create plugin database table
     *
     * Uses dbDelta for safe upgrades.
     *
     * @return void
     */
    public function create_tables() {

        global $wpdb;

        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$this->table_name} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            invoice_value DECIMAL(15,2) NOT NULL DEFAULT 0,
            quantity INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) $charset;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /* =====================================================
       ADMIN MENU
    ===================================================== */

    /**
     * Register admin menu page
     *
     * @return void
     */
    public function register_admin_menu() {
        add_menu_page(
            'Import ERP',
            'Import ERP',
            'manage_options',
            'import-erp',
            [$this, 'render_admin_page'],
            'dashicons-chart-pie',
            26
        );
    }

    /* =====================================================
       ADMIN PAGE RENDER
    ===================================================== */

    /**
     * Render admin UI and handle form submissions
     *
     * @return void
     */
    public function render_admin_page() {

        if (!current_user_can('manage_options')) {
            return;
        }

        global $wpdb;

        // Handle form actions
        $this->handle_form_actions();

        $products   = $wpdb->get_results("SELECT * FROM {$this->table_name}");
        $ccc_total  = (float) get_option('ciep_ccc_total_usd', 0);
        $cslc_total = (float) get_option('ciep_cslc_total_usd', 0);

        ?>
        <div class="wrap">
            <h1>📦 Container Import ERP (CCC & CSLC Allocation)</h1>

            <?php $this->render_container_settings($ccc_total, $cslc_total); ?>
            <hr>
            <?php $this->render_add_product_form(); ?>
            <hr>
            <?php $this->render_products_table($products, $ccc_total, $cslc_total); ?>

            <br>
            <a href="<?php echo esc_url(admin_url('admin.php?page=import-erp&ciep_print=1')); ?>"
               target="_blank"
               class="button button-secondary">
               📄 Export PDF (Print)
            </a>
        </div>
        <?php
    }
    
    
    
    
    
    /* =====================================================
       Render CCC & CSLC settings form
    ===================================================== */
  
    private function render_container_settings($ccc_total, $cslc_total) {
        ?>
        <h2>Container Cost Settings</h2>
        <form method="post">
            <?php wp_nonce_field('ciep_save_costs'); ?>
            <table class="form-table">
                <tr>
                    <th>Total CCC (USD)</th>
                    <td>
                        <input type="number" step="0.01" name="ccc_total"
                               value="<?php echo esc_attr($ccc_total); ?>" required>
                    </td>
                </tr>
                <tr>
                    <th>Total CSLC (USD)</th>
                    <td>
                        <input type="number" step="0.01" name="cslc_total"
                               value="<?php echo esc_attr($cslc_total); ?>" required>
                    </td>
                </tr>
            </table>
            <button name="save_container_cost" class="button button-primary">
                Save Container Costs
            </button>
        </form>
        <?php
    }
    
    
    
    
    /* =====================================================
       Render Add Product form
    ===================================================== */

    private function render_add_product_form() {
        ?>
        <h2>Add Product (China Invoice Price)</h2>
        <form method="post">
            <?php wp_nonce_field('ciep_add_product'); ?>
            <input type="text" name="name" placeholder="Product Name" required>
            <input type="number" step="0.01" name="invoice_value"
                   placeholder="Product CCC Price (USD)" required>
            <input type="number" name="quantity"
                   placeholder="Quantity" required>
            <button name="add_product" class="button button-primary">
                Add Product
            </button>
        </form>
        <?php
    }
    
    

    /* =====================================================
       FORM HANDLING
    ===================================================== */

    /**
     * Process admin form submissions
     *
     * @return void
     */
    private function handle_form_actions() {

        global $wpdb;

        if (!empty($_POST['save_container_cost'])) {
            check_admin_referer('ciep_save_costs');

            update_option('ciep_ccc_total_usd', floatval($_POST['ccc_total']));
            update_option('ciep_cslc_total_usd', floatval($_POST['cslc_total']));
        }

        if (!empty($_POST['add_product'])) {
            check_admin_referer('ciep_add_product');

            $wpdb->insert($this->table_name, [
                'name'          => sanitize_text_field($_POST['name']),
                'invoice_value' => floatval($_POST['invoice_value']),
                'quantity'      => intval($_POST['quantity']),
            ]);
        }

        if (!empty($_POST['clear_products'])) {
            check_admin_referer('ciep_clear_products');
            $wpdb->query("TRUNCATE TABLE {$this->table_name}");
        }
    }

    /* =====================================================
       CALCULATION ENGINE
    ===================================================== */

    /**
     * Perform landed cost allocation
     *
     * @param array $products
     * @param float $ccc_total
     * @param float $cslc_total
     * @return array
     */
    private function calculate_landed_cost($products, $ccc_total, $cslc_total) {

        if ($ccc_total <= 0) {
            return $products;
        }

        foreach ($products as $p) {

            $ccc_percent       = $p->invoice_value / $ccc_total;
            $allocated_cslc    = $ccc_percent * $cslc_total;
            $final_total_cost  = $p->invoice_value + $allocated_cslc;
            $landed_per_unit   = $final_total_cost / max(1, $p->quantity);

            $p->ccc_percent          = $ccc_percent;
            $p->allocated_cslc       = $allocated_cslc;
            $p->final_total_cost     = $final_total_cost;
            $p->landed_cost_per_unit = $landed_per_unit;
        }

        return $products;
    }

    /* =====================================================
       TABLE RENDER
    ===================================================== */

    /**
     * Render product table with calculated totals
     *
     * @param array $products
     * @param float $ccc_total
     * @param float $cslc_total
     * @return void
     */
    private function render_products_table($products, $ccc_total, $cslc_total) {

        $products = $this->calculate_landed_cost($products, $ccc_total, $cslc_total);

        echo '<form method="post" onsubmit="return confirm(\'Delete all products?\');">';
        wp_nonce_field('ciep_clear_products');
        echo '<input type="hidden" name="clear_products" value="1">';
        echo '<button class="button button-danger">🗑 Clear All Products</button>';
        echo '</form><br>';

        echo '<table class="widefat fixed striped">';
        echo '<thead><tr>
            <th>Product</th>
            <th>Qty</th>
            <th>CCC Price (USD)</th>
            <th>CCC %</th>
            <th>Allocated CSLC</th>
            <th>Final Landed Cost</th>
            <th>Landed / Unit</th>
        </tr></thead><tbody>';

        $total_qty = $total_ccc = $total_alloc = $total_final = 0;

        foreach ($products as $p) {

            $total_qty   += $p->quantity;
            $total_ccc   += $p->invoice_value;
            $total_alloc += $p->allocated_cslc ?? 0;
            $total_final += $p->final_total_cost ?? 0;

            echo '<tr>
                <td>'.esc_html($p->name).'</td>
                <td>'.intval($p->quantity).'</td>
                <td>$'.number_format($p->invoice_value,2).'</td>
                <td>'.round(($p->ccc_percent ?? 0)*100,2).'%</td>
                <td>$'.number_format($p->allocated_cslc ?? 0,2).'</td>
                <td><strong>$'.number_format($p->final_total_cost ?? 0,2).'</strong></td>
                <td><strong>$'.number_format($p->landed_cost_per_unit ?? 0,2).'</strong></td>
            </tr>';
        }

        echo '<tr style="font-weight:bold;background:#f4f4f4;">
            <td>TOTAL</td>
            <td>'.$total_qty.'</td>
            <td>$'.number_format($total_ccc,2).'</td>
            <td>100%</td>
            <td>$'.number_format($total_alloc,2).'</td>
            <td>$'.number_format($total_final,2).'</td>
            <td>-</td>
        </tr>';

        echo '</tbody></table>';
    }

    /* =====================================================
       PRINT EXPORT
    ===================================================== */

    /**
     * Handle print export trigger
     *
     * @return void
     */
    public function handle_print_export() {

        if (isset($_GET['ciep_print'])) {
            $this->print_report();
            exit;
        }
    }

    /**
     * Render printable HTML report
     *
     * @return void
     */
    private function print_report() {

        if (!current_user_can('manage_options')) {
            wp_die('Permission denied');
        }

        global $wpdb;

        $products   = $wpdb->get_results("SELECT * FROM {$this->table_name}");
        $ccc_total  = (float) get_option('ciep_ccc_total_usd', 0);
        $cslc_total = (float) get_option('ciep_cslc_total_usd', 0);

        $products = $this->calculate_landed_cost($products, $ccc_total, $cslc_total);

        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <title>Container Import ERP Report</title>
            <style>
                body { font-family: Arial; padding:20px; }
                table { border-collapse: collapse; width:100%; }
                th, td { border:1px solid #000; padding:6px; text-align:center; }
                th { background:#eee; }
            </style>
        </head>
        <body>
            <h2>📦 Container Import ERP Report</h2>
            <p><strong>CCC Total:</strong> $<?php echo number_format($ccc_total,2); ?></p>
            <p><strong>CSLC Total:</strong> $<?php echo number_format($cslc_total,2); ?></p>

            <table>
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Qty</th>
                        <th>CCC Price</th>
                        <th>CCC %</th>
                        <th>Allocated CSLC</th>
                        <th>Final Cost</th>
                        <th>Landed / Unit</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($products as $p): ?>
                    <tr>
                        <td><?php echo esc_html($p->name); ?></td>
                        <td><?php echo intval($p->quantity); ?></td>
                        <td>$<?php echo number_format($p->invoice_value,2); ?></td>
                        <td><?php echo round($p->ccc_percent*100,2); ?>%</td>
                        <td>$<?php echo number_format($p->allocated_cslc,2); ?></td>
                        <td>$<?php echo number_format($p->final_total_cost,2); ?></td>
                        <td>$<?php echo number_format($p->landed_cost_per_unit,2); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <script>
                window.onload = function() { window.print(); };
            </script>
        </body>
        </html>
        <?php
    }
}

/**
 * Boot the plugin
 */
CIEP_Container_Import_ERP::get_instance();