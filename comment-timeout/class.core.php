<?php

// For compatibility with WP 2.0

if (!function_exists('wp_die')) {
	function wp_die($msg) {
		die($msg);
	}
}


class jmct_Core
{
	private $settings;
	private $defaultSettings;

	/* ====== Constructor ====== */

	/**
	 * Initialises the plugin, setting up the actions and filters.
	 */

	function __construct()
	{
		add_filter('the_posts', array(&$this, 'process_posts'));
		add_action('admin_menu', array(&$this, 'add_config_page'));
		add_action('dbx_post_sidebar', array(&$this, 'post_sidebar'));
		add_action('dbx_page_sidebar', array(&$this, 'post_sidebar'));
		// Needs to be called before Akismet
		add_filter('preprocess_comment', array(&$this, 'preprocess_comment'), 0);
		add_action('save_post', array(&$this, 'save_post'));
		add_action('comment_form', array(&$this, 'comment_form'));
	}

	/* ====== get_settings ====== */

	/**
	 * Retrieves the settings from the WordPress settings database.
	 */

	function get_settings()
	{
		// Defaults for the settings

		$this->defaultSettings = array(
			// Number of days from posting before post is stale
			'PostAge' => 120,
			// Number of days from last comment before post is stale
			'CommentAge' => 60,
			// Number of days from last comment before popular post is stale
			'CommentAgePopular' => 365,
			// Definition of a popular post (number of approved comments)
			'PopularityThreshold' => 20,
			// Indicates whether to 'close' (default) or 'moderate' comments on old posts
			'Mode' => 'close',
			// Whether to treat pings 'together' with posts (true or default),
			// 'independent' of posts, or 'ignore' (false)
			'DoPings' => 'together',
			// Whether to apply these rules to pages, images and file uploads
			'DoPages' => FALSE,
			// Whether to allow overrides
			'AllowOverride' => true
		);

		if (!isset($this->settings)) {

			$this->settings = get_option('jammycakes_comment_locking');
			if (FALSE === $this->settings) {
				$this->settings = $this->defaultSettings;
				add_option('jammycakes_comment_locking', $this->settings);
			}
			else if (!isset($this->settings['UniqueID'])) {
				$this->settings = array_merge($this->defaultSettings, $this->settings);
				update_option('jammycakes_comment_locking', $this->settings);
			}
			else {
				$this->settings = array_merge($this->defaultSettings, $this->settings);
			}
			$this->sanitize_settings();
		}
		return $this->settings;
	}


	/* ====== save_settings ====== */

	/**
	 * Saves the settings
	 */

	function save_settings()
	{
		$this->get_settings();

		// Insert the new settings, with validation and type coercion

		foreach ($this->defaultSettings as $k=>$v) {
			$this->settings[$k] = $_POST[$k];
		}
		$this->sanitize_settings();
		update_option('jammycakes_comment_locking', $this->settings);
	}


	/* ====== sanitize_settings ====== */

	/**
	 * Makes sure settings are all in the correct format,
	 * also converts CT 1.0 versions to CT 2.0
	 */

	function sanitize_settings()
	{
		foreach (array_keys($this->settings) as $k) { // iterator safe
			$v = $this->settings[$k];
			switch ($k) {
				case 'PostAge':
				case 'CommentAge':
				case 'CommentAgePopular':
				case 'PopularityThreshold':
					$this->settings[$k] = (int) $v;
					break;
				case 'AllowOverride':
				case 'DoPages':
					$this->settings[$k] = (bool) $v;
					break;
				case 'DoPings':
					if ('ignore' !== $v && 'independent' !== $v && 'together' !== $v) {
						$this->settings[$k] = 'together';
					}
					break;
				case 'Mode':
					$v = (string) $v;
					if ($v != 'moderate') {
						$this->settings['Mode'] = 'close';
					}
					break;
				default:
					unset ($this->settings[$k]);
			}
		}
	}


	/* ====== process_posts ====== */

	/**
	 * Goes through the list of posts, checking each one to see if it should
	 * have comments closed.
	 */

	function process_posts($posts)
	{
		// Check that we have an array of posts

		if (!is_array($posts)) {
			// Is it a single post? If so, process it as an array of posts
			if (is_object($posts) && isset($posts->comment_status)) {
				$p = $this->process_posts(array($posts));
				return $p[0];
			}
			else {
				// Otherwise don't do anything
				return $posts;
			}
		}

		require_once(dirname(__FILE__) . '/class.post-processor.php');
		$processor = new jmct_PostProcessor($this, $posts);
		return $processor->process_posts();
	}


	/* ====== preprocess_comment filter ====== */

	/**
	 * Process a submitted comment. Die if it's not OK
	 */

	function preprocess_comment($comment)
	{
		require_once(dirname(__FILE__) . '/class.comment-processor.php');
		$processor = new jmct_CommentProcessor($this, $comment);
		return $processor->process_comment();
	}

	/* ====== save_post ====== */

	/**
	 * Called when a post or page is saved. Updates CT's per-post settings
	 * from the bit in the sidebar.
	 */

	function save_post($postID)
	{
		$this->get_settings();
		if ($this->settings['AllowOverride']) {
			switch(@$_POST['CommentTimeout']) {
				case 'ignore':
					$setting = 'ignore';
					break;
				case 'custom':
					$setting = (int)$_POST['ctPostAge'] . ',' . (int)$_POST['ctCommentAge'];
					break;
				case 'default':
				default:
					$setting = false;
					break;
			}

			if ($setting !== false) {
				if (!update_post_meta($postID, '_comment_timeout', $setting)) {
					add_post_meta($postID, '_comment_timeout', $setting);
				}
			}
			else {
				delete_post_meta($postID, '_comment_timeout');
			}
		}
	}

	/* ====== add_config_page ====== */

	/**
	 * Adds the configuration page to the submenu
	 */

	function add_config_page()
	{
		add_submenu_page('options-general.php', __('Comment Timeout'), __('Comment Timeout'), 'manage_options', 'comment-timeout', array(&$this, 'config_page'));
	}

	/* ====== config_page ====== */

	/**
	 * Loads in and renders the configuration page in the dashboard.
	 */

	function config_page()
	{
		if ('POST' == $_SERVER['REQUEST_METHOD']) {
			$this->save_settings();
			echo '<div id="comment-locking-saved" class="updated fade-ffff00"">';
			echo '<p><strong>';
			_e('Options saved.');
			echo '</strong></p></div>';
		}
		else {
			$this->get_settings();
		}
		require_once(dirname(__FILE__) . '/form.config.php');
	}

	/* ====== post_sidebar ====== */

	/**
	 * Adds an entry to the post's sidebar to allow us to set simple comment
	 * settings on a post-by-post basis.
	 */

	function post_sidebar()
	{
		$this->get_settings();
		if ($this->settings['AllowOverride']) {
			require_once(dirname(__FILE__) . '/form.post.php');
		}
	}


	/* ====== comment_form ====== */

	function comment_form()
	{
		global $post;
		$this->get_settings();
		if (isset ($post->cutoff_comments)) {
			$ct = $post->cutoff_comments - time();
			if ($ct < 0 && $this->settings['Mode'] == 'moderate') {
				echo '<p class="comment-timeout">Comments will be sent to the moderation queue.</p>';
			}
			elseif ($ct >= 0 && $this->settings['Mode'] == 'close') {
				$ct1 = $post->cutoff_comments + (get_option('gmt_offset') * 3600);
				echo '<p class="comment-timeout">Comments for this post will be closed ';
				if ($ct >= 604800) {
					echo 'on ' . date('j F Y', $ct1);
				}
				else if ($ct >= 172800) {
					echo 'in ' . (int) ($ct/86400) . ' days';
				}
				else if ($ct >= 7200) {
					echo 'in ' . (int) ($ct/3600) . ' hours';
				}
				else if ($ct >= 60) {
					echo 'in ' . (int) ($ct/60) . ' minutes';
				}
				else {
					echo 'within one minute.';
				}
				echo '.</p>';
			}
		}
	}
}

$myCommentTimeout = new jmct_Core();
