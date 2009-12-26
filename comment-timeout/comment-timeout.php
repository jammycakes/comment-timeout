<?php
/*
Plugin Name: Comment Timeout
Plugin URI: http://www.jamesmckay.net/code/comment-timeout/
Description: Automatically closes comments on blog entries after a user-configurable period of time. It has options which allow you to keep the discussion open for longer on older posts which have had recent comments accepted, or to place a fixed limit on the total number of comments in the discussion. Activate the plugin and go to <a href="options-general.php?page=comment-timeout">Options &gt;&gt; Comment Timeout</a> to configure.
Version: 2.1.0-dev
Author: James McKay
Author URI: http://www.jamesmckay.net/
*/

define('COMMENT_TIMEOUT_VERSION', '2.1.0-dev');

if ((int)phpversion() < 5) {
	add_action('admin_notices', create_function('', 'echo \'<div class="error"><p>Comment Timeout no longer supports PHP 4. Please upgrade your server to PHP 5.2 or later.</p></div>\';'));
}
else {
	require_once(dirname(__FILE__) . '/class.core.php');
}