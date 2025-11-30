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

class AI_Product_Description_Generator {
    
    private $option_name = 'ai_product_desc_settings';
    private static $processing_products = array();
    
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
            'model' => 'gemini-pro'
        ));
        
        if (isset($_POST['submit']) && check_admin_referer('ai_product_desc_save', 'ai_product_desc_nonce')) {
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
                'model' => sanitize_text_field($_POST['model'])
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
        $api_provider = $settings['api_provider'];
        $api_key = $settings['api_key'];
        $model = isset($settings['model']) ? $settings['model'] : '';
        
        switch ($api_provider) {
            case 'gemini':
                return $this->call_gemini_api($prompt, $api_key, $model);
            case 'groq':
                return $this->call_groq_api($prompt, $api_key, $model);
            case 'claude':
                return $this->call_claude_api($prompt, $api_key, $model);
            case 'openai':
                return $this->call_openai_api($prompt, $api_key, $model);
            case 'deepseek':
                return $this->call_deepseek_api($prompt, $api_key, $model);
            default:
                return new WP_Error('invalid_provider', 'Invalid API provider');
        }
    }
    
    private function call_gemini_api($prompt, $api_key, $model) {
        $model = $model ?: 'gemini-pro';
        
        // Remove 'models/' prefix if present
        $model = str_replace('models/', '', $model);
        
        $url = 'https://generativelanguage.googleapis.com/v1/models/' . $model . ':generateContent?key=' . $api_key;
        
        $response = wp_remote_post($url, array(
            'headers' => array(
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode(array(
                'contents' => array(
                    array(
                        'parts' => array(
                            array('text' => $prompt)
                        )
                    )
                )
            )),
            'timeout' => 60
        ));
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['error'])) {
            return new WP_Error('api_error', $body['error']['message']);
        }
        
        if (isset($body['candidates'][0]['content']['parts'][0]['text'])) {
            return $body['candidates'][0]['content']['parts'][0]['text'];
        }
        
        return new WP_Error('invalid_response', 'Invalid API response');
    }
    
    private function call_groq_api($prompt, $api_key, $model) {
        $model = $model ?: 'mixtral-8x7b-32768';
        
        $response = wp_remote_post('https://api.groq.com/openai/v1/chat/completions', array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $api_key
            ),
            'body' => json_encode(array(
                'model' => $model,
                'messages' => array(
                    array(
                        'role' => 'user',
                        'content' => $prompt
                    )
                ),
                'max_tokens' => 2048
            )),
            'timeout' => 60
        ));
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['error'])) {
            return new WP_Error('api_error', $body['error']['message']);
        }
        
        if (isset($body['choices'][0]['message']['content'])) {
            return $body['choices'][0]['message']['content'];
        }
        
        return new WP_Error('invalid_response', 'Invalid API response');
    }
    
    private function call_claude_api($prompt, $api_key, $model) {
        $model = $model ?: 'claude-3-5-sonnet-20241022';
        
        $response = wp_remote_post('https://api.anthropic.com/v1/messages', array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'x-api-key' => $api_key,
                'anthropic-version' => '2023-06-01'
            ),
            'body' => json_encode(array(
                'model' => $model,
                'max_tokens' => 2048,
                'messages' => array(
                    array(
                        'role' => 'user',
                        'content' => $prompt
                    )
                )
            )),
            'timeout' => 60
        ));
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['error'])) {
            return new WP_Error('api_error', $body['error']['message']);
        }
        
        if (isset($body['content'][0]['text'])) {
            return $body['content'][0]['text'];
        }
        
        return new WP_Error('invalid_response', 'Invalid API response');
    }
    
    private function call_openai_api($prompt, $api_key, $model) {
        $model = $model ?: 'gpt-3.5-turbo';
        
        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $api_key
            ),
            'body' => json_encode(array(
                'model' => $model,
                'messages' => array(
                    array(
                        'role' => 'user',
                        'content' => $prompt
                    )
                ),
                'max_tokens' => 2048
            )),
            'timeout' => 60
        ));
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['error'])) {
            return new WP_Error('api_error', $body['error']['message']);
        }
        
        if (isset($body['choices'][0]['message']['content'])) {
            return $body['choices'][0]['message']['content'];
        }
        
        return new WP_Error('invalid_response', 'Invalid API response');
    }
    
    private function call_deepseek_api($prompt, $api_key, $model) {
        $model = $model ?: 'deepseek-chat';
        
        $response = wp_remote_post('https://api.deepseek.com/v1/chat/completions', array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $api_key
            ),
            'body' => json_encode(array(
                'model' => $model,
                'messages' => array(
                    array(
                        'role' => 'user',
                        'content' => $prompt
                    )
                ),
                'max_tokens' => 2048
            )),
            'timeout' => 60
        ));
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['error'])) {
            return new WP_Error('api_error', $body['error']['message']);
        }
        
        if (isset($body['choices'][0]['message']['content'])) {
            return $body['choices'][0]['message']['content'];
        }
        
        return new WP_Error('invalid_response', 'Invalid API response');
    }
    
    // Category Management Functions
    
    public function ajax_upload_categories_excel() {
        try {
            if (!isset($_POST['category_upload_nonce']) || !wp_verify_nonce($_POST['category_upload_nonce'], 'upload_categories_excel')) {
                wp_send_json_error(array('message' => 'Security check failed'));
                return;
            }
            
            if (!current_user_can('manage_options')) {
                wp_send_json_error(array('message' => 'Insufficient permissions'));
                return;
            }
            
            if (!isset($_FILES['category_excel_file']) || $_FILES['category_excel_file']['error'] !== UPLOAD_ERR_OK) {
                wp_send_json_error(array('message' => 'File upload failed'));
                return;
            }
            
            $file = $_FILES['category_excel_file'];
            $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            
            if (!in_array($file_ext, array('xlsx', 'xls'))) {
                wp_send_json_error(array('message' => 'Invalid file type. Please upload .xlsx or .xls file'));
                return;
            }
            
            $categories = $this->read_excel_categories($file['tmp_name'], $file_ext);
            
            if (is_wp_error($categories)) {
                wp_send_json_error(array('message' => $categories->get_error_message()));
                return;
            }
            
            wp_send_json_success(array('categories' => $categories));
            
        } catch (Exception $e) {
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }
    
    public function ajax_import_categories() {
        try {
            if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'import_categories')) {
                wp_send_json_error(array('message' => 'Security check failed'));
                return;
            }
            
            if (!current_user_can('manage_options')) {
                wp_send_json_error(array('message' => 'Insufficient permissions'));
                return;
            }
            
            if (!isset($_POST['categories']) || empty($_POST['categories'])) {
                wp_send_json_error(array('message' => 'No categories provided'));
                return;
            }
            
            $categories = json_decode(stripslashes($_POST['categories']), true);
            
            if (!is_array($categories) || empty($categories)) {
                wp_send_json_error(array('message' => 'Invalid categories data'));
                return;
            }
            
            // Delete all existing categories
            $deleted_count = $this->delete_all_categories();
            
            // Import new categories
            $added_count = $this->import_categories($categories);
            
            wp_send_json_success(array(
                'deleted_count' => $deleted_count,
                'added_count' => $added_count
            ));
            
        } catch (Exception $e) {
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }
    
    private function read_excel_categories($file_path, $file_ext) {
        // Try to use PhpSpreadsheet if available
        if (class_exists('PhpOffice\PhpSpreadsheet\IOFactory')) {
            return $this->read_excel_with_phpspreadsheet($file_path);
        }
        
        // Fallback to simple method using ZIP and XML parsing
        if ($file_ext === 'xlsx') {
            return $this->read_xlsx_simple($file_path);
        } else {
            // For .xls files, we need a library or convert to xlsx
            return new WP_Error('unsupported_format', 'Please convert .xls file to .xlsx format');
        }
    }
    
    private function read_excel_with_phpspreadsheet($file_path) {
        try {
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file_path);
            $worksheet = $spreadsheet->getActiveSheet();
            $rows = $worksheet->toArray();
            
            $categories = array();
            
            if (empty($rows)) {
                return $categories;
            }
            
            // First row contains parent categories (headers)
            $parent_categories = array();
            $first_row = $rows[0];
            foreach ($first_row as $col_index => $parent_name) {
                $parent_name = trim($parent_name);
                if (!empty($parent_name)) {
                    $parent_categories[$col_index] = $parent_name;
                }
            }
            
            // Process remaining rows - each row contains child categories for each column
            for ($row_index = 1; $row_index < count($rows); $row_index++) {
                $row = $rows[$row_index];
                
                // Check each column for child categories
                foreach ($parent_categories as $col_index => $parent_name) {
                    $child_name = isset($row[$col_index]) ? trim($row[$col_index]) : '';
                    
                    if (!empty($child_name)) {
                        $categories[] = array(
                            'parent' => $parent_name,
                            'child' => $child_name
                        );
                    }
                }
            }
            
            return $categories;
        } catch (Exception $e) {
            return new WP_Error('read_error', 'Error reading Excel file: ' . $e->getMessage());
        }
    }
    
    private function read_xlsx_simple($file_path) {
        // Simple XLSX reader using ZIP and XML
        if (!class_exists('ZipArchive')) {
            return new WP_Error('zip_not_available', 'ZIP extension is not available. Please install PhpSpreadsheet library or enable ZIP extension.');
        }
        
        try {
            $zip = new ZipArchive();
            if ($zip->open($file_path) !== TRUE) {
                return new WP_Error('zip_open_error', 'Cannot open Excel file');
            }
            
            // Read shared strings
            $shared_strings = array();
            if (($shared_strings_xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
                $xml = simplexml_load_string($shared_strings_xml);
                if ($xml) {
                    foreach ($xml->si as $si) {
                        $shared_strings[] = (string)$si->t;
                    }
                }
            }
            
            // Read first worksheet
            $worksheet_xml = $zip->getFromName('xl/worksheets/sheet1.xml');
            if ($worksheet_xml === false) {
                $zip->close();
                return new WP_Error('worksheet_error', 'Cannot read worksheet');
            }
            
            $zip->close();
            
            // Parse worksheet XML
            $xml = simplexml_load_string($worksheet_xml);
            if (!$xml) {
                return new WP_Error('xml_error', 'Cannot parse worksheet XML');
            }
            
            $categories = array();
            
            // Get all rows
            $namespaces = $xml->getNamespaces(true);
            $xml->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            
            $rows = $xml->xpath('//x:row');
            
            if (empty($rows)) {
                return $categories;
            }
            
            // First row contains parent categories (headers)
            $parent_categories = array();
            $first_row = $rows[0];
            $first_row_cells = $first_row->xpath('.//x:c');
            
            foreach ($first_row_cells as $cell) {
                $cell_ref = (string)$cell['r'];
                $col = preg_replace('/[0-9]+/', '', $cell_ref);
                $col_index = $this->column_to_index($col);
                
                $value = '';
                if (isset($cell->v)) {
                    $cell_value = (string)$cell->v;
                    if (isset($cell['t']) && $cell['t'] == 's') {
                        // Shared string
                        $value = isset($shared_strings[intval($cell_value)]) ? $shared_strings[intval($cell_value)] : '';
                    } else {
                        $value = $cell_value;
                    }
                }
                
                $parent_name = trim($value);
                if (!empty($parent_name)) {
                    $parent_categories[$col_index] = $parent_name;
                }
            }
            
            // Process remaining rows - each row contains child categories for each column
            for ($row_index = 1; $row_index < count($rows); $row_index++) {
                $row = $rows[$row_index];
                $cells = $row->xpath('.//x:c');
                $row_data = array();
                
                foreach ($cells as $cell) {
                    $cell_ref = (string)$cell['r'];
                    $col = preg_replace('/[0-9]+/', '', $cell_ref);
                    $col_index = $this->column_to_index($col);
                    
                    $value = '';
                    if (isset($cell->v)) {
                        $cell_value = (string)$cell->v;
                        if (isset($cell['t']) && $cell['t'] == 's') {
                            // Shared string
                            $value = isset($shared_strings[intval($cell_value)]) ? $shared_strings[intval($cell_value)] : '';
                        } else {
                            $value = $cell_value;
                        }
                    }
                    
                    $row_data[$col_index] = trim($value);
                }
                
                // Check each column for child categories
                foreach ($parent_categories as $col_index => $parent_name) {
                    $child_name = isset($row_data[$col_index]) ? $row_data[$col_index] : '';
                    
                    if (!empty($child_name)) {
                        $categories[] = array(
                            'parent' => $parent_name,
                            'child' => $child_name
                        );
                    }
                }
            }
            
            return $categories;
            
        } catch (Exception $e) {
            return new WP_Error('read_error', 'Error reading Excel file: ' . $e->getMessage());
        }
    }
    
    private function column_to_index($column) {
        $column = strtoupper($column);
        $index = 0;
        $length = strlen($column);
        for ($i = 0; $i < $length; $i++) {
            $index = $index * 26 + (ord($column[$i]) - ord('A') + 1);
        }
        return $index - 1;
    }
    
    private function delete_all_categories() {
        $categories = get_terms(array(
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
        ));
        
        $deleted_count = 0;
        
        foreach ($categories as $category) {
            wp_delete_term($category->term_id, 'product_cat');
            $deleted_count++;
        }
        
        return $deleted_count;
    }
    
    private function import_categories($categories) {
        $added_count = 0;
        $parent_map = array(); // Map parent names to term IDs
        
        foreach ($categories as $cat) {
            $parent_name = trim($cat['parent']);
            $child_name = trim($cat['child']);
            
            // Skip if both are empty
            if (empty($parent_name) && empty($child_name)) {
                continue;
            }
            
            // Handle parent category
            $parent_id = 0;
            if (!empty($parent_name)) {
                if (!isset($parent_map[$parent_name])) {
                    // Check if parent already exists
                    $existing = term_exists($parent_name, 'product_cat');
                    if ($existing) {
                        $parent_id = $existing['term_id'];
                    } else {
                        // Create parent category
                        $result = wp_insert_term($parent_name, 'product_cat');
                        if (!is_wp_error($result)) {
                            $parent_id = $result['term_id'];
                        }
                    }
                    $parent_map[$parent_name] = $parent_id;
                } else {
                    $parent_id = $parent_map[$parent_name];
                }
            }
            
            // Handle child category
            if (!empty($child_name)) {
                // Check if child already exists
                $existing = term_exists($child_name, 'product_cat', $parent_id);
                if (!$existing) {
                    // Create child category
                    $result = wp_insert_term($child_name, 'product_cat', array(
                        'parent' => $parent_id
                    ));
                    if (!is_wp_error($result)) {
                        $added_count++;
                    }
                } else {
                    // Update parent if needed
                    if ($parent_id > 0 && $existing['term_id']) {
                        wp_update_term($existing['term_id'], 'product_cat', array(
                            'parent' => $parent_id
                        ));
                    }
                    $added_count++;
                }
            } elseif (!empty($parent_name) && $parent_id > 0) {
                // Only parent, no child
                $added_count++;
            }
        }
        
        return $added_count;
    }
}

new AI_Product_Description_Generator();