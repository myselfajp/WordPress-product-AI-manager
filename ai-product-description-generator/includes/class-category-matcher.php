<?php
/**
 * Category Matcher Class
 * Prevents new category creation and matches to existing categories using AI
 */

if (!defined('ABSPATH')) {
    exit;
}

class AI_Product_Description_Category_Matcher {
    
    private $option_name = 'ai_product_desc_category_cache';
    private $cache_duration = DAY_IN_SECONDS; // 24 hours
    private $api_handler;
    private $settings_option_name = 'ai_product_desc_settings';
    private static $processing_categories = array(); // Prevent infinite loops
    
    public function __construct() {
        $this->api_handler = new AI_Product_Description_API_Handler();
        
        // Check if feature is enabled
        $settings = get_option($this->settings_option_name);
        if (!isset($settings['prevent_new_categories']) || $settings['prevent_new_categories'] !== '1') {
            return; // Feature disabled, don't hook anything
        }
        
        // Hook to prevent category creation
        add_filter('pre_insert_term', array($this, 'prevent_new_category_creation'), 10, 2);
        
        // Hook to intercept category assignment during import (if available)
        if (has_filter('woocommerce_product_import_pre_set_product_terms')) {
            add_filter('woocommerce_product_import_pre_set_product_terms', array($this, 'match_categories_before_import'), 10, 2);
        }
        
        // Hook to handle category assignment for new/updated products
        add_action('set_object_terms', array($this, 'intercept_category_assignment'), 10, 6);
        
        // Additional hook for product import to handle categories after product is created
        add_action('woocommerce_product_import_inserted_product_object', array($this, 'handle_imported_product_categories'), 10, 2);
        
        // Clear cache when categories are modified
        add_action('created_product_cat', array($this, 'clear_category_cache'));
        add_action('edited_product_cat', array($this, 'clear_category_cache'));
        add_action('delete_product_cat', array($this, 'clear_category_cache'));
    }
    
    /**
     * Get all existing categories with cache
     */
    private function get_existing_categories() {
        // Try to get from cache first
        $cached = get_transient($this->option_name);
        if ($cached !== false) {
            return $cached;
        }
        
        // Get all categories from database
        $categories = get_terms(array(
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
            'hierarchical' => true
        ));
        
        if (is_wp_error($categories) || empty($categories)) {
            return array();
        }
        
        // Format categories for easier matching
        $formatted = array();
        foreach ($categories as $cat) {
            $formatted[] = array(
                'id' => $cat->term_id,
                'name' => $cat->name,
                'slug' => $cat->slug,
                'parent' => $cat->parent,
                'full_path' => $this->get_category_full_path($cat->term_id)
            );
        }
        
        // Cache for 24 hours
        set_transient($this->option_name, $formatted, $this->cache_duration);
        
        return $formatted;
    }
    
    /**
     * Get full category path (parent > child)
     */
    private function get_category_full_path($term_id) {
        $path = array();
        $term = get_term($term_id, 'product_cat');
        
        while ($term && !is_wp_error($term)) {
            array_unshift($path, $term->name);
            if ($term->parent) {
                $term = get_term($term->parent, 'product_cat');
            } else {
                break;
            }
        }
        
        return implode(' > ', $path);
    }
    
    /**
     * Prevent new category creation - redirect to matching function
     */
    public function prevent_new_category_creation($term, $taxonomy) {
        // Only prevent product categories
        if ($taxonomy !== 'product_cat') {
            return $term;
        }
        
        // Check if category already exists
        $existing = term_exists($term, 'product_cat');
        if ($existing) {
            return $term; // Allow if exists
        }
        
        // Get existing categories
        $existing_categories = $this->get_existing_categories();
        
        if (empty($existing_categories)) {
            // No categories exist, allow creation (first category)
            return $term;
        }
        
        // Find best match using AI
        $matched = $this->find_best_category_match($term, $existing_categories);
        
        if ($matched) {
            // Return existing category name to prevent creation
            // This will cause WordPress to use the existing category
            return $matched['name'];
        }
        
        // If no match found, prevent creation
        return false; // Prevent new category creation
    }
    
    /**
     * Add category and all its parents to terms array
     */
    private function add_category_with_parents($matched_category, &$terms_array) {
        // Add the matched category
        if (!in_array($matched_category['name'], $terms_array)) {
            $terms_array[] = $matched_category['name'];
        }
        
        // Add all parents recursively
        if ($matched_category['parent'] > 0) {
            $parent_term = get_term($matched_category['parent'], 'product_cat');
            if ($parent_term && !is_wp_error($parent_term)) {
                // Check if parent already exists in our categories list
                $existing_categories = $this->get_existing_categories();
                foreach ($existing_categories as $cat) {
                    if ($cat['id'] == $parent_term->term_id) {
                        $this->add_category_with_parents($cat, $terms_array);
                        break;
                    }
                }
            }
        }
    }
    
    /**
     * Match categories before import
     */
    public function match_categories_before_import($terms, $product) {
        if (empty($terms) || !is_array($terms)) {
            return $terms;
        }
        
        if (empty($terms['product_cat'])) {
            return $terms;
        }
        
        $existing_categories = $this->get_existing_categories();
        
        if (empty($existing_categories)) {
            return $terms; // No existing categories, allow all
        }
        
        $matched_categories = array();
        
        foreach ($terms['product_cat'] as $category_name) {
            if (empty($category_name)) {
                continue;
            }
            
            // Check if category exists
            $existing = term_exists($category_name, 'product_cat');
            if ($existing) {
                // Add category and its parents
                foreach ($existing_categories as $cat) {
                    if ($cat['name'] === $category_name) {
                        $this->add_category_with_parents($cat, $matched_categories);
                        break;
                    }
                }
                continue;
            }
            
            // Find best match using AI
            $matched = $this->find_best_category_match($category_name, $existing_categories);
            if ($matched) {
                // Add matched category and all its parents
                $this->add_category_with_parents($matched, $matched_categories);
            }
        }
        
        if (!empty($matched_categories)) {
            $terms['product_cat'] = $matched_categories;
        } else {
            unset($terms['product_cat']);
        }
        
        return $terms;
    }
    
    /**
     * Intercept category assignment
     */
    public function intercept_category_assignment($object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids) {
        if ($taxonomy !== 'product_cat') {
            return;
        }
        
        // Prevent infinite loop
        $cache_key = $object_id . '_' . md5(serialize($terms));
        if (isset(self::$processing_categories[$cache_key])) {
            return;
        }
        
        // Only process if terms are being set (not removed)
        if (empty($terms) || !is_array($terms)) {
            return;
        }
        
        // Get product info
        $product = wc_get_product($object_id);
        if (!$product) {
            return;
        }
        
        // Get existing categories
        $existing_categories = $this->get_existing_categories();
        
        if (empty($existing_categories)) {
            return; // No existing categories, allow all
        }
        
        // Check each term being assigned
        $needs_update = false;
        $new_terms = array();
        
        foreach ($terms as $term_name) {
            if (empty($term_name)) {
                continue;
            }
            
            // Check if category exists
            $existing = term_exists($term_name, 'product_cat');
            if ($existing) {
                // Add category and its parents
                foreach ($existing_categories as $cat) {
                    if ($cat['name'] === $term_name) {
                        $this->add_category_with_parents($cat, $new_terms);
                        break;
                    }
                }
                continue;
            }
            
            // Find best match
            $matched = $this->find_best_category_match($term_name, $existing_categories);
            if ($matched) {
                // Add matched category and all its parents
                $this->add_category_with_parents($matched, $new_terms);
                $needs_update = true;
            }
        }
        
        // If we found matches, update the terms
        if ($needs_update && !empty($new_terms)) {
            // Mark as processing
            self::$processing_categories[$cache_key] = true;
            
            // Set the matched categories
            wp_set_object_terms($object_id, $new_terms, 'product_cat', $append);
            
            // Unmark after processing
            unset(self::$processing_categories[$cache_key]);
        }
    }
    
    /**
     * Handle categories for imported products
     */
    public function handle_imported_product_categories($product, $data) {
        if (!$product || !is_a($product, 'WC_Product')) {
            return;
        }
        
        $product_id = $product->get_id();
        
        // Get current product categories
        $current_categories = wp_get_post_terms($product_id, 'product_cat', array('fields' => 'names'));
        
        if (empty($current_categories) || is_wp_error($current_categories)) {
            return;
        }
        
        $existing_categories = $this->get_existing_categories();
        
        if (empty($existing_categories)) {
            return; // No existing categories, allow all
        }
        
        $matched_categories = array();
        $needs_update = false;
        
        foreach ($current_categories as $category_name) {
            // Check if category exists
            $existing = term_exists($category_name, 'product_cat');
            if ($existing) {
                // Add category and its parents
                foreach ($existing_categories as $cat) {
                    if ($cat['name'] === $category_name) {
                        $this->add_category_with_parents($cat, $matched_categories);
                        break;
                    }
                }
                continue;
            }
            
            // Find best match using AI
            $matched = $this->find_best_category_match($category_name, $existing_categories);
            if ($matched) {
                // Add matched category and all its parents
                $this->add_category_with_parents($matched, $matched_categories);
                $needs_update = true;
            }
        }
        
        // If we found matches, update the categories
        if ($needs_update && !empty($matched_categories)) {
            // Prevent infinite loop
            $cache_key = $product_id . '_import_' . md5(serialize($matched_categories));
            if (isset(self::$processing_categories[$cache_key])) {
                return;
            }
            
            // Mark as processing
            self::$processing_categories[$cache_key] = true;
            
            // Set the matched categories
            wp_set_object_terms($product_id, $matched_categories, 'product_cat', false);
            
            // Unmark after processing
            unset(self::$processing_categories[$cache_key]);
        }
    }
    
    /**
     * Find best category match using AI
     */
    private function find_best_category_match($new_category_name, $existing_categories) {
        if (empty($existing_categories)) {
            return null;
        }
        
        // Build categories list for AI prompt
        $categories_list = array();
        foreach ($existing_categories as $cat) {
            $categories_list[] = $cat['full_path'];
        }
        $categories_text = implode("\n", $categories_list);
        
        // Create AI prompt
        $prompt = "You are a category matching assistant. I have a new category name that needs to be matched to an existing category.

New category name: {$new_category_name}

Existing categories in the website:
{$categories_text}

Please analyze the new category name and find the most similar existing category. Consider:
- Semantic similarity
- Product type matching
- Hierarchical relationships
- Language and meaning

Respond with ONLY the full path of the most similar category (e.g., 'Parent > Child'). If no good match exists, respond with 'NO_MATCH'.
Do not include any explanation or additional text, only the category path or 'NO_MATCH'.";

        // Get settings for API call
        $settings = get_option($this->settings_option_name);
        if (empty($settings['api_key'])) {
            // Fallback to simple text matching if no API key
            return $this->fallback_text_match($new_category_name, $existing_categories);
        }
        
        // Call AI API
        $response = $this->api_handler->call_api($prompt, $settings);
        
        if (is_wp_error($response)) {
            // Fallback to simple text matching
            return $this->fallback_text_match($new_category_name, $existing_categories);
        }
        
        $matched_path = trim($response);
        
        // Clean response (remove quotes, extra spaces, etc.)
        $matched_path = trim($matched_path, '"\'');
        
        if (empty($matched_path) || strtoupper($matched_path) === 'NO_MATCH') {
            return $this->fallback_text_match($new_category_name, $existing_categories);
        }
        
        // Find category by full path
        foreach ($existing_categories as $cat) {
            if ($cat['full_path'] === $matched_path) {
                return $cat;
            }
        }
        
        // Try partial match (in case AI returned slightly different format)
        foreach ($existing_categories as $cat) {
            if (stripos($cat['full_path'], $matched_path) !== false || stripos($matched_path, $cat['full_path']) !== false) {
                return $cat;
            }
        }
        
        // Fallback if exact match not found
        return $this->fallback_text_match($new_category_name, $existing_categories);
    }
    
    /**
     * Fallback text matching if AI fails
     */
    private function fallback_text_match($new_category_name, $existing_categories) {
        $best_match = null;
        $best_score = 0;
        
        $new_name_lower = strtolower(trim($new_category_name));
        
        foreach ($existing_categories as $cat) {
            $cat_name_lower = strtolower($cat['name']);
            $cat_path_lower = strtolower($cat['full_path']);
            
            // Check exact match first
            if ($cat_name_lower === $new_name_lower) {
                return $cat;
            }
            
            // Check if new name contains category name or vice versa
            if (stripos($new_name_lower, $cat_name_lower) !== false || stripos($cat_name_lower, $new_name_lower) !== false) {
                $score = 90; // High score for substring match
            } else {
                // Use similar_text for fuzzy matching
                similar_text($new_name_lower, $cat_name_lower, $score);
                
                // Also check against full path
                $path_score = 0;
                similar_text($new_name_lower, $cat_path_lower, $path_score);
                $score = max($score, $path_score);
            }
            
            if ($score > $best_score && $score > 60) { // 60% similarity threshold
                $best_score = $score;
                $best_match = $cat;
            }
        }
        
        return $best_match;
    }
    
    /**
     * Clear category cache
     */
    public function clear_category_cache() {
        delete_transient($this->option_name);
    }
}

