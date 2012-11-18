<?php
$gz_ak_lang = array(
	'Yes' => 'Yes',
	'No' => 'No',
	'desc' => '<i>A spam filter from the creators of WordPress,<br/>proudly brought to you by GameZoo - <a href="http://www.gamezoo.it">www.gamezoo.it</a>.<br/>Visit <a href="http://akismet.com/">akismet.com</a> for more info.</i>',
	'installation' => 'Installation',
	'configuration' => 'Configuration',
	'database create desc' => 'This mod stores the posts that Akismet marked as spam in a database table, so we need to create the table first. Click on the button below to create the table.',
	'database create table' => 'Create table',
	'database create success' => 'The database table has been created without errors.',
	'start configure' => 'Go to the configuration page',
	'api key desc' => 'Akismet needs an API key. If you have a WordPress blog, get your personal API key here: <a href="https://apikey.wordpress.com/">https://apikey.wordpress.com</a>. Otherwise, get one here: <a href="https://akismet.com/signup/">https://akismet.com/signup</a>.',
	'api key input' => 'Paste your API key:',
	'user groups desc' => 'Akismet will check only posts from these user groups. Hint: use FluxBB\'s auto-promotion feature for new users.',
	'user groups input' => 'User groups to check:',
	'first filter desc' => 'Akismet is known to mark as spam posts that are too short, even if they don\'t contain links. Here you can adjust the conditions triggering up Akismet when <b>at least one</b> is verified.',
	'first filter post length' => 'Post length greater than:',
	'first filter post contains links' => 'Presence of links in the post:',
	'save configuration' => 'Save configuration',
	'save configuration success' => 'The configuration has been updated.',
	'back to main' => 'Return to the configuration page',
	'invalid key' => 'The Akismet key you provided is invalid. Please set a valid key.',
	'post length not numeric' => 'The post length must be a numeric value.',
	'spam queue days not numeric' => 'The number of days a post can be kept in the spam queue must be a numeric value.',
	'spam queue days input' => 'Days in spam queue:',
	'spam queue days desc' => 'Akismet keeps the posts marked as spam in a spam queue that can be reviewed by administrators and moderators. You can define here for how many days a post will be kept in the queue before being deleted forever. Setting "0" means that the posts will be kept until an administrator or moderator removes them.',
	'missing installation' => 'Akismet is missing',
	'missing installation desc' => 'The forum admin didn\'t install or configure Akismet. Please ask him to do so.',
	'akismet zapped you' => 'Akismet zapped you. You must wait until admins or moderators review the queue. Do NOT attempt to get unbanned. Do NOT write us. Do NOTHING.',
	'spam queue' => 'Spam queue',
	'spam queue desc' => 'Here\'s the spam Akismet trapped.<br/>If you mark a post as <strong>Spam</strong> it will be deleted forever and the poster infos (signature, url...) will be reset (the poster is already banned). To completely delete the poster from the database use the admin menu.<br/>If you mark a post as <strong>Ham</strong>, it will be restored and the poster unbanned.',
	'spam queue autodeleted plural 1' => ' posts were older than ',
	'spam queue autodeleted plural 2' => ' days and have been automatically deleted.',
	'spam queue autodeleted singular 1' => ' post was older than ',
	'spam queue autodeleted singular 2' => ' days and has been automatically deleted.',
	'spam queue autodelete days 1' => 'Posts older than ',
	'spam queue autodelete days 2' => ' days will be automatically deleted.',
	'spam queue empty' => 'Rejoice and cheer! You have no spam!',
	'spam queue mark spam' => 'Spam',
	'spam queue mark ham' => 'Ham',
	'spam queue save' => 'Delete spam, restore ham',
	'spam queue mark all spam' => 'Mark all as spam',
	'spam queue error list' => 'The selection you made contained these errors:',
	'configuration error list' => 'The configuration you submitted contained these errors and no changes were made:',
	'total spam deleted' => 'Number of spam posts deleted: ',
	'total ham restored' => 'Number of ham posts restored: ',
	'back to queue' => 'Go back to the spam queue',
	'spam ham overlap' => 'You have chosen to mark every selected post as spam <strong>and</strong> ham. Of course this doesn\'t work.',
);
