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
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_scripts($hook) {
        error_log('POD Manager: Hook = ' . $hook);
        
        // Only load on our plugin pages
        if ($hook === 'toplevel_page_pod-manager' || // Main/Quick Add page
            $hook === 'pod-manager_page_pod-manager-designer' || // Designer page
            $hook === 'pod-manager_page_pod-manager-settings') { // Settings page
            
            error_log('POD Manager: Loading scripts for hook: ' . $hook);
            
            // Enqueue jQuery first
            wp_enqueue_script('jquery');
            
            // Get the URLs using plugin_dir_url for full URL path
            $css_url = POD_MANAGER_PLUGIN_URL . 'admin/css/admin.css';
            $js_url = POD_MANAGER_PLUGIN_URL . 'admin/js/admin.js';
            
            error_log('POD Manager: CSS URL = ' . $css_url);
            error_log('POD Manager: JS URL = ' . $js_url);
            
            // Enqueue our files with cache busting
            wp_enqueue_style(
                'pod-admin-style', 
                $css_url, 
                array(), 
                POD_MANAGER_VERSION
            );
            
            wp_enqueue_script(
                'pod-admin-script', 
                $js_url, 
                array('jquery'), 
                POD_MANAGER_VERSION, 
                true
            );
            
            // Add our script data
            wp_localize_script('pod-admin-script', 'podManagerAdmin', array(
                'ajaxurl' => admin_url('admin-ajax.php')
            ));
        }
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
}
