<?php
/*
 * Uninstall cleanup for Restricted Access.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $site_id ) {
		switch_to_blog( $site_id );
		delete_option( 'restricted_access_login_page_id' );
		restore_current_blog();
	}
} else {
	delete_option( 'restricted_access_login_page_id' );
}
