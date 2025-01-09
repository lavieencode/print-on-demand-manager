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
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('admin_init', array($this, 'register_settings'));
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
            array($this, 'render_settings_page'),
            'dashicons-store',
            30
        );

        add_submenu_page(
            'pod-manager',
            'Quick Add Product',
            'Quick Add',
            'manage_options',
            'pod-manager-quick-add',
            array($this, 'render_quick_add_page')
        );
    }

    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_scripts($hook) {
        if (strpos($hook, 'pod-manager') === false) {
            return;
        }

        wp_enqueue_style('pod-admin-style', POD_MANAGER_PLUGIN_URL . 'admin/css/admin.css', array(), POD_MANAGER_VERSION);
        wp_enqueue_style('pod-designer-style', POD_MANAGER_PLUGIN_URL . 'admin/css/designer.css', array(), POD_MANAGER_VERSION);
        
        wp_enqueue_script('pod-admin-script', POD_MANAGER_PLUGIN_URL . 'admin/js/admin.js', array('jquery'), POD_MANAGER_VERSION, true);
        
        wp_localize_script('pod-admin-script', 'podManagerAdmin', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => array(
                'refresh_cache' => wp_create_nonce('pod_refresh_cache'),
                'cancel_cache' => wp_create_nonce('pod_cancel_cache'),
                'get_cache_status' => wp_create_nonce('pod_get_cache_status'),
                'search_products' => wp_create_nonce('pod_search_products'),
                'upload_image' => wp_create_nonce('pod_upload_image'),
                'create_product' => wp_create_nonce('pod_create_product')
            )
        ));
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
}
