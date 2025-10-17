<?php
/**
 * API endpoint definitions for handling order data.
 */

add_action('rest_api_init', function () {
    register_rest_route('order-manager/v1', '/orders', array(
        'methods' => 'POST',
        'callback' => 'create_order',
        'permission_callback' => '__return_true',
    ));

    register_rest_route('order-manager/v1', '/orders', array(
        'methods' => 'GET',
        'callback' => 'get_orders',
        'permission_callback' => '__return_true',
    ));
});

function create_order(WP_REST_Request $request) {
    $data = $request->get_json_params();

    $name = sanitize_text_field($data['name']);
    $address = sanitize_text_field($data['address']);
    $phone = sanitize_text_field($data['phone']);
    $turkey_qty = intval($data['turkey_qty']);
    $ham_qty = intval($data['ham_qty']);
    $combo_qty = intval($data['combo_qty']);
    $donation = floatval($data['donation']);

    $total_units = $turkey_qty + $ham_qty + $combo_qty;
    $total_amount = ($total_units * 10) + $donation;

    // Here you would typically save the order to the database
    // For example, using wp_insert_post() or a custom table

    return new WP_REST_Response(array(
        'message' => 'Order created successfully',
        'total_amount' => $total_amount,
    ), 201);
}

function get_orders(WP_REST_Request $request) {
    // Here you would typically retrieve orders from the database
    // For example, using get_posts() or a custom query

    return new WP_REST_Response(array(
        'orders' => array(), // Replace with actual orders
    ), 200);
}
?>