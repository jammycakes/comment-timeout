<?php

/*
Plugin Name: Comment Timeout
Plugin URI: http://www.jamesmckay.net/categories/wordpress/comment-timeout/
Description: Automatically closes comments on blog entries after a user-configurable period of time. It has options which allow you to keep the discussion open for longer on older posts which have had recent comments accepted, or to place a fixed limit on the total number of comments in the discussion. Activate the plugin and go to <a href="options-general.php?page=comment-timeout">Options &gt;&gt; Comment Timeout</a> to configure.
Version: 1.3-alpha 2
Author: James McKay
Author URI: http://www.jamesmckay.net/
*/

/* ========================================================================== */

/*
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.

 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 */


// For compatibility with WP 2.0

if (!function_exists('wp_die')) {
	function wp_die($msg) {
		die($msg);
	}
}

class jm_CommentTimeout
{
	var $maxPostAge;
	var $maxCommentAge;
	var $commentLimit;	// 0 or less means no limit
	var $doPings;
	var $doPages;

	/* ====== Constructor ====== */

	/**
	 * Creates a new instance of the Comment Timeout plugin and registers the relevant actions.
	 */

	function jm_CommentTimeout()
	{
		add_filter('the_posts', array(&$this, 'process_posts'));
		add_action('preprocess_comment', array(&$this, 'process_comment'), 0); // Run this before Akismet
		add_action('admin_menu', array(&$this, 'add_config_page'));
		add_action('comment_form', array(&$this, 'comment_form_bits'));
		$this->done = FALSE;
	}


	/* ====== check_bb2 ====== */

	/**
	 * Checks an IP address to see if it meets the Bad Behavior exclusion criteria
	 * @param $ip The IP address
	 * @returns TRUE if BB has blocked this IP address too many times, otherwise FALSE.
	 */

	function check_bb2($ip)
	{
		global $wpdb;
		if (!defined('BB2_VERSION')) {
			return FALSE;
		}
		else {
			$this->get_settings();
			if ($this->settings['BadBehavior'] > 0)
			{
				$sql = 'select count(*) from `' . $wpdb->prefix . 'bad_behavior` '
					. 'where `ip` = \'' . $wpdb->escape($ip) . '\'';
				if (!$this->settings['BadBehaviorStrict']) {
					$sql .= ' and `key` != \'00000000\'';
				}
				$sql .= ' and `date` > \'';
				$sql .= date('Y-m-d H:i:s', time() - 604800);
				$sql .= '\'';
				$count = $wpdb->get_var($sql);
				return ($count >= $this->settings['BadBehavior']);
			}
			else {
				return FALSE;
			}
		}
	}


	/* ====== check_spam_queue ====== */

	/**
	 * Checks an IP address to see if it meets the spam queue exclusion criteria
	 * @param $ip The IP address
	 * @returns TRUE if this is a spammy IP, otherwise FALSE.
	 */

	function check_spam_queue($ip)
	{
		global $wpdb;
		$this->get_settings();
		if ($this->settings['SpamQueue'] > 0) {
			$sql = "SELECT COUNT(*) FROM $wpdb->comments WHERE comment_approved='spam' AND comment_author_IP='"
				. $wpdb->escape($ip) . "'";
			$count = $wpdb->get_var($sql);
			return ($count >= $this->settings['SpamQueue']);
		}
		else {
			return FALSE;
		}
	}


	/* ====== check_ip ====== */

	/**
	 * Checks our IP address to see if we should close all comments
	 * @returns TRUE if this is a spammy IP, otherwise FALSE.
	 */

	function check_ip()
	{
		if (!isset($this->spammyIP)) {
			$ip = $_SERVER['REMOTE_ADDR'];
			$this->spammyIP = $this->check_bb2($ip) || $this->check_spam_queue($ip);
		}
		return $this->spammyIP;
	}


	/* ====== getCommentAges ====== */

	/**
	 * Gets an array of posts' last comment time, indexed by post ID
	 */

	function getCommentAges($min, $max)
	{
		global $wpdb, $wp_query;

		if (!isset($this->commentAges)) {

			// Get the lower and upper limits on post ID

			$sql = "select a.ID as ID, max(b.comment_date_gmt) as LastCommentTime " .
				"from $wpdb->posts a inner join $wpdb->comments b on a.ID = b.comment_post_id " .
				"where (a.comment_status = 'open' or a.ping_status = 'open') " .
				"and b.comment_approved='1' " .
				"and a.ID >= $min and a.ID <= $max " .
				"group by a.ID";

			$results = $wpdb->get_results($sql);

			$this->commentAges = array();
			if ($results) {
				foreach ($results as $result) {
					$this->commentAges[$result->ID] = $result->LastCommentTime;
				}
			}
		}
		return $this->commentAges;
	}


	/* ====== hash ====== */

	/**
	 * Hashes a string (IP address or user agent) for use in sanity checks
	 * @param $string The string to hash
	 * @returns The salted hash to be compared
	 */

	function hash($string)
	{
		$this->get_settings();
		return md5($this->settings['UniqueID'] . $string);
	}


	/* ====== comment_form_bits ====== */

	/**
	 * Inserts the hidden fields for the comment sanity check
	 */

	function comment_form_bits($postID)
	{
		$this->get_settings();
		if ($this->settings['UserAgentCheck']) {
			echo '<input type="hidden" name="ctUserAgent" value="'
				. $this->hash($_SERVER['HTTP_USER_AGENT']) . '" />';
		}
		if ($this->settings['IPAddressCheck']) {
			echo '<input type="hidden" name="ctIPAddress" value="'
				. $this->hash($_SERVER['REMOTE_ADDR']);
		}
	}


	/* ====== process_comment ====== */

	/**
	 * Processes a comment to make sure that none have slipped through the net
	 */

	function process_comment($comment)
	{
		$this->get_settings();

		// Check the IP address first of all

		if ($this->check_ip()) {
			wp_die(_e('Sorry, your comment could not be accepted at this time. Please try again later.'));
		}

		// reject BBCode - note we check for both opening and closing tags and they don't have to be on the same line

		if ($this->settings['RejectBBCode'] && preg_match('|\[url(\=.*?)?\].*\[/url\]|is', $comment['comment_content'])) {
			wp_die('Your comment was rejected because it included a BBCode hyperlink. This blog does not use BBCode.');
		}

		// reject comments and trackbacks with more than two hyperlinks

		if ($this->settings['RejectLinks'] > 0) {
			$matches = array();
			if (preg_match_all('|<a(\s+.*?)?>(.*?)</a>|i', $comment['comment_content'], &$matches)
				> $this->settings['RejectLinks']) {
				wp_die('Your comment was rejected because it contained too many hyperlinks. This blog limits comments to a maximum of ' .
					$this->settings['RejectLinks'] . ' hyperlinks.');
			}
		}

		if ($comment['comment_type'] == 'trackback' || $comment['comment_type'] == 'pingback') {
			if ($this->doPings) {
				return $this->canPostComment($comment);
			}
			else {
				return $comment;
			}
		}
		else {

			// Check the comment form bits that were inserted as a sanity check

			if ($this->settings['UserAgentCheck']) {
				if ($_POST['ctUserAgent'] != $this->hash($_SERVER['HTTP_USER_AGENT'])) {
					wp_die('Your comment was rejected as your browser\'s user agent string has changed since you viewed the post.');
				}
			}
			if ($this->settings['IPAddressCheck']) {
				if ($_POST['ctIPAddress'] != $this->hash($_SERVER['REMOTE_ADDR'])) {
					wp_die('Your comment was rejected as an anti-spam measure as your IP address has changed since you viewed the post. Note that this can happen if you are accessing the Internet through a proxy server, or if you disconnected from the Internet and re-connected.');
				}
			}

			return $this->canPostComment($comment);
		}
	}


	/* ====== canPostComment ====== */

	/**
	 * Double check before posting a comment to make sure that none slip through the net
	 */

	function canPostComment($comment)
	{
		global $wpdb;
		$this->get_settings();


		// Check for comments timing out

		$postID = $comment['comment_post_ID'];
		$sql = "select a.post_status, a.post_date_gmt, a.post_type, count(b.comment_id) as num_comments, max(b.comment_date_gmt) as last_comment " .
			"from $wpdb->posts a left join $wpdb->comments b on a.ID = b.comment_post_id " .
			"and b.comment_approved='1' where a.ID=$postID " .
			"group by a.post_status, a.post_date_gmt, a.post_type";
		$row = $wpdb->get_row($sql);
		$this->minID = $this->maxID = $postID;
		if ($this->check($postID, $row->post_status, $row->num_comments, $row->post_date_gmt, $row->post_type, $row->last_comment)) {
			wp_die(_e('Comments on this post are now closed.'));
		}

		return $comment;
	}



	/* ====== check ====== */

	/**
	 * This is where we do the grunt work that tests to see if we can post a comment
	 * @param $postID The post ID
	 * @param $postStatus The post status
	 * @param $commentCount The number of comments in the post so far
	 * @param $postDateGMT The date and time of the post
	 * @param $postType The type of post
	 * @param $commentAges The date and time of the last comment, or FALSE if we need to retrieve it from the database.
	 * @returns TRUE if we are to shut off comments, otherwise FALSE.
	 */

	function check($postID, $postStatus, $commentCount, $postDateGMT, $postType, $commentAges = FALSE)
	{
		$isPost = ($postStatus == 'publish' || $postStatus == 'private');

		// for WordPress 2.1 we need to examine postType

		if ($postType != '' && $postType != 'post') {
			$isPost = false;
		}

		// Don't close drafts
		if ($postStatus == 'draft') return FALSE;

		// Don't close non-post pages unless we're configured to do so
		if (!$isPost && !$this->doPages) return FALSE;

		// Close if the maximum number of comments has been reached
		if (($this->commentLimit) > 0 && ($commentCount >= $this->commentLimit)) {
			return TRUE;
		}

		// Get the age of the post in seconds
		$postAge = time() - strtotime($postDateGMT);

		// Return without closing comments if it's younger than the maximum age
		if ($postAge < $this->maxPostAge && $this->maxPostAge > 0) return FALSE;

		// Otherwise, if we are not considering comment ages, close it off
		if ($this->maxCommentAge <= 0) return TRUE;

		if ($commentAges === FALSE) {
			// Get the age of the last comment in seconds
			$commentAges = $this->getCommentAges($this->minID, $this->maxID);
		}
		// Close the post if there are no comments
		if (!isset($commentAges[$postID])) return TRUE;

		$commentAge = time() - strtotime($commentAges[$postID]);

		return ($commentAge > $this->maxCommentAge);
	}


	/* ====== shouldClosePost ====== */

	/**
	 * Determines whether this post should be closed or not.
	 */

	function shouldClosePost($post)
	{
		return $this->check($post->ID, $post->post_status, $post->comment_count, $post->post_date_gmt, $post->post_type);
	}


	/* ====== process_posts ====== */

	/**
	 * Overwrites the comment status for all the posts in the loop.
	 */

	function &process_posts(&$posts)
	{
		$this->get_settings();
		if ($this->check_ip()) {
			foreach ($posts as $k => $v) {
				$posts[$k]->comment_status = 'closed';
				$posts[$k]->ping_status = 'closed';
			}
			return $posts;
		}
		else {
			foreach ($posts as $p) {
				if (!isset($this->minID) || $this->minID > $p->ID) {
					$this->minID = $p->ID;
				}
				if (!isset($this->maxID) || $this->maxID < $p->ID) {
					$this->maxID = $p->ID;
				}
			}

			foreach ($posts as $k => $v) {
				if ($this->shouldClosePost($v)) {
					$posts[$k]->comment_status = 'closed';
					if ($this->doPings) {
						$posts[$k]->ping_status = 'closed';
					}
				}
			}
		}
		return $posts;
	}


	/* ====================================================================== */


	/* ====== addConfigPage ====== */

	/**
	 * Adds a configuration page to the options
	 */


	function add_config_page()
	{
		add_submenu_page('options-general.php', __('Comment Timeout'), __('Comment Timeout'), 'manage_options', 'comment-timeout', array(&$this, 'config_page'));
	}


	/* ====== get_settings ====== */

	function get_settings()
	{
		if (!isset($this->settings)) {
			$defaultSettings = array(
				'UniqueID' => md5(uniqid(rand(), true)),
				'PostAge' => 120,
				'CommentAge' => 60,
				'CommentLimit' => 0,
				'DoPages' => FALSE,
				'DoPings' => TRUE,
				'RejectBBCode' => TRUE,
				'RejectLinks' => 3,
				'UserAgentCheck' => TRUE,
				'IPAddressCheck' => FALSE,
				'BadBehavior' => 3,
				'BadBehaviorStrict' => FALSE,
				'SpamQueue' => 3
			);


			$this->settings = get_option('jammycakes_comment_locking');
			if (FALSE === $this->settings) {
				$this->settings =& $defaultSettings;
				add_option('jammycakes_comment_locking', $this->settings);
			}
			else if (!isset($this->settings['UniqueID'])) {
				$this->settings = array_merge($defaultSettings, $this->settings);
				update_option('jammycakes_comment_locking', $this->settings);
			}
			else {
				$this->settings = array_merge($defaultSettings, $this->settings);
			}
		}
		$this->maxPostAge = $this->settings['PostAge'] * 86400;
		$this->maxCommentAge = $this->settings['CommentAge'] * 86400;
		$this->commentLimit = $this->settings['CommentLimit'];
		$this->doPages = $this->settings['DoPages'];
		$this->doPings = $this->settings['DoPings'];
		return $this->settings;
	}


	/* ====== save_settings ====== */

	function save_settings()
	{
		$this->settings = array();
		$this->settings['PostAge'] = (isset($_POST['ctLimitByAge']) ? (int)$_POST['PostAge'] : 0);
		$this->settings['CommentAge'] = (isset($_POST['ctLimitByCommentAge']) ? (int)$_POST['CommentAge'] : 0);
		$this->settings['CommentLimit'] = (isset($_POST['ctCommentLimit']) ? (int)$_POST['CommentLimit'] : 0);
		$this->settings['DoPages'] = isset($_POST['DoPages']);
		$this->settings['DoPings'] = isset($_POST['DoPings']);
		$this->settings['RejectBBCode'] = isset($_POST['RejectBBCode']);
		$this->settings['RejectLinks'] = (isset($_POST['ctRejectLinks']) ? (int)$_POST['RejectLinks'] : 0);
		$this->settings['UserAgentCheck'] = isset($_POST['UserAgentCheck']);
		$this->settings['IPAddressCheck'] = isset($_POST['IPAddressCheck']);
		$this->settings['BadBehavior'] = (isset($_POST['ctBadBehavior']) ? (int)$_POST['BadBehavior'] : 0);
		$this->settings['BadBehaviorStrict'] = isset($_POST['BadBehaviorStrict']);
		$this->settings['SpamQueue'] = (isset($_POST['ctSpamQueue']) ? (int)$_POST['SpamQueue'] : 0);
		update_option('jammycakes_comment_locking', $this->settings);
		?>
			<div id='comment-locking-saved' class='updated fade-ffff00'><p><strong> <?php _e('Options saved.') ?></strong></p></div>
		<?php

	}


	function render_checkbox($checkbox_name, $setting_name = "")
	{
		if ($setting_name == '') $setting_name = $checkbox_name;
		echo '<input type="checkbox" ';
		echo $this->settings[$setting_name] ? 'checked="checked"' : '';
		echo " name=\"$checkbox_name\" />";
	}


	function render_textbox_int($textbox_name, $setting_name = "", $size=4)
	{
		if ($setting_name == '') $setting_name = $textbox_name;
		echo '<input type="textbox" value="';
		if ($this->settings[$setting_name] != '') {
			echo (int)$this->settings[$setting_name];
		}
		echo "\" name=\"$textbox_name\" size=\"$size\" />";
	}



	/* ====== config_page ====== */

	function config_page()
	{
		if ('POST' == $_SERVER['REQUEST_METHOD']) {
			$this->save_settings();
		}
		$settings = $this->get_settings();

		?>
		<div class="wrap">
			<h2><?php _e('Automatic comment locking') ?></h2>

			<form action="" method="post" id="comment-timeout-conf">
				<fieldset class="options">
					<legend>
						Disable comments when:
					</legend>
					<ul>
						<li>
							<label>
								<?php $this->render_checkbox('ctLimitByAge', 'PostAge'); ?>
								Post is more than
							</label>
							<label>
								<?php $this->render_textbox_int('PostAge'); ?>
								days old,
							</label>
							or
						</li>
						<li>
							<label>
								<?php $this->render_checkbox('ctLimitByCommentAge', 'CommentAge'); ?>
								has not had a comment for more than
							</label>
							<label>
								<?php $this->render_textbox_int('CommentAge'); ?>
								days,
							</label>
						</li>
						<li>whichever is the later.</li>
					</ul>
				</fieldset>


				<fieldset class="options">
					<legend>Limit number of comments:</legend>
					<ul>
						<li>
							<label>
								<?php $this->render_checkbox('ctCommentLimit', 'CommentLimit'); ?>
								Limit discussion to
							</label>
							<label>
								<?php $this->render_textbox_int('CommentLimit'); ?>
								comments.
							</label>
						</li>
					</ul>
				</fieldset>

				<fieldset class="options">
					<legend>
						Trackbacks, pingbacks etc
					</legend>
					<ul>
						<li>
							<label>
								<?php $this->render_checkbox('DoPings'); ?>
								Apply these settings to trackbacks and pingbacks.
							</label>
						</li>
						<li>
							<label>
								<?php $this->render_checkbox('DoPages'); ?>
								Apply these settings to pages and images as well as posts.
							</label>
						</li>
					</ul>
				</fieldset>

				<fieldset class="options">
					<legend>
						Additional lockdown options
					</legend>

					<p>
						These options temporarily close all comments and trackbacks on a
						per-IP address basis by querying your spam queue and, if you are using it,
						<a href="http://www.bad-behavior.ioerror.us/">Bad Behavior</a>.
					</p>

					<ul>
						<li>
							<label>
								<?php $this->render_checkbox('ctSpamQueue', 'SpamQueue'); ?>
								Close all comments to IP addresses that have
							</label>
							<label>
								<?php $this->render_textbox_int('SpamQueue'); ?>
								or more comments in the spam moderation queue
							</label>
						</li>
						<?php if (defined('BB2_VERSION')): ?>
						<li>
							<label>
								<?php $this->render_checkbox('ctBadBehavior', 'BadBehavior'); ?>
								Close all comments to IP addresses that have been blocked
							</label>
							<label>
								<?php $this->render_textbox_int('BadBehavior'); ?>
								or more times by Bad Behavior in the last seven days
							</label>
							<ul>
								<li>
									<label>
										<?php $this->render_checkbox('BadBehaviorStrict'); ?>
										Strict mode (may block comments from legitimate users)
									</label>
								</li>
							</ul>
						</li>
						<?php endif; ?>
					</ul>
				</fieldset>
				<fieldset class="options">
					<legend>
						Comment validation
					</legend>
					<ul>
						<li>
							<label>
								<?php $this->render_checkbox('RejectBBCode'); ?>
								Reject all comments that contain BBCode links
								(WordPress does not normally use BBCode so genuine comments
								are very unlikely to contain these, although they are common in spam comments)
							</label>
						</li>
						<li>
							<label>
								<?php $this->render_checkbox('ctRejectLinks', 'RejectLinks'); ?>
								Reject all comments that contain more than
							</label>
							<label>
								<?php $this->render_textbox_int('RejectLinks'); ?>
								hyperlinks
							</label>
						</li>
						<li>
							<label>
								<?php $this->render_checkbox('UserAgentCheck'); ?>
								Reject comments when the user agent differs from the original page request
							</label>
						</li>
						<li>
							<label>
								<?php $this->render_checkbox('IPAddressCheck'); ?>
								Reject comments when the IP address differs from the original page request
								(this will help prevent IP address spoofing by spambots, but may cause problems for AOL users)
							</label>
						</li>
					</ul>
				</fieldset>

				<p class="submit">
					<input type="submit" name="Submit" value="Update Options &raquo;" />
				</p>

				<p style="text-align:center">Comment Timeout version 1.3 alpha 1 - Copyright 2007 <a href="http://www.jamesmckay.net/">James McKay</a></p>
			</form>
		</div>
		<?php
	}
}

$myCommentTimeout = new jm_CommentTimeout();

?>