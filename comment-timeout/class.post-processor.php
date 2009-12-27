<?php

class jmct_PostProcessor
{
	private $core;
	private $posts;
	private $settings;

	public function __construct($core, $posts)
	{
		$this->core = $core;
		$this->posts = $posts;
		$this->settings = $this->core->get_settings();
	}

	/* ====== get_post_metainfo ====== */

	/**
	 * Gets comment, trackback and individual setting information for comments
	 * @param $first The numerical ID of the first post to examine
	 * @param $last The numerical ID of the last post to examine
	 * @param $what 'comments', 'pings' or something else
	 * @param $overrides true or false
	 * @returns An array of objects containing the results of the query
	 */

	private function get_post_metainfo($first, $last, $what, $overrides)
	{
		global $wpdb;
		$sql = 'select p.ID as ID, ' .
			($overrides ? 'pm.meta_value as comment_timeout, ' : '') .
			'count(c.comment_ID) as comments, max(c.comment_date_gmt) as last_comment ' .
			"from $wpdb->posts p " .
			"left join $wpdb->comments c on p.ID=c.comment_post_ID and c.comment_approved='1' ";
		switch ($what) {
			case 'comments':
				$sql .= 'and c.comment_type=\'\' ';
				break;
			case 'pings':
				$sql .= 'and c.comment_type<>\'\' ';
				break;
		}
		if ($overrides) {
			$sql .= "left join $wpdb->postmeta pm on p.ID=pm.post_id and pm.meta_key='_comment_timeout' ";
		}
		$sql .= 'where p.ID>=' . (int) $first . ' and p.ID<=' . (int) $last .
			' group by p.ID';
		if ($overrides) {
			$sql .= ', pm.meta_value';
		}

		$results = $wpdb->get_results($sql);

		// Set it up as an associative array indexed by ID
		$meta = array();
		foreach ($results as $r) {
			$meta[$r->ID] = $r;
		}

		return $meta;
	}


	public function process_posts()
	{

		$minID = $maxID = 0;
		foreach ($this->posts as $p) {
			if ($maxID < $p->ID) {
				$maxID = $p->ID;
			}
			if ($minID == 0 || $minID > $p->ID) {
				$minID = $p->ID;
			}
		}

		// Get the metainfo for the posts

		switch($this->settings['DoPings']) {
			case 'ignore':
			case false:	// for CT 1.x compatibility
				$commentmeta = $this->get_post_metainfo
					($minID, $maxID, 'comments', $this->settings['AllowOverride']);
				$pingmeta = null;
				break;
			case 'independent':
				$commentmeta = $this->get_post_metainfo
					($minID, $maxID, 'comments', $this->settings['AllowOverride']);
				$pingmeta = $this->get_post_metainfo
					($minID, $maxID, 'pings', $this->settings['AllowOverride']);
				break;
			case 'together':
			case true:
			default:
				$commentmeta = $this->get_post_metainfo
					($minID, $maxID, '', $this->settings['AllowOverride']);
				$pingmeta =& $commentmeta;
		}

		// Now calculate the date and time (UTC) of when to close the post

		// NB need to get the keys and values this way because PHP 4 gets funny
		// about references if you do foreach ($this->posts as $k => $p)

		foreach (array_keys($this->posts) as $k) {
			$p =& $this->posts[$k];
			$cm = $commentmeta[$p->ID];

			/*
			 * Preconditions: skip if either of the following are true:
			 * 1. Is a non-post and we are only checking posts
			 * 2. Is flagged for ignore and we are allowing overrides
			 */

			$isPost = ($p->post_status == 'publish' || $p->post_status == 'private')
				&& ($p->post_type == '' || $p->post_type == 'post');

			$proceed = ($isPost || $this->settings['DoPages']) &&
				(@$cm->comment_timeout != 'ignore' || !$this->settings['AllowOverride']);

			/*
			 * Per-post settings are stored in a post meta field called
			 * _comment_timeout. This can have one of three values:
			 * "ignore" means we don't close comments
			 * "default" (or nothing) means we use the default settings
			 * two integers separated by a comma means we use per-post settings
			 * - in this case the integers represent the days from the post and
			 *   the last comment respectively
			 */

			if ($proceed) {

				if (@preg_match('|^(\d+),(\d+)$|', $cm->comment_timeout, $matches)) {
					list($dummy, $postAge, $commentAge) = $matches;
					$commentAgePopular = $commentAge;
					$popularityThreshold = 0;
				}
				else {
					// These are the global settings
					$postAge = $this->settings['PostAge'];
					$commentAge = $this->settings['CommentAge'];
					$commentAgePopular = $this->settings['CommentAgePopular'];
					$popularityThreshold = $this->settings['PopularityThreshold'];
				}

				$cutoff = strtotime($p->post_date_gmt) + 86400 * $postAge;
				if ($cm->last_comment != '') {
					$cutoffComment = strtotime($cm->last_comment) + 86400 *
						($cm->comments >= $popularityThreshold
						? $commentAgePopular : $commentAge);
					if ($cutoffComment > $cutoff) $cutoff = $cutoffComment;
				}
				// Cutoff for comments
				$p->cutoff_comments = $cutoff;

				if (isset($pingmeta)) {
					$pm =& $pingmeta[$p->ID];
					$cutoff = strtotime($p->post_date_gmt) + 86400 * $postAge;
					if ($pm->last_comment != '') {
						$cutoffPing = strtotime($pm->last_comment) + 86400 *
							($pm->comments >= $popularityThreshold
							? $commentAgePopular : $commentAge);
						if ($cutoffPing > $cutoff) $cutoff = $cutoffPing;
					}
					// Cutoff for pings
					$p->cutoff_pings = $cutoff;
				}

				/*
				 * Now set the comment status. We only do this if we are
				 * closing comments -- if we are moderating instead, we need to
				 * leave the comment form open
				 */

				if ($this->settings['Mode'] != 'moderate') {
					$now = time();
					if (isset($p->cutoff_comments) && $now > $p->cutoff_comments) {
						$p->comment_status = 'closed';
					}
					if (isset($p->cutoff_pings) && $now > $p->cutoff_pings) {
						$p->ping_status = 'closed';
					}
				}
			} // Post processing ends here
		}
		return $this->posts;
	}
}