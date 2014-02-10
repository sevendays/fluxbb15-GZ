<?php
// Make sure no one attempts to run this script "directly"
if (!defined('PUN'))
	exit;

// We want the complete error message if the script fails
//if (!defined('PUN_DEBUG'))
//	define('PUN_DEBUG', 1);
	
// Load the akismet_gamezoo.php language file
require_once PUN_ROOT.'lang/'.$admin_language.'/gamezoo_akismet_lang.php';

// Tell admin_loader.php that this is indeed a plugin and that it is loaded
define('PUN_PLUGIN_LOADED', 1);

// this generates the admin menu for the mod
generate_admin_menu($plugin);

// is it the first time we're running this mod?
if(!gz_ak_queue_first_time())
	if(gz_ak_queue_spamactions_submitted())
		gz_ak_queue_spamactions();
	else
		gz_ak_queue_show();
else
	gz_ak_queue_request_cfg();

function gz_ak_queue_first_time()
{
	global $pun_config;
	if(isset($pun_config['o_gz_akismet'])) return false;
	return true;
}

function gz_ak_queue_request_cfg()
{
	global $gz_ak_lang;
	?>
	<div class="blockform">
		<h1><span><b>A&sdot;kis&sdot;met</b> for FluxBB</span></h1>
		<p><?php echo $gz_ak_lang['desc']; ?></p>
		
		<h2 class="block2"><span><?php echo $gz_ak_lang['missing installation'] ?></span></h2>
		<div class="box" style="padding: 0 18px 18px 18px">
			<div class="inbox">
				<p><?php echo $gz_ak_lang['missing installation desc']; ?></p>
			</div>
		</div>
	</div>
	<?php
}

function gz_ak_queue_show($errors = NULL)
{
	global $db, $pun_user, $gz_ak_lang, $pun_config;
	
	$gz_ak_cfg = json_decode($pun_config['o_gz_akismet'], true);
	
	// delete old spam
	require_once PUN_ROOT.'include/gamezoo_akismet.php';
	$old_spam_deleted = gz_ak_autodelete_spams();
	
	?>
	<div class="blockform">
		<h1><span><b>A&sdot;kis&sdot;met</b> for FluxBB</span></h1>
		<p><?php echo $gz_ak_lang['desc']; ?></p>
		<h2><span><?php echo $gz_ak_lang['spam queue'] ?></span></h2>
		<div class="box" style="padding: 0 18px 18px 18px">
			
			<?php if (!is_null($errors))
			{
				?>
				<div class="inbox error-info" style="margin:18px">
					<p><strong><?php echo $gz_ak_lang['spam queue error list'] ?></strong></p>
					<ul class="error-list">
					<?php
						foreach ($errors as $cur_error)
						echo "\t\t\t\t".'<li><strong>'.$cur_error.'</strong></li>'."\n";
						?>
					</ul>
				</div>
				<?php
			}
			?>
			
			<div class="inbox">
				<p>
				<?php
				echo $gz_ak_lang['spam queue desc'];
				if($gz_ak_cfg['spam_queue_days'] > 0)
				{
					if($old_spam_deleted == 0)
						echo '<br/>' . $gz_ak_lang['spam queue autodelete days 1'] . $gz_ak_cfg['spam_queue_days'] . $gz_ak_lang['spam queue autodelete days 2'];
					else if($old_spam_deleted == 1)
						echo '<br/>' . $old_spam_deleted . $gz_ak_lang['spam queue autodeleted singular 1'] . $gz_ak_cfg['spam_queue_days'] . $gz_ak_lang['spam queue autodeleted singular 2'];
					else if($old_spam_deleted > 1)
						echo '<br/>' . $old_spam_deleted . $gz_ak_lang['spam queue autodeleted plural 1'] . $gz_ak_cfg['spam_queue_days'] . $gz_ak_lang['spam queue autodeleted plural 2'];
				}
				?>
				</p>
			</div>
			
			<?php
			$result = $db->query('SELECT id, poster, poster_id, poster_ip, poster_email, subject, message, posted FROM '.$db->prefix.'gz_akismet_queue') or error('Unable to fetch spam table', __FILE__, __LINE__, $db->error());
			if($db->num_rows($result) == 0)
			{
				?>
				<div class="inbox infldset">
					<p style="text-align:center"><strong><?php echo $gz_ak_lang['spam queue empty']; ?></strong></p>
				</div>
				<?php
			}
			else
			{
				$even = false;
				?>
				<form name="gz_ak_queue_form" id="gz_ak_queue_form" method="post" action="<?php echo pun_htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
					<p class="submittop" style="border-bottom:none;">
						<button type="button" style="display:none" disabled="true" onclick="GzAkQueueMarkAllSpam()"><?php echo $gz_ak_lang['spam queue mark all spam'] ?></button>
						<input type="submit" name="gz_ak_queue" value="<?php echo $gz_ak_lang['spam queue save'] ?>" />
					</p>
					<?php
					while($spam = $db->fetch_assoc($result))
					{
						?>
						<div class="blockpost <?php if($even) echo 'roweven'; else echo 'rowodd';?>">
							<div class="inbox">
								<div class="postbody">
									<div class="postleft">
										<dl>
											<dt>
												<strong>
													<a href="profile.php?id=<?php echo $spam['poster_id'];?>"><?php echo pun_htmlspecialchars($spam['poster']); ?></a>
												</strong>
											</dt>
											<dd><span>IP: <?php echo pun_htmlspecialchars($spam['poster_ip']); ?></span></dd>
											<dd><span><?php echo pun_htmlspecialchars($spam['poster_email']); ?></span></dd>
											<dd><span><?php echo format_time($spam['posted']) ?></span></dd>
										</dl>
									</div>
									<div class="postright">
										<p>
											<strong><?php if(!empty($spam['subject'])) echo pun_htmlspecialchars($spam['subject']).': '; ?></strong>
											<?php echo pun_htmlspecialchars($spam['message']); ?>
										</p>
									</div>
								</div>
							</div>
							<div class="inbox">
								<div class="postfoot clearb">
									<div class="postfootleft">
									</div>
									<div class="postfootright">
										<input type="checkbox" name="is_spam[]" value="<?php echo $spam['id']; ?>" /><strong><?php echo $gz_ak_lang['spam queue mark spam']; ?></strong>
										<input type="checkbox" name="is_ham[]" value="<?php echo $spam['id']; ?>" /><strong><?php echo $gz_ak_lang['spam queue mark ham']; ?></strong>
									</div>
								</div>
							</div>
						</div>
						<?php
						$even = !$even;
					}
					?>
					<p class="submitend">
						<button type="button" style="display:none" disabled="true" onclick="GzAkQueueMarkAllSpam()"><?php echo $gz_ak_lang['spam queue mark all spam'] ?></button>
						<input type="submit" name="gz_ak_queue" value="<?php echo $gz_ak_lang['spam queue save'] ?>" />
					</p>
				</form>
				<?php
			}
			?>
		</div>
	</div>
	<!-- script to check all checkboxes, enable buttons+display if js is present. -->
	<script type="text/javascript">
	//<![CDATA[
	buttons = document.getElementsByTagName("button");
	for(var i=0; i < buttons.length; i++)
	{
		buttons[i].style.display="inline";
		buttons[i].disabled = false;
	}
	function GzAkQueueMarkAllSpam()
	{
		inputs = document.getElementsByTagName("input");
		for(var i=0; i < inputs.length; i++)
		{
			if(inputs[i].type == "checkbox")
			{	
				// select spam, deselect ham
				if(inputs[i].name.indexOf("is_spam") != -1)
					inputs[i].checked = true;
				if(inputs[i].name.indexOf("is_ham") != -1)
					inputs[i].checked = false;
			}
		}
	}
	//]]>
	</script>
	<?php
}

function gz_ak_queue_spamactions_submitted()
{
	if(isset($_POST['gz_ak_queue'])) return true;
	else return false;
}

function gz_ak_queue_spamactions()
{
	global $gz_ak_lang;
	
	// fetch all from form
	$spams = (isset($_POST['is_spam'])) ? $_POST['is_spam'] : array();
	$hams = (isset($_POST['is_ham'])) ? $_POST['is_ham'] : array();
	
	$errors = array();
	
	// check that we have numeric values for spam ids
	if(!is_null($spams))
	{
		if(!is_array($spams)) $errors['wannabehackers'] = 'Stop fiddling!';
		foreach($spams as $s)
			if(!is_numeric($s)) $errors['wannabehackers'] = 'Stop fiddling!';
	}
	else $spams = array();
	
	// check that we have numeric values for ham ids
	if(!is_null($hams))
	{
		if(!is_array($hams)) $errors['wannabehackers'] = 'Stop fiddling!';
		foreach($hams as $h)
			if(!is_numeric($h)) $errors['wannabehackers'] = 'Stop fiddling!';
	}
	else $hams = array();
	
	// if there are errors, reprint the form passing the errors.
	if(!empty($errors))
	{
		gz_ak_queue_show($errors);
		return;
	}
	
	// Let's remove duplicates and common elements between the 2 arrays.
	$spams = array_unique($spams, SORT_NUMERIC);
	$hams = array_unique($hams, SORT_NUMERIC);
	$common = array_intersect($spams, $hams);
	$realspams = array_diff($spams, $common);
	$realhams = array_diff($hams, $common);
	
	if(!empty($realspams) || !empty($realhams))
		require_once PUN_ROOT.'include/gamezoo_akismet.php';
	else
	{
		$errors[] = $gz_ak_lang['spam ham overlap'];
		gz_ak_queue_show($errors);
		return;
	}
	
	// delete spams
	$total_spam_deleted = gz_ak_spams($realspams);
	
	// restore hams
	$restore_errors = array();
	$total_hams_restored = gz_ak_hams($realhams, $restore_errors);
	
	// print success and redirect to plugin main page
	?>
	<div class="blockform">
		<h1><span><b>A&sdot;kis&sdot;met</b> for FluxBB</span></h1>
		<p><?php echo $gz_ak_lang['total spam deleted'] . $total_spam_deleted .'<br/>'. $gz_ak_lang['total ham restored']. $total_hams_restored; ?>.</p>
		<?php
		if (!empty($restore_errors))
		echo '<p>'.$gz_ak_lang['hams restore errors'].'</p>';
		foreach($restore_errors as $err)
		{
			echo '<p>'.$err.'</p>';
		}
		?>
		<p>
			<a href="<?php echo pun_htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
				<?php echo $gz_ak_lang['back to queue']; ?>
			</a>
		</p>
	</div>
	<?php
}

