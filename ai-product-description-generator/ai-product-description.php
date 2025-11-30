<?php
/**
 * Plugin Name: AI Product Description Generator
 * Plugin URI: https://ali-parsa.com
 * Description: Automatically generate WooCommerce product descriptions using AI APIs (Claude, ChatGPT, DeepSeek, Gemini, Groq)
 * Version: 4.0.0
 * Author: Ali Parsa
 * Text Domain: ai-product-desc
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

// Load required files
require_once plugin_dir_path(__FILE__) . 'includes/class-ai-api-handler.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-category-manager.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-ai-product-description-generator.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-category-matcher.php';

// Initialize the plugin
new AI_Product_Description_Generator();
new AI_Product_Description_Category_Matcher();

