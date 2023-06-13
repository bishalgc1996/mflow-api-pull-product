<?php 

/**
 * Plugin Name: Mflow API Products Pull
 * Description: Sync products from mflow ERP API to WooCommerce.
 * Version: 1.0.0
 * Author: Bishal GC
 */

 class ProductSync {
    private static $instance;

    private function __construct() {
        // Private constructor to prevent direct instantiation
    }

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function sync_products() {
        // Define the API endpoint URL
        $api_url = 'https://stage.mflow.co.il/api/v1/products/listAll';

        // Set the headers with the public and secret keys
        $headers = array(
            'x-mflow-public-key' => 'pk_dd9da640f0e6174fd3819fc642557ed7',
            'x-mflow-secret-key' => 'sk_04ddbf3e6702ace9a24064ed0bd42f1b',
        );

        // Prepare the arguments for the API request
        $args = array(
            'headers' => $headers,
            'timeout' => 50
        );

        // Send the API request
        $response = wp_remote_get($api_url, $args);

        // Check if the request was successful
        if (is_array($response) && !is_wp_error($response)) {
            // Retrieve the response body
            $body = wp_remote_retrieve_body($response);

            // Decode the JSON response
            $data = json_decode($body);

            // Process the data and create products in WooCommerce
            if (!empty($data->products)) {
                $count = 1;
                foreach ($data->products as $product) {
                    // Check if the product already exists in WooCommerce
                    $existing_product = wc_get_product_id_by_sku($product->sku);

                    if ($count > 12) {
                        break;
                    }

                    if ($existing_product) {
                        $this->update_product($existing_product, $product);
                    } elseif ($product->type === 'Single') {
                        $this->create_single_product($product);
                    } elseif ($product->type === 'Variable') {

                        
                        $this->create_variable_product($product);
                    }

                    $count++;
                }
            }
        } else {
            // Handle the error case
            $error_message = "Error: Unable to retrieve product data.";
            if (is_wp_error($response)) {
                $error_message .= " " . $response->get_error_message();
            }
            echo $error_message;
        }
    }

    private function update_product($product_id, $product_data) {
        $product_object = wc_get_product($product_id);
        $product_object->set_name($product_data->name);
        $product_object->set_regular_price($product_data->price);
        $product_object->set_stock_quantity($product_data->stock_quantity);
        $product_object->save();
        update_post_meta($product_id, 'mflow_product_id', $product_data->id);
    }

    private function create_single_product($product_data) {
        $new_product = new WC_Product_Simple();
        $new_product->set_name($product_data->name);
        $new_product->set_regular_price($product_data->price);
        $new_product->set_description($product_data->description);
        $new_product->set_sku($product_data->sku);
        $new_product->set_stock_quantity($product_data->stock_quantity);
        $new_product->save();
        $product_id = $new_product->get_id();
        update_post_meta($product_id, 'mflow_product_id', $product_data->id);
    }
    
    private function create_variable_product($product_data) {
        $new_product = new WC_Product_Variable();
        $new_product->set_name($product_data->name);
        $new_product->set_description($product_data->description);
        $new_product->set_sku($product_data->sku);
    
        // Set attributes for the variable product
        $attributes = array(
            'color' => array(
                'name'         => 'Color', // Attribute name
                'value'        => '', // Attribute initial value (empty)
                'is_visible'   => 1, // Show attribute on the product page
                'is_variation' => 1, // Use attribute for variations
                'is_taxonomy'  => 0 // Not linked to any taxonomy
            )
        );
    
        $new_product->set_attributes($attributes);
        $new_product->save();
    
        // Get the product ID of the newly created variable product
        $product_id = $new_product->get_id();
    
        // Set variations for the variable product
        $variations = $product_data->variations;
    
        foreach ($variations as $variation_data) {
            // Check if the variation SKU already exists
            $existing_variation = wc_get_product_id_by_sku($variation_data->sku);
    
            if ($existing_variation) {
                continue; // Skip creating the variation if the SKU already exists
            }
    
            $variation = new WC_Product_Variation();
            $variation->set_parent_id($product_id);
            $variation->set_sku($variation_data->sku);
            $variation->set_regular_price($variation_data->price);
            $variation->set_manage_stock($variation_data->manage_stock);
            $variation->set_stock_quantity($variation_data->stock_quantity);
    
            // Set attributes for the variation
            $variation_attributes = array(
                'color' => $variation_data->name // Value of the 'color' attribute for this variation
            );
    
            $variation->set_attributes($variation_attributes);
            $variation->save();
        }
    
        // Update the product ID meta for the variable product
        update_post_meta($product_id, 'mflow_product_id', $product_data->id);
    }
    
    
}

// Usage
$product_sync = ProductSync::get_instance();
add_action('init', array($product_sync, 'sync_products'));