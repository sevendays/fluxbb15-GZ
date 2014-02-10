<?php

/** 
 * name: gz_ak_post_presave_hook executed before saving new posts to database.
 * @param
 * @return
 * 
 */

function gz_ak_post_presave_hook($username, $email, $topic_id, $forum_id, $subject, &$message)
{
	global $pun_user, $pun_config;
	// is the mod installed and configured?
	if(!isset($pun_config['o_gz_akismet'])) return;
	
	$gz_ak_cfg = json_decode($pun_config['o_gz_akismet'], true);
	
	// check that the user belongs to a group where akismet has to check
	if($pun_user['is_guest']) $group = PUN_GUEST;
	else $group = $pun_user['g_id'];
	$groups_to_check = explode(',', $gz_ak_cfg['monitored_groups']);
	if(!in_array($group, $groups_to_check)) return;
	
	// check that at least one trigger is true
	$trigger1 = (pun_strlen($message) > $gz_ak_cfg['post_minchars'])?true:false;
	$trigger2 = ($gz_ak_cfg['link_presence'] > 0 && stristr($message, 'http') !== false)?true:false;
	if(!$trigger1 && !$trigger2) return;
	
	// OK: we may now fire up Akismet.
	if(!class_exists('Akismet'))
		require_once PUN_ROOT.'include/Akismet.class.php';
	
	$ak = new Akismet(get_base_url(true), $gz_ak_cfg['api_key']);
	$ak->setCommentAuthor($username);
	$ak->setCommentAuthorEmail($email);
	//$ak->setCommentContent($message); // we're sending the original $_POST content. The $message is the filtered and censored one.
	$ak->setCommentContent($_POST['req_message']);
	$ak->setCommentType('forum-post'); // http://blog.akismet.com/2012/06/19/pro-tip-tell-us-your-comment_type/
	$ak->setCommentUserAgent($_SERVER['HTTP_USER_AGENT']);
	// try to fill in some more info
	if(isset($pun_user['url'])) $ak->setCommentAuthorURL($pun_user['url']);
	if($ak->isCommentSpam())
	{
		//ZAPPED! Put the message in the review queue and ban the user.
		$user_id = ($pun_user['is_guest'])? 1 : $pun_user['id'];
		gz_ak_queue_message($username, $user_id, $email, $message, $subject, $topic_id, $forum_id, $hide_smilies, true);
		gz_ak_ban_user($username, $email);
		gz_ak_byebye();
	}
	// Ok, this message is not spam.
	return;
}

function gz_ak_queue_message($username, $user_id, $email, &$message, $subject, $topic_id, $forum_id, $insert_user_agent = false)
{
	global $db;
	$username = ($username != '') ? '\''.$db->escape($username).'\'' : 'NULL';
	$last_ip = get_remote_address();
	$email = ($email != '') ? '\''.$db->escape($email).'\'' : 'NULL';
	$subject = ($subject != '') ? '\''.$db->escape($subject).'\'' : 'NULL';
	
	// Replace four-byte characters (MySQL cannot handle them)
	$message = strip_bad_multibyte_chars($message);
	
	$message = ($message != '') ? '\''.$db->escape($message).'\'' : 'NULL';
	$posted = time();
	$hide_smilies = isset($_POST['hide_smilies']) ? '1' : '0';
	$user_agent = ($_SERVER['HTTP_USER_AGENT'] != '' && $insert_user_agent) ? '\''.$db->escape($_SERVER['HTTP_USER_AGENT']).'\'' : 'NULL';
	
	// insert: may be a reply to existing topic, or a new topic
	$q ="INSERT INTO ".$db->prefix."gz_akismet_queue (poster, poster_id, poster_ip, poster_email, subject, message, hide_smilies, posted, topic_id, forum_id, user_agent) VALUES ($username,'$user_id','$last_ip',$email,$subject,$message,'$hide_smilies','$posted',NULL,'$forum_id',$user_agent)"; 
	
	if($topic_id)
		$q = "INSERT INTO ".$db->prefix."gz_akismet_queue (poster, poster_id, poster_ip, poster_email, subject, message, hide_smilies, posted, topic_id, forum_id, user_agent) VALUES ($username,'$user_id','$last_ip',$email,$subject,$message,'$hide_smilies','$posted','$topic_id','$forum_id',$user_agent)";
	
	$db->query($q) or error('Unable to insert into Akismet spam queue', __FILE__, __LINE__, $db->error());
}

function gz_ak_ban_user($username, $email)
{
	global $db, $gz_ak_lang;
	$username = $db->escape($username);
	$ip = get_remote_address();
	$email = ($email != '') ? '\''.$db->escape($email).'\'' : 'NULL';
	$message = $db->escape($gz_ak_lang['akismet zapped you']);
	
	$db->query("INSERT INTO ".$db->prefix."bans(username,ip,email,message,expire,ban_creator) VALUES('$username','$ip',$email,'$message',NULL,'2')") or error('Unable to ban spammer', __FILE__, __LINE__, $db->error());
	
	// Regenerate the bans cache
	require_once PUN_ROOT.'include/cache.php';
	generate_bans_cache();
}

function gz_ak_byebye()
{
	global $gz_ak_lang;
	message($gz_ak_lang['akismet zapped you'], true);
}

/** 
 * name: gz_ak_spams
 * @param ids array containing the spam ids
 * @return number of spams deleted
 * 
 */
function gz_ak_spams($ids)
{
	if(empty($ids)) return 0;
	
	global $db;
	
	// retrieve the user ids, let's have the database to filter spam ids too
	$userids = array();
	$realspamids = array();
	$idsquery = implode(',', $ids);
	$result = $db->query('SELECT id, poster_id FROM '.$db->prefix.'gz_akismet_queue WHERE id IN ('.$idsquery.')') or error('Unable to fetch userid from spam queue', __FILE__, __LINE__, $db->error());
	if($db->num_rows($result) == 0) return 0;
	while($row = $db->fetch_row($result))
	{
		$realspamids[] = $row[0];
		$userids[] = $row[1];
	}
	
	// clean user infos, disable email sending
	$db->query('UPDATE '.$db->prefix.'users SET title=NULL, realname=NULL, url=NULL, jabber=NULL, icq=NULL, msn=NULL, aim=NULL, yahoo=NULL, location=NULL, signature=NULL, email_setting=2 WHERE id IN ('.implode(',', $userids).')') or error('Unable to clean spammers\' userinfos', __FILE__, __LINE__, $db->error());
	
	// Delete user avatars
	foreach ($userids as $user_id)
		delete_avatar($user_id);
	
	// delete spams from the queue
	$db->query('DELETE FROM '.$db->prefix.'gz_akismet_queue WHERE id IN ('.implode(',', $realspamids).')') or error('Unable to delete spam from spam queue', __FILE__, __LINE__, $db->error());
	
	return count($realspamids);
}

function gz_ak_hams($ids, &$errors)
{
	if(empty($ids)) return 0;
	
	global $db, $gz_ak_lang, $gz_ak_cfg, $pun_config;
	
	// retrieve all, let's have the database filter out ids
	// since hams are not so frequent, it's acceptable to read all data in a single pass
	$hams = array();
	$userids = array();
	$result = $db->query('SELECT * FROM '.$db->prefix.'gz_akismet_queue WHERE id IN ('.implode(',', $ids).')') or error('Unable to fetch userid from spam queue', __FILE__, __LINE__, $db->error());
	if($db->num_rows($result) == 0) return 0;
	while($ham = $db->fetch_assoc($result))
	{
		$usernames[] = $ham['poster'];
		$hams[] = $ham; // used to restore posts and teach akismet something
	}
	
	if(empty($hams)) return 0;
	
	//unban users
	$db->query('DELETE FROM '.$db->prefix.'bans WHERE username IN(\''.implode('\',\'', $usernames).'\')') or error('Unable to delete ban', __FILE__, __LINE__, $db->error());
	// Regenerate the bans cache
	if (!defined('FORUM_CACHE_FUNCTIONS_LOADED'))
		require_once PUN_ROOT.'include/cache.php';
	generate_bans_cache();
	
	// restore posts
	foreach($hams as $ham)
	{
		$tid = (is_null($ham['topic_id'])) ? 0 : $ham['topic_id'];
		// We saved the forum id in posts.php before the hook, choosing to rely only on $tid presence/absence.
		$fid = $ham['forum_id'];
			
		// if it's a reply check that the topic still exists
		if($tid)
		{
			$result = $db->query('SELECT id FROM '.$db->prefix.'topics WHERE id='.$tid) or error('Unable to check topic existence', __FILE__, __LINE__, $db->error());
			if (!$db->num_rows($result))
			{
				// oh good, the topic disappeared in the meantime. Send to oblivion.
				$errors[] = $gz_ak_lang['topic deleted'];
				continue;
			}
			unset($result);
		}
		
		// check that the forum still exists
		$result = $db->query('SELECT forum_name FROM '.$db->prefix.'forums WHERE id='.$fid) or error('Unable to check forum existence', __FILE__, __LINE__, $db->error());
		if (!$db->num_rows($result))
		{
			// oh good, the forum disappeared in the meantime. Send to oblivion.
			$errors[] = $gz_ak_lang['forum deleted'];
			continue;
		}
		
		// regen all other variables used in post.php
		$cur_posting = $db->fetch_assoc($result);
		$cur_posting['id'] = $fid;
		// The subject was saved too, before the hook.
		$cur_posting['subject'] = $ham['subject'];
		
		// things lacking: $stick_topic, $subject, $subscribe, $is_subscribed, $pun_user
		$is_guest = ($ham['poster_id'] == PUN_GUEST) ? true : false;
		$stick_topic = 0;
		$subscribe = 0;
		$subject = $ham['subject'];
		$is_subscribed = false; // send the email to the zapped user, at least he'll know he's been unbanned
		$email = $ham['poster_email'];
		$username = $ham['poster'];
		$message = $ham['message'];
		$hide_smilies = $ham['hide_smilies'];
		// time() is used instead of the original timestamp $ham['posted'] because it would break notifications to topic subscribers.
		// the problem is that posts are "ORDER BY id" in viewtopic.php, while they should be ordered by date.
		// so this maybe isn't the "real" last post. But since viewtopic.php orders by id, this will be the last post.
		$now = time();

		$hide_smilies = isset($_POST['hide_smilies']) ? '1' : '0';
		$subscribe = isset($_POST['subscribe']) ? '1' : '0';
		$stick_topic = isset($_POST['stick_topic']) && $is_admmod ? '1' : '0';
		
		// update search idx
		require_once PUN_ROOT.'include/search_idx.php';
		
		/*** BEGIN C&P FROM post.php, with some modifications in queries and guest detection ***/
		// If it's a reply
		if ($tid)
		{
			//if (!$pun_user['is_guest'])
			if(!($is_guest))
			{
				$new_tid = $tid;

				// Insert the new post
				//$db->query('INSERT INTO '.$db->prefix.'posts (poster, poster_id, poster_ip, message, hide_smilies, posted, topic_id) VALUES(\''.$db->escape($username).'\', '.$pun_user['id'].', \''.get_remote_address().'\', \''.$db->escape($message).'\', '.$hide_smilies.', '.$now.', '.$tid.')') or error('Unable to create post', __FILE__, __LINE__, $db->error());
				$db->query('INSERT INTO '.$db->prefix.'posts (poster, poster_id, poster_ip, message, hide_smilies, posted, topic_id) VALUES(\''.$db->escape($username).'\', '.$ham['poster_id'].', \''.$ham['poster_ip'].'\', \''.$db->escape($message).'\', '.$hide_smilies.', '.$now.', '.$tid.')') or error('Unable to create post', __FILE__, __LINE__, $db->error());
				$new_pid = $db->insert_id();

				// To subscribe or not to subscribe, that ...
				if ($pun_config['o_topic_subscriptions'] == '1')
				{
					if ($subscribe && !$is_subscribed)
						//$db->query('INSERT INTO '.$db->prefix.'topic_subscriptions (user_id, topic_id) VALUES('.$pun_user['id'].' ,'.$tid.')') or error('Unable to add subscription', __FILE__, __LINE__, $db->error());
						$db->query('INSERT INTO '.$db->prefix.'topic_subscriptions (user_id, topic_id) VALUES('.$ham['poster_id'].' ,'.$tid.')') or error('Unable to add subscription', __FILE__, __LINE__, $db->error());
					else if (!$subscribe && $is_subscribed)
						//$db->query('DELETE FROM '.$db->prefix.'topic_subscriptions WHERE user_id='.$pun_user['id'].' AND topic_id='.$tid) or error('Unable to remove subscription', __FILE__, __LINE__, $db->error());
						$db->query('DELETE FROM '.$db->prefix.'topic_subscriptions WHERE user_id='.$ham['poster_id'].' AND topic_id='.$tid) or error('Unable to remove subscription', __FILE__, __LINE__, $db->error());
				}
			}
			else
			{
				// It's a guest. Insert the new post
				$email_sql = ($pun_config['p_force_guest_email'] == '1' || $email != '') ? '\''.$db->escape($email).'\'' : 'NULL';
				//$db->query('INSERT INTO '.$db->prefix.'posts (poster, poster_ip, poster_email, message, hide_smilies, posted, topic_id) VALUES(\''.$db->escape($username).'\', \''.get_remote_address().'\', '.$email_sql.', \''.$db->escape($message).'\', '.$hide_smilies.', '.$now.', '.$tid.')') or error('Unable to create post', __FILE__, __LINE__, $db->error());
				$db->query('INSERT INTO '.$db->prefix.'posts (poster, poster_ip, poster_email, message, hide_smilies, posted, topic_id) VALUES(\''.$db->escape($username).'\', \''.$ham['poster_ip'].'\', '.$email_sql.', \''.$db->escape($message).'\', '.$hide_smilies.', '.$now.', '.$tid.')') or error('Unable to create post', __FILE__, __LINE__, $db->error());
				$new_pid = $db->insert_id();
			}

			// Update topic
			//$db->query('UPDATE '.$db->prefix.'topics SET num_replies=num_replies+1, last_post='.$now.', last_post_id='.$new_pid.', last_poster=\''.$db->escape($username).'\' WHERE id='.$tid) or error('Unable to update topic', __FILE__, __LINE__, $db->error());
			$db->query('UPDATE '.$db->prefix.'topics SET num_replies=num_replies+1, last_post='.$now.', last_post_id='.$new_pid.', last_poster=\''.$db->escape($username).'\' WHERE id='.$tid) or error('Unable to update topic', __FILE__, __LINE__, $db->error());

			//update_search_index('post', $new_pid, $message);
			update_search_index('post', $new_pid, $ham['message']);

			update_forum($cur_posting['id']);

			// Should we send out notifications?
			if ($pun_config['o_topic_subscriptions'] == '1')
			{
				// Get the post time for the previous post in this topic
				$result = $db->query('SELECT posted FROM '.$db->prefix.'posts WHERE topic_id='.$tid.' ORDER BY id DESC LIMIT 1, 1') or error('Unable to fetch post info', __FILE__, __LINE__, $db->error());
				$previous_post_time = $db->result($result);

				// Get any subscribed users that should be notified (banned users are excluded)
				//$result = $db->query('SELECT u.id, u.email, u.notify_with_post, u.language FROM '.$db->prefix.'users AS u INNER JOIN '.$db->prefix.'topic_subscriptions AS s ON u.id=s.user_id LEFT JOIN '.$db->prefix.'forum_perms AS fp ON (fp.forum_id='.$cur_posting['id'].' AND fp.group_id=u.group_id) LEFT JOIN '.$db->prefix.'online AS o ON u.id=o.user_id LEFT JOIN '.$db->prefix.'bans AS b ON u.username=b.username WHERE b.username IS NULL AND COALESCE(o.logged, u.last_visit)>'.$previous_post_time.' AND (fp.read_forum IS NULL OR fp.read_forum=1) AND s.topic_id='.$tid.' AND u.id!='.$pun_user['id']) or error('Unable to fetch subscription info', __FILE__, __LINE__, $db->error());
				$result = $db->query('SELECT u.id, u.email, u.notify_with_post, u.language FROM '.$db->prefix.'users AS u INNER JOIN '.$db->prefix.'topic_subscriptions AS s ON u.id=s.user_id LEFT JOIN '.$db->prefix.'forum_perms AS fp ON (fp.forum_id='.$cur_posting['id'].' AND fp.group_id=u.group_id) LEFT JOIN '.$db->prefix.'online AS o ON u.id=o.user_id LEFT JOIN '.$db->prefix.'bans AS b ON u.username=b.username WHERE b.username IS NULL AND COALESCE(o.logged, u.last_visit)>'.$previous_post_time.' AND (fp.read_forum IS NULL OR fp.read_forum=1) AND s.topic_id='.$tid.' AND u.id!='.$ham['poster_id']) or error('Unable to fetch subscription info', __FILE__, __LINE__, $db->error());
				if ($db->num_rows($result))
				{
					require_once PUN_ROOT.'include/email.php';

					$notification_emails = array();

					if ($pun_config['o_censoring'] == '1')
						$cleaned_message = bbcode2email($censored_message, -1);
					else
						$cleaned_message = bbcode2email($message, -1);

					// Loop through subscribed users and send emails
					while ($cur_subscriber = $db->fetch_assoc($result))
					{
						// Is the subscription email for $cur_subscriber['language'] cached or not?
						if (!isset($notification_emails[$cur_subscriber['language']]))
						{
							if (file_exists(PUN_ROOT.'lang/'.$cur_subscriber['language'].'/mail_templates/new_reply.tpl'))
							{
								// Load the "new reply" template
								$mail_tpl = trim(file_get_contents(PUN_ROOT.'lang/'.$cur_subscriber['language'].'/mail_templates/new_reply.tpl'));

								// Load the "new reply full" template (with post included)
								$mail_tpl_full = trim(file_get_contents(PUN_ROOT.'lang/'.$cur_subscriber['language'].'/mail_templates/new_reply_full.tpl'));

								// The first row contains the subject (it also starts with "Subject:")
								$first_crlf = strpos($mail_tpl, "\n");
								$mail_subject = trim(substr($mail_tpl, 8, $first_crlf-8));
								$mail_message = trim(substr($mail_tpl, $first_crlf));

								$first_crlf = strpos($mail_tpl_full, "\n");
								$mail_subject_full = trim(substr($mail_tpl_full, 8, $first_crlf-8));
								$mail_message_full = trim(substr($mail_tpl_full, $first_crlf));

								$mail_subject = str_replace('<topic_subject>', $cur_posting['subject'], $mail_subject);
								$mail_message = str_replace('<topic_subject>', $cur_posting['subject'], $mail_message);
								$mail_message = str_replace('<replier>', $username, $mail_message);
								$mail_message = str_replace('<post_url>', get_base_url().'/viewtopic.php?pid='.$new_pid.'#p'.$new_pid, $mail_message);
								$mail_message = str_replace('<unsubscribe_url>', get_base_url().'/misc.php?action=unsubscribe&tid='.$tid, $mail_message);
								$mail_message = str_replace('<board_mailer>', $pun_config['o_board_title'], $mail_message);

								$mail_subject_full = str_replace('<topic_subject>', $cur_posting['subject'], $mail_subject_full);
								$mail_message_full = str_replace('<topic_subject>', $cur_posting['subject'], $mail_message_full);
								$mail_message_full = str_replace('<replier>', $username, $mail_message_full);
								$mail_message_full = str_replace('<message>', $cleaned_message, $mail_message_full);
								$mail_message_full = str_replace('<post_url>', get_base_url().'/viewtopic.php?pid='.$new_pid.'#p'.$new_pid, $mail_message_full);
								$mail_message_full = str_replace('<unsubscribe_url>', get_base_url().'/misc.php?action=unsubscribe&tid='.$tid, $mail_message_full);
								$mail_message_full = str_replace('<board_mailer>', $pun_config['o_board_title'], $mail_message_full);

								$notification_emails[$cur_subscriber['language']][0] = $mail_subject;
								$notification_emails[$cur_subscriber['language']][1] = $mail_message;
								$notification_emails[$cur_subscriber['language']][2] = $mail_subject_full;
								$notification_emails[$cur_subscriber['language']][3] = $mail_message_full;

								$mail_subject = $mail_message = $mail_subject_full = $mail_message_full = null;
							}
						}

						// We have to double check here because the templates could be missing
						if (isset($notification_emails[$cur_subscriber['language']]))
						{
							if ($cur_subscriber['notify_with_post'] == '0')
								pun_mail($cur_subscriber['email'], $notification_emails[$cur_subscriber['language']][0], $notification_emails[$cur_subscriber['language']][1]);
							else
								pun_mail($cur_subscriber['email'], $notification_emails[$cur_subscriber['language']][2], $notification_emails[$cur_subscriber['language']][3]);
						}
					}

					unset($cleaned_message);
				}
			}
		}
		// If it's a new topic
		else if ($fid)
		{
			// Create the topic
			//$db->query('INSERT INTO '.$db->prefix.'topics (poster, subject, posted, last_post, last_poster, sticky, forum_id) VALUES(\''.$db->escape($username).'\', \''.$db->escape($subject).'\', '.$now.', '.$now.', \''.$db->escape($username).'\', '.$stick_topic.', '.$fid.')') or error('Unable to create topic', __FILE__, __LINE__, $db->error());
			$db->query('INSERT INTO '.$db->prefix.'topics (poster, subject, posted, last_post, last_poster, sticky, forum_id) VALUES(\''.$db->escape($username).'\', \''.$db->escape($subject).'\', '.$now.', '.$now.', \''.$db->escape($username).'\', '.$stick_topic.', '.$fid.')') or error('Unable to create topic', __FILE__, __LINE__, $db->error());
			$new_tid = $db->insert_id();

			//if (!$pun_user['is_guest'])
			if(!($is_guest))
			{
				// To subscribe or not to subscribe, that ...
				if ($pun_config['o_topic_subscriptions'] == '1' && $subscribe)
					//$db->query('INSERT INTO '.$db->prefix.'topic_subscriptions (user_id, topic_id) VALUES('.$pun_user['id'].' ,'.$new_tid.')') or error('Unable to add subscription', __FILE__, __LINE__, $db->error());
					$db->query('INSERT INTO '.$db->prefix.'topic_subscriptions (user_id, topic_id) VALUES('.$ham['poster_id'].' ,'.$new_tid.')') or error('Unable to add subscription', __FILE__, __LINE__, $db->error());

				// Create the post ("topic post")
				//$db->query('INSERT INTO '.$db->prefix.'posts (poster, poster_id, poster_ip, message, hide_smilies, posted, topic_id) VALUES(\''.$db->escape($username).'\', '.$pun_user['id'].', \''.get_remote_address().'\', \''.$db->escape($message).'\', '.$hide_smilies.', '.$now.', '.$new_tid.')') or error('Unable to create post', __FILE__, __LINE__, $db->error());
				$db->query('INSERT INTO '.$db->prefix.'posts (poster, poster_id, poster_ip, message, hide_smilies, posted, topic_id) VALUES(\''.$db->escape($username).'\', '.$ham['poster_id'].', \''.$ham['poster_ip'].'\', \''.$db->escape($message).'\', '.$hide_smilies.', '.$now.', '.$new_tid.')') or error('Unable to create post', __FILE__, __LINE__, $db->error());
			}
			else
			{
				// Create the post ("topic post")
				$email_sql = ($pun_config['p_force_guest_email'] == '1' || $email != '') ? '\''.$db->escape($email).'\'' : 'NULL';
				//$db->query('INSERT INTO '.$db->prefix.'posts (poster, poster_ip, poster_email, message, hide_smilies, posted, topic_id) VALUES(\''.$db->escape($username).'\', \''.get_remote_address().'\', '.$email_sql.', \''.$db->escape($message).'\', '.$hide_smilies.', '.$now.', '.$new_tid.')') or error('Unable to create post', __FILE__, __LINE__, $db->error());
				$db->query('INSERT INTO '.$db->prefix.'posts (poster, poster_ip, poster_email, message, hide_smilies, posted, topic_id) VALUES(\''.$db->escape($username).'\', \''.$ham['poster_ip'].'\', '.$email_sql.', \''.$db->escape($message).'\', '.$hide_smilies.', '.$now.', '.$new_tid.')') or error('Unable to create post', __FILE__, __LINE__, $db->error());
			}
			$new_pid = $db->insert_id();

			// Update the topic with last_post_id
			$db->query('UPDATE '.$db->prefix.'topics SET last_post_id='.$new_pid.', first_post_id='.$new_pid.' WHERE id='.$new_tid) or error('Unable to update topic', __FILE__, __LINE__, $db->error());

			update_search_index('post', $new_pid, $message, $subject);

			update_forum($fid);

			// Should we send out notifications?
			if ($pun_config['o_forum_subscriptions'] == '1')
			{
				// Get any subscribed users that should be notified (banned users are excluded)
				//$result = $db->query('SELECT u.id, u.email, u.notify_with_post, u.language FROM '.$db->prefix.'users AS u INNER JOIN '.$db->prefix.'forum_subscriptions AS s ON u.id=s.user_id LEFT JOIN '.$db->prefix.'forum_perms AS fp ON (fp.forum_id='.$cur_posting['id'].' AND fp.group_id=u.group_id) LEFT JOIN '.$db->prefix.'bans AS b ON u.username=b.username WHERE b.username IS NULL AND (fp.read_forum IS NULL OR fp.read_forum=1) AND s.forum_id='.$cur_posting['id'].' AND u.id!='.$pun_user['id']) or error('Unable to fetch subscription info', __FILE__, __LINE__, $db->error());
				$result = $db->query('SELECT u.id, u.email, u.notify_with_post, u.language FROM '.$db->prefix.'users AS u INNER JOIN '.$db->prefix.'forum_subscriptions AS s ON u.id=s.user_id LEFT JOIN '.$db->prefix.'forum_perms AS fp ON (fp.forum_id='.$cur_posting['id'].' AND fp.group_id=u.group_id) LEFT JOIN '.$db->prefix.'bans AS b ON u.username=b.username WHERE b.username IS NULL AND (fp.read_forum IS NULL OR fp.read_forum=1) AND s.forum_id='.$cur_posting['id'].' AND u.id!='.$ham['poster_id']) or error('Unable to fetch subscription info', __FILE__, __LINE__, $db->error());
				if ($db->num_rows($result))
				{
					require_once PUN_ROOT.'include/email.php';

					$notification_emails = array();

					if ($pun_config['o_censoring'] == '1')
						$cleaned_message = bbcode2email($censored_message, -1);
					else
						$cleaned_message = bbcode2email($message, -1);

					// Loop through subscribed users and send emails
					while ($cur_subscriber = $db->fetch_assoc($result))
					{
						// Is the subscription email for $cur_subscriber['language'] cached or not?
						if (!isset($notification_emails[$cur_subscriber['language']]))
						{
							if (file_exists(PUN_ROOT.'lang/'.$cur_subscriber['language'].'/mail_templates/new_topic.tpl'))
							{
								// Load the "new topic" template
								$mail_tpl = trim(file_get_contents(PUN_ROOT.'lang/'.$cur_subscriber['language'].'/mail_templates/new_topic.tpl'));

								// Load the "new topic full" template (with post included)
								$mail_tpl_full = trim(file_get_contents(PUN_ROOT.'lang/'.$cur_subscriber['language'].'/mail_templates/new_topic_full.tpl'));

								// The first row contains the subject (it also starts with "Subject:")
								$first_crlf = strpos($mail_tpl, "\n");
								$mail_subject = trim(substr($mail_tpl, 8, $first_crlf-8));
								$mail_message = trim(substr($mail_tpl, $first_crlf));

								$first_crlf = strpos($mail_tpl_full, "\n");
								$mail_subject_full = trim(substr($mail_tpl_full, 8, $first_crlf-8));
								$mail_message_full = trim(substr($mail_tpl_full, $first_crlf));

								$mail_subject = str_replace('<forum_name>', $cur_posting['forum_name'], $mail_subject);
								$mail_message = str_replace('<topic_subject>', $pun_config['o_censoring'] == '1' ? $censored_subject : $subject, $mail_message);
								$mail_message = str_replace('<forum_name>', $cur_posting['forum_name'], $mail_message);
								$mail_message = str_replace('<poster>', $username, $mail_message);
								$mail_message = str_replace('<topic_url>', get_base_url().'/viewtopic.php?id='.$new_tid, $mail_message);
								$mail_message = str_replace('<unsubscribe_url>', get_base_url().'/misc.php?action=unsubscribe&fid='.$cur_posting['id'], $mail_message);
								$mail_message = str_replace('<board_mailer>', $pun_config['o_board_title'], $mail_message);

								$mail_subject_full = str_replace('<forum_name>', $cur_posting['forum_name'], $mail_subject_full);
								$mail_message_full = str_replace('<topic_subject>', $pun_config['o_censoring'] == '1' ? $censored_subject : $subject, $mail_message_full);
								$mail_message_full = str_replace('<forum_name>', $cur_posting['forum_name'], $mail_message_full);
								$mail_message_full = str_replace('<poster>', $username, $mail_message_full);
								$mail_message_full = str_replace('<message>', $cleaned_message, $mail_message_full);
								$mail_message_full = str_replace('<topic_url>', get_base_url().'/viewtopic.php?id='.$new_tid, $mail_message_full);
								$mail_message_full = str_replace('<unsubscribe_url>', get_base_url().'/misc.php?action=unsubscribe&fid='.$cur_posting['id'], $mail_message_full);
								$mail_message_full = str_replace('<board_mailer>', $pun_config['o_board_title'], $mail_message_full);

								$notification_emails[$cur_subscriber['language']][0] = $mail_subject;
								$notification_emails[$cur_subscriber['language']][1] = $mail_message;
								$notification_emails[$cur_subscriber['language']][2] = $mail_subject_full;
								$notification_emails[$cur_subscriber['language']][3] = $mail_message_full;

								$mail_subject = $mail_message = $mail_subject_full = $mail_message_full = null;
							}
						}

						// We have to double check here because the templates could be missing
						if (isset($notification_emails[$cur_subscriber['language']]))
						{
							if ($cur_subscriber['notify_with_post'] == '0')
								pun_mail($cur_subscriber['email'], $notification_emails[$cur_subscriber['language']][0], $notification_emails[$cur_subscriber['language']][1]);
							else
								pun_mail($cur_subscriber['email'], $notification_emails[$cur_subscriber['language']][2], $notification_emails[$cur_subscriber['language']][3]);
						}
					}

					unset($cleaned_message);
				}
			}
		}

		/*** END C&P FROM post.php ***/
	}
	
	// signal hams to akismet
	if(!class_exists('Akismet'))
		require_once PUN_ROOT.'include/Akismet.class.php';
	$ak = new Akismet(get_base_url(true), $gz_ak_cfg['api_key']);
	
	foreach($hams as $ham)
	{
		$ak->setCommentAuthor($ham['poster']);
		$ak->setCommentAuthorEmail($ham['poster_email']);
		$ak->setCommentContent($ham['message']);
		$ak->setCommentType('forum-post'); // http://blog.akismet.com/2012/06/19/pro-tip-tell-us-your-comment_type/
		$ak->setCommentUserAgent($ham['user_agent']);
		$ak->setUserIP($ham['poster_ip']);
		$ak->submitHam();
	}
	
	// delete hams from the spam queue
	$db->query('DELETE FROM '.$db->prefix.'gz_akismet_queue WHERE id IN ('.implode(',', $ids).')') or error('Unable to delete hams from queue', __FILE__, __LINE__, $db->error());
	
	// return the amount of hams restored
	return count($hams);
}

/** 
 * name: gz_ak_autodelete_spams
 * @param
 * @return number of spams deleted
 * 
 */
function gz_ak_autodelete_spams()
{
	global $pun_config;
	if(!isset($pun_config['o_gz_akismet'])) return 0;
	$gz_ak_cfg = json_decode($pun_config['o_gz_akismet'], true);
	
	if(!isset($gz_ak_cfg['spam_queue_days']) || $gz_ak_cfg['spam_queue_days'] != 0) return 0;
	
	global $db;
	// gather old spam
	$start_time = mktime(0,0,0) - ($gz_ak_cfg['spam_queue_days'] + 1) * 86400; // 1d = 86400s
	$result = $db->query('SELECT id FROM '.$db->prefix.'gz_akismet_queue WHERE posted < \''.$start_time.'\'') or error('Unable to select older spams from spam table', __FILE__, __LINE__, $db->error());
	if($db->num_rows($result) == 0) return 0;
	$oldspams = array();
	while ($row = $db->fetch_row($result))
		$oldspams[] = $row[0];
	// delete them
	return gz_ak_spams($oldspams);
}

// return additional post action "mark as spam"
function gz_ak_viewtopic_preview_hook(&$actions, $poster_group_id, $post_id)
{
	// determine if the user is a trusted user and if the poster is a monitored user
	global $pun_config, $pun_user, $db;
	$gz_ak_cfg = array();
	if(!isset($pun_config['o_gz_akismet'])) return;
	$gz_ak_cfg = json_decode($pun_config['o_gz_akismet'], true);
	
	if(!isset($gz_ak_cfg['monitored_groups']) || !isset($gz_ak_cfg['trusted_groups'])) return;
	
	$monitored_groups = explode(',', $gz_ak_cfg['monitored_groups']);
	$trusted_groups = explode(',', $gz_ak_cfg['trusted_groups']);
	
	$is_trusted = (in_array($pun_user['g_id'], $trusted_groups)) ? true : false;
	if(!$is_trusted) return;
	
	$is_monitored = (in_array($poster_group_id, $monitored_groups)) ? true : false;
	if(!$is_monitored) return;
	
	// offer the option to "mark as spam".
	$actions[] = '<li class="postreport"><span><a href="gamezoo_akismet_spam.php?id='.$post_id.'">'.'This is SPAM!'.'</a></span></li>';
}
