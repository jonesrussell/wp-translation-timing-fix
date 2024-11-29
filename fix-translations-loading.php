<?php
/**
 * Plugin Name: Fix Translations Loading
 * Plugin URI: https://github.com/yourusername/wp-translation-fixer
 * Description: Handles early translation loading issues in WordPress 6.7+ by preventing premature textdomain loading and moving hooks to the correct timing
 * Version: 1.0.3
 * Author: Your Name
 * Author URI: https://your-website.com
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * 
 * This plugin fixes translation loading issues by:
 * 1. Moving plugins_loaded hooks to init when they handle translations
 * 2. Preventing early translation loading before init
 * 3. Reloading translations at the correct time
 * 
 * Particularly useful for:
 * - Bedrock installations
 * - Sites using mu-plugins
 * - Multilingual WordPress sites
 * 
 * @package WP_Translation_Fixer
 */

class WP_Translation_Fixer {
    /** @var self|null Singleton instance */
    private static $instance = null;
    
    /** @var array Tracks which text domains have been processed to prevent duplicates */
    private $processed_domains = [];
    
    /** @var array Tracks which hooks have been processed to prevent duplicates */
    private $processed_hooks = [];
    
    /** @var string Unique identifier for tracking actions within a single request */
    private $request_id;

    /**
     * Singleton getter
     * @return self
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Initialize the fixer and set up hooks
     */
    private function __construct() {
        $this->request_id = uniqid();
        $this->init_hooks();
    }

    /**
     * Register all necessary WordPress hooks
     */
    private function init_hooks() {
        // Early hooks to catch and fix translation loading
        add_action('plugins_loaded', [$this, 'adjust_plugin_hooks'], -999);
        add_action('plugins_loaded', [$this, 'prevent_early_translations'], -999);
        
        // Load translations at the correct time
        add_action('init', [$this, 'load_translations'], 20);
        
        // Monitor for translation loading issues
        add_filter('doing_it_wrong_trigger_error', [$this, 'log_translation_issue'], 10, 4);
    }

    /**
     * Determine if we should log based on WordPress debug settings and request type
     * @return bool
     */
    private function should_log(): bool {
        return defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG &&
               !(defined('DOING_AJAX') || defined('REST_REQUEST') || 
                 defined('DOING_CRON') || defined('XMLRPC_REQUEST') ||
                 strpos($_SERVER['PHP_SELF'], 'admin-ajax.php') !== false || 
                 strpos($_SERVER['PHP_SELF'], 'wp-cron.php') !== false);
    }

    /**
     * Log a message with context if debugging is enabled
     * @param string $message The message to log
     * @param array $context Additional context data
     */
    private function log($message, $context = []) {
        if (!$this->should_log()) {
            return;
        }

        // Prevent duplicate messages within the same request
        static $logged_messages = [];
        $message_key = md5($message . serialize($context));
        
        if (isset($logged_messages[$message_key])) {
            return;
        }
        
        $logged_messages[$message_key] = true;

        // Add request tracking data
        $context['request_id'] = $this->request_id;
        $context['url'] = $_SERVER['REQUEST_URI'] ?? '';

        error_log(sprintf(
            '[Translation Fixer] %s | %s',
            $message,
            json_encode($context)
        ));
    }

    /**
     * Log translation loading issues detected by WordPress
     */
    public function log_translation_issue($show_error, $errno, $errstr, $errfile) {
        if (strpos($errstr, '_load_textdomain_just_in_time') !== false) {
            $this->log('Translation loading issue detected', [
                'message' => strip_tags($errstr),
                'file' => $errfile
            ]);
        }
        return false;
    }

    /**
     * Scan and adjust plugins that load translations too early
     */
    public function adjust_plugin_hooks() {
        global $wp_filter;
        
        if (!isset($wp_filter['plugins_loaded'])) {
            return;
        }

        foreach ($wp_filter['plugins_loaded']->callbacks as $priority => $callbacks) {
            foreach ($callbacks as $id => $callback) {
                $this->process_callback($callback, $priority, $id);
            }
        }
    }

    /**
     * Process a single callback to determine if it needs to be moved to init
     * @param array $callback WordPress hook callback array
     * @param int $priority Hook priority
     * @param string $id Callback ID
     */
    private function process_callback($callback, $priority, $id) {
        // Generate a unique ID for this callback
        $callback_id = is_array($callback['function'])
            ? (is_object($callback['function'][0])
                ? spl_object_hash($callback['function'][0]) . $callback['function'][1]
                : $callback['function'][0] . '::' . $callback['function'][1])
            : (is_object($callback['function'])
                ? spl_object_hash($callback['function'])
                : (string)$callback['function']);
        
        $callback_id = md5($callback_id . $priority);
        
        if (isset($this->processed_hooks[$callback_id])) {
            return;
        }

        // Handle object method callbacks
        if (is_array($callback['function']) && is_object($callback['function'][0])) {
            $object = $callback['function'][0];
            $class_name = get_class($object);

            if (method_exists($object, 'plugins_loaded')) {
                $this->log("Moving hook to init", [
                    'class' => $class_name,
                    'priority' => $priority
                ]);

                remove_action('plugins_loaded', [$object, 'plugins_loaded'], $priority);
                add_action('init', [$object, 'plugins_loaded'], 5);
                $this->processed_hooks[$callback_id] = true;
            }
        } 
        // Handle static method callbacks
        elseif (is_array($callback['function']) && is_string($callback['function'][0])) {
            $class_name = $callback['function'][0];
            
            if (method_exists($class_name, 'get_instance') && method_exists($class_name, 'plugins_loaded')) {
                $this->log("Moving static hook to init", [
                    'class' => $class_name,
                    'priority' => $priority
                ]);

                remove_action('plugins_loaded', [$class_name, 'plugins_loaded'], $priority);
                $instance = call_user_func([$class_name, 'get_instance']);
                add_action('init', [$instance, 'plugins_loaded'], 5);
                $this->processed_hooks[$callback_id] = true;
            }
        }
    }

    /**
     * Prevent plugins from loading translations before init
     */
    public function prevent_early_translations() {
        add_filter("pre_load_textdomain", function($override, $domain) {
            if (!did_action('init') && !isset($this->processed_domains[$domain])) {
                $this->log("Prevented early translation loading", [
                    'domain' => $domain
                ]);
                $this->processed_domains[$domain] = true;
                return true;
            }
            return $override;
        }, 10, 2);
    }

    /**
     * Load translations for all active plugins at the correct time
     */
    public function load_translations() {
        if (!did_action('setup_theme')) {
            return;
        }

        $active_plugins = apply_filters('active_plugins', get_option('active_plugins'));
        
        foreach ($active_plugins as $plugin) {
            $domain = dirname($plugin);
            $plugin_path = WP_PLUGIN_DIR . "/{$plugin}";
            
            if (file_exists($plugin_path) && !isset($this->processed_domains[$domain . '_loaded'])) {
                $rel_path = dirname(plugin_basename($plugin_path));
                
                if (load_plugin_textdomain($domain, false, $rel_path . '/languages')) {
                    $this->processed_domains[$domain . '_loaded'] = true;
                }
            }
        }
    }
}

// Initialize the fixer
WP_Translation_Fixer::get_instance();
