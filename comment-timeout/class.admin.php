<?php

class jmct_Admin
{
	private $core;
	private $settings;

	public function __construct($core)
	{
		$this->core = $core;
	}

	public function init()
	{
		$this->settings = $this->core->get_settings();
		add_submenu_page('options-general.php', __('Comment Timeout'), __('Comment Timeout'), 'manage_options', 'comment-timeout', array(&$this, 'config_page'));
		if ($this->settings['AllowOverride']) {
			add_action('dbx_post_sidebar', array(&$this, 'post_sidebar'));
			add_action('dbx_page_sidebar', array(&$this, 'post_sidebar'));
			add_action('save_post', array(&$this, 'save_post'));
		}
	}


	/* ====== config_page ====== */

	/**
	 * Loads in and renders the configuration page in the dashboard.
	 */

	public function config_page()
	{
		if ('POST' == $_SERVER['REQUEST_METHOD']) {
			$this->core->save_settings();
			echo '<div id="comment-locking-saved" class="updated fade-ffff00"">';
			echo '<p><strong>';
			_e('Options saved.');
			echo '</strong></p></div>';
		}
		require_once(dirname(__FILE__) . '/form.config.php');
	}


	/* ====== save_post ====== */

	/**
	 * Called when a post or page is saved. Updates CT's per-post settings
	 * from the bit in the sidebar.
	 */

	public function save_post($postID)
	{
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


	/* ====== post_sidebar ====== */

	/**
	 * Adds an entry to the post's sidebar to allow us to set simple comment
	 * settings on a post-by-post basis.
	 */

	public function post_sidebar()
	{
		require_once(dirname(__FILE__) . '/form.post.php');
	}
}