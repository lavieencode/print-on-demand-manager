<?php
/**
 * Handles the admin menu and settings pages
 */
class POD_Admin_Menu {
    /**
     * Initialize the admin menu
     */
    public function init() {
        add_action('admin_menu', array($this, 'add_menu_pages'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
    }

    /**
     * Add menu pages
     */
    public function add_menu_pages() {
        add_menu_page(
            'Print on Demand Manager',
            'POD Manager',
            'manage_options',
            'pod-manager',
            array($this, 'render_quick_add_page'),
            'dashicons-cart'
        );

        // Add Quick Add as the first submenu item (replaces the duplicate)
        add_submenu_page(
            'pod-manager',
            'Quick Add Products',
            'Quick Add',
            'manage_options',
            'pod-manager',
            array($this, 'render_quick_add_page')
        );

        // Add Designer page
        add_submenu_page(
            'pod-manager',
            'Product Designer',
            'Designer',
            'manage_options',
            'pod-manager-designer',
            array($this, 'render_designer_page')
        );

        // Add Settings page
        add_submenu_page(
            'pod-manager',
            'POD Manager Settings',
            'Settings',
            'manage_options',
            'pod-manager-settings',
            array($this, 'render_settings_page')
        );
    }

    /**
     * Register the stylesheets and JavaScript for the admin area.
     */
    public function enqueue_admin_scripts($hook) {
        // Check if we're on any of our plugin pages
        $valid_hooks = array(
            'toplevel_page_pod-manager',
            'pod-manager_page_pod-manager-settings',
            'pod-manager_page_pod-manager-designer'
        );
        
        if (!in_array($hook, $valid_hooks)) {
            error_log('POD Manager: Skipping script load for hook: ' . $hook . ' (not in valid hooks)');
            return;
        }

        error_log('POD Manager: Loading scripts for hook: ' . $hook);

        // Get the plugin base URL and path
        $plugin_dir = plugin_dir_path(dirname(__FILE__));
        $plugin_url = plugin_dir_url(dirname(__FILE__));

        error_log('POD Manager: Plugin directory: ' . $plugin_dir);
        error_log('POD Manager: Plugin URL: ' . $plugin_url);

        // Define file paths and URLs
        $css_path = $plugin_dir . 'admin/css/admin.css';
        $js_path = $plugin_dir . 'admin/js/admin.js';
        $css_url = $plugin_url . 'admin/css/admin.css';
        $js_url = $plugin_url . 'admin/js/admin.js';

        error_log('POD Manager: CSS path exists: ' . (file_exists($css_path) ? 'yes' : 'no'));
        error_log('POD Manager: JS path exists: ' . (file_exists($js_path) ? 'yes' : 'no'));
        error_log('POD Manager: CSS URL: ' . $css_url);
        error_log('POD Manager: JS URL: ' . $js_url);

        // Enqueue styles
        wp_enqueue_style(
            'pod-admin-css',
            $css_url,
            array(),
            defined('WP_DEBUG') && WP_DEBUG ? time() : POD_MANAGER_VERSION
        );

        // Enqueue scripts
        wp_enqueue_script(
            'pod-admin-js',
            $js_url,
            array('jquery'),
            defined('WP_DEBUG') && WP_DEBUG ? time() : POD_MANAGER_VERSION,
            true
        );

        // Add our script data
        $nonces = array(
            'refresh_cache' => wp_create_nonce('pod_ajax_nonce'),
            'get_cache_status' => wp_create_nonce('pod_ajax_nonce'),
            'cancel_cache' => wp_create_nonce('pod_ajax_nonce'),
            'view_cache' => wp_create_nonce('pod_ajax_nonce'),
            'debug_cache' => wp_create_nonce('pod_ajax_nonce'),
            'verify_connection' => wp_create_nonce('pod_ajax_nonce')
        );
        
        error_log('POD Manager: Generated nonces for actions: ' . implode(', ', array_keys($nonces)));
        
        wp_localize_script('pod-admin-js', 'podManagerAdmin', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonces' => $nonces,
            'debug' => WP_DEBUG
        ));
        
        error_log('POD Manager: Admin scripts enqueued successfully');
    }

    /**
     * Register settings
     */
    public function register_settings() {
        register_setting('pod_manager_settings', 'pod_printify_api_key');
        register_setting('pod_manager_settings', 'pod_printify_shop_id');
    }

    /**
     * Render settings page
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Include the settings page template
        include POD_MANAGER_PLUGIN_DIR . 'admin/views/settings-page.php';
    }

    /**
     * Render quick add page
     */
    public function render_quick_add_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Include the quick add page template
        include POD_MANAGER_PLUGIN_DIR . 'admin/views/quick-add-page.php';
    }

    /**
     * Render the designer page
     */
    public function render_designer_page() {
        require_once POD_MANAGER_PLUGIN_DIR . 'admin/views/designer-page.php';
    }

    private function render_cache_controls() {
        ?>
        <div class="pod-cache-controls">
            <button type="button" id="pod-update-cache" class="button">
                <span class="dashicons dashicons-update"></span>
                Update Cache Now
            </button>
            <button type="button" id="pod-debug-cache" class="button">
                <span class="dashicons dashicons-admin-tools"></span>
                Reset Cache Flags
            </button>
            <button type="button" id="pod-view-cache" class="button">
                <span class="dashicons dashicons-visibility"></span>
                View Cache Data
            </button>
        </div>
        <div id="pod-cache-data" style="display: none;"></div>
        <?php
    }
}
