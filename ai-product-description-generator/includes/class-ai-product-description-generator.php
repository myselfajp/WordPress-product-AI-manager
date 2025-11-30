<?php
/**
 * Main Plugin Class
 * Handles all plugin functionality
 */

if (!defined('ABSPATH')) {
    exit;
}

class AI_Product_Description_Generator {

    private $option_name = 'ai_product_desc_settings';
    private static $processing_products = array();
    private $api_handler;
    private $category_manager;
    
    public function __construct() {
        // Hooks for new products
        add_action('woocommerce_new_product', array($this, 'generate_description_for_new_product'), 10, 1);
        
        // Hooks for product updates (including bulk edits and imports)
        add_action('woocommerce_update_product', array($this, 'generate_description_for_updated_product'), 10, 1);
        
        // Additional hook for product import (CSV, XML, etc.)
        add_action('woocommerce_product_import_inserted_product_object', array($this, 'handle_imported_product'), 10, 2);
        
        // Hook for bulk edit
        add_action('woocommerce_product_bulk_edit_save', array($this, 'handle_bulk_edit_product'), 10, 1);
        
        // Hook for stock changes to delete out of stock products
        add_action('woocommerce_product_set_stock', array($this, 'check_and_delete_out_of_stock'), 10, 1);
        add_action('woocommerce_variation_set_stock', array($this, 'check_and_delete_out_of_stock'), 10, 1);
        
        // Admin hooks
        add_action('admin_menu', array($this, 'add_settings_page'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('add_meta_boxes', array($this, 'add_meta_box'));
        add_action('wp_ajax_generate_ai_description', array($this, 'ajax_generate_description'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        
        // Category management AJAX hooks
        add_action('wp_ajax_upload_categories_excel', array($this, 'ajax_upload_categories_excel'));
        add_action('wp_ajax_import_categories', array($this, 'ajax_import_categories'));
    }
    
    public function enqueue_admin_scripts($hook) {
        global $post;
        
        if ($hook == 'post-new.php' || $hook == 'post.php') {
            if ('product' === $post->post_type) {
                wp_enqueue_script('jquery');
            }
        }
        
        // Enqueue scripts for settings page
        if ($hook == 'woocommerce_page_ai-product-desc-settings') {
            wp_enqueue_script('jquery');
        }
    }
    
    public function add_settings_page() {
        add_submenu_page(
            'woocommerce',
            'AI Description Settings',
            'AI Descriptions',
            'manage_options',
            'ai-product-desc-settings',
            array($this, 'settings_page_html')
        );
    }
    
    public function register_settings() {
        register_setting('ai_product_desc_group', $this->option_name);
    }
    
    public function settings_page_html() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        $settings = get_option($this->option_name, array(
            'api_provider' => 'gemini',
            'api_key' => '',
            'prompt_template' => 'Write an engaging product description for:
Product Title: {title}
Category: {category}
Price: {price}
Tags: {tags}
Short Description: {short_description}
Attributes: {attributes}
Existing Description: {description}

Please write a complete and professional description.',
            'title_prompt_template' => 'Generate a short and catchy product title based on:
Category: {category}
Tags: {tags}
Attributes: {attributes}
Current Title: {title}

Please write only the title, nothing else.',
            'auto_generate_desc' => '1',
            'auto_generate_title' => '0',
            'auto_update_desc' => '0',
            'auto_update_title' => '0',
            'delete_out_of_stock' => '0',
            'prevent_new_categories' => '0',
            'model' => 'gemini-pro',
            'commission_enabled' => '0',
            'commission_rules' => array()
        ));
        
        if (isset($_POST['submit']) && check_admin_referer('ai_product_desc_save', 'ai_product_desc_nonce')) {
            // Handle commission rules
            $commission_rules = array();
            if (isset($_POST['commission_rules']) && is_array($_POST['commission_rules'])) {
                foreach ($_POST['commission_rules'] as $rule) {
                    $commission_rules[] = array(
                        'min_price' => floatval($rule['min_price']),
                        'max_price' => floatval($rule['max_price']),
                        'type' => sanitize_text_field($rule['type']),
                        'value' => floatval($rule['value']),
                        'apply_to_regular' => isset($rule['apply_to_regular']) ? true : false,
                        'apply_to_sale' => isset($rule['apply_to_sale']) ? true : false
                    );
                }
            }

            $settings = array(
                'api_provider' => sanitize_text_field($_POST['api_provider']),
                'api_key' => sanitize_text_field($_POST['api_key']),
                'prompt_template' => wp_kses_post($_POST['prompt_template']),
                'title_prompt_template' => wp_kses_post($_POST['title_prompt_template']),
                'auto_generate_desc' => isset($_POST['auto_generate_desc']) ? '1' : '0',
                'auto_generate_title' => isset($_POST['auto_generate_title']) ? '1' : '0',
                'auto_update_desc' => isset($_POST['auto_update_desc']) ? '1' : '0',
                'auto_update_title' => isset($_POST['auto_update_title']) ? '1' : '0',
                'delete_out_of_stock' => isset($_POST['delete_out_of_stock']) ? '1' : '0',
                'prevent_new_categories' => isset($_POST['prevent_new_categories']) ? '1' : '0',
                'model' => sanitize_text_field($_POST['model']),
                'commission_enabled' => isset($_POST['commission_enabled']) ? '1' : '0',
                'commission_rules' => $commission_rules
            );
            update_option($this->option_name, $settings);
            echo '<div class="notice notice-success"><p>Settings saved successfully!</p></div>';
        }
        ?>
        <div class="wrap">
            <h1>AI Product Description Settings</h1>
            
            <div class="notice notice-info" style="margin: 20px 0;">
                <h3 style="margin-top: 10px;">🆓 Free AI Providers:</h3>
                <ul style="margin-left: 20px;">
                    <li><strong>Google Gemini:</strong> Free with rate limits - Get key at <a href="https://makersuite.google.com/app/apikey" target="_blank">makersuite.google.com</a></li>
                    <li><strong>Groq:</strong> Free and very fast - Get key at <a href="https://console.groq.com" target="_blank">console.groq.com</a></li>
                    <li><strong>OpenAI:</strong> $5 free credit for new users</li>
                    <li><strong>DeepSeek:</strong> Very cheap pricing</li>
                </ul>
            </div>
            
            <form method="post" action="">
                <?php wp_nonce_field('ai_product_desc_save', 'ai_product_desc_nonce'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="api_provider">API Provider</label></th>
                        <td>
                            <select name="api_provider" id="api_provider" class="regular-text">
                                <option value="gemini" <?php selected($settings['api_provider'], 'gemini'); ?>>Google Gemini (FREE)</option>
                                <option value="groq" <?php selected($settings['api_provider'], 'groq'); ?>>Groq (FREE)</option>
                                <option value="openai" <?php selected($settings['api_provider'], 'openai'); ?>>OpenAI ChatGPT</option>
                                <option value="claude" <?php selected($settings['api_provider'], 'claude'); ?>>Anthropic Claude</option>
                                <option value="deepseek" <?php selected($settings['api_provider'], 'deepseek'); ?>>DeepSeek</option>
                            </select>
                            <p class="description">Select which AI service to use</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><label for="model">AI Model</label></th>
                        <td>
                            <input type="text" name="model" id="model" value="<?php echo esc_attr($settings['model']); ?>" class="regular-text" />
                            <p class="description">
                                <strong>Gemini:</strong> gemini-pro or gemini-1.5-flash (recommended)<br>
                                <strong>Groq:</strong> mixtral-8x7b-32768 or llama-3.1-70b-versatile<br>
                                <strong>OpenAI:</strong> gpt-4 or gpt-3.5-turbo<br>
                                <strong>Claude:</strong> claude-3-5-sonnet-20241022 or claude-3-opus-20240229<br>
                                <strong>DeepSeek:</strong> deepseek-chat
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><label for="api_key">API Key</label></th>
                        <td>
                            <input type="password" name="api_key" id="api_key" value="<?php echo esc_attr($settings['api_key']); ?>" class="regular-text" />
                            <p class="description">Get your API key from the respective provider's dashboard</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><label for="auto_generate_title">1. Yeni Ürün Başlığı Otomatik Oluştur</label></th>
                        <td>
                            <label>
                                <input type="checkbox" name="auto_generate_title" id="auto_generate_title" value="1" <?php checked(isset($settings['auto_generate_title']) ? $settings['auto_generate_title'] : '0', '1'); ?> />
                                YENİ ürünler oluşturulduğunda ürün başlıklarını otomatik olarak oluştur
                            </label>
                            <p class="description">Yeni bir ürün oluşturulduğunda (manuel, import, API veya herhangi bir yöntemle), yapay zeka kullanarak başlık oluşturur.</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><label for="auto_generate_desc">2. Yeni Ürün Açıklaması Otomatik Oluştur</label></th>
                        <td>
                            <label>
                                <input type="checkbox" name="auto_generate_desc" id="auto_generate_desc" value="1" <?php checked(isset($settings['auto_generate_desc']) ? $settings['auto_generate_desc'] : '1', '1'); ?> />
                                YENİ ürünler oluşturulduğunda ürün açıklamalarını otomatik olarak oluştur
                            </label>
                            <p class="description">Yeni bir ürün oluşturulduğunda (manuel, import, API veya herhangi bir yöntemle), yapay zeka kullanarak açıklama oluşturur.</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><label for="auto_update_title">3. Mevcut Ürün Başlığını Otomatik Güncelle</label></th>
                        <td>
                            <label>
                                <input type="checkbox" name="auto_update_title" id="auto_update_title" value="1" <?php checked(isset($settings['auto_update_title']) ? $settings['auto_update_title'] : '0', '1'); ?> />
                                MEVCUT ürünler güncellendiğinde ürün başlıklarını otomatik olarak yeniden oluştur
                            </label>
                            <p class="description">Mevcut bir ürün güncellendiğinde (manuel, toplu düzenleme, import güncelleme, API veya herhangi bir yöntemle), yapay zeka kullanarak başlığı yeniden oluşturur.</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><label for="auto_update_desc">4. Mevcut Ürün Açıklamasını Otomatik Güncelle</label></th>
                        <td>
                            <label>
                                <input type="checkbox" name="auto_update_desc" id="auto_update_desc" value="1" <?php checked(isset($settings['auto_update_desc']) ? $settings['auto_update_desc'] : '0', '1'); ?> />
                                MEVCUT ürünler güncellendiğinde ürün açıklamalarını otomatik olarak yeniden oluştur
                            </label>
                            <p class="description">Mevcut bir ürün güncellendiğinde (manuel, toplu düzenleme, import güncelleme, API veya herhangi bir yöntemle), yapay zeka kullanarak açıklamayı yeniden oluşturur.</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><label for="delete_out_of_stock">5. Stoksuz Ürünleri Otomatik Sil</label></th>
                        <td>
                            <label>
                                <input type="checkbox" name="delete_out_of_stock" id="delete_out_of_stock" value="1" <?php checked(isset($settings['delete_out_of_stock']) ? $settings['delete_out_of_stock'] : '0', '1'); ?> />
                                Stok miktarı sıfır olan ürünleri otomatik olarak siteden sil
                            </label>
                            <p class="description">Bir ürünün stok miktarı sıfır olduğunda, ürün otomatik olarak siteden tamamen silinir ve yapay zeka işlemi yapılmaz. <strong style="color: red;">DİKKAT: Bu işlem geri alınamaz!</strong></p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><label for="prevent_new_categories">6. جلوگیری از ساخت کتگوری جدید</label></th>
                        <td>
                            <label>
                                <input type="checkbox" name="prevent_new_categories" id="prevent_new_categories" value="1" <?php checked(isset($settings['prevent_new_categories']) ? $settings['prevent_new_categories'] : '0', '1'); ?> />
                                جلوگیری از ساخت کتگوری جدید و اتصال به نزدیک‌ترین کتگوری موجود با استفاده از AI
                            </label>
                            <p class="description">
                                وقتی این گزینه فعال باشد، سیستم از ساخت کتگوری جدید جلوگیری می‌کند و با استفاده از هوش مصنوعی، محصول را به نزدیک‌ترین کتگوری موجود متصل می‌کند.<br>
                                این قابلیت برای محصولات import شده از XML، محصولات جدید و به‌روزرسانی شده کار می‌کند.<br>
                                <strong>نکته:</strong> لیست کتگوری‌های موجود به صورت cache ذخیره می‌شود و هر 24 ساعت یکبار به‌روزرسانی می‌شود.
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><label for="prompt_template">Description Prompt Template</label></th>
                        <td>
                            <textarea name="prompt_template" id="prompt_template" rows="10" class="large-text"><?php echo esc_textarea($settings['prompt_template']); ?></textarea>
                            <p class="description">
                                Available variables:<br>
                                <code>{title}</code> - Product title<br>
                                <code>{category}</code> - Category<br>
                                <code>{price}</code> - Price<br>
                                <code>{tags}</code> - Tags<br>
                                <code>{short_description}</code> - Short description<br>
                                <code>{attributes}</code> - Product attributes<br>
                                <code>{description}</code> - Current description
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><label for="title_prompt_template">Title Prompt Template</label></th>
                        <td>
                            <textarea name="title_prompt_template" id="title_prompt_template" rows="8" class="large-text"><?php echo esc_textarea(isset($settings['title_prompt_template']) ? $settings['title_prompt_template'] : ''); ?></textarea>
                            <p class="description">
                                This prompt will be used when "Update Title" is checked in the product editor.<br>
                                Available variables: <code>{title}</code>, <code>{category}</code>, <code>{price}</code>, <code>{tags}</code>, <code>{short_description}</code>, <code>{attributes}</code>, <code>{description}</code>
                            </p>
                        </td>
                    </tr>
                </table>
                
                <hr style="margin: 40px 0;">
                
                <h2>💰 Price Commission / Markup Settings</h2>
                <div class="commission-settings-section" style="background: #f9f9f9; padding: 20px; border: 1px solid #ddd; border-radius: 5px; margin-top: 20px;">
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="commission_enabled">Enable Price Commission</label></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="commission_enabled" id="commission_enabled" value="1" <?php checked(isset($settings['commission_enabled']) ? $settings['commission_enabled'] : '0', '1'); ?> />
                                    Automatically apply commission/markup to product prices
                                </label>
                                <p class="description">When enabled, prices will be automatically adjusted based on the rules below when products are created or updated.</p>
                            </td>
                        </tr>
                    </table>
                    
                    <div id="commission-rules-container" style="margin-top: 20px;">
                        <h3>Commission Rules <span style="font-size: 14px; font-weight: normal; color: #666;">(Rules are applied in order - first match wins)</span></h3>
                        
                        <div id="commission-rules-list">
                            <?php
                            $commission_rules = isset($settings['commission_rules']) ? $settings['commission_rules'] : array();
                            if (empty($commission_rules)) {
                                // Show one empty rule by default
                                $commission_rules = array(
                                    array(
                                        'min_price' => '',
                                        'max_price' => '',
                                        'type' => 'fixed',
                                        'value' => '',
                                        'apply_to_regular' => true,
                                        'apply_to_sale' => false
                                    )
                                );
                            }
                            
                            foreach ($commission_rules as $index => $rule) {
                                ?>
                                <div class="commission-rule" style="background: white; padding: 15px; margin-bottom: 15px; border: 1px solid #ddd; border-radius: 3px; position: relative;">
                                    <div style="display: flex; gap: 15px; align-items: flex-start; flex-wrap: wrap;">
                                        <div style="flex: 1; min-width: 200px;">
                                            <label style="display: block; font-weight: 600; margin-bottom: 5px;">Price Range</label>
                                            <div style="display: flex; gap: 5px; align-items: center;">
                                                <input type="number" name="commission_rules[<?php echo $index; ?>][min_price]" 
                                                       value="<?php echo esc_attr($rule['min_price']); ?>" 
                                                       placeholder="Min" step="0.01" min="0"
                                                       style="width: 100px;" />
                                                <span>to</span>
                                                <input type="number" name="commission_rules[<?php echo $index; ?>][max_price]" 
                                                       value="<?php echo esc_attr($rule['max_price']); ?>" 
                                                       placeholder="Max" step="0.01" min="0"
                                                       style="width: 100px;" />
                                            </div>
                                        </div>
                                        
                                        <div style="flex: 1; min-width: 200px;">
                                            <label style="display: block; font-weight: 600; margin-bottom: 5px;">Commission</label>
                                            <div style="display: flex; gap: 5px; align-items: center;">
                                                <input type="number" name="commission_rules[<?php echo $index; ?>][value]" 
                                                       value="<?php echo esc_attr($rule['value']); ?>" 
                                                       placeholder="Value" step="0.01"
                                                       style="width: 100px;" />
                                                <select name="commission_rules[<?php echo $index; ?>][type]" style="width: 120px;">
                                                    <option value="fixed" <?php selected($rule['type'], 'fixed'); ?>>Fixed (+)</option>
                                                    <option value="percentage" <?php selected($rule['type'], 'percentage'); ?>>Percentage (%)</option>
                                                </select>
                                            </div>
                                        </div>
                                        
                                        <div style="flex: 1; min-width: 150px;">
                                            <label style="display: block; font-weight: 600; margin-bottom: 5px;">Apply To</label>
                                            <div style="display: flex; flex-direction: column; gap: 5px;">
                                                <label style="margin: 0;">
                                                    <input type="checkbox" name="commission_rules[<?php echo $index; ?>][apply_to_regular]" 
                                                           value="1" <?php checked($rule['apply_to_regular'], true); ?> />
                                                    Regular Price
                                                </label>
                                                <label style="margin: 0;">
                                                    <input type="checkbox" name="commission_rules[<?php echo $index; ?>][apply_to_sale]" 
                                                           value="1" <?php checked($rule['apply_to_sale'], true); ?> />
                                                    Sale Price
                                                </label>
                                            </div>
                                        </div>
                                        
                                        <div style="align-self: flex-end;">
                                            <button type="button" class="button remove-commission-rule" style="color: #a00;">Remove</button>
                                        </div>
                                    </div>
                                </div>
                                <?php
                            }
                            ?>
                        </div>
                        
                        <p>
                            <button type="button" id="add-commission-rule" class="button button-secondary">+ Add New Rule</button>
                        </p>
                        
                        <div class="notice notice-info" style="margin-top: 20px;">
                            <p><strong>💡 How it works:</strong></p>
                            <ul style="margin-left: 20px;">
                                <li>Rules are checked in order from top to bottom</li>
                                <li>The <strong>first matching rule</strong> is applied to each price</li>
                                <li><strong>Fixed:</strong> Adds a specific amount (e.g., +5 adds 5 to the price)</li>
                                <li><strong>Percentage:</strong> Adds a percentage (e.g., 10% adds 10% to the price)</li>
                                <li>You can apply rules to Regular Price, Sale Price, or both</li>
                            </ul>
                        </div>
                    </div>
                </div>
                
                <?php submit_button('Save Settings'); ?>
            </form>
            
            <hr style="margin: 40px 0;">
            
            <h2>Kategori Yönetimi (Category Management)</h2>
            <div class="category-management-section" style="background: #f9f9f9; padding: 20px; border: 1px solid #ddd; border-radius: 5px; margin-top: 20px;">
                <div class="notice notice-warning" style="margin: 0 0 20px 0;">
                    <p><strong>⚠️ DİKKAT:</strong> Bu işlem tüm mevcut kategorileri silecek ve Excel dosyasındaki kategorileri ekleyecektir. Bu işlem geri alınamaz!</p>
                </div>
                
                <form id="category-upload-form" enctype="multipart/form-data" style="margin-bottom: 20px;">
                    <?php wp_nonce_field('upload_categories_excel', 'category_upload_nonce'); ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="category_excel_file">Excel Dosyası Yükle</label></th>
                            <td>
                                <input type="file" name="category_excel_file" id="category_excel_file" accept=".xlsx,.xls" required />
                                <p class="description">
                                    Excel dosyası formatı: İlk satır (satır 1) parent kategorileri içermelidir (her sütun bir parent kategori).<br>
                                    Alt satırlar her sütunun alt kategorilerini (child) içermelidir.<br>
                                    Örnek: A1="VİBRATÖRLER", A2="Realistik Vibratör", A3="Uygulamalı Vibratör" gibi.
                                </p>
                            </td>
                        </tr>
                    </table>
                    <p class="submit">
                        <button type="submit" class="button button-primary" id="upload-excel-btn">Excel Dosyasını Yükle ve Oku</button>
                    </p>
                </form>
                
                <div id="category-upload-loading" style="display: none; margin: 20px 0;">
                    <span class="spinner is-active"></span>
                    <span>Excel dosyası okunuyor...</span>
                </div>
                
                <div id="category-preview-section" style="display: none; margin-top: 20px;">
                    <h3>Okunan Kategoriler:</h3>
                    <div id="category-preview-list" style="max-height: 400px; overflow-y: auto; background: white; padding: 15px; border: 1px solid #ddd; border-radius: 3px; margin: 10px 0;"></div>
                    <p class="submit">
                        <button type="button" class="button button-primary button-large" id="import-categories-btn">Başlat - Kategorileri İçe Aktar</button>
                    </p>
                </div>
                
                <div id="category-import-loading" style="display: none; margin: 20px 0;">
                    <span class="spinner is-active"></span>
                    <span>Kategoriler içe aktarılıyor...</span>
                </div>
                
                <div id="category-result" style="display: none; margin-top: 20px;"></div>
            </div>
        </div>
        
        <script>
        document.getElementById('api_provider').addEventListener('change', function() {
            var provider = this.value;
            var modelField = document.getElementById('model');
            
            var defaultModels = {
                'gemini': 'gemini-1.5-flash',
                'groq': 'mixtral-8x7b-32768',
                'openai': 'gpt-3.5-turbo',
                'claude': 'claude-3-5-sonnet-20241022',
                'deepseek': 'deepseek-chat'
            };
            
            if (defaultModels[provider]) {
                modelField.value = defaultModels[provider];
            }
        });
        
        // Commission Rules Management
        (function() {
            var ruleIndex = document.querySelectorAll('.commission-rule').length;
            
            // Add new rule
            document.getElementById('add-commission-rule').addEventListener('click', function() {
                var rulesList = document.getElementById('commission-rules-list');
                var newRule = document.createElement('div');
                newRule.className = 'commission-rule';
                newRule.style.cssText = 'background: white; padding: 15px; margin-bottom: 15px; border: 1px solid #ddd; border-radius: 3px; position: relative;';
                
                newRule.innerHTML = `
                    <div style="display: flex; gap: 15px; align-items: flex-start; flex-wrap: wrap;">
                        <div style="flex: 1; min-width: 200px;">
                            <label style="display: block; font-weight: 600; margin-bottom: 5px;">Price Range</label>
                            <div style="display: flex; gap: 5px; align-items: center;">
                                <input type="number" name="commission_rules[${ruleIndex}][min_price]" 
                                       value="" placeholder="Min" step="0.01" min="0"
                                       style="width: 100px;" />
                                <span>to</span>
                                <input type="number" name="commission_rules[${ruleIndex}][max_price]" 
                                       value="" placeholder="Max" step="0.01" min="0"
                                       style="width: 100px;" />
                            </div>
                        </div>
                        
                        <div style="flex: 1; min-width: 200px;">
                            <label style="display: block; font-weight: 600; margin-bottom: 5px;">Commission</label>
                            <div style="display: flex; gap: 5px; align-items: center;">
                                <input type="number" name="commission_rules[${ruleIndex}][value]" 
                                       value="" placeholder="Value" step="0.01"
                                       style="width: 100px;" />
                                <select name="commission_rules[${ruleIndex}][type]" style="width: 120px;">
                                    <option value="fixed">Fixed (+)</option>
                                    <option value="percentage">Percentage (%)</option>
                                </select>
                            </div>
                        </div>
                        
                        <div style="flex: 1; min-width: 150px;">
                            <label style="display: block; font-weight: 600; margin-bottom: 5px;">Apply To</label>
                            <div style="display: flex; flex-direction: column; gap: 5px;">
                                <label style="margin: 0;">
                                    <input type="checkbox" name="commission_rules[${ruleIndex}][apply_to_regular]" 
                                           value="1" checked />
                                    Regular Price
                                </label>
                                <label style="margin: 0;">
                                    <input type="checkbox" name="commission_rules[${ruleIndex}][apply_to_sale]" 
                                           value="1" />
                                    Sale Price
                                </label>
                            </div>
                        </div>
                        
                        <div style="align-self: flex-end;">
                            <button type="button" class="button remove-commission-rule" style="color: #a00;">Remove</button>
                        </div>
                    </div>
                `;
                
                rulesList.appendChild(newRule);
                ruleIndex++;
            });
            
            // Remove rule (using event delegation)
            document.getElementById('commission-rules-list').addEventListener('click', function(e) {
                if (e.target.classList.contains('remove-commission-rule')) {
                    var rule = e.target.closest('.commission-rule');
                    if (rule) {
                        rule.remove();
                    }
                }
            });
        })();
        
        jQuery(document).ready(function($) {
            var categoriesData = [];
            
            // Handle Excel file upload
            $('#category-upload-form').on('submit', function(e) {
                e.preventDefault();
                
                var formData = new FormData();
                formData.append('action', 'upload_categories_excel');
                formData.append('category_upload_nonce', '<?php echo wp_create_nonce('upload_categories_excel'); ?>');
                formData.append('category_excel_file', $('#category_excel_file')[0].files[0]);
                
                $('#category-upload-loading').show();
                $('#category-preview-section').hide();
                $('#category-result').hide();
                $('#upload-excel-btn').prop('disabled', true);
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    success: function(response) {
                        $('#category-upload-loading').hide();
                        $('#upload-excel-btn').prop('disabled', false);
                        
                        if (response.success) {
                            categoriesData = response.data.categories;
                            displayCategories(categoriesData);
                            $('#category-preview-section').show();
                        } else {
                            var errorMsg = response.data && response.data.message ? response.data.message : 'Bilinmeyen bir hata oluştu';
                            $('#category-result')
                                .html('<div class="notice notice-error"><p>Hata: ' + errorMsg + '</p></div>')
                                .show();
                        }
                    },
                    error: function(xhr, status, error) {
                        $('#category-upload-loading').hide();
                        $('#upload-excel-btn').prop('disabled', false);
                        $('#category-result')
                            .html('<div class="notice notice-error"><p>Bağlantı hatası. Lütfen tekrar deneyin.</p></div>')
                            .show();
                    }
                });
            });
            
            // Display categories
            function displayCategories(categories) {
                var html = '<table class="widefat" style="margin-top: 10px;"><thead><tr><th>Parent (Ana Kategori)</th><th>Child (Alt Kategori)</th></tr></thead><tbody>';
                
                if (categories.length === 0) {
                    html += '<tr><td colspan="2">Kategori bulunamadı.</td></tr>';
                } else {
                    categories.forEach(function(cat) {
                        html += '<tr><td>' + (cat.parent || '-') + '</td><td>' + (cat.child || '-') + '</td></tr>';
                    });
                }
                
                html += '</tbody></table>';
                html += '<p style="margin-top: 10px;"><strong>Toplam ' + categories.length + ' kategori bulundu.</strong></p>';
                
                $('#category-preview-list').html(html);
            }
            
            // Handle category import
            $('#import-categories-btn').on('click', function(e) {
                e.preventDefault();
                
                if (categoriesData.length === 0) {
                    alert('Önce Excel dosyasını yükleyin ve kategorileri okuyun.');
                    return;
                }
                
                if (!confirm('Tüm mevcut kategoriler silinecek ve yeni kategoriler eklenecek. Emin misiniz?')) {
                    return;
                }
                
                $('#category-import-loading').show();
                $('#import-categories-btn').prop('disabled', true);
                $('#category-result').hide();
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'import_categories',
                        categories: JSON.stringify(categoriesData),
                        nonce: '<?php echo wp_create_nonce('import_categories'); ?>'
                    },
                    success: function(response) {
                        $('#category-import-loading').hide();
                        $('#import-categories-btn').prop('disabled', false);
                        
                        if (response.success) {
                            var successMsg = '✓ Kategoriler başarıyla içe aktarıldı!<br>';
                            successMsg += 'Silinen kategoriler: ' + (response.data.deleted_count || 0) + '<br>';
                            successMsg += 'Eklenen kategoriler: ' + (response.data.added_count || 0);
                            
                            $('#category-result')
                                .html('<div class="notice notice-success"><p>' + successMsg + '</p></div>')
                                .show();
                            
                            // Clear form
                            $('#category_excel_file').val('');
                            $('#category-preview-section').hide();
                            categoriesData = [];
                        } else {
                            var errorMsg = response.data && response.data.message ? response.data.message : 'Bilinmeyen bir hata oluştu';
                            $('#category-result')
                                .html('<div class="notice notice-error"><p>Hata: ' + errorMsg + '</p></div>')
                                .show();
                        }
                    },
                    error: function(xhr, status, error) {
                        $('#category-import-loading').hide();
                        $('#import-categories-btn').prop('disabled', false);
                        $('#category-result')
                            .html('<div class="notice notice-error"><p>Bağlantı hatası. Lütfen tekrar deneyin.</p></div>')
                            .show();
                    }
                });
            });
        });
        </script>
        <?php
    }
    
    public function add_meta_box() {
        add_meta_box(
            'ai_generate_description',
            'AI Description Generator',
            array($this, 'meta_box_html'),
            'product',
            'side',
            'default'
        );
    }
    
    public function meta_box_html($post) {
        ?>
        <div class="ai-desc-meta-box" style="text-align: center;">
            <div class="ai-desc-field" style="margin-bottom: 10px; text-align: left;">
                <label for="ai-desc-custom-title" style="display: block; font-weight: 600; margin-bottom: 4px;">Title for AI prompt</label>
                <input type="text" id="ai-desc-custom-title" class="widefat" value="<?php echo esc_attr($post->post_title); ?>" />
            </div>
            <div class="ai-desc-field" style="margin-bottom: 10px; text-align: left;">
                <label for="ai-desc-custom-description" style="display: block; font-weight: 600; margin-bottom: 4px;">Description to clean</label>
                <textarea id="ai-desc-custom-description" class="widefat" rows="5"><?php echo esc_textarea($post->post_content); ?></textarea>
                <p class="description" style="margin-top: 4px;">The current product description will be sent to AI as <code>{description}</code> so it can improve or clean it.</p>
            </div>
            <div class="ai-desc-field" style="margin-bottom: 10px; text-align: left;">
                <label style="display: flex; align-items: center; gap: 6px;">
                    <input type="checkbox" id="ai-desc-update-title" value="1" />
                    <strong>Also update product title using AI</strong>
                </label>
                <p class="description" style="margin-top: 4px;">If checked, the product title will be regenerated using the Title Prompt Template from settings.</p>
            </div>
            <button type="button" class="button button-primary" id="generate-ai-desc-btn" style="width: 100%; margin-bottom: 10px;">
                Generate Description
            </button>
            <div id="ai-desc-loading" style="display: none;">
                <span class="spinner is-active" style="float: none;"></span>
                <p>Generating description...</p>
            </div>
            <div id="ai-desc-result" style="display: none; margin-top: 10px;"></div>
        </div>
        
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            $('#generate-ai-desc-btn').on('click', function(e) {
                e.preventDefault();
                
                var postId = <?php echo $post->ID; ?>;
                var $btn = $(this);
                
                var defaultTitle = $('#title').val();
                var customTitle = $('#ai-desc-custom-title').val();
                var effectiveTitle = customTitle && customTitle.trim() !== '' ? customTitle : defaultTitle;
                
                var customDescription = $('#ai-desc-custom-description').val();
                if (!customDescription || customDescription.trim() === '') {
                    if (typeof tinyMCE !== 'undefined' && tinyMCE.get('content')) {
                        customDescription = tinyMCE.get('content').getContent();
                    } else {
                        customDescription = $('#content').val();
                    }
                    $('#ai-desc-custom-description').val(customDescription);
                }
                
                if (!effectiveTitle || effectiveTitle.trim() === '') {
                    $('#ai-desc-result')
                        .html('<div style="color: red; padding: 10px; background: #fee; border-radius: 3px;">Please enter a product title first</div>')
                        .show();
                    return;
                }
                
                var updateTitle = $('#ai-desc-update-title').is(':checked') ? '1' : '0';
                
                $('#ai-desc-loading').show();
                $('#ai-desc-result').hide();
                $btn.prop('disabled', true);
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'generate_ai_description',
                        post_id: postId,
                        custom_title: effectiveTitle,
                        custom_description: customDescription,
                        update_title: updateTitle,
                        nonce: '<?php echo wp_create_nonce('generate_ai_desc_' . $post->ID); ?>'
                    },
                    success: function(response) {
                        $('#ai-desc-loading').hide();
                        $btn.prop('disabled', false);
                        
                        if (response.success) {
                            var successMsg = '✓ Description generated successfully!';
                            if (response.data.title) {
                                successMsg = '✓ Title and description generated successfully!';
                                $('#title').val(response.data.title);
                                $('#ai-desc-custom-title').val(response.data.title);
                            }
                            
                            $('#ai-desc-result')
                                .html('<div style="color: green; padding: 10px; background: #efe; border-radius: 3px;">' + successMsg + '</div>')
                                .show();
                            
                            if (typeof tinyMCE !== 'undefined' && tinyMCE.get('content')) {
                                tinyMCE.get('content').setContent(response.data.description);
                            } else {
                                $('#content').val(response.data.description);
                            }
                            
                            $('#ai-desc-custom-description').val(response.data.description);
                            
                            setTimeout(function() {
                                $('#ai-desc-result').fadeOut();
                            }, 5000);
                        } else {
                            var errorMsg = response.data && response.data.message ? response.data.message : 'Unknown error occurred';
                            $('#ai-desc-result')
                                .html('<div style="color: red; padding: 10px; background: #fee; border-radius: 3px;">Error: ' + errorMsg + '</div>')
                                .show();
                        }
                    },
                    error: function(xhr, status, error) {
                        $('#ai-desc-loading').hide();
                        $btn.prop('disabled', false);
                        $('#ai-desc-result')
                            .html('<div style="color: red; padding: 10px; background: #fee; border-radius: 3px;">Connection error. Please try again.</div>')
                            .show();
                    }
                });
            });
        });
        </script>
        <?php
    }
    
    public function ajax_generate_description() {
        try {
            if (!isset($_POST['nonce']) || !isset($_POST['post_id'])) {
                wp_send_json_error(array('message' => 'Missing required parameters'));
                return;
            }
            
            $post_id = intval($_POST['post_id']);
            
            if (!wp_verify_nonce($_POST['nonce'], 'generate_ai_desc_' . $post_id)) {
                wp_send_json_error(array('message' => 'Security check failed'));
                return;
            }
            
            if (!current_user_can('edit_products')) {
                wp_send_json_error(array('message' => 'Insufficient permissions'));
                return;
            }
            
            if (!class_exists('WooCommerce')) {
                wp_send_json_error(array('message' => 'WooCommerce is not active'));
                return;
            }
            
            $settings = get_option($this->option_name);
            if (empty($settings['api_key'])) {
                wp_send_json_error(array('message' => 'Please configure API key in settings'));
                return;
            }
            
            $overrides = array();
            if (isset($_POST['custom_title'])) {
                $overrides['title'] = sanitize_text_field(wp_unslash($_POST['custom_title']));
            }
            if (isset($_POST['custom_description'])) {
                $overrides['description'] = wp_kses_post(wp_unslash($_POST['custom_description']));
            }
            
            $update_title = isset($_POST['update_title']) && $_POST['update_title'] === '1';
            
            $description = $this->generate_description($post_id, $overrides);
            
            if ($description && !is_wp_error($description)) {
                $this->update_product_description($post_id, $description);
                
                $response_data = array('description' => $description);
                
                if ($update_title) {
                    $new_title = $this->generate_title($post_id, $overrides);
                    if ($new_title && !is_wp_error($new_title)) {
                        $new_title = trim($new_title);
                        wp_update_post(array(
                            'ID' => $post_id,
                            'post_title' => $new_title
                        ));
                        $response_data['title'] = $new_title;
                    }
                }
                
                wp_send_json_success($response_data);
            } else {
                $error_message = is_wp_error($description) ? $description->get_error_message() : 'Failed to generate description';
                wp_send_json_error(array('message' => $error_message));
            }
            
        } catch (Exception $e) {
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }
    
    public function generate_description_for_new_product($product_id) {
        $settings = get_option($this->option_name);
        
        $should_generate_title = isset($settings['auto_generate_title']) && $settings['auto_generate_title'] === '1';
        $should_generate_desc = isset($settings['auto_generate_desc']) && $settings['auto_generate_desc'] === '1';
        
        if (!$should_generate_desc && !$should_generate_title) {
            return;
        }
        
        if ($should_generate_title && !empty($settings['title_prompt_template'])) {
            $new_title = $this->generate_title($product_id);
            if ($new_title && !is_wp_error($new_title)) {
                $new_title = trim($new_title);
                $this->mark_product_processing($product_id);
                try {
                    wp_update_post(array(
                        'ID' => $product_id,
                        'post_title' => $new_title
                    ));
                } finally {
                    $this->unmark_product_processing($product_id);
                }
            }
        }
        
        if ($should_generate_desc) {
            $product = get_post($product_id);
            if (empty($product->post_content)) {
                $description = $this->generate_description($product_id);
                if ($description && !is_wp_error($description)) {
                    $this->update_product_description($product_id, $description);
                }
            }
        }
        
        // Apply commission to prices
        $this->apply_commission_to_product($product_id);
    }
    
    public function generate_description_for_updated_product($product_id) {
        $settings = get_option($this->option_name);
        
        // Check if product should be deleted due to zero stock
        if ($this->should_delete_product_for_stock($product_id, $settings)) {
            return;
        }
        
        $should_update_title = isset($settings['auto_update_title']) && $settings['auto_update_title'] === '1';
        $should_update_desc = isset($settings['auto_update_desc']) && $settings['auto_update_desc'] === '1';
        
        if (!$should_update_desc && !$should_update_title) {
            return;
        }
        
        if ($this->is_product_processing($product_id)) {
            return;
        }
        
        if (wp_is_post_revision($product_id)) {
            return;
        }
        
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        
        $product = get_post($product_id);
        if (!$product || $product->post_type !== 'product') {
            return;
        }
        
        if ($should_update_title && !empty($settings['title_prompt_template'])) {
            $new_title = $this->generate_title($product_id);
            if ($new_title && !is_wp_error($new_title)) {
                $new_title = trim($new_title);
                $this->mark_product_processing($product_id);
                try {
                    wp_update_post(array(
                        'ID' => $product_id,
                        'post_title' => $new_title
                    ));
                } finally {
                    $this->unmark_product_processing($product_id);
                }
            }
        }
        
        if ($should_update_desc) {
            $description = $this->generate_description($product_id);
            if ($description && !is_wp_error($description)) {
                $this->update_product_description($product_id, $description);
            }
        }
        
        // Apply commission to prices
        $this->apply_commission_to_product($product_id);
    }
    
    public function handle_imported_product($product, $data) {
        if (!$product || !is_a($product, 'WC_Product')) {
            return;
        }
        
        $product_id = $product->get_id();
        
        $settings = get_option($this->option_name);
        $should_generate_title = isset($settings['auto_generate_title']) && $settings['auto_generate_title'] === '1';
        $should_generate_desc = isset($settings['auto_generate_desc']) && $settings['auto_generate_desc'] === '1';
        
        if (!$should_generate_desc && !$should_generate_title) {
            return;
        }
        
        // Prevent processing loop
        if ($this->is_product_processing($product_id)) {
            return;
        }
        
        if ($should_generate_title && !empty($settings['title_prompt_template'])) {
            $new_title = $this->generate_title($product_id);
            if ($new_title && !is_wp_error($new_title)) {
                $new_title = trim($new_title);
                $this->mark_product_processing($product_id);
                try {
                    wp_update_post(array(
                        'ID' => $product_id,
                        'post_title' => $new_title
                    ));
                } finally {
                    $this->unmark_product_processing($product_id);
                }
            }
        }
        
        if ($should_generate_desc) {
            $description = $this->generate_description($product_id);
            if ($description && !is_wp_error($description)) {
                $this->update_product_description($product_id, $description);
            }
        }
        
        // Apply commission to prices
        $this->apply_commission_to_product($product_id);
    }
    
    public function handle_bulk_edit_product($product) {
        if (is_numeric($product)) {
            $product_id = $product;
        } elseif (is_a($product, 'WC_Product')) {
            $product_id = $product->get_id();
        } else {
            return;
        }
        
        // Use the same logic as update
        $this->generate_description_for_updated_product($product_id);
    }
    
    private function update_product_description($product_id, $description) {
        if (empty($description)) {
            return;
        }
        
        $this->mark_product_processing($product_id);
        
        try {
            wp_update_post(array(
                'ID' => $product_id,
                'post_content' => $description
            ));
        } finally {
            $this->unmark_product_processing($product_id);
        }
    }
    
    private function mark_product_processing($product_id) {
        self::$processing_products[$product_id] = true;
    }
    
    private function unmark_product_processing($product_id) {
        if (isset(self::$processing_products[$product_id])) {
            unset(self::$processing_products[$product_id]);
        }
    }
    
    private function is_product_processing($product_id) {
        return isset(self::$processing_products[$product_id]);
    }
    
    public function check_and_delete_out_of_stock($product) {
        $settings = get_option($this->option_name);
        
        // Check if delete out of stock feature is enabled
        if (!isset($settings['delete_out_of_stock']) || $settings['delete_out_of_stock'] !== '1') {
            return;
        }
        
        if (is_a($product, 'WC_Product')) {
            $product_id = $product->get_id();
            $stock_quantity = $product->get_stock_quantity();
        } else {
            return;
        }
        
        // If stock is 0 or null (when managing stock and it becomes zero)
        if ($stock_quantity === 0 || ($product->managing_stock() && $stock_quantity <= 0)) {
            // Delete the product permanently
            wp_delete_post($product_id, true);
        }
    }
    
    private function should_delete_product_for_stock($product_id, $settings) {
        // Check if delete out of stock feature is enabled
        if (!isset($settings['delete_out_of_stock']) || $settings['delete_out_of_stock'] !== '1') {
            return false;
        }
        
        $product = wc_get_product($product_id);
        if (!$product) {
            return false;
        }
        
        $stock_quantity = $product->get_stock_quantity();
        
        // If stock is 0 or null (when managing stock and it becomes zero)
        if ($stock_quantity === 0 || ($product->managing_stock() && $stock_quantity <= 0)) {
            // Delete the product permanently
            wp_delete_post($product_id, true);
            return true;
        }
        
        return false;
    }
    
    private function generate_description($product_id, $overrides = array()) {
        $settings = get_option($this->option_name);
        
        if (empty($settings['api_key']) || empty($settings['prompt_template'])) {
            return new WP_Error('missing_settings', 'API key or prompt template not configured');
        }
        
        $product = wc_get_product($product_id);
        if (!$product) {
            return new WP_Error('invalid_product', 'Product not found');
        }
        
        $product_data = $this->collect_product_data($product);
        
        if (!empty($overrides) && is_array($overrides)) {
            $product_data = array_merge($product_data, $overrides);
        }
        $prompt = $this->replace_variables($settings['prompt_template'], $product_data);
        
        $description = $this->call_ai_api($prompt, $settings);
        
        return $description;
    }
    
    private function generate_title($product_id, $overrides = array()) {
        $settings = get_option($this->option_name);
        
        if (empty($settings['api_key'])) {
            return new WP_Error('missing_settings', 'API key not configured');
        }
        
        if (empty($settings['title_prompt_template'])) {
            return new WP_Error('missing_settings', 'Title prompt template not configured');
        }
        
        $product = wc_get_product($product_id);
        if (!$product) {
            return new WP_Error('invalid_product', 'Product not found');
        }
        
        $product_data = $this->collect_product_data($product);
        
        if (!empty($overrides) && is_array($overrides)) {
            $product_data = array_merge($product_data, $overrides);
        }
        $prompt = $this->replace_variables($settings['title_prompt_template'], $product_data);
        
        $title = $this->call_ai_api($prompt, $settings);
        
        return $title;
    }
    
    private function collect_product_data($product) {
        $categories = wp_get_post_terms($product->get_id(), 'product_cat', array('fields' => 'names'));
        $tags = wp_get_post_terms($product->get_id(), 'product_tag', array('fields' => 'names'));
        
        $attributes = array();
        foreach ($product->get_attributes() as $attribute) {
            if ($attribute->is_taxonomy()) {
                $values = wc_get_product_terms($product->get_id(), $attribute->get_name(), array('fields' => 'names'));
                $attributes[] = $attribute->get_name() . ': ' . implode(', ', $values);
            } else {
                $attributes[] = $attribute->get_name() . ': ' . implode(', ', $attribute->get_options());
            }
        }
        
        return array(
            'title' => $product->get_name() ? $product->get_name() : 'Untitled Product',
            'category' => !empty($categories) ? implode(', ', $categories) : 'Uncategorized',
            'price' => $product->get_price() ? wc_price($product->get_price()) : 'Contact for price',
            'tags' => !empty($tags) ? implode(', ', $tags) : 'No tags',
            'short_description' => $product->get_short_description() ? $product->get_short_description() : 'No short description',
            'attributes' => !empty($attributes) ? implode(' | ', $attributes) : 'No attributes',
            'description' => $product->get_description() ? $product->get_description() : 'No description provided'
        );
    }
    
    private function replace_variables($template, $data) {
        foreach ($data as $key => $value) {
            $template = str_replace('{' . $key . '}', $value, $template);
        }
        return $template;
    }
    
    private function call_ai_api($prompt, $settings) {
        if (!$this->api_handler) {
            $this->api_handler = new AI_Product_Description_API_Handler();
        }
        return $this->api_handler->call_api($prompt, $settings);
    }

    public function ajax_upload_categories_excel() {
        if (!$this->category_manager) {
            $this->category_manager = new AI_Product_Description_Category_Manager();
        }
        return $this->category_manager->ajax_upload_categories_excel();
    }

    public function ajax_import_categories() {
        if (!$this->category_manager) {
            $this->category_manager = new AI_Product_Description_Category_Manager();
        }
        return $this->category_manager->ajax_import_categories();
    }
}
