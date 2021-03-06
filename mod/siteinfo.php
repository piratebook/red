<?php

function siteinfo_init(&$a) {

	if ($a->argv[1]=="json"){
		$register_policy = Array('REGISTER_CLOSED', 'REGISTER_APPROVE', 'REGISTER_OPEN');

		$sql_extra = '';
		if(x($a->config,'admin_nickname')) {
			$sql_extra = sprintf(" AND nickname = '%s' ",dbesc($a->config['admin_nickname']));
		}
		if (isset($a->config['admin_email']) && $a->config['admin_email']!=''){
			$r = q("SELECT username, nickname FROM user WHERE email='%s' $sql_extra", dbesc($a->config['admin_email']));
			$admin = array(
				'name' => $r[0]['username'],
				'profile'=> $a->get_baseurl().'/channel/'.$r[0]['nickname'],
			);
		} else {
			$admin = false;
		}

		$visible_plugins = array();
		if(is_array($a->plugins) && count($a->plugins)) {
			$r = q("select * from addon where hidden = 0");
			if(count($r))
				foreach($r as $rr)
					$visible_plugins[] = $rr['name'];
		}

		$data = Array(
			'version' => FRIENDICA_VERSION,
			'url' => z_root(),
			'plugins' => $visible_plugins,
			'register_policy' =>  $register_policy[$a->config['system']['register_policy']],
			'admin' => $admin,
			'site_name' => $a->config['sitename'],
			'platform' => FRIENDICA_PLATFORM,
			'info' => ((x($a->config,'info')) ? $a->config['info'] : '')			
		);

		echo json_encode($data);
		killme();
	}
}



function siteinfo_content(&$a) {

	// FIXME Gak - this needs to be a template.

	$o = '';
	$o .= '<h3>' . t('Red') . '</h3>';


	$o .= '<p></p><p>';

	$o .= t('This is Red - another decentralized, distributed communications project by the folks at Friendica.');
	if(! get_config('system','hidden_version_siteinfo'))
		$o .= '</p><p>' . sprintf( t('Version %s'), FRIENDICA_VERSION ) . '</p><p>';
	$o .= '</p><p>';
	$o .= t('Running at web location') . ' ' . z_root() . '</p><p>';

	$o .= t('Please visit <a href="http://friendica.com">Friendica.com</a> to learn more about the Friendica and/or Red project.') . '</p><p>';	

	$o .= t('Bug reports and issues: please visit') . ' ' . '<a href="http://bugs.friendica.com">Bugs.Friendica.com</a></p><p>';
	$o .= t('Suggestions, praise, donations, etc. - please email "Info" at Friendica - dot com') . '</p>';

	$o .= '<p></p>';

	$visible_plugins = array();
	if(is_array($a->plugins) && count($a->plugins)) {
		$r = q("select * from addon where hidden = 0");
		if(count($r))
			foreach($r as $rr)
				$visible_plugins[] = $rr['name'];
	}


	if(count($visible_plugins)) {
		$o .= '<p>' . t('Installed plugins/addons/apps:') . '</p>';
		$sorted = $visible_plugins;
		$s = '';
		sort($sorted);
		foreach($sorted as $p) {
			if(strlen($p)) {
				if(strlen($s)) $s .= ', ';
				$s .= $p;
			}
		}
		$o .= '<div style="margin-left: 25px; margin-right: 25px;">' . $s . '</div>';
	}
	else
		$o .= '<p>' . t('No installed plugins/addons/apps') . '</p>';

	call_hooks('about_hook', $o); 	

	return $o;

}
