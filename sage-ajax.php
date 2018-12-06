<?php
declare(strict_types=1);

/*
Plugin Name:  Sage Ajax
Author:       Triggerfish
Author URI:   https://www.triggerfish.se/
License:      MIT License
*/

add_action('rest_api_init', ['Triggerfish\Ajax\Ajax', 'registerRESTRoute']);
