<?php
/**
 * Builds the Shoevenir store inside WordPress Playground.
 * Run by the blueprint after WooCommerce and the theme are installed.
 * Product photos are read from /wordpress/sv-images.
 */
require_once '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

// The photos are already web-sized, so each one is copied straight into uploads and registered with its
// size. (media_handle_sideload opens every image, which made the demo slow to build.)
function sv_sideload( $tmp, $name, $parent, $title ) {
	$up   = wp_upload_dir();
	$file = wp_unique_filename( $up['path'], $name );
	$dest = trailingslashit( $up['path'] ) . $file;
	if ( ! @rename( $tmp, $dest ) && ! copy( $tmp, $dest ) ) {
		return new WP_Error( 'sv_copy', 'Could not copy ' . $name );
	}
	$type = wp_check_filetype( $file );
	$size = @getimagesize( $dest ) ?: [ 0, 0 ];
	$id   = wp_insert_attachment( [ 'post_mime_type' => $type['type'], 'post_title' => $title, 'post_status' => 'inherit', 'guid' => trailingslashit( $up['url'] ) . $file ], $dest, $parent, true, false );
	if ( is_wp_error( $id ) ) {
		return $id;
	}
	wp_update_attachment_metadata( $id, [ 'width' => $size[0], 'height' => $size[1], 'file' => _wp_relative_upload_path( $dest ), 'sizes' => [], 'image_meta' => [] ] );
	return $id;
}

// One transaction for the whole import: SQLite otherwise commits (and syncs to disk) after every query.
wp_defer_term_counting( true );
wp_suspend_cache_invalidation( true );
$wpdb->query( 'START TRANSACTION' );

// Fast demo build: the photos are already web-sized, so skip making thumbnails of each one.
// (Real hosting can regenerate thumbnails later.)
if ( defined( 'SV_FAST' ) && SV_FAST ) {
	add_filter( 'intermediate_image_sizes_advanced', '__return_empty_array' );
	add_filter( 'big_image_size_threshold', '__return_false' );
	add_filter( 'woocommerce_background_image_regeneration', '__return_false' );
	add_filter( 'woocommerce_resize_images', '__return_false' );
}

// Store settings.
foreach ( [
	'blogname'                               => 'Shoevenir',
	'blogdescription'                        => 'Authentic Ex-UK mtumba / thrifted shoes. Countrywide deliveries & worldwide shipping.',
	'timezone_string'                        => 'Africa/Nairobi',
	'woocommerce_currency'                   => 'KES',
	'woocommerce_currency_pos'               => 'left_space',
	'woocommerce_price_num_decimals'         => '0',
	'woocommerce_price_thousand_sep'         => ',',
	'woocommerce_default_country'            => 'KE:KE30',
	'woocommerce_store_city'                 => 'Nairobi',
	'woocommerce_manage_stock'               => 'yes',
	'woocommerce_notify_low_stock_amount'    => '0',
	'woocommerce_notify_no_stock_amount'     => '0',
	'woocommerce_allowed_countries'          => 'all',
	'woocommerce_ship_to_countries'          => '',
	'woocommerce_enable_reviews'             => 'no',
	'woocommerce_onboarding_profile'         => [ 'skipped' => true ],
	'woocommerce_task_list_hidden'           => 'yes',
	'woocommerce_coming_soon'                => 'no',
	'woocommerce_checkout_phone_field'       => 'required',
	'woocommerce_enable_coupons'             => 'yes',
	'woocommerce_hide_out_of_stock_items'    => 'no',
] as $k => $v ) {
	update_option( $k, $v );
}

// Remove sample content.
foreach ( get_posts( [ 'post_type' => [ 'post', 'page' ], 'name' => 'hello-world', 'numberposts' => 1 ] ) as $p ) wp_delete_post( $p->ID, true );
$sample = get_page_by_path( 'sample-page' );
if ( $sample ) wp_delete_post( $sample->ID, true );

// Categories (menu order = order in the filters). Empty ones stay hidden until staff add shoes to them.
$cat = [];
$i   = 0;
foreach ( [
	'new-drops'      => [ 'New Drops', 'The latest batch, fresh from the bale. One pair of each, so first come, first served.' ],
	'jordans'        => [ 'Jordans & Basketball', 'Air Jordan 1 lows, mids and highs, plus Nike basketball classics.' ],
	'sports-running' => [ 'Sports & Running', 'Air Max, TNs, New Balance and more: cushioned runners for everyday wear.' ],
	'casual-skate'   => [ 'Casual & Skate', 'Converse, Vans and Reebok: canvas and leather everyday shoes.' ],
	'formal-boots'   => [ 'Formal & Boots', 'Leather oxfords, brogues, Chelsea boots and Timberlands.' ],
	'casual-loafers' => [ 'Casual & Loafers', 'Loafers, boat shoes and slip-ons.' ],
	'heels-ladies'   => [ 'Heels & Ladies', 'Heels, flats and ladies\' sneakers.' ],
	'clearance'      => [ 'Clearance Sale', 'Marked-down pairs. When they\'re gone, they\'re gone.' ],
] as $slug => [ $name, $desc ] ) {
	$t = term_exists( $slug, 'product_cat' ) ?: wp_insert_term( $name, 'product_cat', [ 'slug' => $slug, 'description' => $desc ] );
	$cat[ $slug ] = (int) $t['term_id'];
	update_term_meta( $cat[ $slug ], 'order', $i++ );
}

// Global attributes with their own pages (/size/eu-43/, /grade/grade-a-plus/), used by the size and grade filters.
function sv_attribute( $slug, $label, $terms ) {
	if ( ! wc_attribute_taxonomy_id_by_name( $slug ) ) {
		wc_create_attribute( [ 'name' => $label, 'slug' => $slug, 'type' => 'select', 'order_by' => 'menu_order', 'has_archives' => true ] );
	}
	$tax = wc_attribute_taxonomy_name( $slug );
	if ( ! taxonomy_exists( $tax ) ) {
		register_taxonomy( $tax, [ 'product' ], [ 'hierarchical' => false, 'show_ui' => false, 'query_var' => true, 'rewrite' => false ] );
	}
	$ids = [];
	foreach ( $terms as $n => [ $term_slug, $name ] ) {
		$t = term_exists( $term_slug, $tax ) ?: wp_insert_term( $name, $tax, [ 'slug' => $term_slug ] );
		$ids[ $term_slug ] = (int) $t['term_id'];
		update_term_meta( $ids[ $term_slug ], 'order', $n );
	}
	return $ids;
}
$sizes = [];
foreach ( [ '38', '39', '40', '41', '42', '42.5', '43', '44', '44.5', '45', '45.5', '46' ] as $s ) {
	$sizes[] = [ 'eu-' . str_replace( '.', '-', $s ), 'EU ' . $s ];
}
$size_ids  = sv_attribute( 'size', 'Size', $sizes );
$grade_ids = sv_attribute( 'grade', 'Grade', [ [ 'grade-a-plus', 'Grade A+ · Like New' ], [ 'grade-a', 'Grade A · Clean' ], [ 'pre-loved', 'Pre-Loved' ] ] );
delete_transient( 'wc_attribute_taxonomies' );

// Delivery. PLACEHOLDER prices, to confirm with the client.
$zone = new WC_Shipping_Zone();
$zone->set_zone_name( 'Nairobi' );
$zone->add_location( 'KE:KE30', 'state' );
$zone->save();
$id = $zone->add_shipping_method( 'free_shipping' );
update_option( "woocommerce_free_shipping_{$id}_settings", [ 'title' => 'Free Nairobi Express rider', 'requires' => 'min_amount', 'min_amount' => '10000' ] );
$id = $zone->add_shipping_method( 'flat_rate' );
update_option( "woocommerce_flat_rate_{$id}_settings", [ 'title' => 'Nairobi Express rider (same day)', 'cost' => '300', 'tax_status' => 'none' ] );

$rest = new WC_Shipping_Zone();
$rest->set_zone_name( 'Rest of Kenya' );
$rest->add_location( 'KE', 'country' );
$rest->save();
$id = $rest->add_shipping_method( 'flat_rate' );
update_option( "woocommerce_flat_rate_{$id}_settings", [ 'title' => 'Wells Fargo / Fargo Courier (1–2 days)', 'cost' => '450', 'tax_status' => 'none' ] );
$id = $rest->add_shipping_method( 'flat_rate' );
update_option( "woocommerce_flat_rate_{$id}_settings", [ 'title' => 'EasyCoach parcel (collect at the stage)', 'cost' => '350', 'tax_status' => 'none' ] );

$world = new WC_Shipping_Zone( 0 ); // "Locations not covered by your other zones" = everywhere outside Kenya.
$id = $world->add_shipping_method( 'flat_rate' );
update_option( "woocommerce_flat_rate_{$id}_settings", [ 'title' => 'DHL Worldwide Express (3–7 days)', 'cost' => '6500', 'tax_status' => 'none' ] );

// Pick-up in Nairobi, paid on collection. PLACEHOLDER spot: the client shares the exact place on WhatsApp.
update_option( 'woocommerce_pickup_location_settings', [ 'enabled' => 'yes', 'title' => 'Pick up in Nairobi CBD', 'tax_status' => 'none', 'cost' => '' ] );
update_option( 'pickup_location_pickup_locations', [ [
	'name'    => 'Shoevenir pick-up point, Nairobi CBD',
	'address' => [ 'address_1' => 'Nairobi CBD (exact spot sent on WhatsApp)', 'city' => 'Nairobi', 'state' => 'KE30', 'postcode' => '', 'country' => 'KE' ],
	'details' => 'Monday to Saturday, 8 am – 7 pm. We confirm the time and spot on WhatsApp before you come.',
	'enabled' => true,
] ] );

// Payment.
// M-Pesa Express: an offline method for now. Staff confirm the pair, then send the M-Pesa prompt / till number.
// (A live STK push needs the client's own Safaricom Daraja keys.)
update_option( 'woocommerce_cheque_settings', [
	'enabled'      => 'yes',
	'title'        => 'M-Pesa Express',
	'description'  => 'Place the order, and we\'ll confirm your pair is reserved and send an M-Pesa payment prompt to your phone number.',
	'instructions' => 'We\'re confirming your pair now. Watch your phone for the M-Pesa prompt from Shoevenir, enter your PIN, and we dispatch as soon as it\'s paid.',
] );
update_option( 'woocommerce_cod_settings', [
	'enabled'            => 'yes',
	'title'              => 'Cash on delivery / Pay on pick-up',
	'description'        => 'Nairobi: pay the rider in cash or M-Pesa when your shoes arrive, or pay when you pick up. Check the pair first.',
	'instructions'       => 'We\'ll call or WhatsApp you to confirm the pair and arrange delivery or pick-up.',
	'enable_for_methods' => [],
	'enable_for_virtual' => 'yes',
] );
update_option( 'woocommerce_gateway_order', [ 'cheque' => 0, 'cod' => 1 ] );

/*
 * Products: the pairs posted in the Shoevenir WhatsApp group (drops of 23, 24 and 29 September 2026).
 * Photos are raw/m<n>.jpg in the catalogue folder, made into <slug>.webp, <slug>-2.webp… by build-images.js.
 * PLACEHOLDERS to confirm with the client: every price, the UK retail comparison, the grade and the condition scores.
 * Multi-pair posts: the caption gave the sizes but not which pair is which, so pairs are matched to sizes in the
 * order the caption lists them (left to right in the group photo). To confirm.
 */
$products = [
	[
		'slug' => 'air-jordan-1-low-black-pink-volt', 'name' => 'Air Jordan 1 Low "Black, Pink & Volt"', 'brand' => 'Jordan', 'cats' => [ 'jordans' ], 'price' => 5500, 'retail' => 18000,
		'size' => '45.5', 'uk' => '10.5', 'grade' => 'grade-a-plus',
		'desc' => 'White leather base with black overlays, a hot-pink Swoosh and a volt Wings logo on the heel. Clean midsoles, crisp laces and hardly any wear on the outsole.',
		'cond' => [ 'Sole' => 9, 'Upper' => 9, 'Cleanliness' => 9, 'Insole' => 9 ],
	],
	[
		'slug' => 'air-jordan-1-mid-red-green', 'name' => 'Air Jordan 1 Mid "Red, Green & White"', 'brand' => 'Jordan', 'cats' => [ 'jordans' ], 'price' => 6000, 'retail' => 19500,
		'size' => '43', 'uk' => '8.5', 'grade' => 'grade-a-plus',
		'desc' => 'Deep red leather, a forest-green Swoosh and heel, on a white base. A bold, festive-looking Mid that still has sharp toe boxes and bright red outsoles.',
		'cond' => [ 'Sole' => 9, 'Upper' => 9, 'Cleanliness' => 9, 'Insole' => 9 ],
	],
	[
		'slug' => 'converse-chuck-70-low-olive-green', 'name' => 'Converse Chuck 70 Low "Olive & Forest Green"', 'brand' => 'Converse', 'cats' => [ 'casual-skate' ], 'price' => 4000, 'retail' => 14500,
		'size' => '42.5', 'uk' => '8.5', 'grade' => 'grade-a-plus',
		'desc' => 'The premium Chuck 70: heavier canvas in olive with forest-green heel panels, vintage egret midsoles and the black heel patch. Soles look barely worn.',
		'cond' => [ 'Sole' => 9, 'Upper' => 9, 'Cleanliness' => 9, 'Insole' => 9 ],
	],
	[
		'slug' => 'nike-air-flight-89-white-royal', 'name' => 'Nike Air Flight \'89 "White & Royal Blue"', 'brand' => 'Nike', 'cats' => [ 'jordans' ], 'price' => 5000, 'retail' => 18500,
		'size' => '41', 'uk' => '7', 'grade' => 'grade-a-plus',
		'desc' => 'The \'89 basketball classic Jordan wore before the IV: white leather, royal-blue Swoosh and royal outsole, with visible Air in the heel.',
		'cond' => [ 'Sole' => 9, 'Upper' => 9, 'Cleanliness' => 10, 'Insole' => 9 ],
	],
	[
		'slug' => 'air-jordan-1-mid-three-pairs', 'name' => 'Air Jordan 1 Mid – 3 Pairs', 'brand' => 'Jordan', 'cats' => [ 'jordans' ], 'price' => 6000, 'retail' => 19500,
		'grade' => 'grade-a', 'attr' => 'Pair',
		'pairs' => [ 'Teal & black' => '44.5', 'Red & white' => '43', 'Grey & white' => '42.5' ],
		'desc' => 'Three Jordan 1 Mids from the same bale, one pair of each: teal and black, gym red and white, and smoke grey. Pick your pair and size.',
		'cond' => [ 'Sole' => 8, 'Upper' => 9, 'Cleanliness' => 9, 'Insole' => 8 ],
	],
	[
		'slug' => 'converse-chuck-taylor-low-green-knit', 'name' => 'Converse Chuck Taylor All Star Low "Green Space-Dye"', 'brand' => 'Converse', 'cats' => [ 'casual-skate' ], 'price' => 3000, 'retail' => 10000,
		'size' => '44', 'uk' => '9.5', 'grade' => 'grade-a-plus',
		'desc' => 'A mint-and-white space-dye canvas All Star with navy heel stripes. Light, easy and different from the usual black and white.',
		'cond' => [ 'Sole' => 9, 'Upper' => 9, 'Cleanliness' => 9, 'Insole' => 9 ],
	],
	[
		'slug' => 'nike-air-max-excee-black-white', 'name' => 'Nike Air Max Excee "Black & White"', 'brand' => 'Nike', 'cats' => [ 'sports-running' ], 'price' => 4500, 'retail' => 16000,
		'size' => '43', 'uk' => '8.5', 'grade' => 'grade-a-plus',
		'desc' => 'Air Max 90 styling in a lighter everyday runner: black mesh and suede, a big white Swoosh, grey heel and a visible Air unit.',
		'cond' => [ 'Sole' => 9, 'Upper' => 9, 'Cleanliness' => 9, 'Insole' => 9 ],
	],
	[
		'slug' => 'air-jordan-1-high-flyknit-grey-multi', 'name' => 'Air Jordan 1 High Flyknit "Grey Multi-Swoosh"', 'brand' => 'Jordan', 'cats' => [ 'jordans' ], 'price' => 6500, 'retail' => 27000,
		'size' => '43', 'uk' => '8.5', 'grade' => 'grade-a-plus',
		'desc' => 'A grey Flyknit Jordan 1 High with a different-coloured Swoosh on every side (red, yellow, blue and black) and a gum outsole. A rare one.',
		'cond' => [ 'Sole' => 9, 'Upper' => 9, 'Cleanliness' => 9, 'Insole' => 9 ],
	],
	[
		'slug' => 'reebok-club-c-85-white-green', 'name' => 'Reebok Club C 85 "White & Green"', 'brand' => 'Reebok', 'cats' => [ 'casual-skate' ], 'price' => 4000, 'retail' => 13500,
		'size' => '42', 'uk' => '8', 'grade' => 'grade-a-plus',
		'desc' => 'The clean tennis classic: soft white leather, green Reebok branding and a white cupsole. Goes with everything.',
		'cond' => [ 'Sole' => 9, 'Upper' => 9, 'Cleanliness' => 10, 'Insole' => 9 ],
	],
	[
		'slug' => 'vans-authentic-white-checker', 'name' => 'Vans Authentic "White Leather Checker"', 'brand' => 'Vans', 'cats' => [ 'casual-skate' ], 'price' => 3500, 'retail' => 11000,
		'size' => '42', 'uk' => '8', 'grade' => 'grade-a-plus',
		'desc' => 'All-white leather Authentic with a black-and-white checkerboard foxing stripe and the waffle outsole.',
		'cond' => [ 'Sole' => 9, 'Upper' => 9, 'Cleanliness' => 9, 'Insole' => 9 ],
	],
	[
		'slug' => 'vans-era-two-pairs', 'name' => 'Vans Era & Authentic – 2 Pairs', 'brand' => 'Vans', 'cats' => [ 'casual-skate' ], 'price' => 3500, 'retail' => 11000,
		'grade' => 'grade-a', 'attr' => 'Pair', 'sold' => true,
		'pairs' => [ 'Paisley skull print' => '42', 'White checker' => '43' ],
		'desc' => 'A paisley-and-skull print Era and a white leather Authentic with checker foxing.',
		'cond' => [ 'Sole' => 8, 'Upper' => 9, 'Cleanliness' => 9, 'Insole' => 8 ],
	],
	[
		'slug' => 'adidas-adifom-white-green', 'name' => 'adidas adiFOM "White & Green"', 'brand' => 'adidas', 'cats' => [ 'sports-running', 'new-drops' ], 'price' => 4500, 'retail' => 17000,
		'size' => '44', 'uk' => '9', 'grade' => 'grade-a-plus',
		'desc' => 'Futuristic one-piece foam with cut-out panels and a green stripe. Light, breathable and easy to wash.',
		'cond' => [ 'Sole' => 9, 'Upper' => 10, 'Cleanliness' => 9, 'Insole' => 9 ],
	],
	[
		'slug' => 'puma-runner-white-black-burgundy', 'name' => 'Puma Runner "White, Black & Burgundy"', 'brand' => 'Puma', 'cats' => [ 'sports-running', 'new-drops' ], 'price' => 3500, 'retail' => 12000,
		'size' => '43', 'uk' => '8.5', 'grade' => 'grade-a-plus',
		'desc' => 'A chunky retro runner in white mesh with black and grey overlays and a burgundy heel.',
		'cond' => [ 'Sole' => 9, 'Upper' => 9, 'Cleanliness' => 9, 'Insole' => 9 ],
	],
	[
		'slug' => 'new-balance-327-white-black', 'name' => 'New Balance 327 "White & Black"', 'brand' => 'New Balance', 'cats' => [ 'sports-running', 'new-drops' ], 'price' => 5000, 'retail' => 16000,
		'size' => '43', 'uk' => '8.5', 'grade' => 'grade-a-plus',
		'desc' => 'The 327 with its oversized black "N", sea-salt suede and nylon, and the lugged outsole that wraps up the heel.',
		'cond' => [ 'Sole' => 9, 'Upper' => 9, 'Cleanliness' => 9, 'Insole' => 9 ],
	],
	[
		'slug' => 'air-jordan-1-low-grey-black', 'name' => 'Air Jordan 1 Low "Grey & Black"', 'brand' => 'Jordan', 'cats' => [ 'jordans', 'new-drops' ], 'price' => 5500, 'retail' => 18000,
		'size' => '43', 'uk' => '8.5', 'grade' => 'grade-a-plus',
		'desc' => 'Cement-grey and light-grey suede with black laces, heel and collar. An easy everyday Jordan.',
		'cond' => [ 'Sole' => 9, 'Upper' => 9, 'Cleanliness' => 9, 'Insole' => 9 ],
	],
	[
		'slug' => 'nike-air-max-plus-tn-triple-red', 'name' => 'Nike Air Max Plus TN "Triple Red"', 'brand' => 'Nike', 'cats' => [ 'sports-running', 'new-drops' ], 'price' => 6500, 'retail' => 29000,
		'size' => '41', 'uk' => '7', 'grade' => 'grade-a-plus',
		'desc' => 'The TN in head-to-toe university red: wavy cage, Tuned Air pods and the whale-tail shank. Loud in the best way.',
		'cond' => [ 'Sole' => 9, 'Upper' => 9, 'Cleanliness' => 9, 'Insole' => 9 ],
	],
	[
		'slug' => 'air-jordan-1-low-three-pairs', 'name' => 'Air Jordan 1 Low – 3 Pairs', 'brand' => 'Jordan', 'cats' => [ 'jordans', 'new-drops' ], 'price' => 5500, 'retail' => 18000,
		'grade' => 'grade-a', 'attr' => 'Pair',
		'pairs' => [ 'Black, pink & volt' => '45.5', 'Lucky green' => '43', 'Grey & black' => '42.5' ],
		'desc' => 'Three Jordan 1 Lows, one pair of each: black, pink and volt, lucky green and white, and grey and black.',
		'cond' => [ 'Sole' => 8, 'Upper' => 9, 'Cleanliness' => 9, 'Insole' => 8 ],
	],
];

// UK sizes that go with the EU sizes above (as on the listings; brands differ by half a size).
const SV_UK = [ '41' => '7', '42' => '8', '42.5' => '8', '43' => '8.5', '44' => '9', '44.5' => '10', '45.5' => '10.5' ];

function sv_attach_images( $pid, $slug, $name ) {
	$num   = fn( $f ) => preg_match( '#^' . preg_quote( $slug, '#' ) . '(?:-(\d+))?\.webp$#', basename( $f ), $m ) ? (int) ( $m[1] ?? 1 ) : 0;
	$files = array_values( array_filter( glob( "/wordpress/sv-images/$slug*.webp" ) ?: [], fn( $f ) => $num( $f ) > 0 ) );
	usort( $files, fn( $a, $b ) => $num( $a ) <=> $num( $b ) );
	$ids = [];
	foreach ( $files as $file ) {
		$base = basename( $file );
		$tmp  = wp_tempnam( $base );
		copy( $file, $tmp );
		$n   = $num( $file );
		$att = sv_sideload( $tmp, $base, $pid, $name . ( $n > 1 ? ' – photo ' . $n : '' ) );
		if ( ! is_wp_error( $att ) ) $ids[ $n ] = $att;
	}
	return $ids; // photo number => attachment id
}

function sv_global_attr( $slug, array $term_ids, $position ) {
	$a = new WC_Product_Attribute();
	$a->set_id( wc_attribute_taxonomy_id_by_name( $slug ) );
	$a->set_name( wc_attribute_taxonomy_name( $slug ) );
	$a->set_options( array_values( $term_ids ) );
	$a->set_position( $position );
	$a->set_visible( true );
	$a->set_variation( false );
	return $a;
}

$size_slug = fn( $eu ) => 'eu-' . str_replace( '.', '-', $eu );

foreach ( $products as $order => $d ) {
	$variable = ! empty( $d['pairs'] );
	$p = $variable ? new WC_Product_Variable() : new WC_Product_Simple();
	$p->set_name( $d['name'] );
	$p->set_slug( $d['slug'] );
	$p->set_status( 'publish' );
	$p->set_menu_order( $order );
	$p->set_description( $d['desc'] );
	$p->set_short_description( $d['desc'] );
	$p->set_category_ids( array_map( fn( $c ) => $cat[ $c ], $d['cats'] ) );
	$p->set_featured( true );
	$p->set_sold_individually( true ); // One pair of each.
	$p->update_meta_data( '_sv_retail', $d['retail'] );
	$p->update_meta_data( '_sv_condition', implode( "\n", array_map( fn( $k, $v ) => "$k: $v", array_keys( $d['cond'] ), $d['cond'] ) ) );

	$eus   = $variable ? array_values( $d['pairs'] ) : [ $d['size'] ];
	$attrs = [
		sv_global_attr( 'size', array_map( fn( $eu ) => $size_ids[ $size_slug( $eu ) ], $eus ), 1 ),
		sv_global_attr( 'grade', [ $grade_ids[ $d['grade'] ] ], 2 ),
	];
	if ( $variable ) {
		$a = new WC_Product_Attribute();
		$a->set_name( $d['attr'] );
		$labels = array_map( fn( $label, $eu ) => $label . ' – EU ' . $eu, array_keys( $d['pairs'] ), $d['pairs'] );
		$a->set_options( $labels );
		$a->set_position( 0 );
		$a->set_visible( true );
		$a->set_variation( true );
		array_unshift( $attrs, $a );
		$p->set_default_attributes( [ sanitize_title( $d['attr'] ) => $labels[0] ] );
	} else {
		$p->set_regular_price( $d['price'] );
		$p->set_manage_stock( true );
		$p->set_stock_quantity( empty( $d['sold'] ) ? 1 : 0 );
		$p->update_meta_data( '_sv_uk', $d['uk'] );
	}
	$p->set_attributes( $attrs );
	$pid = $p->save();

	if ( $d['brand'] && taxonomy_exists( 'product_brand' ) ) {
		wp_set_object_terms( $pid, $d['brand'], 'product_brand' );
	}

	$imgs = sv_attach_images( $pid, $d['slug'], $d['name'] );
	if ( $imgs ) {
		// Written as meta: a second full product save here slowed the import.
		set_post_thumbnail( $pid, $imgs[1] ?? reset( $imgs ) );
		update_post_meta( $pid, '_product_image_gallery', implode( ',', array_values( array_diff_key( $imgs, [ 1 => true ] ) ) ) );
	}

	if ( $variable ) {
		$key = sanitize_title( $d['attr'] );
		$i   = 0;
		foreach ( $d['pairs'] as $label => $eu ) {
			$v = new WC_Product_Variation();
			$v->set_parent_id( $pid );
			$v->set_attributes( [ $key => $label . ' – EU ' . $eu ] );
			$v->set_regular_price( $d['price'] );
			$v->set_manage_stock( true );
			$v->set_stock_quantity( empty( $d['sold'] ) ? 1 : 0 );
			$v->set_menu_order( $i++ );
			$v->update_meta_data( '_sv_uk', SV_UK[ $eu ] ?? '' );
			$v->save();
		}
		WC_Product_Variable::sync( $pid );
	}
}

// Pages.
function sv_page( $slug, $title, $content ) {
	$existing = get_page_by_path( $slug );
	if ( $existing ) return $existing->ID;
	return wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => $slug, 'post_title' => $title, 'post_content' => $content ] );
}
sv_page( 'wishlist', 'Your wishlist', "<!-- wp:shortcode -->\n[sv_wishlist]\n<!-- /wp:shortcode -->" );
$shop_page = (int) wc_get_page_id( 'shop' );
if ( $shop_page > 0 ) wp_update_post( [ 'ID' => $shop_page, 'post_title' => 'All shoes' ] );

update_option( 'permalink_structure', '/%postname%/' );
flush_rewrite_rules();

// Skip WooCommerce's first-run redirect and setup checklist so the admin opens on the store itself.
delete_transient( '_wc_activation_redirect' );
update_option( 'woocommerce_task_list_hidden_lists', [ 'setup', 'extended' ] );
update_option( 'woocommerce_task_list_complete', 'yes' );
update_option( 'woocommerce_show_marketplace_suggestions', 'no' );
update_option( 'woocommerce_admin_install_timestamp', time() - WEEK_IN_SECONDS );

$wpdb->query( 'COMMIT' );
wp_suspend_cache_invalidation( false );
wp_defer_term_counting( false );
