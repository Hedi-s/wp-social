<?php
// Service Filters
add_filter('social_response_body', array('Social_Service_Twitter', 'response_body'));
add_filter('get_comment_author_link', array('Social_Service_Twitter', 'get_comment_author_link'));

/**
 * Twitter implementation for the service.
 *
 * @package Social
 * @subpackage services
 */
final class Social_Service_Twitter extends Social_Service implements Social_Interface_Service {

	/**
	 * @var  string  service key
	 */
	protected $_key = 'twitter';

	/**
	 * The max length a post can be when broadcasted.
	 *
	 * @return int
	 */
	public function max_broadcast_length() {
		return 140;
	}

	/**
	 * Broadcasts the message to the specified account. Returns the broadcasted ID.
	 *
	 * @param  Social_Service_Account  $account  account to broadcast to
	 * @param  string  $message  message to broadcast
	 * @param  array   $args  extra arguments to pass to the request
	 * @return Social_Response
	 */
	public function broadcast($account, $message, array $args = array()) {
		$args = $args + array(
			'status' => $message
		);

		return $this->request($account, 'statuses/update', $args, 'POST');
	}

	/**
	 * Aggregates comments by URL.
	 *
	 * @param  object  $post
	 * @param  array   $urls
	 * @return void
	 */
	public function aggregate_by_url(&$post, array $urls) {
		$url = 'http://search.twitter.com/search.json?q='.implode('+OR+', $urls);
		Social::log('Searching by URL(s) for post #:post_id. (Query: :url)', array(
			'post_id' => $post->ID,
			'url' => $url
		));
		$request = wp_remote_get($url);
		if (!is_wp_error($request)) {
			$response = apply_filters('social_response_body', $request['body'], $this->_key);
			if (isset($response->results) and is_array($response->results) and count($response->results)) {
				foreach ($response->results as $result) {
					$data = array(
						'username' => $result->from_user,
					);

					if (in_array($result->id, $post->aggregated_ids[$this->_key])) {
						Social_Aggregation_Log::instance($post->ID)->add($this->_key, $result->id, 'url', true, $data);
						continue;
					}
					else if ($this->is_original_broadcast($post, $result->id)) {
						continue;
					}

					Social_Aggregation_Log::instance($post->ID)->add($this->_key, $result->id, 'url', false, $data);
					$post->aggregated_ids[$this->_key][] = $result->id;
					$post->results[$this->_key][$result->id] = $result;
				}
			}
		}
		else {
			Social::log('URL search failed for post #:post_id.', array(
				'post_id' => $post->ID
			));
		}
	}

	/**
	 * Aggregates comments by the service's API.
	 *
	 * @param  object  $post
	 * @return array
	 */
	public function aggregate_by_api(&$post) {
		$accounts = $this->get_aggregation_accounts($post);

		if (isset($accounts[$this->_key]) and count($accounts[$this->_key])) {
			foreach ($accounts[$this->_key] as $account) {
				if (isset($post->broadcasted_ids[$this->_key][$account->id()])) {
					// Retweets
					$response = $this->request($account, 'statuses/retweets/'.$post->broadcasted_ids[$this->_key][$account->id()]);
					if ($response->body() !== false and is_array($response->body()) and count($response->body())) {
						foreach ($response->body() as $result) {
							$data = array(
								'username' => $result->user->screen_name,
							);

							if (in_array($result->id, $post->aggregated_ids[$this->_key])) {
								Social_Aggregation_Log::instance($post->ID)->add($this->_key, $result->id, 'retweet', true, $data);
								continue;
							}
							else if ($this->is_original_broadcast($post, $result->id)) {
								continue;
							}

							Social_Aggregation_Log::instance($post->ID)->add($this->_key, $result->id, 'retweet', false, $data);
							$post->aggregated_ids[$this->_key][] = $result->id;
							$post->results[$this->_key][$result->id] = (object) array(
								'id' => $result->id,
								'from_user_id' => $result->user->id,
								'from_user' => $result->user->screen_name,
								'text' => $result->text,
								'created_at' => $result->created_at,
								'profile_image_url' => $result->user->profile_image_url,
							);
						}
					}

					// Mentions
					$response = $this->request($account, 'statuses/mentions', array(
						'since_id' => $post->broadcasted_ids[$this->_key][$account->id()],
						'count' => 200
					));
					if ($response->body() !== false and is_array($response->body()) and count($response->body())) {
						foreach ($response->body() as $result) {
							$data = array(
								'username' => $result->user->screen_name,
							);

							if (in_array($result->id, $post->aggregated_ids[$this->_key])) {
								Social_Aggregation_Log::instance($post->ID)->add($this->_key, $result->id, 'reply', true, $data);
								continue;
							}
							else if ($this->is_original_broadcast($post, $result->id)) {
								continue;
							}

							Social_Aggregation_Log::instance($post->ID)->add($this->_key, $result->id, 'reply', false, $data);
							$post->aggregated_ids[$this->_key][] = $result->id;
							$post->results[$this->_key][$result->id] = (object) array(
								'id' => $result->id,
								'from_user_id' => $result->user->id,
								'from_user' => $result->user->screen_name,
								'text' => $result->text,
								'created_at' => $result->created_at,
								'profile_image_url' => $result->user->profile_image_url,
							);
						}
					}
				}
			}
		}
	}

	/**
	 * Saves the aggregated comments.
	 *
	 * @param  object  $post
	 * @return void
	 */
	public function save_aggregated_comments(&$post) {
		if (isset($post->results[$this->_key])) {
			$in_reply_ids = array();
			foreach ($post->results[$this->_key] as $result) {
				$account = (object) array(
					'user' => (object) array(
						'id' => $result->from_user_id,
						'screen_name' => $result->from_user,
					),
				);
				$class = 'Social_Service_'.$this->_key.'_Account';
				$account = new $class($account);

				$commentdata = array(
					'comment_post_ID' => $post->ID,
					'comment_type' => 'social-'.$this->_key,
					'comment_author' => $account->username(),
					'comment_author_email' => $this->_key.'.'.$account->id().'@example.com',
					'comment_author_url' => $account->url(),
					'comment_content' => $result->text,
					'comment_date' => date('Y-m-d H:i:s', strtotime($result->created_at) + (get_option('gmt_offset') * 3600)),
					'comment_date_gmt' => gmdate('Y-m-d H:i:s', strtotime($result->created_at)),
					'comment_author_IP' => $_SERVER['SERVER_ADDR'],
					'comment_agent' => 'Social Aggregator',
				);

				$commentdata['comment_approved'] = wp_allow_comment($commentdata);
				$comment_id = wp_insert_comment($commentdata);

				update_comment_meta($comment_id, 'social_account_id', $result->from_user_id);
				update_comment_meta($comment_id, 'social_profile_image_url', $result->profile_image_url);
				update_comment_meta($comment_id, 'social_status_id', $result->id);

				if ($commentdata['comment_approved'] !== 'spam') {
					if ($commentdata['comment_approved'] == '0') {
						wp_notify_moderator($comment_id);
					}

					if (get_option('comments_notify') and $commentdata['comment_approved'] and (!isset($commentdata['user_id']) or $post->post_author != $commentdata['user_id'])) {
						wp_notify_postauthor($comment_id, isset($commentdata['comment_type']) ? $commentdata['comment_type'] : '');
					}
				}

				// Attempt to see if the comment is in response to an existing Tweet.
				if (isset($result->in_reply_to_status_id)) {
					if (!isset($in_reply_ids[$result->in_reply_to_status_id])) {
						$in_reply_ids[$result->in_reply_to_status_id] = array();
					}
					
					$in_reply_ids[$result->in_reply_to_status_id][] = $comment_id;
				}
			}

			if (count($in_reply_ids)) {
				global $wpdb;

				$wheres = array();
				foreach ($in_reply_ids as $ids) {
					$wheres[] = '`meta_value` = %s';
				}
				$results = $wpdb->get_results($wpdb->prepare("
					SELECT comment_id, meta_value
					  FROM $wpdb->commentmeta
					 WHERE meta_key = 'social_status_id'
					   AND ".implode(' OR ', $wheres), array_keys($in_reply_ids)));

				if (!empty($results)) {
					foreach ($results as $result) {
						$comment_ids = $in_reply_ids[$result->meta_value];
						$wheres = array();
						foreach ($comment_ids as $id) {
							$wheres[] = '`comment_ID` = %s';
						}
						$wpdb->query($wpdb->prepare("
							UPDATE $wpdb->comments
							   SET comment_parent = %s
							 WHERE ".implode(' OR ', $wheres), $result->comment_id, $comment_ids));
					}
				}
			}
		}
	}

	/**
	 * Hook to allow services to define their aggregation row items based on the passed in type.
	 *
	 * @param  string  $type
	 * @param  object  $item
	 * @param  string  $username
	 * @param  int     $id
	 * @return string
	 */
	public function aggregation_row($type, $item, $username, $id) {
		if ($type == 'retweet') {
			$link = $this->status_url($username, $id);
			return '<a href="'.$link.'" target="_blank">#'.$item->id.'</a> ('.__('Retweet Search', Social::$i18n).')';
		}
		return '';
	}

	/**
	 * Imports a Tweet by URL.
	 *
	 * @param  int     $post_id
	 * @param  string  $url
	 * @return void
	 */
	public function import_tweet_by_url($post_id, $url) {
		$post = get_post($post_id);

		$url = explode('/', $url);
		$id = end($url);

		$post_comments = get_post_meta($post->ID, '_social_aggregated_ids', true);
		if (empty($post_comments)) {
			$post_comments = array();
		}

		$url = 'http://api.twitter.com/1/statuses/show.json?id='.$id;
		$request = wp_remote_get($url);
		if (!is_wp_error($request)) {
			$logger = Social_Aggregation_Log::instance($post->ID);
			$response = apply_filters('social_response_body', $request['body'], $this->_key);

			if (in_array($id, $post_comments)) {
				$logger->add($this->_key, $response->id, 'Imported', true, array(
					'username' => $response->user->screen_name
				));
			}
			else {
				$logger->add($this->_key, $response->id, 'Imported', false, array(
					'username' => $response->user->screen_name
				));

				$post->aggregated_ids[$this->_key][] = $response->id;
				$post->results[$this->_key][$response->id] = (object) array(
					'id' => $response->id,
					'from_user_id' => $response->user->id,
					'from_user' => $response->user->screen_name,
					'text' => $response->text,
					'created_at' => $response->created_at,
					'profile_image_url' => $response->user->profile_image_url,
				);

				$this->save_aggregated_comments($post);

				// Some cleanup...
				unset($post->aggregated_ids);
				unset($post->results);
			}
			$logger->save(true);
		}
	}

	/**
	 * Checks the response to see if the broadcast limit has been reached.
	 *
	 * @param  string  $response
	 * @return bool
	 */
	public function limit_reached($response) {
		return false;
	}

	/**
	 * Checks the response to see if the broadcast is a duplicate.
	 *
	 * @param  string  $response
	 * @return bool
	 */
	public function duplicate_status($response) {
		if ($response == 'Status is duplicate.') {
			return true;
		}

		return false;
	}

	/**
	 * Checks the response to see if the account has been deauthorized.
	 *
	 * @param  string  $response
	 * @return bool
	 */
	public function deauthorized($response) {
		if ($response == 'Could not authenticate with OAuth.') {
			return true;
		}

		return false;
	}

	/**
	 * Returns the key to use on the request response to pull the ID.
	 *
	 * @return string
	 */
	public function response_id_key() {
		return 'id_str';
	}

	/**
	 * Returns the status URL to a broadcasted item.
	 *
	 * @param  string      $username
	 * @param  string|int  $id
	 * @return string
	 */
	public function status_url($username, $id) {
		return 'http://twitter.com/'.$username.'/status/'.$id;
	}

	/**
	 * Hack to fix the "Twitpocalypse" bug on 32-bit systems.
	 *
	 * @static
	 * @param  string  $body
	 * @return object
	 */
	public static function response_body($body) {
		$body = preg_replace('/"id":(\d+)/', '"id":"$1"', $body);
		$body = preg_replace('/"in_reply_to_status_id":(\d+)/', '"in_reply_to_status_id:"$1"', $body);
		return json_decode($body);
	}

	/**
	 * Adds the account ID to the rel for the author link.
	 *
	 * @static
	 * @param  string  $url
	 * @return string
	 */
	public static function get_comment_author_link($url) {
		global $comment;
		if ($comment->comment_type == 'twitter') {
			$status_id = get_comment_meta($comment->comment_ID, 'social_status_id', true);
			$output = str_replace("rel='", "rel='" . $status_id . " ", $url);

			$api_key = Social::option('twitter_anywhere_api_key');
			if (!empty($api_key)) {
				$output = str_replace("'>", "' style='display:none'>@", $output);
				$output .= '@' . get_comment_author($comment->comment_ID);
			}
			else {
				$output = str_replace("'>", "'>@", $output);
			}

			return $output;
		}

		return $url;
	}

} // End Social_Service_Twitter
