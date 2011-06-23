<?php

// For compatibility with WP 2.0

if (!function_exists('wp_die')) {
	function wp_die($msg) {
		die($msg);
	}
}


class jmct_Core
{
	/* ====== init ====== */
	
	/**
	 * Initialises the plugin, setting up the required hooks.
	 */
	
	private static $instance;

	public static function get_instance()
	{
		return jmct_Core::$instance;
	}


	public static function init()
	{
		/*
		 * Remove the default WordPress comment closing functionality
		 * since it interferes with ours.
		 */
		remove_filter('the_posts',     '_close_comments_for_old_posts');
		remove_filter('comments_open', '_close_comments_for_old_post', 10, 2);
		remove_filter('pings_open',    '_close_comments_for_old_post', 10, 2);

		$core = new jmct_Core();
		add_filter('the_posts', array(&$core, 'process_posts'));
		add_action('admin_menu', array(&$core, 'init_admin'));
		// Needs to be called before Akismet
		add_filter('preprocess_comment', array(&$core, 'preprocess_comment'), 0);
		add_action('comment_form', array(&$core, 'comment_form'));
		jmct_Core::$instance =& $core;
	}


	private $settings;
	private $defaultSettings;

	/* ====== Constructor ====== */

	/**
	 * Initialises the plugin, setting up the actions and filters.
	 */

	private function __construct()
	{
	}

	/* ====== get_settings ====== */

	/**
	 * Retrieves the settings from the WordPress settings database.
	 * This method may be called more than once.
	 * @return The settings.
	 */

	public function get_settings()
	{
		if (isset($this->settings)) return $this->settings;

		/*
		 * Get the WordPress native default comment closing settings.
		 */
		$this->wp_active = (bool)get_option('close_comments_for_old_posts');
		$this->wp_timeout = (int)get_option('close_comments_days_old');

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
			'DoPages' => false,
			// Whether to allow overrides
			'AllowOverride' => true,
			// Whether to display when comments timeout as 'absolute' (default), time remaining 'relative' or 'off'
			'DisplayTimeout' => 'date'
		);

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
		return $this->settings;
	}


	/* ====== save_settings ====== */

	/**
	 * Saves the settings back to WordPress, without pre-processing.
	 */

	private function save_settings()
	{
		update_option('jammycakes_comment_locking', $this->settings);
		update_option('close_comments_for_old_posts', $this->wp_active);
		update_option('close_comments_days_old', $this->wp_timeout);
	}


	/* ====== save_settings_from_postback ====== */

	/**
	 * Updates the settings from the admin page postback, and saves them.
	 */

	public function save_settings_from_postback()
	{
		$this->get_settings();

		// Insert the new settings, with validation and type coercion

		foreach ($this->defaultSettings as $k=>$v) {
			$this->settings[$k] = $_POST[$k];
		}
		$this->sanitize_settings();
		$this->save_settings();
		return $this->settings;
	}


	/* ====== sanitize_settings ====== */

	/**
	 * Makes sure settings are all in the correct format,
	 * also converts CT 1.0 versions to CT 2.0
	 */

	private function sanitize_settings()
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
				case 'DisplayTimeout':
					$v = (string) $v;
					if ($v !== 'absolute' && $v !== 'relative' && $v !== 'off') {
						$this->settings['DisplayTimeout'] = 'absolute';
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

	public function process_posts($posts)
	{
		if (is_admin()) {
			return $posts;
		}
		
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

	public function preprocess_comment($comment)
	{
		require_once(dirname(__FILE__) . '/class.comment-processor.php');
		$processor = new jmct_CommentProcessor($this, $comment);
		return $processor->process_comment();
	}


	/* ====== init_admin ====== */

	/**
	 * Adds the configuration page to the admin submenu; also initialises the other admin-related hooks.
	 */

	function init_admin()
	{
		require_once(dirname(__FILE__) . '/class.admin.php');
		$adm = new jmct_Admin($this);
		$adm->init();
	}


	public function render_template_tag($relative, $dateformat = false, $before = '', $after = '', $moderated = '')
	{
		global $post;
		$timeout = get_comment_timeout();
		if ($timeout === false) {
			return;
		}
		$ct = $post->cutoff_comments - time();
		if ($ct < 0 && $this->settings['Mode'] == 'moderate') {
			echo $moderated;
		}
		elseif ($ct >= 0 && $this->settings['Mode'] == 'close') {
			echo $before;
			if ($relative) {
				if ($ct >= 63072000) {
					echo (int)($ct/31536000) . _(' years');
				}
				elseif($ct >= 5184000) {
					echo (int)($ct/2592000) . _(' months');
				}
				elseif($ct >= 1209600) {
					echo (int)($ct/604800) . _(' weeks');
				}
				elseif ($ct >= 172800) {
					echo (int) ($ct/86400) . _(' days');
				}
				else if ($ct >= 7200) {
					echo (int) ($ct/3600) . _(' hours');
				}
				else if ($ct >= 60) {
					echo (int) ($ct/60) . _(' minutes');
				}
				else {
					echo _('within one minute');
				}
			}
			else {
				$format = $dateformat === false ? get_option('date_format') : $dateformat;
				echo date($format, get_comment_timeout() + (get_option('gmt_offset') * 3600));
			}
			echo $after;
		}
	}

	private function render_comment_timeout($relative)
	{
		$this->render_template_tag(
			$relative,
			false,
			'<p class="comment-timeout">'
				. _('Comments will be closed ' . ($relative ? 'in ' : 'on ')),
			'.</p>',
			'<p class="comment-timeout">'
				. _('Comments will be sent to the moderation queue.')
				. '</p>'
		);
	}


	/* ====== comment_form ====== */

	public function comment_form()
	{
		$settings = $this->get_settings();
		switch($settings['DisplayTimeout'])  {
			case 'absolute':
				$this->render_comment_timeout(false);
				break;
			case 'relative':
				$this->render_comment_timeout(true);
				break;
		}
	}
}

jmct_Core::init();


/* ====== get_comment_timeout ====== */

/**
 * @returns
 *     the date and time that the comments for the current post will be cutoff,
 *     or false if they will remain open indefinitely. Date is returned as a Unix
 *     timestamp.
 */

function get_comment_timeout()
{
	global $post;
	if (isset ($post->cutoff_comments)) {
		return $post->cutoff_comments;
	}
	else {
		return false;
	}
}


/* ====== the_comment_timeout ====== */

/**
 * Displays the date and time that the comments for the current post will be cutoff.
 * @param $relative
 *     true to display the time in relative format, otherwise false.
 * @param $dateformat
 *     The PHP date() format used to print the (absolute) date. If not set,
 *     use the WordPress default. Ignored if $relative is true.
 * @param $before
 *     The HTML to render before the date (absolute or relative).
 * @param $after
 *     The HTML to render after the date (absolute or relative).
 * @param $moderated
 *     The HTML to render if late comments are being sent to the moderation queue.
 */

function the_comment_timeout($relative, $dateformat = false, $before = '', $after = '', $moderated = '')
{
	jmct_Core::get_instance()->render_template_tag($relative, $dateformat, $before, $after, $moderated);
}