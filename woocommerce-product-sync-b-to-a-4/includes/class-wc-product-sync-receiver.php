<?php
/**
 * WooCommerce Product Sync Receiver (Website B).
 *
 * Adds a custom REST endpoint under wc/v3 so we can create a product
 * with a specific ID (matching Website A's ID).
 *
 * Route: /wp-json/wc/v3/product-sync/create-with-id  (POST)
 *
 * Auth: Uses WooCommerce REST API authentication (consumer key/secret).
 * Permission: Requires a user with capability `manage_woocommerce` or `edit_products`.
 *
 * Notes:
 * - We DO NOT handle images here (per requirement).
 * - If the product already exists on B (same ID), we update basic fields.
 * - If it doesn't exist, we create a product row with that exact ID, then set fields.
 * - Now supports variable products by handling attributes and variations.
 * - Handles ID conflicts by deleting conflicting non-product posts.
 * - Creates missing taxonomy terms for attribute options to ensure variations match properly.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

class WC_Product_Sync_Receiver_B {

    /**
     * Ensure taxonomy term exists and return its ID.
     *
     * @param string $taxonomy The taxonomy name (e.g., 'pa_asynsync').
     * @param string $slug     The term slug.
     * @param string $name     The term name (optional, defaults to capitalized slug).
     * @return int|WP_Error The term ID or WP_Error on failure.
     */
    private function ensure_term_exists( $taxonomy, $slug, $name = '' ) {
        $term = get_term_by( 'slug', $slug, $taxonomy );
        if ( $term && ! is_wp_error( $term ) ) {
            return $term->term_id;
        }

        if ( empty( $name ) ) {
            $name = ucwords( str_replace( '-', ' ', $slug ) );
        }

        $termarr = array(
            'taxonomy'   => $taxonomy,
            'slug'       => $slug,
            'description' => '',
            'parent'     => 0,
        );

        // For WooCommerce product attributes, we need to insert as term with name.
        $new_term = wp_insert_term( $name, $taxonomy, $termarr );
        if ( is_wp_error( $new_term ) ) {
            return $new_term;
        }

        return $new_term['term_id'];
    }


    /**
     * Finds a specific term by its slug and its parent's slug.
     * This is necessary to distinguish between terms that have the same slug but different parents.
     *
     * @param string $slug The slug of the term to find.
     * @param string $parent_slug The slug of the parent term.
     * @return \WP_Term|null The found term object or null if not found.
     */
    private function find_term_by_slug_and_parent( $slug, $parent_slug ) {
        $args = array(
            'taxonomy'   => 'product_cat',
            'slug'       => $slug,
            'hide_empty' => false,
        );
        $terms = get_terms( $args );

        if ( empty( $terms ) || is_wp_error( $terms ) ) {
            return null;
        }

        foreach ( $terms as $term ) {
            $parent_term_id = $term->parent;
            if ( empty( $parent_slug ) ) {
                // We are looking for a top-level term.
                if ( $parent_term_id == 0 ) {
                    return $term;
                }
            } else {
                // We are looking for a child term.
                if ( $parent_term_id != 0 ) {
                    $parent_term = get_term( $parent_term_id, 'product_cat' );
                    if ( $parent_term && ! is_wp_error( $parent_term ) && $parent_term->slug === $parent_slug ) {
                        return $term;
                    }
                }
            }
        }

        return null; // No exact match found.
    }

    public function __construct() {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    public function register_routes() {
        register_rest_route(
            'wc/v3',
            '/product-sync/create-with-id',
            array(
                array(
                    'methods'             => 'POST',
                    'callback'            => array( $this, 'create_with_id' ),
                    'permission_callback' => array( $this, 'permission_check' ),
                    'args'                => array(),
                ),
            )
        );
    }

    public function permission_check( $request ) {
        return current_user_can( 'manage_woocommerce' ) || current_user_can( 'edit_products' );
    }

    public function create_with_id( WP_REST_Request $request ) {
        $data = $request->get_json_params();

        $desired_id = isset( $data['id'] ) ? absint( $data['id'] ) : 0;
        if ( ! $desired_id ) {
            return new WP_Error( 'missing_id', __( 'Product ID is required.', 'wc-product-sync-b-to-a' ), array( 'status' => 400 ) );
        }

        // Determine if the product is being created or updated for the category sync logic.
        $product_post   = get_post( $desired_id );
        $is_new_product = ( ! $product_post || 'product' !== $product_post->post_type );

        // Basic validation
        $existing = get_post( $desired_id );
        if ( $existing && 'product' !== $existing->post_type ) {
            // Delete the conflicting post to allow creation of product with this ID
            $deleted = wp_delete_post( $desired_id, true );
            if ( ! $deleted ) {
                if ( class_exists( 'WC_Product_Sync_Logger_B_To_A' ) ) {
                    WC_Product_Sync_Logger_B_To_A::log( sprintf( 'Failed to delete conflicting post with ID %d.', $desired_id ), 'error' );
                }
                return new WP_Error( 'delete_conflict_failed', __( 'Failed to remove conflicting post.', 'wc-product-sync-b-to-a' ), array( 'status' => 500 ) );
            }
            if ( class_exists( 'WC_Product_Sync_Logger_B_To_A' ) ) {
                WC_Product_Sync_Logger_B_To_A::log( sprintf( 'Deleted conflicting non-product post with ID %d (post_type: %s) to allow product creation.', $desired_id, $existing->post_type ), 'info' );
            }
            // Refresh existing check
            $existing = false;
        }

        // Try to create if not exists.
        if ( ! $existing ) {
            // First, try via wp_insert_post with import_id (preferred).
            $postarr = array(
                'post_type'   => 'product',
                'post_status' => ! empty( $data['status'] ) ? sanitize_key( $data['status'] ) : 'draft',
                'post_title'  => isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : '',
                'post_name'   => isset( $data['slug'] ) ? sanitize_title( $data['slug'] ) : '',
                'import_id'   => $desired_id,
            );

            $inserted_id = wp_insert_post( $postarr, true );

            if ( is_wp_error( $inserted_id ) ) {
                if ( class_exists( 'WC_Product_Sync_Logger_B_To_A' ) ) {
                    WC_Product_Sync_Logger_B_To_A::log( sprintf( 'wp_insert_post failed for desired ID %d: %s', $desired_id, $inserted_id->get_error_message() ), 'error' );
                }
                return new WP_Error(
                    'insert_failed',
                    sprintf( __( 'Failed to insert product with ID %d. Reason: %s', 'wc-product-sync-b-to-a' ), $desired_id, $inserted_id->get_error_message() ),
                    array( 'status' => 500 )
                );
            }

            // wp_insert_post worked, but we MUST verify it used the desired ID.
            if ( (int) $inserted_id !== (int) $desired_id ) {
                // This is a critical failure. WordPress created a post with a different ID.
                // We must delete the incorrect post and return an error.
                wp_delete_post( $inserted_id, true );

                if ( class_exists( 'WC_Product_Sync_Logger_B_To_A' ) ) {
                    WC_Product_Sync_Logger_B_To_A::log( sprintf( 'CRITICAL: wp_insert_post created post with ID %d instead of desired ID %d. The incorrect post has been deleted.', $inserted_id, $desired_id ), 'critical' );
                }

                return new WP_Error(
                    'id_mismatch',
                    sprintf( __( 'Failed to create product with specified ID %d. WordPress assigned a different ID (%d). This may be due to a conflicting post or a database issue. The operation was aborted.', 'wc-product-sync-b-to-a' ), $desired_id, $inserted_id ),
                    array( 'status' => 500 )
                );
            }
        }

        // We have a product post with the exact ID; now set WooCommerce data.
        $type = ! empty( $data['type'] ) ? sanitize_key( $data['type'] ) : 'simple';
        $classname = WC_Product_Factory::get_product_classname( $desired_id, $type );
        if ( ! class_exists( $classname ) ) {
            return new WP_Error( 'invalid_type', __( 'Invalid product type.', 'wc-product-sync-b-to-a' ), array( 'status' => 400 ) );
        }

        $product = new $classname( $desired_id );

        // Set basic fields
        if ( isset( $data['name'] ) ) {
            $product->set_name( sanitize_text_field( $data['name'] ) );
        }
        if ( isset( $data['slug'] ) ) {
            $product->set_slug( sanitize_title( $data['slug'] ) );
        }
        if ( isset( $data['status'] ) ) {
            $product->set_status( sanitize_key( $data['status'] ) );
        }
        if ( isset( $data['catalog_visibility'] ) ) {
            $product->set_catalog_visibility( sanitize_key( $data['catalog_visibility'] ) );
        }
        if ( isset( $data['short_description'] ) ) {
            $product->set_short_description( wp_kses_post( $data['short_description'] ) );
        }
        if ( isset( $data['description'] ) ) {
            $product->set_description( wp_kses_post( $data['description'] ) );
        }
        if ( isset( $data['sku'] ) ) {
            $product->set_sku( wc_clean( $data['sku'] ) );
        }

        // Get price sync mode from settings
        $options = get_option( 'wc_product_sync_b_to_a_settings' );
        $price_sync_mode = isset( $options['price_sync_mode'] ) ? $options['price_sync_mode'] : 'sync_normal';

        // --- Attributes (for all product types) ---
        if ( ! empty( $data['attributes'] ) && is_array( $data['attributes'] ) ) {
            $attributes = array();
            foreach ( $data['attributes'] as $attr_data ) {
                $attribute = new WC_Product_Attribute();
                $attribute->set_name( $attr_data['name'] );

                // If it's a taxonomy, ensure terms exist and set options as slugs.
                $is_taxonomy = ( 0 === strpos( $attr_data['name'], 'pa_' ) );
                if ( $is_taxonomy ) {
                    $option_slugs = (array) $attr_data['options'];
                    // Ensure the attribute itself exists as a taxonomy
                    if ( function_exists( 'wc_create_attribute' ) ) {
                        wc_create_attribute( array( 'name' => str_replace( 'pa_', '', $attr_data['name'] ), 'slug' => str_replace( 'pa_', '', $attr_data['name'] ) ) );
                    }

                    foreach ( $option_slugs as $slug ) {
                        // Ensure the term exists on Site B.
                        $this->ensure_term_exists( $attr_data['name'], $slug );
                    }
                    $attribute->set_options( $option_slugs );
                } else {
                    // It's a custom attribute, so options are just text values.
                    $attribute->set_options( (array) $attr_data['options'] );
                }

                $attribute->set_visible( isset( $attr_data['visible'] ) ? (bool) $attr_data['visible'] : false );
                $attribute->set_variation( isset( $attr_data['variation'] ) ? (bool) $attr_data['variation'] : false );
                $attributes[] = $attribute;
            }
            $product->set_attributes( $attributes );
        }

        // Handle simple products or non-variable
        if ( 'simple' === $type || 'variable' !== $type ) {
            // Set prices based on mode
            if ( 'set_to_zero' === $price_sync_mode ) {
                $product->set_regular_price( '0' );
                $product->set_sale_price( '' );
            } else {
                if ( isset( $data['regular_price'] ) ) {
                    $product->set_regular_price( wc_clean( (string) $data['regular_price'] ) );
                }
                if ( isset( $data['sale_price'] ) ) {
                    $product->set_sale_price( wc_clean( (string) $data['sale_price'] ) );
                }
            }

            // Set stock
            if ( isset( $data['manage_stock'] ) ) {
                $product->set_manage_stock( (bool) $data['manage_stock'] );
            }
            if ( isset( $data['stock_quantity'] ) ) {
                $product->set_stock_quantity( intval( $data['stock_quantity'] ) );
            }
            if ( isset( $data['stock_status'] ) ) {
                $product->set_stock_status( sanitize_key( $data['stock_status'] ) );
            }
        } else {
            // Handle variable products
            // Then, handle variations
            if ( ! empty( $data['variations'] ) && is_array( $data['variations'] ) ) {
                foreach ( $data['variations'] as $var_data ) {
                    $variation_id = absint( $var_data['id'] );
                    $existing_var = get_post( $variation_id );

                    if ( $existing_var && 'product_variation' !== $existing_var->post_type ) {
                        // Delete conflicting variation post
                        wp_delete_post( $variation_id, true );
                        $existing_var = false;
                        if ( class_exists( 'WC_Product_Sync_Logger_B_To_A' ) ) {
                            WC_Product_Sync_Logger_B_To_A::log( sprintf( 'Deleted conflicting non-variation post with ID %d for variation creation.', $variation_id ), 'info' );
                        }
                    }

                    if ( ! $existing_var ) {
                        // Create new variation with specific ID
                        $var_postarr = array(
                            'post_type'   => 'product_variation',
                            'post_status' => 'publish',
                            'post_parent' => $desired_id,
                            'post_title'  => 'Variation', // Updated from 'AUTO DRAFT'
                            'import_id'   => $variation_id,
                            'menu_order'  => isset( $var_data['menu_order'] ) ? intval( $var_data['menu_order'] ) : 0,
                        );
                        $inserted_var_id = wp_insert_post( $var_postarr, true );

                        if ( is_wp_error( $inserted_var_id ) || (int) $inserted_var_id !== (int) $variation_id ) {
                            // Log error and skip if cannot create with exact ID
                            if ( class_exists( 'WC_Product_Sync_Logger_B_To_A' ) ) {
                                WC_Product_Sync_Logger_B_To_A::log( sprintf( 'Failed to create variation ID %d for product %d.', $variation_id, $desired_id ), 'error' );
                            }
                            continue;
                        }
                    } else {
                        $inserted_var_id = $variation_id;
                    }

                    // Load variation object
                    $variation = new WC_Product_Variation( $inserted_var_id );

                    // Set variation description if provided
                    if ( isset( $var_data['description'] ) ) {
                        update_post_meta( $inserted_var_id, '_variation_description', wp_kses_post( $var_data['description'] ) );
                    }

                    // Set variation attributes
                    if ( ! empty( $var_data['attributes'] ) && is_array( $var_data['attributes'] ) ) {
                        $var_attributes = array();
                        foreach ( $var_data['attributes'] as $key => $value ) {
                            // Ensure term exists for the variation value
                            $taxonomy = wc_attribute_taxonomy_name( $key ); // e.g., 'pa_asynsync'
                            $this->ensure_term_exists( $taxonomy, sanitize_title( $value ), $value );
                            
                            $var_attributes[ sanitize_title( $key ) ] = sanitize_text_field( $value );
                        }
                        $variation->set_attributes( $var_attributes );
                    }

                    // Set prices based on mode
                    if ( 'set_to_zero' === $price_sync_mode ) {
                        $variation->set_regular_price( '0' );
                        $variation->set_sale_price( '' );
                    } else {
                        if ( isset( $var_data['regular_price'] ) ) {
                            $variation->set_regular_price( wc_clean( (string) $var_data['regular_price'] ) );
                        }
                        if ( isset( $var_data['sale_price'] ) ) {
                            $variation->set_sale_price( wc_clean( (string) $var_data['sale_price'] ) );
                        }
                    }

                    // Set stock
                    if ( isset( $var_data['manage_stock'] ) ) {
                        $variation->set_manage_stock( (bool) $var_data['manage_stock'] );
                    }
                    if ( isset( $var_data['stock_quantity'] ) ) {
                        $variation->set_stock_quantity( intval( $var_data['stock_quantity'] ) );
                    }
                    if ( isset( $var_data['stock_status'] ) ) {
                        $variation->set_stock_status( sanitize_key( $var_data['stock_status'] ) );
                    }

                    // Set SKU if provided
                    if ( isset( $var_data['sku'] ) && '' !== $var_data['sku'] ) {
                        $variation->set_sku( wc_clean( $var_data['sku'] ) );
                    }

                    $variation->save();
                }

                // After all variations are saved, update the parent variable product's price range, etc.
                $product->variable_product_sync();
            }
        }

        // Handle categories only if the product is new.
        if ( $is_new_product && ! empty( $data['categories'] ) && is_array( $data['categories'] ) ) {
            $category_ids = array();
            foreach ( $data['categories'] as $cat_data ) {
                $slug        = isset( $cat_data['slug'] ) ? $cat_data['slug'] : '';
                $parent_slug = isset( $cat_data['parent'] ) ? $cat_data['parent'] : '';

                if ( ! empty( $slug ) ) {
                    $term = $this->find_term_by_slug_and_parent( $slug, $parent_slug );
                    if ( $term ) {
                        $category_ids[] = $term->term_id;
                    }
                }
            }

            // Use wp_set_object_terms to assign all categories at once.
            if ( ! empty( $category_ids ) ) {
                wp_set_object_terms( $desired_id, array_unique( $category_ids ), 'product_cat' );
            }
        }

        $product->save();

        // Clear caches (transients) for the product to ensure front-end displays updated data.
        if ( function_exists( 'wc_delete_product_transients' ) ) {
            wc_delete_product_transients( $desired_id );
        }

        if ( class_exists( 'WC_Product_Sync_Logger_B_To_A' ) ) {
            WC_Product_Sync_Logger_B_To_A::log( sprintf( 'Created/updated product with forced ID %d via custom endpoint.', $desired_id ), 'info' );
        }

        return new WP_REST_Response(
            array(
                'id'      => $desired_id,
                'success' => true,
            ),
            201
        );
    }
}