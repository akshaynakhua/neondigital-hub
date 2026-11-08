<?php
/** Any address the app does not own: send people to the landing page. */
defined( 'ABSPATH' ) || exit;
wp_safe_redirect( home_url( '/' ) );
exit;
