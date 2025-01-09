<?php
/**
 * Database management class for Print on Demand Manager
 */
class POD_DB_Manager {
    /**
     * Get instance of this class
     */
    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Get table definitions
     * 
     * Following WordPress dbDelta requirements:
     * 1. Primary key must be first
     * 2. Must have two spaces between PRIMARY KEY and the definition
     * 3. Must have KEY in the key definition
     * 4. Must not use IF NOT EXISTS in CREATE TABLE
     * 5. Field types must be lowercase
     */
    public function get_table_definitions() {
        global $wpdb;
        
        return array(
            $wpdb->prefix . 'pod_printify_blueprints' => array(
                'id' => 'bigint(20) unsigned NOT NULL auto_increment',
                'blueprint_id' => 'varchar(255) NOT NULL',
                'title' => 'varchar(255) NOT NULL',
                'data' => 'longtext NOT NULL',
                'last_updated' => 'datetime NOT NULL',
                'PRIMARY KEY' => 'PRIMARY KEY  (id)',
                'KEY blueprint_id' => 'KEY blueprint_id (blueprint_id)',
                'KEY last_updated' => 'KEY last_updated (last_updated)'
            ),
            
            $wpdb->prefix . 'pod_printify_providers' => array(
                'id' => 'bigint(20) unsigned NOT NULL auto_increment',
                'provider_id' => 'varchar(255) NOT NULL',
                'blueprint_id' => 'varchar(255) NOT NULL',
                'title' => 'varchar(255) NOT NULL',
                'data' => 'longtext NOT NULL',
                'last_updated' => 'datetime NOT NULL',
                'PRIMARY KEY' => 'PRIMARY KEY  (id)',
                'KEY provider_blueprint' => 'KEY provider_blueprint (provider_id,blueprint_id)',
                'KEY blueprint_id' => 'KEY blueprint_id (blueprint_id)',
                'KEY last_updated' => 'KEY last_updated (last_updated)'
            ),
            
            $wpdb->prefix . 'pod_printify_variants' => array(
                'id' => 'bigint(20) unsigned NOT NULL auto_increment',
                'variant_id' => 'varchar(255) NOT NULL',
                'blueprint_id' => 'varchar(255) NOT NULL',
                'provider_id' => 'varchar(255) NOT NULL',
                'data' => 'longtext NOT NULL',
                'last_updated' => 'datetime NOT NULL',
                'PRIMARY KEY' => 'PRIMARY KEY  (id)',
                'KEY variant_blueprint_provider' => 'KEY variant_blueprint_provider (variant_id,blueprint_id,provider_id)',
                'KEY blueprint_provider' => 'KEY blueprint_provider (blueprint_id,provider_id)',
                'KEY last_updated' => 'KEY last_updated (last_updated)'
            )
        );
    }

    /**
     * Create database tables
     */
    public function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        $tables = $this->get_table_definitions();
        
        foreach ($tables as $table_name => $columns) {
            error_log("POD Manager: Creating table $table_name");
            
            $sql = array();
            $sql[] = "CREATE TABLE $table_name (";
            
            // Add regular columns
            $column_definitions = array();
            foreach ($columns as $column => $definition) {
                if ($column !== 'PRIMARY KEY' && strpos($column, 'KEY') !== 0) {
                    $column_definitions[] = "$column $definition";
                }
            }
            
            // Add primary key
            if (isset($columns['PRIMARY KEY'])) {
                $column_definitions[] = "PRIMARY KEY  (id)";
            }
            
            // Add unique keys
            foreach ($columns as $column => $definition) {
                if (strpos($column, 'UNIQUE KEY') === 0) {
                    $column_definitions[] = str_replace('UNIQUE KEY ', 'UNIQUE KEY ', $definition);
                }
            }
            
            // Add regular keys
            foreach ($columns as $column => $definition) {
                if (strpos($column, 'KEY') === 0 && strpos($column, 'UNIQUE KEY') !== 0 && $column !== 'PRIMARY KEY') {
                    $column_definitions[] = str_replace('KEY ', 'KEY ', $definition);
                }
            }
            
            $sql[] = implode(",\n", $column_definitions);
            $sql[] = ") $charset_collate;";
            
            $final_sql = implode("\n", $sql);
            error_log("POD Manager: SQL for $table_name:\n$final_sql");
            
            dbDelta($final_sql);
            
            // Verify table exists
            $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
            if (!$table_exists) {
                error_log("POD Manager: Failed to create table $table_name");
                throw new Exception("Failed to create table $table_name");
            }
        }
    }

    /**
     * Drop database tables
     */
    public function drop_tables() {
        global $wpdb;
        
        $tables = array_keys($this->get_table_definitions());
        
        foreach ($tables as $table) {
            $wpdb->query("DROP TABLE IF EXISTS $table");
        }
    }

    /**
     * Clear all data from tables
     */
    public function truncate_tables() {
        global $wpdb;
        
        $tables = array_keys($this->get_table_definitions());
        
        foreach ($tables as $table) {
            $wpdb->query("TRUNCATE TABLE $table");
        }
    }

    /**
     * Check if tables exist
     */
    public function tables_exist() {
        global $wpdb;
        
        $tables = array_keys($this->get_table_definitions());
        $missing_tables = array();
        
        foreach ($tables as $table) {
            if ($wpdb->get_var("SHOW TABLES LIKE '$table'") != $table) {
                $missing_tables[] = $table;
            }
        }
        
        return empty($missing_tables) ? true : $missing_tables;
    }

    /**
     * Store data in a table
     * 
     * @param string $table_name Table name without prefix
     * @param array $data Data to store
     * @return int|false The number of rows inserted, or false on error
     */
    public function insert_data($table_name, $data) {
        global $wpdb;
        
        $table = $wpdb->prefix . $table_name;
        
        error_log("POD Manager: Inserting data into {$table}");
        
        // Add last_updated if not present
        if (!isset($data['last_updated'])) {
            $data['last_updated'] = current_time('mysql');
        }
        
        // Convert any array/object data to JSON
        foreach ($data as $key => $value) {
            if (is_array($value) || is_object($value)) {
                $data[$key] = wp_json_encode($value);
            }
        }
        
        $result = $wpdb->insert($table, $data);
        
        if ($result === false) {
            error_log("POD Manager: Database error inserting into {$table}: " . $wpdb->last_error);
            return false;
        }
        
        error_log("POD Manager: Successfully inserted data into {$table}");
        return $result;
    }

    /**
     * Update data in a table
     * 
     * @param string $table_name Table name without prefix
     * @param array $data Data to update
     * @param array $where Where clause
     * @return int|false The number of rows updated, or false on error
     */
    public function update_data($table_name, $data, $where) {
        global $wpdb;
        
        $table = $wpdb->prefix . $table_name;
        
        error_log("POD Manager: Updating data in {$table}");
        
        // Add last_updated if not present
        if (!isset($data['last_updated'])) {
            $data['last_updated'] = current_time('mysql');
        }
        
        // Convert any array/object data to JSON
        foreach ($data as $key => $value) {
            if (is_array($value) || is_object($value)) {
                $data[$key] = wp_json_encode($value);
            }
        }
        
        $result = $wpdb->update($table, $data, $where);
        
        if ($result === false) {
            error_log("POD Manager: Database error updating {$table}: " . $wpdb->last_error);
            return false;
        }
        
        error_log("POD Manager: Successfully updated data in {$table}");
        return $result;
    }

    /**
     * Get cached data summary
     * 
     * @return array Cache statistics and sample data
     */
    public function get_cache_summary() {
        global $wpdb;
        
        $summary = array(
            'blueprints' => array(
                'count' => 0,
                'last_updated' => null,
                'sample' => null
            ),
            'providers' => array(
                'count' => 0,
                'last_updated' => null,
                'sample' => null
            ),
            'variants' => array(
                'count' => 0,
                'last_updated' => null,
                'sample' => null
            )
        );
        
        // Get blueprint stats
        $blueprint_table = $wpdb->prefix . 'pod_printify_blueprints';
        $blueprint_stats = $wpdb->get_row(
            "SELECT COUNT(*) as count, MAX(last_updated) as last_updated 
             FROM {$blueprint_table}"
        );
        
        if ($blueprint_stats) {
            $summary['blueprints']['count'] = (int)$blueprint_stats->count;
            $summary['blueprints']['last_updated'] = $blueprint_stats->last_updated;
            
            // Get a sample blueprint
            if ($summary['blueprints']['count'] > 0) {
                $sample = $wpdb->get_row(
                    "SELECT blueprint_id, title, data 
                     FROM {$blueprint_table} 
                     ORDER BY last_updated DESC 
                     LIMIT 1"
                );
                if ($sample) {
                    $summary['blueprints']['sample'] = array(
                        'id' => $sample->blueprint_id,
                        'title' => $sample->title,
                        'data' => json_decode($sample->data, true)
                    );
                }
            }
        }
        
        // Get provider stats
        $provider_table = $wpdb->prefix . 'pod_printify_providers';
        $provider_stats = $wpdb->get_row(
            "SELECT COUNT(*) as count, MAX(last_updated) as last_updated 
             FROM {$provider_table}"
        );
        
        if ($provider_stats) {
            $summary['providers']['count'] = (int)$provider_stats->count;
            $summary['providers']['last_updated'] = $provider_stats->last_updated;
            
            // Get a sample provider
            if ($summary['providers']['count'] > 0) {
                $sample = $wpdb->get_row(
                    "SELECT provider_id, blueprint_id, title, data 
                     FROM {$provider_table} 
                     ORDER BY last_updated DESC 
                     LIMIT 1"
                );
                if ($sample) {
                    $summary['providers']['sample'] = array(
                        'id' => $sample->provider_id,
                        'blueprint_id' => $sample->blueprint_id,
                        'title' => $sample->title,
                        'data' => json_decode($sample->data, true)
                    );
                }
            }
        }
        
        // Get variant stats
        $variant_table = $wpdb->prefix . 'pod_printify_variants';
        $variant_stats = $wpdb->get_row(
            "SELECT COUNT(*) as count, MAX(last_updated) as last_updated 
             FROM {$variant_table}"
        );
        
        if ($variant_stats) {
            $summary['variants']['count'] = (int)$variant_stats->count;
            $summary['variants']['last_updated'] = $variant_stats->last_updated;
            
            // Get a sample variant
            if ($summary['variants']['count'] > 0) {
                $sample = $wpdb->get_row(
                    "SELECT variant_id, blueprint_id, provider_id, data 
                     FROM {$variant_table} 
                     ORDER BY last_updated DESC 
                     LIMIT 1"
                );
                if ($sample) {
                    $summary['variants']['sample'] = array(
                        'id' => $sample->variant_id,
                        'blueprint_id' => $sample->blueprint_id,
                        'provider_id' => $sample->provider_id,
                        'data' => json_decode($sample->data, true)
                    );
                }
            }
        }
        
        return $summary;
    }
}
