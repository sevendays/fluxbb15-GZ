<?php
// Make sure no one attempts to run this script "directly"
if (!defined('PUN'))
	exit;

// We want the complete error message if the script fails
if (!defined('PUN_DEBUG'))
	define('PUN_DEBUG', 1);
	
// Load the akismet_gamezoo.php language file
require PUN_ROOT.'lang/'.$admin_language.'/gamezoo_akismet_lang.php';

// Tell admin_loader.php that this is indeed a plugin and that it is loaded
define('PUN_PLUGIN_LOADED', 1);

// this generates the admin menu for the mod
generate_admin_menu($plugin);

// is it the first time we're running this mod?
if(!gz_ak_first_time())
{
	if(gz_ak_update_submitted())
		gz_ak_update();
	else
		gz_ak_show_update();
}
else
{
	if(gz_ak_install_submitted())
		gz_ak_install();
	else
		gz_ak_show_install();
}

// ok, here you go...
function gz_ak_first_time()
{
	// query FluxBB for akismet configuration options.
	// If there is a config return false.
	global $pun_config;
	if(isset($pun_config['o_gz_akismet'])) return false;
	// if the table exists return false.
	global $db, $db_type;
	switch ($db_type)
	{
		case 'mysql':
		case 'mysqli':
			$result = $db->query('SHOW TABLES') or error('Unable to fetch tables', __FILE__, __LINE__, $db->error());
			break;
		case 'pgsql': // untested
			$result = $db->query("select table_name from information_schema.tables where table_schema NOT IN ('pg_catalog', 'information_schema');") or error('Unable to fetch tables', __FILE__, __LINE__, $db->error());
			break;
		case 'sqlite': // untested
			$result = $db->query("SELECT name FROM sqlite_master WHERE type='table';") or error('Unable to fetch tables', __FILE__, __LINE__, $db->error());
			break;
	}
	$tables = array();
	while ($table = $db->fetch_row($result))
		$tables[] = $table[0];
	if(in_array($db->prefix.'gz_akismet_queue', $tables)) return false;
	return true;
}

function gz_ak_install_submitted()
{
	if(isset($_POST['gz_ak_install'])) return true;
	else return false;
}

function gz_ak_install()
{
	global $db, $db_type, $pun_config, $gz_ak_lang;
	$sql = '';
	// install tables
	switch ($db_type)
	{
		case 'mysql':
		case 'mysqli':
			$sql = "CREATE TABLE ".$db->prefix."gz_akismet_queue (
					id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
					poster VARCHAR(200) NOT NULL DEFAULT '',
					poster_id INT(10) UNSIGNED NOT NULL DEFAULT 1,
					poster_ip VARCHAR(39),
					poster_email VARCHAR(80),
					subject VARCHAR(255),					
					message MEDIUMTEXT NOT NULL DEFAULT '',
					hide_smilies TINYINT(1) NOT NULL DEFAULT 0,
					posted INT(10) UNSIGNED NOT NULL DEFAULT 0,
					edited INT(10) UNSIGNED,
					edited_by VARCHAR(200),
					topic_id INT(10) UNSIGNED,
					forum_id INT(10) UNSIGNED,
					user_agent MEDIUMTEXT,
					PRIMARY KEY (id)
					);";
			break;

		case 'pgsql': // not tested
			$sql = "CREATE TABLE ".$db->prefix."gz_akismet_queue (
					id SERIAL,
					poster VARCHAR(200) NOT NULL DEFAULT '',
					poster_id INT NOT NULL DEFAULT 1,
					poster_ip VARCHAR(39),
					poster_email VARCHAR(80),
					subject VARCHAR(255),
					message TEXT NOT NULL DEFAULT '',
					hide_smilies SMALLINT NOT NULL DEFAULT 0,
					posted INT NOT NULL DEFAULT 0,
					edited INT,
					edited_by VARCHAR(200),
					topic_id INT,
					forum_id INT,
					user_agent TEXT,
					PRIMARY KEY (id)
					)";
			break;

		case 'sqlite': // not tested
			$sql = "CREATE TABLE ".$db->prefix."gz_akismet_queue (
					id INTEGER NOT NULL,
					poster VARCHAR(200) NOT NULL DEFAULT '',
					poster_id INT(10) UNSIGNED NOT NULL DEFAULT 1,
					poster_ip VARCHAR(39),
					poster_email VARCHAR(80),
					subject VARCHAR(255),
					message TEXT NOT NULL DEFAULT '',
					hide_smilies INTEGER NOT NULL DEFAULT 0,
					posted INTEGER NOT NULL DEFAULT 0,
					edited INTEGER,
					edited_by VARCHAR(200),
					topic_id INTEGER,
					forum_id INTEGER,
					user_agent TEXT,
					PRIMARY KEY (id)
					)";
			break;
	}
	
	$db->query($sql) or error('Unable to create table '.$db->prefix.'gz_akismet_queue. Please check your settings and try again.',  __FILE__, __LINE__, $db->error());	
	
	// print success and redirect to plugin main page
	?>
	<div class="blockform">
		<h1><span><b>A&sdot;kis&sdot;met</b> for FluxBB</span></h1>
		<p><?php echo $gz_ak_lang['database create success']; ?>.</p>
		<p>
			<a href="<?php echo pun_htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
				<?php echo $gz_ak_lang['start configure']; ?>
			</a>
		</p>
	</div>
	<?php
}

function gz_ak_show_install()
{
	global $gz_ak_lang;
	?>
	<div class="plugin blockform">
		<h1><span><b>A&sdot;kis&sdot;met</b> for FluxBB</span></h1>
		<p><?php echo $gz_ak_lang['desc']; ?></p>
		
		<h2 class="block2"><span><?php echo $gz_ak_lang['installation'] ?></span></h2>
		<div class="box">
			<form id="gz_ak_install_form" method="post" action="<?php echo pun_htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
				<div class="inform">
					<fieldset>
						<legend>Database</legend>
						<div class="infldset">
							<div class="inbox">
								<p><?php echo $gz_ak_lang['database create desc']; ?></p>
							</div>
							<table class="aligntop" cellspacing="0">
								<tr>
									<th scope="row">
										<?php echo $gz_ak_lang['database create table'] . ' gz_akismet_queue'; ?>
									</th>
									<td>
										<div>
											<input type="submit" name="gz_ak_install" value="<?php echo $gz_ak_lang['database create table'] ?>" tabindex="2" />
										</div>
									</td>
								</tr>
							</table>
						</div>
					</fieldset>
				</div>
			</form>
		</div>
	</div>
	<?php
}

function gz_ak_update()
{
	global $db, $pun_config, $gz_ak_lang;
	
	// fetch saved options if they exist, or use defaults
	$gz_ak_cfg['api_key'] = 'undefined'; // api key is mandatory
	$gz_ak_cfg['monitored_groups'] = PUN_GUEST; // always check guest posts
	$gz_ak_cfg['trusted_groups'] = PUN_ADMIN.','.PUN_MOD; // trust only admins and mods by default
	$gz_ak_cfg['post_minchars'] = 2; // = strlen('No')
	$gz_ak_cfg['link_presence'] = 1; // check every time there's a link
	$gz_ak_cfg['spam_queue_days'] = 10; // delete automatically spam after X days
	if(isset($pun_config['o_gz_akismet']))
		$gz_ak_cfg = array_merge($gz_ak_cfg, json_decode($pun_config['o_gz_akismet'], true));
	
	// fetch all
	$api_key = $_POST['api_key'];
	$monitored_groups = $_POST['monitored_groups'];
	$trusted_groups = $_POST['trusted_groups'];
	$post_minchars = $_POST['post_minchars'];
	$link_presence = $_POST['link_presence'];
	$spam_queue_days = $_POST['spam_queue_days'];
	
	// errors
	$errors = array();
	
	// check that the api key is correct - just ask akismet!
	if(!class_exists('Akismet'))
		require PUN_ROOT.'include/Akismet.class.php';
	$akismet = new Akismet(get_base_url(true), $api_key);
	if($akismet->isKeyValid()) $gz_ak_cfg['api_key'] = $api_key;
	else $errors['api_key'] = $gz_ak_lang['invalid key'];
	
	// fetch user groups
	$result = $db->query('SELECT g_id FROM '.$db->prefix.'groups ORDER BY g_id') or error('Unable to fetch user groups', __FILE__, __LINE__, $db->error());
	$groups = array();
	while ($g = $db->fetch_row($result)) $groups[] = $g[0];
	
	// check that monitored groups exist and are numbers
	if(!is_null($monitored_groups))
	{
		if(!is_array($monitored_groups)) $errors['wannabehackers'] = 'Stop fiddling!';
		foreach($monitored_groups as $mg)
		{
			if(!is_numeric($mg)) $errors['wannabehackers'] = 'Stop fiddling!';
			if(!in_array($mg, $groups)) $errors['wannabehackers'] = 'Stop fiddling!';
		}
		$gz_ak_cfg['monitored_groups'] = implode(',', $monitored_groups);
	}
	else $gz_ak_cfg['monitored_groups'] = '';
	
	// check that trusted groups exist and are numbers
	if(!is_null($trusted_groups))
	{
		if(!is_array($trusted_groups)) $errors['wannabehackers'] = 'Stop fiddling!';
		foreach($trusted_groups as $tg)
		{
			if(!is_numeric($tg)) $errors['wannabehackers'] = 'Stop fiddling!';
			if(!in_array($tg, $groups)) $errors['wannabehackers'] = 'Stop fiddling!';
		}
		$gz_ak_cfg['trusted_groups'] = implode(',', $trusted_groups);
	}
	else $gz_ak_cfg['trusted_groups'] = '';
		
	// check that minchars is a number
	if(!is_null($post_minchars))
	{
		if(is_numeric($post_minchars)) $gz_ak_cfg['post_minchars'] = intval($post_minchars);
		else $errors['post_minchars'] = $gz_ak_lang['post length not numeric'];
	}
	
	// check that link_presence is a number
	if(!is_null($link_presence))
	{
		if(is_numeric($link_presence))
		{
			if(intval($link_presence) > 0) $gz_ak_cfg['link_presence'] = 1;
			else $gz_ak_cfg['link_presence'] = 0;
		}
		else $errors['wannabehackers'] = 'Stop fiddling!';
	}
	
	// check that spam_queue_days is a number
	if(!is_null($spam_queue_days))
	{
		if(is_numeric($spam_queue_days)) $gz_ak_cfg['spam_queue_days'] = intval($spam_queue_days);
		else $errors['spam_queue_days'] = $gz_ak_lang['spam queue days not numeric'];
	}
	
	// if there are errors, reprint the form passing the errors.
	if(!empty($errors))
	{
		gz_ak_show_update($errors);
	}
	else
	{
		// save to config table
		$key = $db->escape('o_gz_akismet');
		$value = $db->escape(json_encode($gz_ak_cfg));
		
		if(isset($pun_config['o_gz_akismet']))
		{
			// update
			$db->query('UPDATE '.$db->prefix.'config SET conf_value=\''.$value.'\' WHERE conf_name=\''.$key.'\'') or error('Unable to update Akismet board config', __FILE__, __LINE__, $db->error());
		}
		else
		{
			// new
			$db->query('INSERT INTO '.$db->prefix.'config (conf_value, conf_name) VALUES (\''.$value.'\',\''.$key.'\')') or error('Unable to create Akismet board config', __FILE__, __LINE__, $db->error());
		}
		
		// Regenerate the config cache
		if (!defined('FORUM_CACHE_FUNCTIONS_LOADED'))
			require PUN_ROOT.'include/cache.php';

		generate_config_cache();
		
		// print success and link to plugin main page
		?>
		<div class="plugin blockform">
			<h1><span><b>A&sdot;kis&sdot;met</b> for FluxBB</span></h1>
			<p><?php echo $gz_ak_lang['save configuration success']; ?></p>
			<p>
				<a href="<?php echo pun_htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
					<?php echo $gz_ak_lang['back to main']; ?>
				</a>
			</p>
		</div>
		<?php
	}
}

function gz_ak_update_submitted()
{
	if(isset($_POST['gz_ak_update'])) return true;
	else return false;
}

function gz_ak_show_update($errors = NULL)
{
	global $gz_ak_lang;
	global $db, $db_type, $pun_config;
	
	$gz_ak_cfg = array();
	
	// fetch saved options if they exist, or use defaults
	$gz_ak_cfg['api_key'] = 'undefined'; // api key is mandatory
	$gz_ak_cfg['monitored_groups'] = PUN_GUEST; // always check guest posts
	$gz_ak_cfg['trusted_groups'] = PUN_ADMIN.','.PUN_MOD; // trust only admins and mods by default
	$gz_ak_cfg['post_minchars'] = 2; // = strlen('No')
	$gz_ak_cfg['link_presence'] = 1; // check every time there's a link
	$gz_ak_cfg['spam_queue_days'] = 10; // delete automatically spam after X days
	
	if(isset($pun_config['o_gz_akismet']))
		$gz_ak_cfg = array_merge($gz_ak_cfg, json_decode($pun_config['o_gz_akismet'], true));
	?>
	<div class="blockform">
		<h1><span><b>A&sdot;kis&sdot;met</b> for FluxBB</span></h1>
		<p><?php echo $gz_ak_lang['desc']; ?></p>
		
		<h2><?php echo $gz_ak_lang['configuration'] ?></h2>
		
		<div class="box">
			
			<?php if (!is_null($errors))
			{
				?>
				<div class="inbox error-info" style="margin:18px">
					<p><strong><?php echo $gz_ak_lang['configuration error list'] ?></strong></p>
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
			
			<form id="gz_ak_config_form" method="post" action="<?php echo pun_htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
				<div class="inform">
					<fieldset>
						<legend>API key</legend>
						<div class="infldset">
							<div class="inbox">
								<p><?php echo $gz_ak_lang['api key desc']; ?></p>
							</div>
							<table class="aligntop" cellspacing="0">
								<tr>
									<th scope="row">
										<?php echo $gz_ak_lang['api key input'] ?>
									</th>
									<td>
										<input type="text" name="api_key" size="25" tabindex="1" <?php
										echo 'value="'.$gz_ak_cfg['api_key'].'"';
										?>/>
									</td>
								</tr>
							</table>
						</div>
					</fieldset>
				</div>
				
				<div class="inform">
					<fieldset>
						<legend>Untrusted users</legend>
						<div class="infldset">
							<div class="inbox">
								<p><?php echo $gz_ak_lang['user groups desc']; ?></p>
							</div>
							<table class="aligntop" cellspacing="0">
								<tr>
									<th scope="row">
										<?php echo $gz_ak_lang['user groups input'] ?>
									</th>
									<td>
										<?php
										// saved groups
										$akgroups = explode(',', $gz_ak_cfg['monitored_groups']);
										// Fetch all groups
										$result = $db->query('SELECT * FROM '.$db->prefix.'groups ORDER BY g_id') or error('Unable to fetch user groups', __FILE__, __LINE__, $db->error());
										$groups = array();
										while ($cur_group = $db->fetch_assoc($result))
											$groups[$cur_group['g_id']] = $cur_group;
										$i=0;
										foreach($groups as $gid=>$g)
										{
											echo '<input type="checkbox" name="monitored_groups[]" value="'.$gid.'"';
											if(in_array($gid, $akgroups))
												echo ' checked="checked"';
											echo ' />'.$g['g_title'].'<br />';
											$i++;
										}
										?>
									</td>
								</tr>
							</table>
						</div>
					</fieldset>
				</div>
				
				<div class="inform">
					<fieldset>
						<legend>Trusted users</legend>
						<div class="infldset">
							<div class="inbox">
								<p><?php echo $gz_ak_lang['trusted user groups desc']; ?></p>
							</div>
							<table class="aligntop" cellspacing="0">
								<tr>
									<th scope="row">
										<?php echo $gz_ak_lang['trusted user groups input'] ?>
									</th>
									<td>
										<?php
										// saved groups
										$aktgroups = explode(',', $gz_ak_cfg['trusted_groups']);
										// Fetch all groups
										$result = $db->query('SELECT * FROM '.$db->prefix.'groups ORDER BY g_id') or error('Unable to fetch user groups', __FILE__, __LINE__, $db->error());
										$groups = array();
										while ($cur_group = $db->fetch_assoc($result))
											$groups[$cur_group['g_id']] = $cur_group;
										$i=0;
										foreach($groups as $gid=>$g)
										{
											echo '<input type="checkbox" name="trusted_groups[]" value="'.$gid.'"';
											if(in_array($gid, $aktgroups))
												echo ' checked="checked"';
											echo ' />'.$g['g_title'].'<br />';
											$i++;
										}
										?>
									</td>
								</tr>
							</table>
						</div>
					</fieldset>
				</div>
				
				<div class="inform">
					<fieldset>
						<legend>Triggers</legend>
						<div class="infldset">
							<div class="inbox">
								<p><?php echo $gz_ak_lang['first filter desc']; ?></p>
							</div>
							<table class="aligntop" cellspacing="0">
								<tr>
									<th scope="row">
										<?php echo $gz_ak_lang['first filter post length'] ?>
									</th>
									<td>
										<input type="text" name="post_minchars" size="25" tabindex="1" <?php
										echo 'value = "'.$gz_ak_cfg['post_minchars'].'"';
										?> />
									</td>
								</tr>
								<tr>
									<th scope="row">
										<?php echo $gz_ak_lang['first filter post contains links'] ?>
									</th>
									<td>
										<input type="radio" name="link_presence" value="1" <?php
										if($gz_ak_cfg['link_presence'] != 0)
												echo 'checked="checked"';
										?> /><?php echo $gz_ak_lang['Yes']; ?>
										<input type="radio" name="link_presence" value="0" <?php
										if($gz_ak_cfg['link_presence'] == 0)
												echo 'checked="checked"';
										?> /><?php echo $gz_ak_lang['No'] ?>
									</td>
								</tr>
							</table>
						</div>
					</fieldset>
				</div>
				<div class="inform">
					<fieldset>
						<legend><?php echo $gz_ak_lang['spam queue']; ?></legend>
						<div class="infldset">
							<div class="inbox">
								<p><?php echo $gz_ak_lang['spam queue days desc']; ?></p>
							</div>
							<table class="aligntop" cellspacing="0">
								<tr>
									<th scope="row">
										<?php echo $gz_ak_lang['spam queue days input'] ?>
									</th>
									<td>
										<input type="text" name="spam_queue_days" size="25" tabindex="1" <?php
										echo 'value="'.$gz_ak_cfg['spam_queue_days'].'"';
										?>/>
									</td>
								</tr>
							</table>
						</div>
					</fieldset>
				</div>
				<p class="submitend">
					<input type="submit" name="gz_ak_update" value="<?php echo $gz_ak_lang['save configuration'] ?>" />
				</p>
			</form>
		</div>
		
	</div>
	<?php
}



