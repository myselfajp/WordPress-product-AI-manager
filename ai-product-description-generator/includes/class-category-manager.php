<?php
/**
 * Category Manager Class
 * Handles category import/export from Excel files
 */

if (!defined('ABSPATH')) {
    exit;
}

class AI_Product_Description_Category_Manager {
    
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

