<?php
declare(strict_types=1);

/*
Plugin Name:  REST Ajax
Author:       Triggerfish
Author URI:   https://www.triggerfish.se/
License:      MIT License
*/

add_action('rest_api_init', ['Triggerfish\REST_Ajax\Controller', 'registerRESTRoute']);
