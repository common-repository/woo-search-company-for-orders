<?php
/**
 * Plugin Name: Search Customer by Company Name / WooCommerce New Order page
 * Description: Order/Add New : use this plugin to search for customers' company names
 * Version: 0.3
 * Plugin Slug: woo_search_company_for_orders
 * Author: Fluenx
 * Requires at least: 4.0
 * Tested up to: 6.4
 * Author: Malaiac
 * Author URI: http://solutions.fluenx.com/
 * Text Domain: woo_search_company_for_orders
 */

/**
 * WooCommerce search extension
 * Adding WooCommerce company name field to 'wc-customer-search' search input
 * as of WooCommerce 3.1.2 this function hooks on :
 * apply_filters( 'woocommerce_json_search_found_customers', $found_customers ) );
 * in json_search_customers()
 * located in woocommerce\includes\class-wc-ajax.php:1214
 * won't search for terms less than 2 characters
 * limit results to 20 for terms less than 5 to prevent clogging
 * @see WC_AJAX::json_search_customers()
 * @return array
 */
add_filter('woocommerce_json_search_found_customers','wc_admin_add_company_name_to_json_search_customers',10,1);
function wc_admin_add_company_name_to_json_search_customers($found_customers = array()) {
	
	// nothing to search here
	if(!array_key_exists('term',$_GET))
		return $found_customers;

	$term    = wc_clean( stripslashes( $_GET['term'] ) );
	// json_search_customers already searched that term : 
	// - as a possible user ID 
	// - or as value in 'search_columns' => array( 'user_login', 'user_url', 'user_email', 'user_nicename', 'display_name' ), 
	
	if(strlen($term) < 2)
		return $found_customers;
	
	$limit = false;
	if(strlen($term) < 5)
		$limit = 20;
	
	// we could do a WP_Query with a WP_Meta_Query 
	global $wpdb;
	$search_term = $term;
	$search_term = $wpdb->esc_like($term);
	$search_term = '%' . $search_term . '%';

	// prevent double results
	if(count($found_customers))
		$already_found_ids = array_keys($found_customers);
	else
		$already_found_ids = array();

	$sql = $wpdb->prepare("SELECT DISTINCT(`user_id`) FROM $wpdb->usermeta
		WHERE `meta_key` IN('billing_company','shipping_company') 
		AND `meta_value` LIKE %s",
		$search_term
	);
	if(count($already_found_ids))
		$sql .=  " AND `user_id` NOT IN (" . implode(',',array_map('intval',$already_found_ids)) .")";
	if($limit && intval($limit) > 0)
		$sql .= " LIMIT " . intval($limit);

	$ids = $wpdb->get_col($sql);

	$newly_found_customers = array();
	if($ids) foreach ( $ids as $id ) {
		if(array_key_exists($id,$found_customers))
			continue;

		$customer = new WC_Customer( $id );

		$company_names = array($customer->get_billing_company(),$customer->get_shipping_company());
		$company_names = array_filter(array_unique($company_names));
		// we expect to have at least one company name 

		$newly_found_customers[ $id ] = sprintf(
			esc_html__( '%1$s (#%2$s &ndash; %3$s)', 'woocommerce' ),
			$customer->get_first_name() . ' ' . $customer->get_last_name() . ' (' . implode(', ',$company_names) .')',
			$customer->get_id(),
			$customer->get_email()
		);
	}

	// adding company name to previously found customers for consistency. @todo check for performance on a large user DB
	if(count($found_customers)) foreach($found_customers as $id => $customer_data) {
		$customer = new WC_Customer( $id );

		$company_names = array($customer->get_billing_company(),$customer->get_shipping_company());
		$company_names = array_filter(array_unique($company_names));

		$full_name = $customer->get_first_name() . ' ' . $customer->get_last_name() ;
		// might not have company names (ie. no previous order)
		if(count($company_names)) {
			$full_name .= ' (' . implode(', ',$company_names) .')';
		}

		$found_customers[$id] = sprintf(
			esc_html__( '%1$s (#%2$s &ndash; %3$s)', 'woocommerce' ),
			$full_name,
			$customer->get_id(),
			$customer->get_email()
		);
	}

	$found_customers = $newly_found_customers + $found_customers;

	return $found_customers;
}
