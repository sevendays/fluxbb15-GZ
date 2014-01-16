<?php

/**
 * Copyright (C) 2012 GameZoo
 * based on code by Rickard Andersson copyright (C) 2002-2008 PunBB
 * License: http://www.gnu.org/licenses/gpl.html GPL version 2 or higher
 */

define('PUN_ROOT', dirname(__FILE__).'/');
require PUN_ROOT.'include/common.php';

if ($pun_user['g_read_board'] == '0')
	message($lang_common['No view'], false, '403 Forbidden');

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id < 1)
	message($lang_common['Bad request'], false, '404 Not Found');
	
// Get config options
$gz_ak_cfg = array();
if(isset($pun_config['o_gz_akismet']))
	$gz_ak_cfg = array_merge($gz_ak_cfg, json_decode($pun_config['o_gz_akismet'], true));
if(empty($gz_ak_cfg) || !isset($gz_ak_cfg['trusted_groups']) || !isset($gz_ak_cfg['monitored_groups']))
	message($lang_common['Bad request'], false, '404 Not Found');
	
// determine if we are a trusted user
$trusted_groups = explode(',', $gz_ak_cfg['trusted_groups']);
$is_trusted = (in_array($pun_user['g_id'], $trusted_groups)) ? true : false;
// Do we have permission to mark this post as spam?
if (!$is_trusted)
	message($lang_common['No permission'], false, '403 Forbidden');

// Fetch some info about the post, the topic and the forum
$result = $db->query('SELECT f.id AS fid, f.forum_name, f.moderators, f.redirect_url, fp.post_replies, fp.post_topics, t.id AS tid, t.subject, t.first_post_id, t.closed, p.posted, p.poster, p.poster_id, p.poster_ip, p.message, p.hide_smilies FROM '.$db->prefix.'posts AS p INNER JOIN '.$db->prefix.'topics AS t ON t.id=p.topic_id INNER JOIN '.$db->prefix.'forums AS f ON f.id=t.forum_id LEFT JOIN '.$db->prefix.'forum_perms AS fp ON (fp.forum_id=f.id AND fp.group_id='.$pun_user['g_id'].') WHERE (fp.read_forum IS NULL OR fp.read_forum=1) AND p.id='.$id) or error('Unable to fetch spam post info', __FILE__, __LINE__, $db->error());
if (!$db->num_rows($result))
	message($lang_common['Bad request'], false, '404 Not Found');
$cur_post = $db->fetch_assoc($result);
// Fetch just one more info about the post author - whether he is monitored or not
$result = $db->query('SELECT group_id FROM '.$db->prefix.'users WHERE id = '.$cur_post['poster_id']) or error('Unable to fetch spam post author info', __FILE__, __LINE__, $db->error());
if (!$db->num_rows($result))
	message($lang_common['Bad request'], false, '404 Not Found');
$cur_post = array_merge($cur_post, $db->fetch_assoc($result));

// Determine if the poster is a monitored user
$monitored_groups = explode(',', $gz_ak_cfg['monitored_groups']);
$is_monitored = (in_array($cur_post['group_id'], $monitored_groups)) ? true : false;
// Do we have permission to mark this post as spam?
if (!$is_monitored)
	message($lang_common['No permission'], false, '403 Forbidden');

$is_topic_post = ($id == $cur_post['first_post_id']) ? true : false;

// Load the gamezoo akismet language file
require PUN_ROOT.'lang/'.$pun_user['language'].'/gamezoo_akismet_lang.php';


// has the confirmation been sent or not?
if (isset($_POST['mark_as_spam']))
{
	confirm_referrer('gamezoo_akismet_spam.php');

	require PUN_ROOT.'include/search_idx.php';
	
	// include our functions
	require_once PUN_ROOT.'include/gamezoo_akismet.php';
	
	// determine the email. It's not saved in the post if the user is not a guest.
	$email = NULL;
	if($cur_post['poster_id'] == PUN_GUEST)
	{
		// read from post
		$result = $db->query('SELECT poster_email FROM '.$db->prefix.'posts WHERE id='.$id) or error('Unable to fetch spam guest email', __FILE__, __LINE__, $db->error());
		if (!$db->num_rows($result))
			message($lang_common['Bad request'], false, '404 Not Found');
		$row = $db->fetch_assoc($result);
		$email = $row['poster_email'];
	}
	else
	{
		// read from users
		$result = $db->query('SELECT email FROM '.$db->prefix.'users WHERE id='.$cur_post['poster_id']) or error('Unable to fetch spam user email', __FILE__, __LINE__, $db->error());
		if (!$db->num_rows($result))
			message($lang_common['Bad request'], false, '404 Not Found');
		$row = $db->fetch_assoc($result);
		$email = $row['email'];
	}
	
	// signal spam to akismet
	if(!class_exists('Akismet'))
		require PUN_ROOT.'include/Akismet.class.php';
	$ak = new Akismet(get_base_url(true), $gz_ak_cfg['api_key']);
	$ak->setCommentAuthor($cur_post['poster']);
	$ak->setCommentAuthorEmail($email);
	$ak->setCommentContent($cur_post['message']);
	$ak->setCommentType('forum-post'); // http://blog.akismet.com/2012/06/19/pro-tip-tell-us-your-comment_type/
	//$ak->setCommentUserAgent($cur_post['user_agent']); // no way to have it saved
	$ak->setUserIP($cur_post['poster_ip']);
	$ak->submitSpam();
	
	// ban the poster
	gz_ak_ban_user($cur_post['poster'], $email);
	
	if ($is_topic_post)
	{
		// move the first post to the spam queue
		gz_ak_queue_message($cur_post['poster'], $cur_post['poster_id'], $email, $cur_post['message'], $cur_post['subject'], NULL, $cur_post['fid']);
		// Delete the topic and all of its posts
		delete_topic($cur_post['tid']);
		update_forum($cur_post['fid']);

		redirect('viewforum.php?id='.$cur_post['fid'], $gz_ak_lang['Topic del redirect']);
	}
	else
	{
		// move the post to the spam queue
		gz_ak_queue_message($cur_post['poster'], $cur_post['poster_id'], $email, $cur_post['message'], $cur_post['subject'],$cur_post['tid'], $cur_post['fid']);
		// Delete just this one post
		delete_post($id, $cur_post['tid']);
		update_forum($cur_post['fid']);

		// Redirect towards the previous post
		$result = $db->query('SELECT id FROM '.$db->prefix.'posts WHERE topic_id='.$cur_post['tid'].' AND id < '.$id.' ORDER BY id DESC LIMIT 1') or error('Unable to fetch post info', __FILE__, __LINE__, $db->error());
		$post_id = $db->result($result);

		redirect('viewtopic.php?pid='.$post_id.'#p'.$post_id, $gz_ak_lang['Post del redirect']);
	}
}

// print out the form page asking for confirmation
$page_title = array(pun_htmlspecialchars($pun_config['o_board_title']), $gz_ak_lang['mark as spam']);
define ('PUN_ACTIVE_PAGE', 'index');
require PUN_ROOT.'header.php';

require PUN_ROOT.'include/parser.php';
$cur_post['message'] = parse_message($cur_post['message'], $cur_post['hide_smilies']);

?>
<div class="linkst">
	<div class="inbox">
		<ul class="crumbs">
			<li><a href="index.php"><?php echo $lang_common['Index'] ?></a></li>
			<li><span>»&#160;</span><a href="viewforum.php?id=<?php echo $cur_post['fid'] ?>"><?php echo pun_htmlspecialchars($cur_post['forum_name']) ?></a></li>
			<li><span>»&#160;</span><a href="viewtopic.php?pid=<?php echo $id ?>#p<?php echo $id ?>"><?php echo pun_htmlspecialchars($cur_post['subject']) ?></a></li>
			<li><span>»&#160;</span><strong><?php echo $gz_ak_lang['mark as spam'] ?></strong></li>
		</ul>
	</div>
</div>

<div class="blockform">
	<h2><span><?php echo $gz_ak_lang['mark as spam'] ?></span></h2>
	<div class="box">
		<form method="post" action="gamezoo_akismet_spam.php?id=<?php echo $id ?>">
			<div class="inform">
				<div class="forminfo">
					<h3><span><?php printf($is_topic_post ? $gz_ak_lang['Topic by'] : $gz_ak_lang['Reply by'], '<strong>'.pun_htmlspecialchars($cur_post['poster']).'</strong>', format_time($cur_post['posted'])) ?></span></h3>
					<p><?php echo ($is_topic_post) ? '<strong>'.$gz_ak_lang['topic warning'].'</strong>' : '<strong>'.$gz_ak_lang['warning'].'</strong>' ?><br /><?php echo $gz_ak_lang['mark as spam info'] ?></p>
				</div>
			</div>
			<p class="buttons"><input type="submit" name="mark_as_spam" value="<?php echo $gz_ak_lang['mark as spam confirm'] ?>" /> <a href="javascript:history.go(-1)"><?php echo $lang_common['Go back'] ?></a></p>
		</form>
	</div>
</div>

<div id="postreview">
	<div class="blockpost">
		<div class="box">
			<div class="inbox">
				<div class="postbody">
					<div class="postleft">
						<dl>
							<dt><strong><?php echo pun_htmlspecialchars($cur_post['poster']) ?></strong></dt>
							<dd><span><?php echo format_time($cur_post['posted']) ?></span></dd>
						</dl>
					</div>
					<div class="postright">
						<div class="postmsg">
							<?php echo $cur_post['message']."\n" ?>
						</div>
					</div>
				</div>
				<div class="clearer"></div>
			</div>
		</div>
	</div>
</div>
<?php

require PUN_ROOT.'footer.php';
